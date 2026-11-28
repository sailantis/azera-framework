<?php

namespace Azera\Tests\Orm\Fixtures;

use Azera\Orm\Model;

/**
 * The shape {@see \Azera\Orm\ConflictTarget} exists for: a DATABASE-GENERATED
 * primary key (so the caller has no PK to name before the row exists) plus a
 * natural unique key that carries the real identity.
 *
 * `stripe_customer_id` mirrors a PARTIAL unique index — the predicate lives in
 * the application's migration, not in the model, so the conflict target has to
 * carry it too.
 */
class NaturalKeyRow extends Model
{
    /** Database-generated: unset on the insert path. */
    public $id;

    public $stripe_customer_id;

    public $email;

    public $name;
}
