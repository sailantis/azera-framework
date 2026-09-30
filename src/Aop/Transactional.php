<?php

namespace Azera\Aop;

/**
 * Marks a method as transactional.
 *
 * The {@see TransactionalInterceptor} wraps the method in a database
 * transaction: begins before execution, commits on success, rolls back
 * on any Throwable.
 *
 * Supports nested transactions via savepoints (see Database::begin(nesting: true)).
 *
 * Example:
 * ```php
 * #[Advised]
 * class BillingService
 * {
 *     #[Transactional]
 *     public function chargeSubscription(Account $a): void { ... }
 *
 *     #[Transactional('analytics')]
 *     public function logEvent(Event $e): void { ... }
 * }
 * ```
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
class Transactional extends Advice
{
    public function __construct(
        public readonly ?string $connection = null,
    ) {}
}