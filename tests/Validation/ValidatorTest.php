<?php

namespace Azera\Tests\Validation;

require_once __DIR__ . '/../../vendor/autoload.php';

use Azera\Validation\ValidationException;
use Azera\Validation\Validator;
use PHPUnit\Framework\TestCase;

class ValidatorTest extends TestCase
{
    // ---- Presence -----------------------------------------------------------

    public function testRequiredFieldMissing(): void
    {
        $v = new Validator([]);
        $v->field('name')->required()->string();

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('name', $v->errors());
        $this->assertSame('required', $v->errors()['name']);
    }

    public function testOptionalFieldMissing(): void
    {
        $v = new Validator([]);
        $v->field('bio')->optional()->string();

        $this->assertFalse($v->fails());
        $this->assertEmpty($v->errors());
        $this->assertArrayNotHasKey('bio', $v->validated());
    }

    public function testRequiredFieldPresent(): void
    {
        $v = new Validator(['name' => 'Alice']);
        $v->field('name')->required()->string();

        $this->assertFalse($v->fails());
        $this->assertSame('Alice', $v->validated()['name']);
    }

    // ---- Type coercion ------------------------------------------------------

    public function testIntCoercesStringDigit(): void
    {
        $v = new Validator(['age' => '25']);
        $v->field('age')->int();

        $this->assertFalse($v->fails());
        $this->assertSame(25, $v->validated()['age']);
    }

    public function testIntCoercesNegative(): void
    {
        $v = new Validator(['offset' => '-10']);
        $v->field('offset')->int();

        $this->assertFalse($v->fails());
        $this->assertSame(-10, $v->validated()['offset']);
    }

    public function testIntPassthroughNativeInt(): void
    {
        $v = new Validator(['n' => 42]);
        $v->field('n')->int();

        $this->assertFalse($v->fails());
        $this->assertSame(42, $v->validated()['n']);
    }

    public function testIntRejectsNonNumeric(): void
    {
        $v = new Validator(['age' => 'abc']);
        $v->field('age')->int();

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('age', $v->errors());
    }

    public function testFloatCoercesString(): void
    {
        $v = new Validator(['price' => '9.99']);
        $v->field('price')->float();

        $this->assertFalse($v->fails());
        $this->assertEqualsWithDelta(9.99, $v->validated()['price'], 0.0001);
    }

    public function testFloatRejectsNonNumeric(): void
    {
        $v = new Validator(['price' => 'free']);
        $v->field('price')->float();

        $this->assertTrue($v->fails());
    }

    public function testBoolCoercesStringTrue(): void
    {
        foreach (['true', '1', 'yes', 'on'] as $input) {
            $v = new Validator(['flag' => $input]);
            $v->field('flag')->bool();
            $this->assertFalse($v->fails(), "Expected true for input '{$input}'");
            $this->assertTrue($v->validated()['flag'], "Expected true for input '{$input}'");
        }
    }

    public function testBoolCoercesStringFalse(): void
    {
        foreach (['false', '0', 'no', 'off'] as $input) {
            $v = new Validator(['flag' => $input]);
            $v->field('flag')->bool();
            $this->assertFalse($v->fails(), "Expected false for input '{$input}'");
            $this->assertFalse($v->validated()['flag'], "Expected false for input '{$input}'");
        }
    }

    public function testBoolRejectsAmbiguousString(): void
    {
        $v = new Validator(['flag' => 'maybe']);
        $v->field('flag')->bool();

        $this->assertTrue($v->fails());
    }

    public function testStringCastsValue(): void
    {
        $v = new Validator(['count' => 3]);
        $v->field('count')->string();

        $this->assertFalse($v->fails());
        $this->assertSame('3', $v->validated()['count']);
    }

    // ---- Min / Max ----------------------------------------------------------

