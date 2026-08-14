# Image Attachment Delivery

Design for KAN-542 — <https://do.reuterben.de/KAN-542>

## Problem

`GetAttachmentTool` returns image and audio attachments inline, uncapped. A 6 MB
scan becomes ~8 MB of base64 inside a single JSON-RPC response; the transfer is
slow enough that clients abandon it, which is what the bug reporter observed
("der Ticket-Server bricht bei den 6-MB-Scans oft ab"). Text attachments already
have a 256 KiB cap with `offset` pagination — images and audio have nothing.

Three things make it worse:

- The upload ceiling is 12 MB (`config/attachments.php`), so 6 MB is not the
  worst case.
- Claude downsamples images past ~1568 px on its side and rejects images over
  5 MB base64 outright, so the full-resolution bytes are discarded upstream
  anyway. The payload is pure cost.
- HEIC and TIFF — common for scanned material — match `image/*` and are shipped
  raw today. GD cannot decode them, so they are simultaneously the largest
  payloads and the ones no client can render.

There is also no way for an agent to know an attachment's size before fetching
it. The MCP `get-task` listing returns only `id`, `name`, `mime_type` and
`is_inline`, so a 12 KB icon and a 6 MB scan are indistinguishable until one of
them times out.

## Goals

1. A large image never breaks an MCP fetch by default.
2. A consumer can ask for exactly the rendition it wants — width, height,
   format, quality — instead of taking whatever the server sends.
3. A consumer can see an image's dimensions and byte size before deciding to
   fetch it, so it does not request a downscale of an already-small image.

## Non-goals

- Caching or pre-generating derivatives. Deferred to the web-routes task below.
- Automatic image descriptions. Filed separately.
- Cropping and fit modes. The image is always fitted inside the requested box
  with its aspect ratio preserved — never cropped, never stretched, never
  upscaled.
- Changing the web `view` / `thumbnail` routes. Follow-up task.

## Architecture

### The transform service

A new `App\Support\Images` namespace, sitting alongside the existing
`ImageProcessing` / `Thumbnail` / `Avatar` helpers in `App\Support`.

```
App\Support\Images\TransformSpec      readonly value object: width, height, format, quality
App\Support\Images\ImageTransformer   the entry point every surface calls
App\Support\Images\Contracts\ImageDriver
App\Support\Images\Drivers\ImagickDriver
App\Support\Images\Drivers\GdDriver
```

`ImageTransformer` exposes two operations:

- `transform(string $bytes, TransformSpec $spec): ?string` — returns encoded
  bytes, or `null` when the input is not a decodable raster image or trips the
  decompression-bomb guard.
- `dimensions(string $bytes): ?array{int, int}` — width and height, or `null`.

The driver seam exists for one reason: **Imagick decodes HEIC, TIFF and AVIF;
GD does not.** `ImageTransformer` selects `ImagickDriver` when the extension is
loaded and falls back to `GdDriver` otherwise. Both extensions are present on
this host, and `Thumbnail` already imports `Imagick`, so this is not a new
dependency — it is making an existing one explicit. Where neither driver can
decode the bytes, callers fall through to a metadata-only response, so no
deployment behaves worse than today.

The existing `ImageProcessing::downscale()` keeps its GD implementation and its
current callers (thumbnails, avatars, export). It is not rewritten as part of
this change — it produces PNG at 256 px for a different purpose, and churning it
would put the thumbnail pipeline at risk for no gain. Its decompression-bomb
guard (`width * height > 40_000_000`) is reproduced in the new drivers.

### Parameters

Accepted on every surface that serves image bytes:

| Param | Type | Rules |
| --- | --- | --- |
| `width` | int | 1–4096. Maximum output width. |
| `height` | int | 1–4096. Maximum output height. |
| `format` | string | `webp` (default), `jpeg`, `png`, `avif`. |
| `quality` | int | 1–100, default 80. Ignored for `png`. |

`width` and `height` are independent bounds on a box the image is fitted
**inside**, aspect ratio preserved: the scale factor is
`min(width / sourceWidth, height / sourceHeight, 1)`. Either may be given alone,
leaving the other axis unbounded. The trailing `1` is what forbids upscaling —
an image already inside the box is returned at its original dimensions (still
re-encoded if `format` or `quality` was requested).

