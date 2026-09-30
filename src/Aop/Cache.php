<?php

namespace Azera\Aop;

/**
 * Marks a method's return value as cacheable.
 *
 * The {@see CacheInterceptor} stores the method's return value in the
 * cache under a computed key. On subsequent calls, the cached value is
 * returned without executing the method.
 *
 * The key is derived from the method name and arguments by default.
 * A custom key template can be provided with `{argName}` placeholders.
 *
 * Example:
 * ```php
 * #[Advised]
 * class UserService
 * {
 *     #[Cache(ttl: 300)]
 *     public function getProfile(int $userId): Profile { ... }
 *
 *     #[Cache(ttl: 600, key: 'user_{userId}_profile')]
 *     public function loadProfile(int $userId): Profile { ... }
 * }
 * ```
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
class Cache extends Advice
{
    public function __construct(
        public readonly ?int $ttl = null,
        public readonly ?string $key = null,
    ) {}
}