    public function testMinOnStringLength(): void
    {
        $v = new Validator(['name' => 'Al']);
        $v->field('name')->string()->min(3);

        $this->assertTrue($v->fails());

        $v2 = new Validator(['name' => 'Alice']);
        $v2->field('name')->string()->min(3);
        $this->assertFalse($v2->fails());
    }

    public function testMaxOnStringLength(): void
    {
        $v = new Validator(['name' => 'AliceLongName']);
        $v->field('name')->string()->max(5);

        $this->assertTrue($v->fails());
    }

    public function testMinOnIntValue(): void
    {
        $v = new Validator(['age' => 15]);
        $v->field('age')->int()->min(18);

        $this->assertTrue($v->fails());
    }

    public function testMaxOnIntValue(): void
    {
        $v = new Validator(['age' => 150]);
        $v->field('age')->int()->max(120);

        $this->assertTrue($v->fails());
    }

    public function testMinOnArrayCount(): void
    {
        $v = new Validator(['tags' => []]);
        $v->field('tags')->list(fn($f) => $f->string())->min(1);

        $this->assertTrue($v->fails());
    }

    // ---- Format rules -------------------------------------------------------

    public function testEmailAcceptsValid(): void
    {
        $v = new Validator(['email' => 'user@example.com']);
        $v->field('email')->email();

        $this->assertFalse($v->fails());
    }

    public function testEmailRejectsInvalid(): void
    {
        $v = new Validator(['email' => 'not-an-email']);
        $v->field('email')->email();

        $this->assertTrue($v->fails());
    }

    public function testUrlAcceptsValid(): void
    {
        $v = new Validator(['site' => 'https://example.com']);
        $v->field('site')->url();

        $this->assertFalse($v->fails());
    }

    public function testUrlRejectsInvalid(): void
    {
        $v = new Validator(['site' => 'not a url']);
        $v->field('site')->url();

        $this->assertTrue($v->fails());
    }

    public function testIpAcceptsValid(): void
    {
        $v = new Validator(['ip' => '192.168.1.1']);
        $v->field('ip')->ip();

        $this->assertFalse($v->fails());
    }

    public function testIpRejectsInvalid(): void
    {
        $v = new Validator(['ip' => '999.999.999.999']);
        $v->field('ip')->ip();

        $this->assertTrue($v->fails());
    }

    public function testPatternAcceptsMatch(): void
    {
        $v = new Validator(['zip' => '12345']);
        $v->field('zip')->pattern('/^\d{5}$/');

        $this->assertFalse($v->fails());
    }

    public function testPatternRejectsNonMatch(): void
    {
        $v = new Validator(['zip' => 'ABCDE']);
        $v->field('zip')->pattern('/^\d{5}$/');

        $this->assertTrue($v->fails());
    }

    public function testInAcceptsAllowedValue(): void
    {
        $v = new Validator(['role' => 'admin']);
        $v->field('role')->in(['admin', 'editor', 'viewer']);

        $this->assertFalse($v->fails());
    }

    public function testInRejectsDisallowedValue(): void
    {
        $v = new Validator(['role' => 'superuser']);
        $v->field('role')->in(['admin', 'editor', 'viewer']);

        $this->assertTrue($v->fails());
    }

    // ---- List structure -----------------------------------------------------

    public function testListValidatesEachElement(): void
    {
        $v = new Validator(['tags' => ['php', 'azera', 'web']]);
        $v->field('tags')->list(fn($f) => $f->string()->max(20));

        $this->assertFalse($v->fails());
        $this->assertSame(['php', 'azera', 'web'], $v->validated()['tags']);
    }

    public function testListCoercesElementTypes(): void
    {
        $v = new Validator(['ids' => ['1', '2', '3']]);
        $v->field('ids')->list(fn($f) => $f->int());

        $this->assertFalse($v->fails());
        $this->assertSame([1, 2, 3], $v->validated()['ids']);
    }

    public function testListProducesDotPathErrors(): void
    {
        $v = new Validator(['ids' => [1, 'bad', 3]]);
        $v->field('ids')->list(fn($f) => $f->int());

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('ids[1]', $v->errors());
    }

