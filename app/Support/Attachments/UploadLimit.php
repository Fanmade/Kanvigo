<?php

namespace App\Support\Attachments;

/**
 * The largest attachment an upload request can actually carry.
 *
 * The application caps attachments at config("attachments.max_size"), but PHP
 * enforces its own ceilings first: upload_max_filesize per file and
 * post_max_size per request. When either sits below the configured cap, a
 * request the app would accept is rejected before Laravel ever sees the file —
 * post_max_size as a 413 from the ValidatePostSize middleware, upload_max_filesize
 * as an empty upload that fails validation. Both surface as a generic failure.
 *
 * Every place that states or enforces the limit (the dropzone's up-front size
 * check and batching, the Livewire and REST validation rules) takes it from
 * here, so the size the user is told, the size the client sends and the size
 * the server accepts all agree with what PHP will let through.
 */
final class UploadLimit
{
    /**
     * A multipart body is larger than the files in it (boundaries, part
     * headers, field names), so a request whose files exactly fill post_max_size
     * still overflows it. Keep the per-request limit this far below.
     */
    public const int POST_HEADROOM_BYTES = 64 * 1024;

    /**
     * @param  int|null  $uploadMaxFilesize  PHP's per-file ceiling in bytes; null or non-positive means unlimited.
     * @param  int|null  $postMaxSize  PHP's per-request ceiling in bytes; null or non-positive means unlimited.
     */
    public function __construct(
        private readonly ?int $uploadMaxFilesize,
        private readonly ?int $postMaxSize,
    ) {}

    public static function fromIni(): self
    {
        return new self(
            uploadMaxFilesize: self::iniBytes('upload_max_filesize'),
            postMaxSize: self::iniBytes('post_max_size'),
        );
    }

    /**
     * The effective per-file limit in kilobytes, as Laravel's "max" rule expects it.
     */
    public function kilobytes(): int
    {
        $kilobytes = (int) config('attachments.max_size');

        if ($this->uploadMaxFilesize !== null && $this->uploadMaxFilesize > 0) {
            $kilobytes = min($kilobytes, intdiv($this->uploadMaxFilesize, 1024));
        }

        if ($this->postMaxSize !== null && $this->postMaxSize > 0) {
            $kilobytes = min($kilobytes, intdiv($this->postMaxSize - self::POST_HEADROOM_BYTES, 1024));
        }

        return max(0, $kilobytes);
    }

    public function bytes(): int
    {
        return $this->kilobytes() * 1024;
    }

    /**
     * The effective limit for people: "12 MB", "1.5 MB" or "512 KB".
     */
    public function label(): string
    {
        $kilobytes = $this->kilobytes();

        if ($kilobytes < 1024) {
            return $kilobytes.' KB';
        }

        return rtrim(rtrim(number_format($kilobytes / 1024, 1, '.', ''), '0'), '.').' MB';
    }

    private static function iniBytes(string $option): ?int
    {
        $value = ini_get($option);

        if ($value === false || $value === '') {
            return null;
        }

        return ini_parse_quantity($value);
    }
}
