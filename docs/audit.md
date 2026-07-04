# Audit layer

Every audited action in Kanvigo — task/project changes, comments, tags,
assignments, cancellations and so on — is emitted once as an immutable audit
event and delivered to every registered **audit sink**. The default install
ships with a single sink, the product **activity feed**, and behaves exactly
like the classic activity log with zero configuration.

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