    public function testListRejectsNonArray(): void
    {
        $v = new Validator(['tags' => 'not-an-array']);
        $v->field('tags')->list(fn($f) => $f->string());

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('tags', $v->errors());
    }

    // ---- Model structure ----------------------------------------------------

    public function testModelValidatesNestedFields(): void
    {
        $v = new Validator(['address' => ['street' => 'Main St', 'zip' => '12345']]);
        $v->field('address')->model([
            'street' => fn($f) => $f->required()->string(),
            'zip' => fn($f) => $f->required()->pattern('/^\d{5}$/'),
        ]);

        $this->assertFalse($v->fails());
        $this->assertSame(['street' => 'Main St', 'zip' => '12345'], $v->validated()['address']);
    }

    public function testModelProducesDotPathErrors(): void
    {
        $v = new Validator(['address' => ['street' => 'Main St', 'zip' => 'WRONG']]);
        $v->field('address')->model([
            'street' => fn($f) => $f->required()->string(),
            'zip' => fn($f) => $f->required()->pattern('/^\d{5}$/'),
        ]);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('address.zip', $v->errors());
        $this->assertArrayNotHasKey('address.street', $v->errors());
    }

    public function testModelRequiredSubFieldMissing(): void
    {
        $v = new Validator(['address' => ['street' => 'Main St']]);
        $v->field('address')->model([
            'street' => fn($f) => $f->required()->string(),
            'zip' => fn($f) => $f->required()->string(),
        ]);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('address.zip', $v->errors());
    }

    public function testModelOptionalSubFieldMissing(): void
    {
        $v = new Validator(['address' => ['street' => 'Main St']]);
        $v->field('address')->model([
            'street' => fn($f) => $f->required()->string(),
            'zip' => fn($f) => $f->optional()->pattern('/^\d{5}$/'),
        ]);

        $this->assertFalse($v->fails());
    }

    public function testModelRejectsNonArray(): void
    {
        $v = new Validator(['address' => 'flat string']);
        $v->field('address')->model(['street' => fn($f) => $f->required()->string()]);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('address', $v->errors());
    }

    // ---- Defaults -----------------------------------------------------------

    public function testDefaultUsedWhenFieldMissing(): void
    {
        $v = new Validator([]);
        $v->field('role')->optional()->default('viewer');

        $this->assertFalse($v->fails());
        $this->assertSame('viewer', $v->validated()['role']);
    }

    public function testDefaultNullIsDistinctFromNoDefault(): void
    {
        // explicit null default → key present with null value
        $v1 = new Validator([]);
        $v1->field('bio')->optional()->default(null);
        $this->assertArrayHasKey('bio', $v1->validated());
        $this->assertNull($v1->validated()['bio']);

        // no default → key absent
        $v2 = new Validator([]);
        $v2->field('bio')->optional();
        $this->assertArrayNotHasKey('bio', $v2->validated());
    }

    public function testDefaultIntUsedWhenFieldMissing(): void
    {
        $v = new Validator([]);
        $v->field('count')->optional()->int()->default(0);

        $this->assertFalse($v->fails());
        $this->assertSame(0, $v->validated()['count']);
    }

    public function testDefaultNotUsedWhenFieldPresent(): void
    {
        $v = new Validator(['role' => 'admin']);
        $v->field('role')->optional()->default('viewer');

        $this->assertFalse($v->fails());
        $this->assertSame('admin', $v->validated()['role']);
    }

    public function testDefaultImpliesOptional(): void
    {
        // default() makes the field optional automatically — no need for ->optional()
        $v = new Validator([]);
        $v->field('role')->default('viewer');

        $this->assertFalse($v->fails());
        $this->assertSame('viewer', $v->validated()['role']);
    }

