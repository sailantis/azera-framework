<?php

namespace Azera\Tests\Orm\Fixtures;

use Azera\Orm\Attribute\Column;
use Azera\Orm\Model;

/**
 * Backed enums used as column types — the zero-config path.
 *
 * The enum CLASS is the metadata column type (inferred from the property
 * type), so the cast registry derives an EnumCast from the key alone and
 * no registration call is needed.
 */
enum ArticleStatus: string
{
    case Draft  = 'draft';
    case Public = 'published';
    case Closed = 'closed';
}

/** Int-backed enum — its driver returns numerics as STRINGS. */
enum ArticleLevel: int
{
    case Low    = 1;
    case Normal = 2;
    case High   = 3;
}

/**
 * Exercises enum-typed properties: inferred (both backing types),
 * nullable, explicitly typed, and cast-suppressed.
 */
class EnumArticle extends Model
{
    #[Column(pk: true)]
    public int $id;

    /** Inferred -> ArticleStatus::class. */
    public ArticleStatus $status;

    /** Inferred -> ArticleLevel::class; `?T` drives nullable. */
    public ?ArticleLevel $level = null;

    /**
     * Explicit type: wins over inference (here redundantly, to prove the
     * attribute path is also a valid enum-class key).
     */
    #[Column(type: ArticleStatus::class)]
    public ArticleStatus $explicit;

    /**
     * Cast suppressed: the caller owns the stored representation. The
     * property must therefore be UNTYPED — an enum-typed property with
     * `cast: false` is refused at compile time, because the cast is the
     * only thing that converts between the case and the stored scalar.
     */
    #[Column(name: 'raw_status', cast: false)]
    public $raw;
}

/**
 * The refused combination: an ENUM-TYPED property with the cast
 * suppressed. Isolated in its own class so compiling it cannot affect the
 * working fixtures.
 */
class SuppressedEnumArticle extends Model
{
    #[Column(type: ArticleStatus::class, cast: false)]
    public ArticleStatus $status;
}

/**
 * A PURE enum (no backing type) has no scalar representation and is
 * REFUSED at compile time. Isolated in its own class so compiling it
 * cannot affect the working fixtures.
 */
enum ArticlePureState
{
    case On;
    case Off;
}

class PureEnumArticle extends Model
{
    public ArticlePureState $state;
}
