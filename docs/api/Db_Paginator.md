# Class: Paginator

**Full name:** [Azera\Db\Paginator](../../src/Db/Paginator.php)

Paginator class for paginating database query results.

## Public methods

### __construct() · <small>[🗎](../../src/Db/Paginator.php#L34)</small>

`public function __construct(Azera\Db\Query $builder, int $page = 1, int $pageSize = 30, bool $reverse = false): mixed`

Create a new Paginator instance.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$builder` | [Query](Db_Query.md) | - | The Query builder instance to paginate. |
| `$page` | int | `1` | The current page number. |
| `$pageSize` | int | `30` | The number of items per page. |
| `$reverse` | bool | `false` | Whether to reverse the order of items. |

**Return value**

- Type: `mixed`


---

### reverse() · <small>[🗎](../../src/Db/Paginator.php#L52)</small>

`public function reverse(bool $reverse = true): static`

Set whether to reverse the order of items.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$reverse` | bool | `true` | True to reverse the order, false otherwise. |

**Return value**

- Type: `static`


---

### entities() · <small>[🗎](../../src/Db/Paginator.php#L67)</small>

`public function entities(): array`

Execute and return items as heap-tracked model instances.

Requires the query to have a model bound (e.g. via Item::query()).
Hydrates through the ORM (FastHydrator + request-scoped heap) — the
same identity-mapped path as Model::find()/entities().

**Return value**

- Type: `array`


---

### objects() · <small>[🗎](../../src/Db/Paginator.php#L78)</small>

`public function objects(): array`

Execute and return items as plain stdClass objects.

No entity hydration — rows are fetched directly via PDO::FETCH_OBJ.
Table resolution and relations still go through the model.

**Return value**

- Type: `array`


---

### assoc() · <small>[🗎](../../src/Db/Paginator.php#L88)</small>

`public function assoc(): array`

Execute and return items as associative arrays.

No entity hydration — rows are fetched directly via PDO::FETCH_ASSOC.

**Return value**

- Type: `array`


---

### fetch() · <small>[🗎](../../src/Db/Paginator.php#L98)</small>

`public function fetch(mixed $fetchMode = 0): array`

Execute and return items using the PDO default fetch mode.

Backward-compatible with the original execute() API.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$fetchMode` | mixed | `0` |  |

**Return value**

- Type: `array`


---

### items() · <small>[🗎](../../src/Db/Paginator.php#L156)</small>

`public function items(): array|null`

Get the items for the current page. Return null if the query has not been executed yet.

**Return value**

- Type: `array`|`null`
- Description: The items for the current page, or null if the query has not been executed yet.


---

### totalItems() · <small>[🗎](../../src/Db/Paginator.php#L166)</small>

`public function totalItems(): int`

Get the total number of items across all pages.

**Return value**

- Type: `int`
- Description: The total number of items.


---

### firstItem() · <small>[🗎](../../src/Db/Paginator.php#L176)</small>

`public function firstItem(): int`

Get the position of the first item in the current page (1-based index).

**Return value**

- Type: `int`
- Description: The position of the first item in the current page.


---

### lastItem() · <small>[🗎](../../src/Db/Paginator.php#L186)</small>

`public function lastItem(): int`

Get the position of the last item in the current page (1-based index).

**Return value**

- Type: `int`
- Description: The position of the last item in the current page.


---

### currentPage() · <small>[🗎](../../src/Db/Paginator.php#L196)</small>

`public function currentPage(): int`

Get the current page number.

**Return value**

- Type: `int`
- Description: The current page number.


---

### pageSize() · <small>[🗎](../../src/Db/Paginator.php#L206)</small>

`public function pageSize(): int`

Get the page size (number of items per page).

**Return value**

- Type: `int`
- Description: The page size.


---

### previousPage() · <small>[🗎](../../src/Db/Paginator.php#L216)</small>

`public function previousPage(): int`

Get the previous page number.

**Return value**

- Type: `int`
- Description: The previous page number.


---

### nextPage() · <small>[🗎](../../src/Db/Paginator.php#L226)</small>

`public function nextPage(): int`

Get the next page number.

**Return value**

- Type: `int`
- Description: The next page number.


---

### hasPrevious() · <small>[🗎](../../src/Db/Paginator.php#L236)</small>

`public function hasPrevious(): bool`

Check if there is a previous page.

**Return value**

- Type: `bool`
- Description: True if there is a previous page, false otherwise.


---

### hasNext() · <small>[🗎](../../src/Db/Paginator.php#L246)</small>

`public function hasNext(): bool`

Check if there is a next page.

**Return value**

- Type: `bool`
- Description: True if there is a next page, false otherwise.


---

### lastPage() · <small>[🗎](../../src/Db/Paginator.php#L256)</small>

`public function lastPage(): int`

Get the last page number.

**Return value**

- Type: `int`
- Description: The last page number.



---

[Back to the Index ⤴](README.md)
