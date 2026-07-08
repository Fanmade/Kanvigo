<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Temporary KAN-413 diagnostic: records every MCP request and its outcome to
 * storage/logs/mcp-diagnostic.log by writing the file directly — deliberately
 * bypassing the logging stack, so it keeps working when Monolog (or anything
 * else in the framework) is the thing that is failing. A shutdown hook also
 * captures PHP fatals (OOM, stack overflow, timeouts), which never reach
 * Laravel's exception handler or laravel.log.
 *
 * Remove once the production 500s on MCP task tools are diagnosed.
 */
class CaptureMcpFailures
{
    public function handle(Request $request, Closure $next): Response
    {
        $entry = [
            'at' => date('c'),
            'phase' => 'request',
            'method' => $request->input('method'),
            'tool' => $request->input('params.name'),
            'pid' => getmypid(),
            'memory_limit' => ini_get('memory_limit'),
        ];

        self::write($entry);

        // A fatal (OOM, stack overflow, timeout) skips everything below; this
        // shutdown hook is the only way to get it on record from inside PHP.
        register_shutdown_function(static function () use ($entry): void {
            $error = error_get_last();

            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                self::write([
                    ...$entry,
                    'phase' => 'fatal',
                    'error' => $error['message'],
                    'file' => $error['file'].':'.$error['line'],
                    'peak_memory' => memory_get_peak_usage(true),
                ]);
            }
        });

        try {
            $response = $next($request);

            // Auth has run by now — record who called and with which kind of
            // credential, so Sanctum probes and OAuth clients are told apart.
            $entry['user'] = $request->user()?->getKey();
            $entry['credential'] = ($token = $request->user()?->currentAccessToken()) !== null ? class_basename($token) : null;
        } catch (Throwable $e) {
            self::write([
                ...$entry,
                'phase' => 'throwable',
                'exception' => $e::class,
                'error' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
                'trace' => substr($e->getTraceAsString(), 0, 4000),
            ]);

            throw $e;
        }

        self::write([
            ...$entry,
            'phase' => 'response',
            'status' => $response->getStatusCode(),
        ]);

        return $response;
    }

    /**
     * Append a JSON line, straight to disk — no Monolog, no cache, no queue.
     *
     * @param  array<string, mixed>  $entry
     */
    protected static function write(array $entry): void
    {
        @file_put_contents(
            storage_path('logs/mcp-diagnostic.log'),
            json_encode($entry).PHP_EOL,
            FILE_APPEND | LOCK_EX,
        );
    }
}
