<?php

namespace Azera\Aop;

use Psr\SimpleCache\CacheInterface;

/**
 * Caches the return value of methods marked with {@see Cache}.
 *
 * Key resolution:
 * 1. If the advice carries a `key` template, `{argName}` placeholders are filled from the
 *    invocation's named arguments (transparent mode only — explicit calls are positional).
 * 2. Otherwise a key is derived from a class/identity name, the method name and a hash of
 *    the arguments.
 *
 * Explicit (pipeline) calls have no advice and no argument names; they always use the
 * derived key and the `defaultTtl` constructor argument.
 */
class CacheInterceptor implements InterceptorInterface
{
    public function __construct(
        private CacheInterface $cache,
        private ?int $defaultTtl = null,
    ) {}

    public function intercept(Invocation $invocation, callable $next): mixed
    {
        $advice = $invocation->advice(Cache::class);
        $ttl    = $advice?->ttl ?? $this->defaultTtl;

        $key = $advice?->key !== null
            ? $this->interpolateKey($advice->key, $invocation)
            : self::keyFor($invocation->identity, $invocation->method, $invocation->args);

        $cached = $this->cache->get($key);
        if ($cached !== null) {
            return $cached;
        }

        $result = $next($invocation);

        $this->cache->set($key, $result, $ttl);

        return $result;
    }

    /**
     * Build the default cache key for an identity/method/args triple.
     *
     * @param string      $className  Identity of the unit (class, `file:line`, or caller-supplied label).
     * @param string|null $methodName Method name, or null for explicit calls.
     * @param array       $args       Arguments.
     */
    public static function keyFor(string $className, ?string $methodName, array $args): string
    {
        return hash('xxh128', serialize([$className, $methodName, $args]));
    }

    private function interpolateKey(string $template, Invocation $invocation): string
    {
        // Only the placeholders the template actually names are resolved. An
        // argument the template ignores must not be tokenized: it may be an object
        // that cannot be serialized, and failing on it would reject a perfectly
        // resolvable key.
        $referenced = [];

        if (preg_match_all('/\{[A-Za-z_]\w*\}/', $template, $matches) > 0) {
            $referenced = array_fill_keys($matches[0], true);
        }

        $replacements = [];

        if ($invocation->parameterNames === []) {
            // Explicit calls: the args array's string keys are the only names we have.
            // Read the value by that key — using array_keys() to index positionally
            // would miss (args are keyed by name, not by 0,1,2) and silently produce
            // an empty substitution, collapsing every argument onto one cache entry.
            foreach ($invocation->args as $name => $value) {
                if (!is_string($name)) {
                    continue;
                }
                $placeholder = '{' . $name . '}';
                if (isset($referenced[$placeholder])) {
                    $replacements[$placeholder] = self::token($value, $name, $invocation);
                }
            }
        } else {
            foreach ($invocation->parameterNames as $i => $name) {
                $placeholder = '{' . $name . '}';
                if (isset($referenced[$placeholder])) {
                    $replacements[$placeholder] = self::token($invocation->args[$i] ?? null, $name, $invocation);
                }
            }
        }

        // Substitute by matching placeholders in the TEMPLATE, not by scanning the
        // result. Scanning the fully substituted string meant an argument VALUE that
        // merely looked like a placeholder (`'user_{admin}'`) raised a LogicException
        // even though the template resolved cleanly. Here only placeholders that are
        // actually present in the template are considered, and only those need a
        // replacement.
        $resolved = preg_replace_callback(
            '/\{[A-Za-z_]\w*\}/',
            static function (array $match) use ($replacements, $invocation): string {
                if (!array_key_exists($match[0], $replacements)) {
                    // The caller asked for a named argument that was not supplied.
                    // Failing loudly beats returning another call's cached value.
                    throw new \LogicException(sprintf(
                        'Cache key template placeholder %s cannot be resolved on %s. '
                        . 'Proxied calls bake parameter names in; explicit Pipeline::call() resolves them only from string-keyed args.',
                        $match[0],
                        $invocation->identity,
                    ));
                }

                return $replacements[$match[0]];
            },
            $template,
        );

        if ($resolved === null) {
            throw new \LogicException(sprintf(
                'Unable to resolve the cache key template "%s" on %s: %s',
                $template,
                $invocation->identity,
                preg_last_error_msg(),
            ));
        }

        return $resolved;
    }

    /**
     * Render one placeholder value as a deterministic, key-safe token.
     *
     * The token must be stable across requests (a cache key is cross-process) and
     * must not collide for values the cache should keep apart. The old code mapped
     * every non-scalar to `''`, so two different arrays or objects — and `null`,
     * `false` and `''` — all collapsed onto the same cache entry, silently returning
     * one call's result for another's arguments.
     *
     * Scalars keep a readable form, because that is the point of a `key:` template
     * (`user_{id}_profile`). Values whose readable form is not key-safe, and all
     * non-scalars, are folded into a digest instead. When a value cannot be
     * serialized deterministically (a closure, a resource) this throws rather than
     * guessing a key that would collide with another call.
     *
     * @param string $placeholder The placeholder name, for error messages.
     */
    private static function token(mixed $value, string $placeholder, Invocation $invocation): string
    {
        // Every type carries its own prefix so values of DIFFERENT types cannot
        // render alike: without it the string `'null'`, the string `'42'` and the
        // integer `42` would each share a token with another type's value.
        if ($value === null) {
            return 'null';
        }
        if ($value === true) {
            return 'true';
        }
        if ($value === false) {
            return 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return 'f_' . var_export($value, true);
        }
        if (is_string($value)) {
            return self::stringToken($value);
        }
        if (is_array($value)) {
            return 'arr_' . substr(hash('xxh128', serialize($value)), 0, 24);
        }
        if (is_object($value)) {
            try {
                $encoded = serialize($value);
            } catch (\Throwable $e) {
                throw new \LogicException(sprintf(
                    'Cache key template placeholder {%s} on %s holds a %s that cannot be serialized into a stable key: %s',
                    $placeholder,
                    $invocation->identity,
                    $value::class,
                    $e->getMessage(),
                ), 0, $e);
            }

            return 'obj_' . substr(hash('xxh128', $encoded), 0, 24);
        }

        throw new \LogicException(sprintf(
            'Cache key template placeholder {%s} on %s holds a %s, which cannot be turned into a stable cache key.',
            $placeholder,
            $invocation->identity,
            get_debug_type($value),
        ));
    }

    /**
     * A key-safe rendering of a string.
     *
     * Short, already-key-safe values keep a readable form (`s_abc`) because the
     * readability is the point of a `key:` template. Everything else — including the
     * empty string and any value with characters a cache backend rejects — is folded
     * into a digest under a DIFFERENT prefix (`str_`), so a literal value can never
     * collide with a digested one.
     */
    private static function stringToken(string $value): string
    {
        if (preg_match('/^[A-Za-z0-9._-]{1,44}$/', $value) === 1) {
            return 's_' . $value;
        }

        return 'str_' . substr(hash('xxh128', $value), 0, 24);
    }
}
