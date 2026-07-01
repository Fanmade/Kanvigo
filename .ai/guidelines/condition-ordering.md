# Order Conditions Cheapest-First

In a short-circuiting boolean chain (`&&` / `||`), put the **cheaper** operand
first so the expensive one is skipped whenever the cheap one already decides the
result. This silences the PHP Inspections (EA Extended) hint *"This condition
execution costs less than the previous one"* and avoids needless work.

- **`&&`** — lead with the cheap check that is quickest to be **false**, so a
  failing cheap check short-circuits before the expensive one runs.
- **`||`** — lead with the cheap check that is quickest to be **true**, so a
  passing cheap check short-circuits before the expensive one runs.

"Cheap" is a property/array access, a null or scalar comparison, `isset()`, or a
cached boolean flag. "Expensive" is a method or function call — especially one
that hits the database, iterates a collection, or recomputes state (`isDirty()`,
`->exists()`, `->count()`, `->contains()`).

```php
// Preferred — the cheap property+null check gates the isDirty() method call.
if ($task->parent_id !== null && $task->isDirty('parent_id')) {

// Flagged — the method call runs even when parent_id is null.
if ($task->isDirty('parent_id') && $task->parent_id !== null) {
```

## Correctness beats the micro-optimisation

Only reorder operands that are **side-effect-free and independent**. Never move a
guard that protects the operand after it — `$user !== null && $user->isActive()`
must keep the null check first, or the reorder would risk a call on null. Cheap-
first and guard-first almost always agree; when they don't, keep it correct.
