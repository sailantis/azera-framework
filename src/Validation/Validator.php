<?php

namespace Azera\Validation;

/**
 * Validates and coerces an associative input array against a set of field rules.
 *
 * Usage:
 *
 *   $v = new Validator($request->post());
 *
 *   $v->field('email')->required()->email()->max(255);
 *   $v->field('age')->required()->int()->min(18)->max(120);
 *   $v->field('name')->optional()->string()->min(2)->max(100);
 *   $v->field('tags')->optional()->list(fn($f) => $f->string()->max(50));
 *   $v->field('address')->optional()->model([
 *       'street' => fn($f) => $f->required()->string(),
 *       'zip'    => fn($f) => $f->required()->pattern('/^\d{5}$/'),
 *   ]);
 *
 *   if ($v->fails()) {
 *       return Response::json(['errors' => $v->errors()], 422);
 *   }
 *   $data = $v->validated();
 *
 * Or in a single call (throws ValidationException on failure):
 *
 *   $data = $v->validate();
 */
class Validator
{
    /** @var array<string, FieldValidator> */
    private array $fields = [];

    /** @var array<string, array{code: string, params: array<string, mixed>, template: string}> */
    private array $errors = [];

    /** @var array<string, mixed> */
    private array $validatedData = [];

    private bool $ran = false;

    /** @var callable|null */
    private $translator = null;

    /**
     * @param array<string, mixed> $data Raw input array (e.g. from Request::post()).
     */
    public function __construct(private array $data) {}

    /**
     * Set a translator callback invoked for each error when rendering messages.
     *
     * The callback receives:
     *   - $field:          the dot-path field name (e.g. "address.zip", "tags[0]")
     *   - $code:           the error code (see table below)
     *   - $params:         raw parameters with native PHP types
     *   - $template:       the English template string with {placeholder} markers intact
     *
     * The callback may return either a translated template (placeholders will be
     * replaced by the framework) or a fully pre-rendered string (str_replace is
     * a no-op when no markers remain). Return $template as-is to fall back to
     * the English default.
     *
     * Error codes and their $params keys / types:
     *   required, type.int, type.float, type.bool,
     *   email, url, ip, domain, pattern,
     *   not_array, not_object              => params is []
     *   min.string, min.number, min.array  => ['min' => int|float]
     *   max.string, max.number, max.array  => ['max' => int|float]
     *   in                                 => ['allowed' => array<mixed>]
     *   custom                             => [] (template is the callback's own message)
     *
     * @param callable(string $field, string $code, array<string,mixed> $params, string $template): string $fn
     */
    public function setTranslator(callable $fn): static
    {
        $this->translator = $fn;
        return $this;
    }

    /**
     * Register rules for a field and return the fluent FieldValidator.
     *
     * Fields default to required. Call ->optional() on the returned validator
     * to make the field optional.
     */
    public function field(string $name): FieldValidator
    {
        $fv = new FieldValidator();
        $this->fields[$name] = $fv;
        $this->ran           = false; // invalidate any previous run
        return $fv;
    }

    /**
     * Run all rules. Returns true when at least one rule failed.
     */
    public function fails(): bool
    {
        $this->run();
        return !empty($this->errors);
    }

    /**
     * Dot-path keyed error messages from the last run.
     * Empty when validation has not been run yet or all rules passed.
     *
     * @return array<string, string>
     */
    public function errors(): array
    {
        $this->run();

        $out = [];
        foreach ($this->errors as $path => $error) {
            // Let the translator rewrite the template (or return a pre-rendered string).
            $template = $this->translator !== null
                ? ($this->translator)($path, $error['code'], $error['params'], $error['template'])
                : $error['template'];

            // Replace any {placeholder} markers left in the template.
            foreach ($error['params'] as $k => $v) {
                $placeholder = '{' . $k . '}';
                $replacement = \is_array($v)
                    ? \implode(', ', $v)
                    : (string) $v;
                $template = \str_replace($placeholder, $replacement, $template);
            }
            $out[$path] = $template;
        }

        return $out;
    }

    /**
     * Returns only the fields that passed validation, with values coerced to
     * their declared types. Fields that failed are excluded.
     *
     * @return array<string, mixed>
     */
    public function validated(): array
    {
        $this->run();
        return $this->validatedData;
    }

    /**
     * Run validation and return the validated data, or throw on failure.
     *
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function validate(): array
    {
        if ($this->fails()) {
            throw new ValidationException($this->errors());
        }
        return $this->validatedData;
    }

    // ---- Private ------------------------------------------------------------

    private function run(): void
    {
        if ($this->ran) {
            return;
        }

        $this->ran           = true;
        $this->errors        = [];
        $this->validatedData = [];

        foreach ($this->fields as $name => $fv) {
            $exists = \array_key_exists($name, $this->data);

            if (!$exists) {
                if ($fv->isRequired()) {
                    $this->errors[$name] = ['code' => 'required', 'params' => [], 'template' => 'required'];
                } elseif ($fv->hasDefault()) {
                    $this->validatedData[$name] = $fv->getDefault();
                }
                continue;
            }

            $errorsBefore = \count($this->errors);
            $coerced      = $fv->validate($this->data[$name], $name, $this->errors);

            if (\count($this->errors) === $errorsBefore) {
                $this->validatedData[$name] = $coerced;
            }
        }
    }
}