    public function testModelSubFieldDefault(): void
    {
        $v = new Validator(['address' => ['street' => 'Main St']]);
        $v->field('address')->model([
            'street' => fn($f) => $f->required()->string(),
            'country' => fn($f) => $f->optional()->default('DE'),
        ]);

        $this->assertFalse($v->fails());
        $this->assertSame('DE', $v->validated()['address']['country']);
    }

    // ---- Validated / validate() / ValidationException -----------------------

    public function testValidatedExcludesFailedFields(): void
    {
        $v = new Validator(['email' => 'bad', 'name' => 'Alice']);
        $v->field('email')->email();
        $v->field('name')->string();

        $this->assertTrue($v->fails());
        $validated = $v->validated();
        $this->assertArrayNotHasKey('email', $validated);
        $this->assertArrayHasKey('name', $validated);
    }

    public function testValidateThrowsOnFailure(): void
    {
        $v = new Validator(['email' => 'bad']);
        $v->field('email')->email();

        $this->expectException(ValidationException::class);
        $v->validate();
    }

    public function testValidateThrowsWithErrors(): void
    {
        $v = new Validator(['age' => 'not-a-number']);
        $v->field('age')->int();

        try {
            $v->validate();
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('age', $e->errors());
        }
    }

    public function testValidateReturnsDataOnSuccess(): void
    {
        $v = new Validator(['email' => 'user@example.com', 'age' => '30']);
        $v->field('email')->email();
        $v->field('age')->int();

        $data = $v->validate();

        $this->assertSame('user@example.com', $data['email']);
        $this->assertSame(30, $data['age']);
    }

    // ---- Multi-field scenarios ----------------------------------------------

    public function testMultipleFieldsAllPass(): void
    {
        $v = new Validator([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'age' => '28',
            'role' => 'admin',
        ]);
        $v->field('name')->string()->min(2)->max(100);
        $v->field('email')->email()->max(255);
        $v->field('age')->int()->min(18)->max(120);
        $v->field('role')->in(['admin', 'editor', 'viewer']);

        $this->assertFalse($v->fails());
        $validated = $v->validated();
        $this->assertSame('Alice', $validated['name']);
        $this->assertSame(28, $validated['age']);
    }

    public function testMultipleFieldsCollectAllErrors(): void
    {
        $v = new Validator(['name' => '', 'email' => 'bad', 'age' => 'old']);
        $v->field('name')->string()->min(1);
        $v->field('email')->email();
        $v->field('age')->int();

        $this->assertTrue($v->fails());
        $errors = $v->errors();
        $this->assertArrayHasKey('name', $errors);
        $this->assertArrayHasKey('email', $errors);
        $this->assertArrayHasKey('age', $errors);
    }

    // ---- Domain format rule -------------------------------------------------

    public function testDomainAcceptsValid(): void
    {
        $v = new Validator(['host' => 'example.com']);
        $v->field('host')->domain();

        $this->assertFalse($v->fails());
    }

    public function testDomainAcceptsSubdomain(): void
    {
        $v = new Validator(['host' => 'sub.example.co.uk']);
        $v->field('host')->domain();

        $this->assertFalse($v->fails());
    }

    public function testDomainRejectsSpaces(): void
    {
        $v = new Validator(['host' => 'not a domain']);
        $v->field('host')->domain();

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('host', $v->errors());
    }

    public function testDomainRejectsScheme(): void
    {
        $v = new Validator(['host' => 'https://example.com']);
        $v->field('host')->domain();

        $this->assertTrue($v->fails());
    }

    // ---- Custom format rule -------------------------------------------------

    public function testCustomPassesOnNull(): void
    {
        $v = new Validator(['code' => 'ABC']);
        $v->field('code')->custom(fn($val) => null);

        $this->assertFalse($v->fails());
    }

    public function testCustomFailsWithReturnedMessage(): void
    {
        $v = new Validator(['code' => 'ABC']);
        $v->field('code')->custom(fn($val) => 'must start with Z');

        $this->assertTrue($v->fails());
        $this->assertSame('must start with Z', $v->errors()['code']);
    }

