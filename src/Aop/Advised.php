<?php

namespace Azera\Aop;

/**
 * Class-level marker attribute that opts a class into AOP proxy generation.
 *
 * The {@see ProxyFactory} uses this as a fast-path check: if a class
 * does NOT have this attribute, no proxy is created and the raw object
 * is returned with zero overhead. This avoids scanning every method
 * of every class for advice attributes.
 *
 * Place on any class that has methods with advice attributes:
 *
 * ```php
 * #[Advised]
 * class BillingService
 * {
 *     #[Transactional]
 *     public function chargeSubscription(Account $a): void { ... }
 *
 *     #[Cache(ttl: 300)]
 *     public function loadProfile(User $u): Profile { ... }
 * }
 * ```
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class Advised
{
}