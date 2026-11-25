<?php

namespace Azera\Tests\Aop;

require_once __DIR__ . '/../../vendor/autoload.php';

use Azera\Aop\Advised;
use Azera\Aop\Invocation;
use Azera\Aop\Transactional;
use Azera\Aop\TransactionalInterceptor;
use Azera\AppContext;
use Azera\Db\Database;
use Azera\Db\DatabaseManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[Advised]
class TransactionalService
{    public function __construct(public Database $db) {}

    #[Transactional]
    public function writesThenSucceeds(): string
    {
        $this->db->query("INSERT INTO t (v) VALUES ('ok')");
        return 'done';
    }

    #[Transactional]
    public function writesThenThrows(): void
    {
        $this->db->query("INSERT INTO t (v) VALUES ('discarded')");
        throw new RuntimeException('boom');
    }

    #[Transactional]
    public function outer(): void
    {
        $this->db->query("INSERT INTO t (v) VALUES ('outer')");
        try {
            $this->innerThatThrows();
        } catch (Throwable) {
            // Swallowed: only the inner savepoint must be undone.
        }
        $this->db->query("INSERT INTO t (v) VALUES ('outer2')");
    }

    #[Transactional]
    public function innerThatThrows(): void
    {
        $this->db->query("INSERT INTO t (v) VALUES ('inner')");
        throw new RuntimeException('inner boom');
    }
}

/**
 * Fixture for connection-role validation: names a role that does not exist.
 */
#[Advised]
class UnknownRoleService
{
    public function __construct(public Database $db) {}

    #[Transactional(connection: 'analytcs')]
    public function go(): string
    {
        return 'ok';
    }
}

/**
 * Covers the transaction semantics the AOP advice relies on.
 */
class TransactionalInterceptorTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        AppContext::setInstance(new AppContext());

        $this->db = new Database('sqlite::memory:');
        $this->db->query('CREATE TABLE t (v TEXT)');

        $manager = new DatabaseManager();
        $manager->set('write', $this->db);

        $ctx = AppContext::instance();
        $ctx->set(DatabaseManager::class, $manager);
        $ctx->set(Database::class, $this->db);
        $ctx->registerInterceptor(
            Transactional::class,
            fn() => new TransactionalInterceptor($ctx->dbManager()),
        );
    }

    private function service(): TransactionalService
    {
        return AppContext::instance()->get(TransactionalService::class);
    }

    private function values(): array
    {
        return array_map(
            static fn(object $row) => $row->v,
            $this->db->query('SELECT v FROM t ORDER BY rowid')->fetchAll(),
        );
    }

    /**
     * Build the Invocation the proxy would hand the interceptor, so the direct
     * `intercept()` calls below exercise the same shape the generated code uses.
     */
    private function invocation(object|string|null $target, ?string $method, array $advice = [], ?string $identity = null): Invocation
    {
        return new Invocation(
            target: $target,
            method: $method,
            identity: $identity ?? (is_object($target) ? $target::class . '::' . ($method ?? '__invoke') : 'test'),
            args: [],
            advice: $advice,
        );
    }

    /** @return int The framework's nesting counter. */
    private function level(): int
    {
        return (fn() => $this->transactionLevel)->call($this->db);
    }

    /** @return bool Whether PDO itself believes a transaction is open. */
    private function pdoInTransaction(): bool
    {
        return (fn() => $this->pdo->inTransaction())->call($this->db);
    }

    public function testCommitsOnSuccess(): void
    {
        $this->assertSame('done', $this->service()->writesThenSucceeds());

        $this->assertSame(['ok'], $this->values());
        $this->assertSame(0, $this->level());
        $this->assertFalse($this->pdoInTransaction());
    }

    public function testRollsBackAndRethrowsOnThrowable(): void
    {
        try {
            $this->service()->writesThenThrows();
            $this->fail('expected the original exception to propagate');
        } catch (Throwable $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame([], $this->values());
        $this->assertSame(0, $this->level());
        $this->assertFalse($this->pdoInTransaction());
    }

    /**
     * Nested advised calls on the same connection must use a savepoint: the
     * inner failure is undone, the outer work still commits.
     */
    public function testNestedCallUsesSavepoint(): void
    {
        $this->service()->outer();

        $this->assertSame(['outer', 'outer2'], $this->values());
        $this->assertSame(0, $this->level());
        $this->assertFalse($this->pdoInTransaction());
    }

    /**
     * A COMMIT that fails (here: a deferred foreign key violation) must surface
     * its own error. The previous shape let the following rollback() raise
     * "There is no active transaction", which replaced the real cause, and left
     * the nesting counter out of step with the driver.
     */
    public function testFailedCommitSurfacesItsOwnErrorAndReconcilesState(): void
    {
        $db = new Database('sqlite::memory:');
        $db->query('PRAGMA foreign_keys = ON');
        $db->query('CREATE TABLE parent (id INTEGER PRIMARY KEY)');
        $db->query(
            'CREATE TABLE child (id INTEGER PRIMARY KEY, parent_id INTEGER REFERENCES parent(id)'
            . ' DEFERRABLE INITIALLY DEFERRED)'
        );

        $manager = AppContext::instance()->dbManager();
        $manager->set('write', $db);

        $interceptor = new TransactionalInterceptor($manager);

        $caught = null;
        try {
            $interceptor->intercept(
                $this->invocation(new \stdClass(), 'writesThenSucceeds', [Transactional::class => new Transactional()], 'TransactionalService::writesThenSucceeds'),
                static function () use ($db) {
                    $db->query('INSERT INTO child (parent_id) VALUES (999)');
                    return 'done';
                },
            );
        } catch (Throwable $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(\PDOException::class, $caught, 'the commit error must not be masked');
        $this->assertStringNotContainsString('no active transaction', $caught->getMessage());

        $level = (fn() => $this->transactionLevel)->call($db);
        $open  = (fn() => $this->pdo->inTransaction())->call($db);

        $this->assertSame(0, $level, 'counter must be reconciled with the driver');
        $this->assertFalse($open, 'no transaction may be left dangling');
    }

    /**
     * `begin(false)` used to consume a nesting level it never opened, so the
     * caller's later commit() tried to close a transaction that did not exist.
     */
    public function testNonNestingBeginDoesNotConsumeALevel(): void
    {
        $this->db->begin();
        $this->assertFalse($this->db->begin(false));
        $this->assertSame(1, $this->level());

        $this->db->commit();
        $this->assertSame(0, $this->level());
        $this->assertFalse($this->pdoInTransaction());
    }

    /**
     * A role that is not configured must fail loudly.
     *
     * `DatabaseManager::getOrDefault()` silently returns the default connection
     * for an unknown role, so a typo like `#[Transactional('analytcs')]` would
     * write to the wrong database with no warning at all.
     */
    public function testUnknownConnectionRoleIsRejected(): void
    {
        $interceptor = new TransactionalInterceptor(AppContext::instance()->dbManager());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/analytcs/');

        $interceptor->intercept(
            $this->invocation(
                new UnknownRoleService($this->db),
                'go',
                [Transactional::class => new Transactional('analytcs')],
                'UnknownRoleService::go',
            ),
            static fn() => 'ok',
        );
    }

    /**
     * A typo'd role error must also name the roles that *are* configured, so the
     * fix is obvious from the message alone.
     */
    public function testUnknownRoleErrorListsConfiguredRoles(): void
    {
        AppContext::instance()->dbManager()->set('analytics', new Database('sqlite::memory:'));

        $interceptor = new TransactionalInterceptor(AppContext::instance()->dbManager());

        try {
            $interceptor->intercept(
                $this->invocation(
                    new UnknownRoleService($this->db),
                    'go',
                    [Transactional::class => new Transactional('analytcs')],
                    'UnknownRoleService::go',
                ),
                static fn() => 'ok',
            );
            $this->fail('expected the unknown role to be rejected');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('write', $e->getMessage());
            $this->assertStringContainsString('analytics', $e->getMessage());
        }
    }

    /**
     * The cross-connection leak is the one thing the interceptor cannot see
     * statically: the body may write through a role the advice never opened, so
     * those writes are never committed. Detectable at runtime, behind a flag.
     */
    public function testLeakedConnectionIsDetectedWhenEnabled(): void
    {
        $manager = AppContext::instance()->dbManager();
        $tenant  = new Database('sqlite::memory:');
        $manager->set('tenant_a', $tenant);

        $interceptor = new TransactionalInterceptor($manager, detectLeakedConnections: true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/tenant_a/');

        try {
            $interceptor->intercept(
                $this->invocation(
                    new TransactionalService($this->db),
                    'writesThenSucceeds',
                    [Transactional::class => new Transactional()],
                    'TransactionalService::writesThenSucceeds',
                ),
                static function () use ($tenant) {
                    $tenant->begin();

                    return 'ok';
                },
            );
        } finally {
            if ($tenant->inTransaction()) {
                $tenant->rollback();
            }
        }
    }

    public function testLeakedConnectionIsIgnoredWhenDisabled(): void
    {
        $manager = AppContext::instance()->dbManager();
        $tenant  = new Database('sqlite::memory:');
        $manager->set('tenant_a', $tenant);

        $interceptor = new TransactionalInterceptor($manager);

        $tenant->begin();

        try {
            $result = $interceptor->intercept(
                $this->invocation(
                    new TransactionalService($this->db),
                    'writesThenSucceeds',
                    [Transactional::class => new Transactional()],
                    'TransactionalService::writesThenSucceeds',
                ),
                static fn() => 'ok',
            );
            $this->assertSame('ok', $result);
        } finally {
            if ($tenant->inTransaction()) {
                $tenant->rollback();
            }
        }
    }

    /**
     * peeking must not instantiate a role whose factory has never run, otherwise
     * the leak check would open every configured connection.
     */
    public function testPeekDoesNotOpenUnusedConnections(): void
    {
        $manager = new DatabaseManager();
        $opened  = 0;
        $manager->set('write', fn() => new Database('sqlite::memory:'));
        $manager->set('lazy', function () use (&$opened) {
            $opened++;

            return new Database('sqlite::memory:');
        });

        $this->assertNull($manager->peek('lazy'));
        $this->assertSame(0, $opened, 'peek must not invoke the factory');

        $manager->get('lazy');
        $this->assertInstanceOf(Database::class, $manager->peek('lazy'));
    }
}
