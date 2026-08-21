<?php

/**
 * Upload
 *
 * @author      Gravity PDF
 * @copyright   2026 Gravity PDF
 * @link        https://github.com/GravityPDF/Upload
 *
 * MIT LICENSE
 *
 * Permission is hereby granted, free of charge, to any person obtaining
 * a copy of this software and associated documentation files (the
 * "Software"), to deal in the Software without restriction, including
 * without limitation the rights to use, copy, modify, merge, publish,
 * distribute, sublicense, and/or sell copies of the Software, and to
 * permit persons to whom the Software is furnished to do so, subject to
 * the following conditions:
 *
 * The above copyright notice and this permission notice shall be
 * included in all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
 * EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
 * MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
 * NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE
 * LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION
 * OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION
 * WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 */

declare(strict_types=1);

namespace GravityPdf\Upload;

/* Two copies of this library in one process — an unprefixed `vendor/` shared between plugins
   — would be a fatal redeclaration. Two copies of a class are harmless; a function is not. */
if (!function_exists('GravityPdf\Upload\__')) {
    /**
     * Mark a string for the catalogue without translating it
     *
     * gettext's `N_()` under a more familiar name: it returns `$text` unchanged. `File` does
     * the lookup later, when it renders `getErrors()`. That is what keeps
     * `Exception::getMessage()` in English and the raw msgid in `getErrorDetails()`.
     *
     * **Not WordPress's `__()`.** The two coexist, but PHP falls back to the global namespace
     * when the current one has no match, so a file outside `GravityPdf\Upload` that forgets
     * `use function GravityPdf\Upload\__;` calls WordPress's instead and translates too
     * early. `I18nTest` checks `src/` for that. `\GravityPdf\Upload\__()` cannot go wrong.
     *
     * `$domain` is discarded — the lookup always uses `Translation::DOMAIN` — and is there for
     * your extractor. Extraction reads the first argument only; `-k__:1,2` finds nothing.
     *
     * @param string $text   The English string, which is also the gettext msgid
     * @param string $domain The catalogue the string belongs to, for tooling that reads these
     */
    function __(string $text, string $domain = Translation::DOMAIN): string
    {
        return $text;
    }
}