Both bounds matter, and width alone is not enough. A 1000 × 8000 scan is well
inside a 1568 px width limit while being far larger than a 1568 × 1568 image;
capping only width would leave the tallest documents — the ones most likely to
be multi-page scans — entirely untransformed. Bounding both axes is also what
makes the megapixel count, and therefore the encoded size, actually bounded.

`avif` is accepted only when the active driver reports an AVIF encoder;
otherwise it is a 422 naming the formats that are available. When a dimension is
given without `format`, the source format is preserved if it is encodable, and
`webp` is used when it is not (HEIC, TIFF).

Passing any transform param for a non-image attachment is a 422 rather than a
silently ignored parameter.

### Per-surface behavior

**REST `GET /api/v1/attachments/{id}` and the signed download URL — byte-exact
by default.** With no params, the response is the current streamed, unmodified
file. Any param switches it to a transformed response with the output format's
`Content-Type` and a `Content-Disposition` filename carrying the new extension.
A download endpoint that silently re-encoded bytes would be wrong; the caller
has to opt in.

The signed URL needs one specific accommodation: Laravel signs the full query
string, so appending `?width=1024` to an issued link invalidates it. The route
validates with `hasValidSignatureWhileIgnoring(['width', 'height', 'format', 'quality'])`.
Leaving those four unsigned is safe — they cannot change which attachment is
served or which user it was issued for, and each is clamped to its stated range
before use. This is called out because it is the one place where the change
touches signature validation.

**MCP `get-attachment` — safe default, opt out with params.** The tool gains the
same four optional params. With none passed, an image is auto-downscaled when it
exceeds either threshold:

- longest edge > 1568 px, or
- stored size > 512 KiB

Longest edge, not width — a tall scan has to trip this the same way a wide one
does.

The default rendition fits inside 1568 × 1568, WebP, quality 80 — sized to what
a vision model actually consumes. The accompanying text block states that the image was
transformed, gives the original dimensions and size, and names the signed URL
for the untouched original. Images under both thresholds are returned exactly as
they are stored, so a small logo costs nothing extra and loses no fidelity.

Two fallbacks, both returning metadata plus the signed URL rather than bytes:

- The bytes cannot be decoded by any available driver.
- The transformed output still exceeds 2 MiB.

**Audio over MCP.** Audio has the identical uncapped path — a 20 MB MP3 fails
the same way, and no transform applies. Over a 4 MiB ceiling it returns metadata
plus the signed URL. This is in scope because it is the same defect.

### Metadata

**Stored dimensions.** A migration adds nullable `width` and `height` integer
columns to `attachments`. `StoreAttachment` populates them via
`ImageTransformer::dimensions()` from the contents it already reads for
thumbnail generation — no extra file read. An `attachments:backfill-dimensions`
command fills existing rows, skipping anything undecodable. Storing beats
computing per request: the listings below would otherwise have to open every
file on every `get-task` call.

**Cheap fields inline in listings.** `size`, `width` and `height` join the
attachment objects in MCP `get-task` and `get-project`, and `width` / `height`
join `AttachmentResource` (which already carries `size`). This is what actually
prevents the wasted request — an agent that must call a metadata endpoint to
decide whether to call a fetch endpoint has still made two calls.

**`GET /api/v1/attachments/{id}/metadata`.** The full record: `id`, `name`,
`mime_type`, `size`, `width`, `height`, `is_inline`, `created_at`,
`uploaded_by`, `download_url`, and `transformable` (whether an available driver
can decode and re-encode this file). Its purpose is to be the home for data too
heavy to repeat in every task payload — starting with automatic descriptions.
It follows the existing API conventions: `ReferenceResolver`, 404-not-403 for
attachments outside the caller's projects.

**No MCP metadata tool for now.** Every registered tool costs context in every
session, and today an MCP client gets everything it needs from the enriched
listings — the only field it would gain is `transformable`. The tool earns its
place when descriptions exist and are worth a dedicated fetch. Flagged as a
deliberate omission, not an oversight.

## Data flow

