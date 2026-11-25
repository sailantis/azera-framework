<?php

namespace Azera\Aop;

use Azera\Db\Database;
use Azera\Db\DatabaseManager;
use Throwable;

/**
 * Wraps methods marked with {@see Transactional} in a database transaction.
 *
 * - Begins a transaction (or savepoint when nested) before the method
 * - Commits on success
 * - Rolls back on any Throwable, then re-throws
 *
 * Resolution order for the connection role: the attribute's `connection` argument wins and
 * must name a configured role; otherwise the `defaultRole` constructor argument is used,
 * falling back to the manager's default connection when that role is not configured
 * (`write` by default).
 */
class TransactionalInterceptor implements InterceptorInterface
{
    public function __construct(
        private DatabaseManager $dbManager,
        private string $defaultRole = 'write',
        /**
         * When true, verify after the method body that no *other* connection was
         * left inside a transaction.
         *
         * A model can redirect its writes (`Model::setDefaultWriteRole()`,
         * `#[Connection]`) to a connection this interceptor never opened. The
         * interceptor cannot see the method body statically, so the only way to
         * notice is at runtime. Off by default because it costs one
         * `inTransaction()` probe per connection.
         */
        private bool $detectLeakedConnections = false,
    ) {
    }

    public function intercept(Invocation $invocation, callable $next): mixed
    {
        $advice = $invocation->advice(Transactional::class);

        return $this->run($this->resolveDatabase($advice?->connection), $invocation, $next);
    }

    /**
     * @param callable(Invocation): mixed $next
     */
    private function run(Database $db, Invocation $invocation, callable $next): mixed
    {
        // Snapshot which connections were already inside a transaction BEFORE the
        // advised method runs. Without it, a connection that an outer transaction
        // opened (another role, a parent unit of work) still reports
        // inTransaction() afterwards and would be reported as a leak even though
        // this interceptor never opened it.
        $baseline = $this->detectLeakedConnections ? $this->transactionBaseline($db) : [];

        // begin() is deliberately outside the try: if the transaction could not
        // be started there is nothing to roll back, and the original error
        // should reach the caller unwrapped.
        $db->begin(true);

        try {
            $result = $next($invocation);
        } catch (Throwable $e) {
            $this->rollbackKeeping($db, $e);
            throw $e;
        }

        // The leak check MUST run before COMMIT. Running it afterwards meant a
        // detected leak threw with the advised method's work already committed,
        // so an outer RetryInterceptor could retry the body and duplicate those
        // writes. Rolling back here keeps the unit of work atomic with respect to
        // the failure, exactly as a throw from the body would be.
        if ($this->detectLeakedConnections) {
            try {
                $this->assertNoLeakedConnections($db, $baseline);
            } catch (Throwable $leakError) {
                $this->rollbackKeeping($db, $leakError);
                throw $leakError;
            }
        }

        // COMMIT is handled separately from the body so its own failure is
        // reported as-is. Rolling back in the same try meant rollback()'s
        // "There is no active transaction" could replace the real error —
        // e.g. a deferred FK violation surfacing at commit time.
        try {
            $db->commit();
        } catch (Throwable $commitError) {
            $this->rollbackKeeping($db, $commitError);
            throw $commitError;
        }

        return $result;
    }

    /**
     * Record which connections are inside a transaction before the advised method
     * runs, so {@see self::assertNoLeakedConnections()} can tell "already open"
     * apart from "this method opened it and never closed it".
     *
     * Only already-instantiated connections are inspected — see `peek()`.
     *
     * @return array<string, bool> Role name => was it in a transaction.
     */
    private function transactionBaseline(Database $opened): array
    {
        $baseline = [];

        foreach ($this->dbManager->roles() as $role) {
            $db = $this->dbManager->peek($role);

            if ($db === null || $db === $opened) {
                continue;
            }

            $baseline[$role] = $db->inTransaction();
        }

        return $baseline;
    }

    /**
     * Report connections still inside a transaction after the advised method
     * returned successfully.
     *
     * This catches the case the interceptor cannot see statically: the body
     * wrote through a different role (a model's `writeRole`, a tenant
     * connection), so the transaction that was opened here is not the one that
     * received the writes. Those writes are never committed — the classic quiet
     * data-loss shape.
     *
     * @param array<string, bool> $baseline Roles already in a transaction before the body ran.
     */
    private function assertNoLeakedConnections(Database $opened, array $baseline): void
    {
        $leaked = [];

        foreach ($this->dbManager->roles() as $role) {
            // Only touch connections already instantiated: calling get() here
            // would eagerly open connections the application never used.
            $db = $this->dbManager->peek($role);

            if ($db === null || $db === $opened) {
                continue;
            }

            // A connection that was already in a transaction before this unit of
            // work belongs to an outer scope; its state is not this advice's leak.
            if (($baseline[$role] ?? false) === true) {
                continue;
            }

            if ($db->inTransaction()) {
                $leaked[] = $role;
            }
        }

        if ($leaked !== []) {
            throw new \RuntimeException(sprintf(
                'Transactional method returned but connection(s) %s are still inside a transaction.'
                . ' The advised method probably writes through a different connection role than the one'
                . ' the advice opened. Name that role on the attribute (e.g. #[Transactional(connection: \'%s\')])'
                . ' or commit it explicitly.',
                implode(', ', array_map(static fn(string $r): string => "'{$r}'", $leaked)),
                $leaked[0],
            ));
        }
    }

    /**
     * Roll back the transaction opened here without letting a rollback failure hide
     * the error that triggered it.
     *
     * A failed COMMIT does not always leave the transaction open: MySQL and
     * PostgreSQL discard it on deadlock or deferred-constraint errors. In that
     * case the connection reports no active transaction and a rollback would
     * throw "There is no active transaction" — an error that says nothing about
     * the real cause, and which callers and logs then report instead.
     */
    private function rollbackKeeping(Database $db, Throwable $cause): void
    {
        if (!$db->inTransaction()) {
            return; // already discarded by the driver; $cause is the real story
        }

        try {
            $db->rollback();
        } catch (Throwable) {
            // Nothing better to do: the connection is already unusable for this
            // unit of work and $cause is the exception worth surfacing. The
            // driver's own state was reconciled by Database::rollback().
        }
    }

    /**
     * Resolve the connection for a role.
     *
     * An explicitly named role must exist: `getOrDefault()` silently falls back to
     * the default connection, so a typo like `#[Transactional('analytcs')]` would
     * quietly write to the wrong database. Fail loudly instead.
     *
     * An unnamed role is the interceptor's *default intent*, not a user-supplied
     * name, so it keeps the historical `getOrDefault()` behaviour: an application
     * with only a default connection configured must keep working.
     */
    private function resolveDatabase(?string $role): Database
    {
        if ($role === null) {
            return $this->dbManager->getOrDefault($this->defaultRole);
        }

        if (!$this->dbManager->has($role)) {
            $configured = $this->dbManager->roles();
            throw new \RuntimeException(sprintf(
                "Transactional advice names connection role '%s', which is not configured."
                . ' Configured roles: %s. Register it with DatabaseManager::set(),'
                . ' or correct the attribute.',
                $role,
                $configured === [] ? '(none)' : implode(', ', $configured),
            ));
        }

        return $this->dbManager->get($role);
    }
}
