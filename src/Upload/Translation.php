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
 * Where a developer plugs in their own translations
 *
 * No translations ship. The English string is the message id, so with no translator installed
 * every message reads as it always has.
 *
 * The callable is given a string and a text domain. It is never given the values that go into
 * the string. Those are interpolated afterwards, so a filename containing `%` cannot be read
 * as a `printf` conversion.
 *
 * Anything the translator throws is swallowed, `LogicException` included. This is the only
 * place in the library that does not re-throw it. A broken catalogue should leave the message
 * in English, not turn a rejected upload into a fatal error. A translator answering with an
 * empty string, or with something that is not a string, is ignored the same way.
 *
 * @since   4.0.0
 *
 * @package Upload
 */
final class Translation
{
    /**
     * The text domain for this library's strings
     *
     * Not configurable. WordPress forbids a variable text domain and no extractor can follow
     * one. Your translator is handed it and may ignore it; the WordPress recipe does, looking
     * the string up in the plugin's own domain instead.
     */
    public const DOMAIN = 'gravitypdf-upload';

    /**
     * @var callable|null The installed translator, or null while none is
     */
    private static $translator;

    /**
     * Install the callable that looks a message id up
     *
     * Process-wide. It is called when a message is rendered, not when it is installed, so a
     * locale switched mid-request still gives the right answer.
     *
     * @param callable $translator `function (string $text, string $domain): string`
     * @phpstan-param callable(string,string):string $translator
     */
    public static function setTranslator(callable $translator): void
    {
        self::$translator = $translator;
    }

    /**
     * Remove the translator, putting every message back to English
     *
     * Call this in `tear_down()` if a test installs one. `backupGlobals` does not cover a
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
     * Look one string up. Nothing is interpolated into it.
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

        /* Translators do not have to return a string. WordPress passes every lookup through
           the `gettext` filter, which is any plugin in the process.

           An empty string counts as no translation, which is what an empty `msgstr` means in
           gettext. Not every loader applies that rule: Symfony's `PoFileLoader` reads a
           partly-translated `.po` into one catalogue entry per msgid, empty ones included, so
           `trans()` answers `''` for anything not yet done. Rendering that would blank the
           message instead of falling back to English. */
        return is_string($translated) && $translated !== '' ? $translated : $text;
    }

    /**
     * Count the `printf` conversions in a template
     *
     * `%%` does not match — the pattern needs a conversion letter. A space is a valid flag, so
     * `% o` in `50% of your quota` does count as one. `vsprintf()` agrees, and `render()` is
     * what stops that mattering.
     */
    private static function countPlaceholders(string $template): int
    {
        return (int) preg_match_all('/%(?:\d+\$)?[-+ 0\x27]*[0-9]*(?:\.[0-9]+)?[bcdeEfFgGosuxX]/', $template);
    }

    /**
     * Translate a message id and fill in its placeholders
     *
     * `File` calls this when rendering `getErrors()`. Each step falls back on its own: an
     * unusable translation reverts to English here, and values that do not fit revert to the
     * template at the interpolation.
     *
     * @param string $messageId The English source string, which is also the gettext msgid
     * @param array<int,string|int|float> $args Values for its `%1$s` placeholders
     * @phpstan-param list<string|int|float> $args
     */
    public static function render(string $messageId, array $args = []): string
    {
        $template = self::translate($messageId);

        /* Only worth checking when there are values to fill. With none, `interpolate()`
           returns the template untouched, so the check could only reject a good translation:
           `50% of your quota is used` counts one conversion by PHP's grammar, and its German
           translation counts none. */
        if ($args !== [] && self::countPlaceholders($template) !== self::countPlaceholders($messageId)) {
            $template = $messageId;
        }

        return self::interpolate($template, $args);
    }

    /**
     * Fill a template's placeholders
     *
     * Always after the lookup, so a `%` in a filename or a size bound is never read as a
     * conversion.
     *
     * With no values the template is returned untouched and never reaches `vsprintf()`, which
     * would raise on a message like `50% of your quota is used`.
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
     * `vsprintf()` that returns null instead of failing, on every supported PHP version
     *
     * The catalogue comes from the application and can hold a template the values do not fit.
     *
     * @param array<int,string|int|float> $args
     */
    private static function format(string $template, array $args): ?string
    {
        /* PHP 7.3 and 7.4 warn and return `false`, which a `string` return type turns into a
           `TypeError` one frame out. PHP 8 throws instead. Converting the warning handles
           both the same way. */
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
