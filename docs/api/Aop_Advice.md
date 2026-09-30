# Class: Advice

**Full name:** [Azera\Aop\Advice](../../src/Aop/Advice.php)

Base attribute class for all advice types.

Concrete advice attributes extend this class and are placed on methods
of a class marked with [`Advised`](Aop_Advised.md). The [`ProxyFactory`](Aop_ProxyFactory.md) detects
them via ReflectionMethod::getAttributes() and builds an interceptor
chain for each advised method.

Example:
```php
#[\Attribute(\Attribute::TARGET_METHOD)]
class Transactional extends Advice
{
    public function __construct(public readonly ?string $connection = null) }
}
```



---

[Back to the Index ⤴](README.md)
