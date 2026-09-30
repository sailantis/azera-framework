<?php

namespace Azera\Aop;

/**
 * Marks a method for automatic logging.
 *
 * The {@see LogInterceptor} logs method entry, exit, duration, and
 * any exceptions. Useful for debugging and audit trails.
 *
 * Example:
 * ```php
 * #[Advised]
 * class PaymentService
 * {
 *     #[Log(level: 'info')]
 *     public function processPayment(Payment $p): Result { ... }
 * }
 * ```
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
class Log extends Advice
{
    public function __construct(
        public readonly string $level = 'info',
        public readonly bool $logArgs = false,
    ) {}
}