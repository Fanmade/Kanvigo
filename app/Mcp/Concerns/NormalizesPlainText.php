<?php

namespace App\Mcp\Concerns;

/**
 * Repairs plain-text fields (titles) that an MCP client has HTML-escaped.
 *
 * MCP clients are language models that routinely HTML-escape the values they
 * emit — a plain "&" in a title arrives as "&amp;", "<" as "&lt;" and so on.
 * Titles are stored verbatim and escaped exactly once by Blade on output, so a
 * leftover entity renders literally ("A &amp; B" shows up as "A &amp;amp; B").
 * Decoding a single entity layer here cancels the client's escaping and stores
 * the character the caller meant.
 *
 * The repair is intentionally one layer deep: a caller that genuinely wants a
 * literal entity such as "&amp;" displayed can double-escape it ("&amp;amp;"),
 * which decodes back to "&amp;" and survives Blade's output escaping. This lives
 * at the MCP boundary only — the web form already submits literal plain text, so
 * the shared write actions must not decode.
 */
trait NormalizesPlainText
{
    /**
     * Decode one layer of HTML entities from a plain-text value.
     */
    protected function decodePlainText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5);
    }
}