    public function testCustomChainedShortCircuits(): void
    {
        $called = 0;
        $v = new Validator(['val' => 'x']);
        $v->field('val')
            ->custom(function ($val) use (&$called) {
                $called++;
                return 'first error';
            })
            ->custom(function ($val) use (&$called) {
                $called++;
                return null;
            });

        $this->assertTrue($v->fails());
        $this->assertSame(1, $called);
    }

    public function testCustomArrayReturnCode(): void
    {
        $v = new Validator(['val' => 'x']);
        $v->field('val')->custom(fn($v) => ['code' => 'my_custom_code']);

        $this->assertTrue($v->fails());
        // Without translator, code is used as template fallback
        $this->assertSame('my_custom_code', $v->errors()['val']);
    }

    public function testCustomArrayReturnWithTemplate(): void
    {
        $v = new Validator(['val' => 'x']);
        $v->field('val')->custom(fn($v) => [
            'code' => 'too_short',
            'params' => ['min' => 5],
            'template' => 'must be at least {min} characters',
        ]);

        $this->assertTrue($v->fails());
        $this->assertSame('must be at least 5 characters', $v->errors()['val']);
    }

    public function testCustomArrayReturnTranslatorReceivesCodeAndParams(): void
    {
        $received = [];
        $v = new Validator(['val' => 'x']);
        $v->field('val')->custom(fn($v) => [
            'code' => 'below_minimum',
            'params' => ['min' => 10],
        ]);
        $v->setTranslator(function ($field, $code, $params, $template) use (&$received) {
            $received = compact('field', 'code', 'params', 'template');
            return $template;
        });

        $v->errors();

        $this->assertSame('val', $received['field']);
        $this->assertSame('below_minimum', $received['code']);
        $this->assertSame(10, $received['params']['min']);
    }

    // ---- Translator ---------------------------------------------------------

    public function testTranslatorReceivesCodeParamsAndTemplate(): void
    {
        $received = [];
        $v = new Validator(['age' => 15]);
        $v->field('age')->int()->min(18);
        $v->setTranslator(function (string $field, string $code, array $params, string $template) use (&$received) {
            $received = compact('field', 'code', 'params', 'template');
            return $template;
        });

        $v->errors();

        $this->assertSame('age', $received['field']);
        $this->assertSame('min.number', $received['code']);
        $this->assertSame(18, $received['params']['min']);
        $this->assertSame('must be at least {min}', $received['template']);
    }

    public function testTranslatorReturnIsUsedAsMessage(): void
    {
        $v = new Validator(['email' => 'bad']);
        $v->field('email')->email();
        $v->setTranslator(fn($field, $code, $params, $template) => 'ungültige E-Mail-Adresse');

        $this->assertSame('ungültige E-Mail-Adresse', $v->errors()['email']);
    }

    public function testTranslatorReturnedTemplateGetsPlaceholderReplaced(): void
    {
        $v = new Validator(['name' => 'Al']);
        $v->field('name')->string()->min(3);
        $v->setTranslator(fn($field, $code, $params, $template) => 'mindestens {min} Zeichen erforderlich');

        $this->assertSame('mindestens 3 Zeichen erforderlich', $v->errors()['name']);
    }

    public function testNoTranslatorRendersEnglish(): void
    {
        $v = new Validator(['name' => 'Al']);
        $v->field('name')->string()->min(3);

        $this->assertSame('must have at least 3 characters', $v->errors()['name']);
    }

    public function testTranslatorReceivesRawAllowedArray(): void
    {
        $receivedAllowed = null;
        $v = new Validator(['role' => 'superuser']);
        $v->field('role')->in(['admin', 'editor']);
        $v->setTranslator(function ($field, $code, $params, $template) use (&$receivedAllowed) {
            $receivedAllowed = $params['allowed'] ?? null;
            return $template;
        });

        $v->errors();

        $this->assertSame(['admin', 'editor'], $receivedAllowed);
    }
}
