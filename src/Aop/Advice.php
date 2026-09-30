<?php

namespace Azera\Aop;

/**
 * Base attribute class for all advice types.
 *
 * Concrete advice attributes extend this class and are placed on methods
 * of a class marked with {@see Advised}. The {@see ProxyFactory} detects
 * them via ReflectionMethod::getAttributes() and builds an interceptor
 * chain for each advised method.
 *
 * Example:
 * ```php
 * #[\Attribute(\Attribute::TARGET_METHOD)]
 * class Transactional extends Advice
 * {
 *     public function __construct(public readonly ?string $connection = null) {}
 * }
 * ```
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
abstract class Advice
{
}