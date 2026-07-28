# Audit layer

Every audited action in Kanvigo — task/project changes, comments, tags,
assignments, cancellations and so on — is emitted once as an immutable audit
event and delivered to every registered **audit sink**. The default install
ships with a single sink, the product **activity feed**, and behaves exactly
like the classic activity log with zero configuration.

## What is audited

Beyond the content changes shown in the activity feed, the audit layer records
every security-relevant action. These events do not appear in the feed; they
flow to the outbox and to any registered compliance/transport sinks:

- **Authentication** — login, logout, failed attempts, lockouts, password
  resets and changes, email verification, registration (invitation
  acceptance), the two-factor lifecycle (enable, confirm, disable, challenge,
  failed challenge, recovery codes) and passkey registration, verification and
  deletion.
- **Authorization & membership** — project members added/removed, project role
  grants/revocations, custom role creation/edits/deletion, account permission
  grants/revocations, and the invitation lifecycle (created, resent, accepted,
  revoked).
- **Content edits & deletions** — task title/description/due-date/priority
  edits, project title/short-name/description edits, the full note lifecycle
  (create, edit, convert, delete), and task/project/user deletions — recorded
  at the model level, so every write path (UI, MCP, REST API) is covered.
  Description bodies are recorded as "the field changed" without content
  snapshots (free text in an immutable trail is a PII liability).
- **API tokens & accounts** — token creation and revocation (including the
  bulk revoke on deactivation), account deactivation, reactivation and
  deletion.
