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
 *
 * The `#[\Attribute]` flag belongs on the concrete subclasses, not here: PHP 8.5
 * rejects `#[\Attribute]` on an abstract class. That is also where it worked all
 * along -- an attribute is identified by the class written at the use site, so the
 * flag on the base class only ever described a class nobody instantiates.
 */
abstract class Advice
{
}