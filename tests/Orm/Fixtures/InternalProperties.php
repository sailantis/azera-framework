<?php

namespace Azera\Tests\Orm\Fixtures;

use Azera\Orm\Model;
use Azera\Orm\Attribute\Column;

/**
 * Property SHAPES the compiler must exclude from the column set.
 *
 * Everything here is declared so that a naive "every declared property
 * is a column" compile would break in a different way:
 *
 * - statics, readonly and non-public properties are excluded by the
 *   guards in Metadata::compile() — the framework cannot read or write
 *   them with plain instance syntax, so a compiled column would fatal
 *   during hydration (`Cannot access protected property ...`);
 * - `#[Column(persist: false)]` is the escape hatch for a MUTABLE
 *   property that must stay out of the database — readonly/static need
 *   no attribute at all (the type guards already exclude them);
 * - the single- and double-underscore properties prove the compiler no
 *   longer treats the `__` NAME PREFIX as "internal" — a real column
 *   named `__cached` or `_revision` must stay persistable.
 */
class InternalProperties extends Model
{
    public $id;

    public $title;

    /** static: process-global, never per-entity data. */
    public static int $instances = 0;

    /** static array — the shape the framework's own role maps use. */
    protected static array $registry = [];

    /** readonly: the pipeline cannot re-apply it (fresh()/revert()). */
    public readonly string $immutable;

    /** readonly + persist: false — redundant but harmless, because the
     *  readonly guard is checked first. Kept to pin that ordering. */
    #[Column(persist: false)]
    public readonly string $label;

    /** protected: not reachable through plain instance syntax. */
    protected string $protectedState = 'p';

    /** private: same — the framework has no access. */
    private string $privateState = 'q';

    /** Non-public props stay reachable to the class itself. */
    public function protectedState(): string
    {
        return $this->protectedState;
    }

    /** Single underscore names are ordinary persistable columns. */
    public $_revision;

    /** Double underscore is ALSO an ordinary column — no `__` rule. */
    public $__cached;

    /** The explicit escape hatch, non-readonly. */
    #[Column(persist: false)]
    public $derived;

    public function __construct()
    {
        $this->immutable = 'set-once';
        $this->label     = 'derived-label';
    }
}