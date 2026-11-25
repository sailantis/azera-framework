<?php

namespace Azera\Tests\Aop;

require_once __DIR__ . '/../../vendor/autoload.php';

use Azera\Aop\Advised;
use Azera\Aop\Pipeline;
use Azera\Aop\Retry;
use Azera\Aop\RetryInterceptor;
use Azera\Aop\Transactional;
use Azera\Aop\TransactionalInterceptor;
use Azera\AppContext;
use Azera\Db\Database;
use Azera\Db\DatabaseManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * The transparent (proxy) path and the explicit (pipeline) path must behave
 * identically. They are two mechanisms for one idea, and the generator is the
 * only thing that should differ — if they drift, the explicit path is no longer
 * a reliable description of what the proxy does.
 */
#[Advised]
class EquivalenceTransacted
{
    public function __construct(public Database $db) {}

    #[Transactional]
    public function write(string $value): string
    {
        $this->db->query('INSERT INTO t (v) VALUES (?)', [$value]);

        return 'done';
    }

    #[Transactional]
    public function writeThenFail(string $value): void
    {
        $this->db->query('INSERT INTO t (v) VALUES (?)', [$value]);

        throw new RuntimeException('boom');
    }
}

#[Advised]
class ByReferenceService
{
    /**
     * A `&$array` parameter. The generator used to collect arguments by value, so
     * mutations made by the method body were silently discarded.
     */
    #[Retry(times: 1, backoff: 0)]
    public function fill(array &$target): void
    {
        $target[] = 'added';
    }

    #[Retry(times: 1, backoff: 0)]
    public function fillAfter(int $n, array &$target): void
    {
        $target[] = "n={$n}";
    }
}

class AopEquivalenceTest extends TestCase
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
    }

    private function values(): array
    {
        return array_map(
            static fn(object $row) => $row->v,
            $this->db->query('SELECT v FROM t ORDER BY rowid')->fetchAll(),
        );
    }

    public function testTransparentCommitMatchesExplicit(): void
    {
        $ctx = AppContext::instance();
        $ctx->registerInterceptor(Transactional::class, fn() => new TransactionalInterceptor($ctx->dbManager()));

        // Transparent
        $svc = $ctx->get(EquivalenceTransacted::class);
        $this->assertSame('done', $svc->write('transparent'));

        $transparentRows = $this->values();
        $this->assertSame(['transparent'], $transparentRows);

        // Explicit, same work
        $explicit = $ctx->pipeline([new TransactionalInterceptor($ctx->dbManager())])
            ->call(
                fn(Database $db) => $db->query('INSERT INTO t (v) VALUES (?)', ['explicit']),
                [$this->db],
            );

        $this->assertSame(['transparent', 'explicit'], $this->values());
    }

    public function testTransparentRollbackMatchesExplicit(): void
    {
        $ctx = AppContext::instance();
        $ctx->registerInterceptor(Transactional::class, fn() => new TransactionalInterceptor($ctx->dbManager()));

        $svc = $ctx->get(EquivalenceTransacted::class);

        try {
            $svc->writeThenFail('transparent');
            $this->fail('expected the exception to propagate');
        } catch (Throwable $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame([], $this->values(), 'transparent path must roll back');

        try {
            $ctx->pipeline([new TransactionalInterceptor($ctx->dbManager())])
                ->call(static function (Database $db) {
                    $db->query('INSERT INTO t (v) VALUES (?)', ['explicit']);

                    throw new RuntimeException('boom');
                }, [$this->db]);
            $this->fail('expected the exception to propagate');
        } catch (Throwable $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame([], $this->values(), 'explicit path must roll back');
    }

    /**
     * Regression: the generator collected arguments with `[$x, $arr]`, so a
     * by-reference parameter arrived at the parent method as a copy and every
     * mutation was lost — silently, with no error.
     */
    public function testByReferenceParametersReachTheMethod(): void
    {
        $ctx = AppContext::instance();
        $ctx->registerInterceptor(Retry::class, new RetryInterceptor());

        $svc = $ctx->get(ByReferenceService::class);
        $this->assertStringStartsWith('AzeraAopProxy_', $svc::class, 'the class must actually be proxied');

        $target = ['original'];
        $svc->fill($target);

        $this->assertSame(['original', 'added'], $target);

        $mixed = ['original'];
        $svc->fillAfter(5, $mixed);

        $this->assertSame(['original', 'n=5'], $mixed);
    }
}
