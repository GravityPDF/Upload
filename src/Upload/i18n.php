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

/* Two copies in one process — an unprefixed `vendor/` shared between plugins — would be a
   fatal redeclaration, where two copies of a class are harmless. */
if (!function_exists('GravityPdf\Upload\__')) {
    /**
     * Mark a string for the catalogue without translating it
     *
     * gettext's `N_()` under a familiar name: it returns `$text`, and the lookup happens once,
     * where `File` renders `getErrors()`. That keeps `Exception::getMessage()` English, keeps
     * the untranslated msgid in `getErrorDetails()`, and takes the locale when the message is
     * read rather than when the file was rejected. `xgettext -k__:1` finds every call.
     *
     * Namespaced, so it coexists with WordPress's global `__()` rather than colliding. PHP
     * resolves an unqualified call against the global namespace when the current one has no
     * match, though, so a file outside `GravityPdf\Upload` that omits `use function
     * GravityPdf\Upload\__;` reaches WordPress's function instead — no error, and the string
     * is translated at the throw. `I18nTest` reads `src/` to catch that. A fully qualified
     * `\GravityPdf\Upload\__()` cannot take that path and extracts the same.
     *
     * `$domain` is discarded. It describes the string to your extractor; the runtime lookup
     * always uses `Translation::DOMAIN`. Optional, as on WordPress's `__()`. Extraction reads
     * the first argument only — `-k__:1,2` would read this one as a plural and find nothing.
     *
     * @param string $text   The English source string, which is also the gettext msgid
     * @param string $domain The catalogue the string belongs to, for whatever reads these calls
     */
    function __(string $text, string $domain = Translation::DOMAIN): string
    {
        return $text;
    }
}
