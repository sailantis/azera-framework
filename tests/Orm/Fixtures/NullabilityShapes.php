<?php

namespace Azera\Tests\Orm\Fixtures;

use Azera\Orm\Model;
use Azera\Orm\Attribute\Column;

/**
 * Nullability declarations in every legal spelling, plus the `cast: false`
 * typed-numeric shape that used to schedule a phantom UPDATE. Compiled by
 * MetadataTest (flag resolution) and hydrated by CastTest (runtime
 * behavior) — no DB is ever touched.
 *
 * NOTE the deliberate absence of `#[Column(nullable: true)]` on a
 * non-nullable typed property: that shape is REFUSED at compile time (a
 * nullable column needs a null-capable property), so putting it here
 * would make this whole fixture class uncompilable. Its rejection is
 * pinned by MetadataTest on an eval'd class instead.
 */
class NullabilityShapes extends Model
{
    public int $id;

    /** `?int` — nullable from the PHP type alone. */
    public ?int $from_php_type;

    /** Untyped — nullability has no PHP answer, so it defaults to nullable. */
    public $untyped;

    /** Untyped + explicit downgrade. */
    #[Column(nullable: false)]
    public $untyped_not_nullable;

    /** Non-nullable both ways (redundant attribute confirmation). */
    #[Column(nullable: false)]
    public int $plain;

    /** Typed numeric with the cast SUPPRESSED — the phantom-UPDATE shape. */
    #[Column(cast: false)]
    public int $raw_int;

    /** Untyped numeric with the cast suppressed — no PHP coercion involved. */
    #[Column(type: 'int', cast: false)]
    public $raw_untyped_int;
}
