<?php

namespace Azera\Aop;

use Azera\Db\Database;
use Azera\Db\DatabaseManager;
use ReflectionMethod;
use Throwable;

/**
 * Intercepts methods marked with {@see Transactional} and wraps them
 * in a database transaction.
 *
 * - Begins a transaction (or savepoint if nested) before the method
 * - Commits on success
 * - Rolls back on any Throwable, then re-throws
 *
 * The connection role can be specified via the attribute argument
 * (defaults to the write connection).
 */
class TransactionalInterceptor implements InterceptorInterface
{
    public function __construct(
        private DatabaseManager $dbManager,
    )
    {
    }

    public function intercept(object $target, ReflectionMethod $method, array $args, callable $next): mixed
    {
        // Resolve the advice attribute to get the connection role
        $advice = $this->getAdvice($method);
        $role   = $advice?->connection;

        $db = $this->resolveDatabase($role);

        // begin() is deliberately outside the try: if the transaction could not
        // be started there is nothing to roll back, and the original error
        // should reach the caller unwrapped.
        $db->begin(true);

        try {
            $result = $next($args);
        } catch (Throwable $e) {
            $this->rollbackKeeping($db, $e);
            throw $e;
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
     * Roll back the transaction opened by {@see intercept()} without letting a
     * rollback failure hide the error that triggered it.
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

    private function getAdvice(ReflectionMethod $method): ?Transactional
    {
        $attrs = $method->getAttributes(Transactional::class);
        if ($attrs === []) {
            return null;
        }
        return $attrs[0]->newInstance();
    }

    private function resolveDatabase(?string $role): Database
    {
        if ($role !== null) {
            return $this->dbManager->get($role);
        }

        return $this->dbManager->getOrDefault('write');
    }
}