```
get-task  ──▶ attachment listing now carries size, width, height
                              │
                    agent decides: fetch as-is, fetch downscaled, or skip
                              │
        ┌─────────────────────┴──────────────────────┐
        ▼                                            ▼
get-attachment (MCP)                    GET /api/v1/attachments/{id}
  no params → auto-downscale if big       no params → byte-exact stream
  params    → honored exactly             params    → transformed
        │                                            │
        └──────────────▶ ImageTransformer ◀──────────┘
                               │
                   Imagick if loaded, else GD,
                   else metadata + signed URL
```

## Error handling

| Condition | Result |
| --- | --- |
| `width` / `height` / `quality` out of range | 422 naming the valid range |
| `format` unknown, or `avif` without encoder support | 422 naming supported formats |
| Transform param on a non-image attachment | 422 |
| Undecodable bytes (no driver handles the format) | Metadata + signed URL (MCP); 422 (REST, params given) |
| Decompression-bomb dimensions | Treated as undecodable |
| Transformed output still over 2 MiB | Metadata + signed URL (MCP only) |
| Attachment missing from disk | Existing behavior — 404 / tool error |
| Attachment outside the caller's projects | Existing behavior — 404, not 403 |

## Testing

**Unit — `ImageTransformer` and drivers.** Fitting inside a box given `width`
alone, `height` alone, and both; aspect ratio preserved in each case; the
binding axis is the one that constrains most (a 1000 × 8000 image against a
1568 × 1568 box scales on height, not width); no upscaling when the source
already fits; each output format
encodes and is detected as that format; quality bounds; decompression-bomb
guard returns null; driver selection prefers Imagick and falls back to GD;
`dimensions()` on a valid image and on garbage bytes.

**Feature — REST.** No params returns byte-identical content to the stored file;
`width` returns a narrower image with the right `Content-Type`; `height` alone
bounds a tall image; `format` changes
the extension in `Content-Disposition`; invalid params 422; params on a PDF 422;
foreign-project attachment 404.

**Feature — signed URL.** A link issued without params still validates when
`width` / `height` / `format` / `quality` are appended; tampering with the attachment id or
user segment still fails.

**Feature — MCP `get-attachment`.** A large image comes back transformed — the
returned base64 decodes to ≤1568 px on its longest edge and fewer bytes than the
original — and the text block says so; a tall, narrow image (1000 × 8000) is
transformed too, which is the case a width-only cap would have missed; an image under both thresholds is returned byte-identical;
explicit params override the default; an undecodable image returns metadata plus
the link and no image block; audio over the ceiling returns metadata plus the
link. `AccessAudit::attachmentDownloaded` is still recorded whenever bytes are
served, and not recorded on the metadata-only fallthrough.

**Feature — metadata.** Endpoint shape; `transformable` true for a JPEG and
false for a text file; 404 for a foreign-project attachment.

**Feature — listings.** MCP `get-task` / `get-project` and `AttachmentResource`
carry `size`, `width`, `height`; non-images report null dimensions.

**Migration.** `attachments:backfill-dimensions` fills existing image rows and
leaves undecodable rows null.

No performance test. This adds no query that scales with data — dimensions are
stored columns on rows already loaded — and per the project's convention a
threshold test cannot see a change like this without descending into mechanism.

## Documentation

- `docs/developing/api.md` — the transform params, the metadata endpoint, and
  the note that the download endpoint stays byte-exact without params.
- MCP tool descriptions for `get-attachment`, `get-task`, `get-project`.
- `README.md` feature list only if the user-facing wording needs it; this is
  largely an API-surface change.

No new user-facing UI strings, so no `de.json` additions. The web-routes
follow-up may introduce some.

## Follow-up tasks

- **Web routes adopt the transformer, with caching.** The `view` and `thumbnail`
  routes move onto `ImageTransformer` so the UI stops shipping full-resolution
  images into `<img>` tags. That surface has real users, repeated loads, and a
  small fixed set of widths — which is where an on-disk derivative cache
  (keyed by params, cleaned up with the attachment) earns its invalidation and
  disk cost. It can be added behind the same service without changing a caller.
- **Automatic image descriptions.** Generated on upload, editable by users,
  surfaced on the metadata endpoint. Needs a model choice, a queue job, cost
  controls, an edit UI, and translations — a feature in its own right.
