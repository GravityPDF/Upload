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

use RuntimeException;
use Throwable;

/**
 * The hook a developer installs to translate the strings this library shows an end user
 *
 * No translations ship. The English source string is the message id, so an install that never
 * calls `setTranslator()` produces the bytes it produced before this class existed.
 *
 * Call sites mark their strings with `GravityPdf\Upload\__()`; `File` renders them here. The
 * callable receives a string and a text domain, never the values interpolated into the string:
 * a filename or a configured limit containing `%` would be read as a conversion if it reached
 * a `printf` format position.
 *
 * Everything the translator does is absorbed, `LogicException` included — the one place in this
 * library where that type is not re-thrown. An untranslated message is a correct outcome, and
 * the alternative is a broken catalogue turning a rejected upload into a fatal error on the
 * failure path. `Filename::sanitizeForDisplay()` still runs over the result where `File`
 * records it, so a catalogue sits inside the same boundary a custom validation's message does.
 *
 * @since   4.0.0
 *
 * @package Upload
 */
final class Translation
{
    /**
     * The text domain this library's own strings belong to
     *
     * Fixed rather than injectable: WordPress forbids a variable text domain, and no extractor
     * can follow one. A consumer's translator receives it and is free to ignore it, which is
     * what the WordPress recipe does — it looks the string up in the plugin's own domain.
     */
    public const DOMAIN = 'gravitypdf-upload';

    /**
     * @var callable|null The installed translator, or null while none is
     */
    private static $translator;

    /**
     * Install the callable that turns a message id into text for the current locale
     *
     * Process-wide, and read when a message is rendered rather than when it is installed, so a
     * translator that consults the locale still answers correctly after a mid-request switch.
     *
     * @param callable $translator `function (string $text, string $domain): string`
     * @phpstan-param callable(string,string):string $translator
     */
    public static function setTranslator(callable $translator): void
    {
        self::$translator = $translator;
    }

    /**
     * Remove the installed translator, returning every message to its English source
     *
     * A test that installs one needs this in `tear_down()`: `backupGlobals` does not cover a
     * static property.
     */
    public static function resetTranslator(): void
    {
        self::$translator = null;
    }

    /** Is a translator installed? */
    public static function hasTranslator(): bool
    {
        return self::$translator !== null;
    }

    /**
     * Look one string up, without interpolating anything into it
     *
     * @param string $text The English source string, which is also the gettext msgid
     */
    public static function translate(string $text, string $domain = self::DOMAIN): string
    {
        if (self::$translator === null) {
            return $text;
        }

        try {
            $translated = call_user_func(self::$translator, $text, $domain);
        } catch (Throwable $e) {
            return $text;
        }

        /* A translator is not required to return a string. WordPress runs every lookup
           through the `gettext` filter, which is any plugin in the process. */
        return is_string($translated) ? $translated : $text;
    }

    /**
     * How many `printf` conversions a template carries
     *
     * `%%` does not match: the pattern requires a conversion letter, and `%` is not one. A
     * space is a legal flag, so the `% o` in `50% of your quota` does count as one. That is
     * not an over-count — `vsprintf()` raises on that string — and `render()` is where it is
     * kept from doing harm.
     */
    private static function countPlaceholders(string $template): int
    {
        return (int) preg_match_all('/%(?:\d+\$)?[-+ 0\x27]*[0-9]*(?:\.[0-9]+)?[bcdeEfFgGosuxX]/', $template);
    }

    /**
     * Translate a message id and fill its placeholders
     *
     * What `File` calls when it renders `getErrors()`. Two steps, each failing safely on its
     * own: a translation that cannot carry the values falls back to the English here, and an
     * unusable set of values falls back to the template at the interpolation.
     *
     * @param string $messageId The English source string, which is also the gettext msgid
     * @param array<int,string|int|float> $args Values for its `%1$s` placeholders
     * @phpstan-param list<string|int|float> $args
     */
    public static function render(string $messageId, array $args = []): string
    {
        $template = self::translate($messageId);

        /* Mismatched conversions would fill the wrong values in, or leave one out — but only
           where there are values to fill. With none, `interpolate()` hands the template back
           untouched, so checking then could only reject a good translation: `50% of your quota
           is used` carries one conversion by PHP's grammar and its German counterpart carries
           none. Hence here rather than at the lookup. */
        if ($args !== [] && self::countPlaceholders($template) !== self::countPlaceholders($messageId)) {
            $template = $messageId;
        }

        return self::interpolate($template, $args);
    }

    /**
     * Fill a template's placeholders with their values
     *
     * Always after the lookup, so a `%` in a filename or a configured bound cannot be read as
     * a conversion specifier.
     *
     * A template with no values is not a format string at all, which matters for a message
     * this library did not write: a validation of your own reporting `50% of your quota is
     * used` has to survive intact. It is not free of conversions — PHP reads `% o` as a space
     * flag and an octal, and `vsprintf()` raises — so the short-circuit is what keeps it
     * whole, not the absence of a `%`.
     *
     * @param array<int,string|int|float> $args
     * @phpstan-param list<string|int|float> $args
     */
    public static function interpolate(string $template, array $args = []): string
    {
        if ($args === []) {
            return $template;
        }

        return self::format($template, $args) ?? $template;
    }

    /**
     * `vsprintf()` that answers null rather than failing, across the supported range
     *
     * An application-supplied catalogue can carry a template the values do not fit: too few
     * placeholders, or a specifier PHP does not know. Both ends of the supported range are
     * handled here so neither reaches a caller, the same reason `Filename::forceValidUtf8()`
     * owns its own guard.
     *
     * @param array<int,string|int|float> $args
     */
    private static function format(string $template, array $args): ?string
    {
        /* Converted rather than suppressed: 7.3 and 7.4 warn and return `false`, which a
           `string` return type turns into a `TypeError` one frame out, while PHP 8 throws.
           One mechanism for both, so neither end of the range puts a fatal on the failure
           path. */
        set_error_handler(static function (int $severity, string $message): bool {
            throw new RuntimeException($message);
        });

        try {
            return vsprintf($template, $args);
        } catch (Throwable $e) {
            return null;
        } finally {
            restore_error_handler();
        }
    }
}
