<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\AccessAudit;
use App\Audit\Pii\AuditRedactor;
use App\Http\Controllers\Controller;
use App\Support\Facades\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Kanvigo\Audit\Contracts\AuditEvent;

/**
 * The pull surface of the audit-event publishing mechanism (KAN-397): an
 * external consumer (a SIEM, a compliance archiver) polls this endpoint,
 * walking the transactional outbox forward by its monotonic id.
 *
 * The outbox id is the cursor. Because rows are only ever appended in commit
 * order and never renumbered, paging by `id > after` gives a stable total
 * order and at-least-once completeness for free — a consumer that records the
 * last id it saw resumes exactly where it left off, and the idempotency key on
 * each event lets it dedupe a re-read after a crash.
 *
 * Every event is passed through the {@see AuditRedactor} first: this is an
 * external boundary, so personal fields are tokenized and sensitive free text
 * dropped, while the internal outbox row keeps full fidelity.
 *
 * Reading the stream is itself an access event ({@see AccessAudit::streamRead()}),
 * recorded to the outbox for the internal "who exported the log" trail. Those
 * `audit_stream_read` rows are excluded from this endpoint's own output, so a
 * polling consumer never reads its own reads back — which would keep the stream
 * from ever settling to a short "caught up" page.
 */
class AuditEventController extends Controller
{
    /**
     * The largest page a consumer may request. Keeps a single poll bounded
     * regardless of how far behind the consumer has fallen.
     */
    private const MAX_LIMIT = 200;

    private const DEFAULT_LIMIT = 100;

    public function index(Request $request, AuditRedactor $redactor): JsonResponse
    {
        $validated = $request->validate([
            'after' => ['sometimes', 'integer', 'min:0'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
        ]);

        $after = (int) ($validated['after'] ?? 0);
        $limit = (int) ($validated['limit'] ?? self::DEFAULT_LIMIT);

        $rows = DB::table('audit_outbox')
            ->where('id', '>', $after)
            // A consumer reading the stream never needs its own reads reflected
            // back; excluding them here (LIMIT applies after the filter, so the
            // page stays full) keeps the stream from looping on itself.
            ->where('event->action', '!=', 'audit_stream_read')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'event']);

        $data = $rows->map(static function (object $row) use ($redactor): array {
            $event = $redactor->redact(AuditEvent::fromArray(
                json_decode((string) $row->event, true, flags: JSON_THROW_ON_ERROR),
            ));

            return ['id' => (int) $row->id] + $event->toArray();
        })->all();

        // A full page means there may be more; a short page means the consumer
        // has caught up and should keep polling the same cursor.
        $nextCursor = $rows->count() === $limit ? (int) $rows->last()->id : null;

        Audit::record(AccessAudit::streamRead($after, $limit, count($data)));

        return response()->json([
            'data' => $data,
            'next_cursor' => $nextCursor,
        ]);
    }
}
