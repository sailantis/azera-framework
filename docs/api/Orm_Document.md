# Class: Document

**Full name:** [Azera\Orm\Document](../../src/Orm/Document.php)

Base class for MongoDB-backed objects (pairs with #[Entity(store: 'mongo')]).

FACADE over the [`EntityManager`](Orm_EntityManager.md): save()/delete() delegate to the
EM's write pipeline (persist + flush), so documents and SQL models go
through the SAME diff -> order -> transaction path and land in the SAME
request-scoped heap. The #[Entity] attribute's `store` selects the
registered store type (EntityManager::setStore()).

The EM's heap-node diff is authoritative — hydrated documents are
heap-tracked, so persist() schedules an UPDATE only when fields actually
changed.

## Public methods

### store() · <small>[🗎](../../src/Orm/Document.php#L28)</small>

`public function store(): string`

Which store type handles this document (the registry key
EntityManager::setStore() maps to an instance). Mirrors the
#[Entity(store: ...)] attribute; the attribute is the authority
(it compiles into metadata).

**Return value**

- Type: `string`


---

### save() · <small>[🗎](../../src/Orm/Document.php#L39)</small>

`public function save(): bool`

Save via the EM: INSERT when untracked, diff-UPDATE when managed.

The EM's heap-node diff is authoritative (hydrated documents are
heap-tracked with a baseline snapshot), so we schedule FIRST,
check whether anything was actually queued, and only then flush.

**Return value**

- Type: `bool`


---

### delete() · <small>[🗎](../../src/Orm/Document.php#L54)</small>

`public function delete(): bool`

**Return value**

- Type: `bool`


---

### hasChanged() · <small>[🗎](../../src/Orm/Document.php#L86)</small>

`public function hasChanged(): bool`

Whether any field differs from the heap baseline (untracked entity:
true when any metadata column has a set value).

**Return value**

- Type: `bool`


---

### changedData() · <small>[🗎](../../src/Orm/Document.php#L97)</small>

`public function changedData(): array`

Field-name-keyed map of values that differ from the heap baseline
(untracked entity: all set values).

**Return value**

- Type: `array`


---

### loadState() · <small>[🗎](../../src/Orm/Document.php#L106)</small>

`public function loadState(): static`

Revert all properties to the values recorded in the heap node
snapshot (the loadState() replacement). No-op for untracked entities.

**Return value**

- Type: `static`


---

### refresh() · <small>[🗎](../../src/Orm/Document.php#L117)</small>

`public function refresh(): static|null`

Re-read this document's row from storage and refresh the instance
IN PLACE (current values + synced snapshot). Returns $this, or NULL
when the row is gone in storage (detached). Throws for untracked
documents and documents with scheduled unflushed writes.

**Return value**

- Type: `static`|`null`



---

[Back to the Index ⤴](README.md)