- **Read & access** — a curated slice of high-value read events ("who looked at
  what"): reading the instance-wide audit export stream, one member viewing
  another's contact info (email) through the REST or MCP user endpoints,
  attachment downloads (REST, web and note attachments, plus serving attachment
  content through the MCP get-attachment tool), and an administrator opening the
  user-administration directory (which lists every account's contact info —
  recorded once per open, not per row). Ordinary list and page reads are
  deliberately not audited — this slice is the rare, sensitive access worth a
  forensic record, not every view.

The coverage matrix in `tests/Feature/Audit/AuditCoverageTest.php` asserts
each of these actions produces an audit record, so a code path that skips the
audit layer fails the suite.

## How events flow

1. The action emits an event, which lands in a transactional **outbox** inside
   the same database transaction as the action — an aborted action leaves no
   audit record.
2. After the action commits, synchronous sinks (like the activity feed) run
   inline; a failing sink is reported and isolated, never breaking the request
   or the other sinks.
3. Queued sinks are shipped by the scheduled `audit:outbox:drain` command in
   strict outbox order, at-least-once (sinks dedupe on the event's idempotency
   key). Fully dispatched rows are pruned daily after the retention window
   (`AUDIT_OUTBOX_RETENTION_DAYS`, default 30 days; 0 keeps them forever).

Every event carries the actor (or the system actor for scheduler/worker
actions), what changed, and the request context: IP, user agent, the source
surface (UI, MCP token, REST token, queued job, or system) and the acting
token's name.

## Adding a sink

Implement the `AuditSink` interface from the
[`kanvigo/audit-contracts`](https://github.com/Fanmade/kanvigo-audit-contracts)
package — `accepts()` picks which events the sink wants (filter by
`AuditCategory`: Content, Authn, Authz, Token, Security), `record()` persists
or ships them, and `policy()` declares how it runs:

- **sync** — inline after the action commits; failures are isolated.
- **queued** — shipped by the outbox drain worker; failures are retried.
- **fail-closed** — synchronous, inside the action's transaction; a failed
  audit write aborts the action ("no guaranteed record → no action"). With a
  fail-closed sink registered, audited mutations must run inside a database
  transaction — emitting outside one throws.

Register the class in `config/audit.php` under `sinks`. Sinks run alongside
each other; each receives every event it accepts, so you can run the feed, a
compliance ledger and a webhook transport in parallel without touching core.

### Compliance ledger (optional Chronicle bridge)

For a tamper-evident, hash-chained compliance ledger you don't have to build a
sink yourself. The optional
[`kanvigo/audit-chronicle`](https://github.com/Fanmade/kanvigo-audit-chronicle)
package bridges the audit layer to
[Chronicle](https://github.com/laravel-chronicle/core) — an append-only ledger
with signed checkpoints, WORM anchoring (S3 Object Lock) and per-subject GDPR
crypto-shredding. The core never depends on it; a self-hoster opts in:

```bash
composer require kanvigo/audit-chronicle
php artisan audit:chronicle:install
```

The command registers a fail-closed `ChronicleSink` and prints the hardening
checklist (signing key, encryption, scheduled verification, anchoring).

## PII & redaction

Audit events carry personal data — the actor, IP addresses, e-mail addresses,
name snapshots, and free text such as a cancellation message. `config/audit.php`
classifies every field of an event as **public**, **pii** or **sensitive** under
`pii.fields`, with per-action overrides under `pii.actions` (the same `old`/`new`
keys hold a task title in one event and a free-text message in the next). Fields
are public unless listed, so a new metadata key is never over-shared by accident.

This classification is the one shared vocabulary the sinks read. A sink that
ships events out of the system opts into redaction, and then only ever sees the
minimised copy:

```php
'sinks' => [
    ActivityLogSink::class,                 // internal — full fidelity
    WebhookSink::class => ['redact' => true],
],
```

Redaction applies one strategy per class (`pii.strategies`): **pii** fields are
tokenised — replaced by a stable pseudonym, so a consumer can still correlate
the same person across events without ever seeing them — and **sensitive** fields
are dropped. The internal record is untouched: the outbox row and the activity
feed always keep the real values.

Tokens are a keyed hash salted with `AUDIT_PII_TOKEN_SALT` (falling back to the
application key). Rotating the salt invalidates every token already published,
which is the erasure lever for pseudonymised data that has already left the
system.

## Consuming the stream externally

An external system pulls the instance-wide audit log from the REST API:

```
GET /api/v1/audit-events?after=<id>&limit=<n>
```

It returns a page of events plus a `next_cursor`. The cursor is the outbox id —
a monotonic, commit-ordered sequence that is never renumbered — so a consumer
that records the last id it saw resumes exactly there. Delivery is at-least-once
and each event carries an `idempotency_key`, so a consumer re-reading after a
crash deduplicates rather than double-counting. A short page (fewer than `limit`)
means the consumer has caught up; it keeps polling the same cursor. `limit`
defaults to 100 and is capped at 200.

Each event is the versioned schema (`v`, `action`, `category`, subject, actor,
metadata, context) with the outbox `id` added as the cursor. Payloads pass
through the redaction boundary above, so the stream is minimized — the endpoint
is an external consumer like any other.

Because the stream spans every user and project — unlike the rest of the v1 API,
which is scoped to the token owner — it is doubly gated: the acting user needs
the account-level `manage-users` permission, and the token needs the dedicated
`audit` ability (mintable from the API tokens screen only by an operator who
holds that permission). An ordinary read/write token cannot reach it.

Reading the stream is itself an access event, recorded for the internal "who
exported the log" trail — but those read events are excluded from the stream's
own output, so a polling consumer never reads its own reads back (and a quiet
system's stream still settles to an empty "caught up" page).

### Erasure vs. immutability

An immutable trail and a right-to-erasure request pull in opposite directions.
The position, to be confirmed with your own legal review before you rely on it:

- The **internal** trail is the system of record and is not edited in place. A
  user's personal data is removed from it by account deletion and by the outbox
  retention window, not by rewriting history.
- **Published** events carry pseudonyms rather than identities, so erasure at an
  external consumer is achieved by destroying the key (rotating the salt), not by
  chasing down copies.
- A compliance ledger (the Chronicle bridge) encrypts classified fields per
  subject and erases by destroying that subject's key — the ciphertext stays, so
  the chain still verifies.
- A **legal hold** outranks an erasure request: while a subject is on hold the
  ledger refuses to erase. That conflict is resolved by policy, not by code.
