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

/**
 * What this library considers a usable filename
 *
 * One home for the rules, because two layers apply them to different ends. `FileInfo`
 * **rewrites** a name it is given: sanitizing a client-supplied string is its job. `Storage`
 * **refuses** a name that still breaks a rule, because a `FileInfoInterface` is a public
 * extension point and inventing a filename is not storage's job. Both read the rules from here
 * so they cannot disagree about what the rules are, as they did before 4.0.0 when each layer
 * carried its own control-character filter and each had to be fixed separately.
 *
 * `sanitizeForDisplay()` is a third use, for strings that are prose rather than names. It shares the
 * two character sets and nothing else.
 *
 * @package Upload
 * @since   4.0.0
 */
final class Filename
{
    /**
     * The longest a stored filename may be, in bytes, name and extension together
     */
    public const MAX_LENGTH = 255;

    /**
     * The longest extension `FileInfo::setExtension()` will keep, in bytes
     *
     * Nothing else bounds an alphanumeric extension, so without a cap it can spend the whole
     * `MAX_LENGTH` budget and leave the name past the limit. The cap floors the name's budget
     * at 222 bytes, which is what stops truncation manufacturing a reserved device name.
     */
    public const MAX_EXTENSION_LENGTH = 32;

    /**
     * What a name is called when sanitizing leaves nothing of it
     */
    public const FALLBACK = 'unnamed-file';

    /**
     * The characters that carry no glyph and let a name forge a log line or drive a terminal
     *
     * C0, DEL, and C1 as its UTF-8 encoding — U+0085 ends a line for anything matching on `\R`,
     * and U+009B is the CSI introducer. A bare fragment rather than a full pattern, so it
     * composes into the alternation in `rewrite()` and is usable on its own.
     */
    public const CONTROL_CHARACTERS = '[\x00-\x1F\x7F]|\xC2[\x80-\x9F]';

    /**
     * The characters that reorder, break or hide the text around them
     *
     * Unicode's `Bidi_Control` property plus the zero-width marks, the line and paragraph
     * separators, U+206A–U+206F and the BOM. They carry no visual content, so
     * `resume\u{202E}gpj.exe` reads as `resumeexe.jpg` while the stored file is the executable.
     *
     * Matched as UTF-8 bytes rather than with the `u` modifier, because a name is not known to
     * be valid UTF-8 until `forceValidUtf8()` has run over it.
     */
    public const BIDI_CONTROLS = "\xD8\x9C|\xE2\x80[\x8B-\x8F\xA8-\xAE]|\xE2\x81[\xA6-\xAF]|\xEF\xBB\xBF";

    /**
     * Names Windows resolves to a device rather than a file, lowercased
     *
     * Opening one of these talks to the console or a port instead of creating a file, whatever
     * extension follows — `con.txt` is `con`. `COM0` and `LPT0` are on Microsoft's list
     * alongside `COM1`–`COM9`, as are the three superscript digits that exist in Latin-1.
     *
     * @var string[]
     *
     * @link https://learn.microsoft.com/en-us/windows/win32/fileio/naming-a-file
     */
    public const RESERVED_WINDOWS_NAMES = [
        'con', 'prn', 'aux', 'nul',
        'com0', 'com1', 'com2', 'com3', 'com4', 'com5', 'com6', 'com7', 'com8', 'com9',
        "com\u{00B9}", "com\u{00B2}", "com\u{00B3}",
        'lpt0', 'lpt1', 'lpt2', 'lpt3', 'lpt4', 'lpt5', 'lpt6', 'lpt7', 'lpt8', 'lpt9',
        "lpt\u{00B9}", "lpt\u{00B2}", "lpt\u{00B3}",
    ];

    /**
     * Rewrite a client-supplied name into one that is safe to store, then fit it to the budget
     *
     * @param string $extension The extension the name shares its byte budget with
     * @param string[]|null $reserved Device names to blank, or null for the list above
     * @return string Never empty: `FALLBACK` when nothing usable survives
     *
     * @internal Not part of the public API
     */
    public static function sanitizeName(string $name, string $extension = '', ?array $reserved = null): string
    {
        return self::finalize(self::rewriteCharacters($name), $extension, $reserved);
    }

    /**
     * Sanitize a whole filename, name and extension together
     *
     * The two halves answer to different rules — the name is rewritten, the extension is
     * validated and discarded whole — so a caller holding one string has to split it the same
     * way `FileInfo` does, or `report.txt` comes back as `report-txt` with the dot rewritten.
     *
     * @param string[]|null $reserved
     */
    public static function sanitizeNameWithExtension(string $filename, ?array $reserved = null): string
    {
        $extension = self::acceptExtension((string) pathinfo($filename, PATHINFO_EXTENSION), $reserved);
        $name = self::sanitizeName((string) pathinfo($filename, PATHINFO_FILENAME), $extension, $reserved);

        return $extension === '' ? $name : sprintf('%s.%s', $name, $extension);
    }

    /**
     * Make a string safe to render as one line of prose
     *
     * **Not escaping.** `<`, `>`, `&` and `"` pass through, so the result still needs
     * `htmlspecialchars()` where it lands. What goes is what misrepresents the text around it
     * wherever it is read, escaped or not: a log line, a terminal, an email.
     *
     * The other flavour of sanitizing. `sanitizeName()` and friends answer to what a *filename*
     * may be — a byte budget, device names, `%` and `/` rewritten — which would turn
     * `Must be one of: image/png` into `Must be one of- image-png`. So controls collapse to a
     * space rather than `-`, nothing bounds the length, and UTF-8 is forced for the reason
     * `finalize()` forces it, needing `ext-mbstring` the same way.
     */
    public static function sanitizeForDisplay(string $value): string
    {
        $value = (string) preg_replace('/' . self::BIDI_CONTROLS . '/', '', $value);
        $value = (string) preg_replace('/(?:' . self::CONTROL_CHARACTERS . ')+/', ' ', $value);

        return trim(self::forceValidUtf8($value));
    }

    /**
     * The character rewriting half, with no truncation and no device-name handling. May return ''
     *
     * Separate because `FileInfo::setExtension()` has to redo the *fitting* against a new
     * extension without re-running any of this.
     *
     * The numbered markers below key to the standards each class comes from:
     *
     * @internal 1. file system reserved https://en.wikipedia.org/wiki/Filename#Reserved_characters_and_words
     * phpcs:ignore
     * @internal 2. control characters http://msdn.microsoft.com/en-us/library/windows/desktop/aa365247%28v=vs.85%29.aspx
     * @internal 3. URI reserved https://www.rfc-editor.org/rfc/rfc3986#section-2.2
     * @internal 4. URL unsafe characters https://www.ietf.org/rfc/rfc1738.txt
     */
    private static function rewriteCharacters(string $name): string
    {
        /* Deleted rather than rewritten: these carry no visual content of their own, so
           replacing them with a hyphen would invent a character the name never had. */
        $name = (string) preg_replace('/' . self::BIDI_CONTROLS . '/', '', $name);

        $name = str_replace(['%20', '+', '.'], '-', $name);
        $name = (string) preg_replace('/[\r\n\t-]+/', '-', $name);
        $name = (string) preg_replace(
            '~
        [%<>:"/\\\|?*]|          # @internal 1.
        ' . self::CONTROL_CHARACTERS . '|  # @internal 2.
        [#\[\]@!$&\'()+,;=]|    # @internal 3.
        [{}^\~`]                # @internal 4.
        ~x',
            '-',
            $name
        );

        // collapse runs: "file - -name.zip" becomes "file-name.zip"
        $name = (string) preg_replace(
            ['/ +/', '/_+/', '/ - -+/', '/-+/'],
            [' ', '_', '-', '-'],
            $name
        );

        return trim((string)$name, '.-_ ');
    }

    /**
     * Fit an already-rewritten name to its budget and settle what is left
     *
     * @param string[]|null $reserved
     *
     * @internal Not part of the public API
     */
    public static function finalize(string $name, string $extension = '', ?array $reserved = null): string
    {
        $maxLength = self::maxNameLength($extension);
        /* `function_exists()`, not `extension_loaded('mbstring')`: `symfony/polyfill-mbstring`
           defines these in userland and registers no extension, so `extension_loaded()` is
           false for it and every polyfilled install would silently take the `substr()` branch
           and skip the UTF-8 repair. Only the two this truncation calls; `forceValidUtf8()`
           checks the three it needs for itself. */
        $multibyte = function_exists('mb_strcut') && function_exists('mb_detect_encoding');

        /* Only when there is something to cut: the detect-and-cut pair is not free. */
        if (strlen($name) > $maxLength && $multibyte) {
            /* Explicit detect order, not the ambient `mb_detect_order()`, so an application
               calling that cannot change what this library makes of the same bytes. The
               `?: 'UTF-8'` is belt and braces: detection only returns an encoding
               `mb_strcut()` already accepts. */
            $encoding = mb_detect_encoding($name, ['ASCII', 'UTF-8']);
            $name = mb_strcut($name, 0, $maxLength, $encoding !== false ? $encoding : 'UTF-8');
        } elseif (strlen($name) > $maxLength) {
            $name = substr($name, 0, $maxLength);
        }

        /* For a name that arrived invalid, not for one this class broke: `rewrite()` is
           single-byte ASCII except `\xC2[\x80-\x9F]`, which matches both bytes as a unit, and
           `mb_strcut()` cuts on a character boundary. Unconditional, because `substr()` above
           can split a sequence and repairing that is worth doing on a build where `mb_strcut()`
           is missing but the three functions this needs are not. */
        $name = self::forceValidUtf8($name);

        /* After forceValidUtf8(), not before it: dropping an invalid byte can produce a name
           that was not reserved when the bytes arrived, and `con\xC3.txt` is not `con` until
           that trailing byte goes. Truncation cannot manufacture one, because the budget never
           falls below 222 bytes. */
        if (self::isReservedDeviceComponent($name, $reserved)) {
            $name = '';
        }

        /* Not empty(): a file legitimately called `0` is not an unnamed one */
        return $name === '' ? self::FALLBACK : $name;
    }

    /**
     * How many bytes a name may use once its extension has taken its share
     */
    private static function maxNameLength(string $extension): int
    {
        return self::MAX_LENGTH - ($extension !== '' ? strlen($extension) + 1 : 0);
    }

    /**
     * The extension this library will keep, or `''` for one it will not
     *
     * Validates rather than rewrites. Stripping the disallowed characters could produce an
     * extension the client never sent: `avatar.p-h-p` became `avatar.php`, defeating both a
     * caller's own deny-list on the raw filename and any web server configured to execute
     * `.php`. A file stored with no extension is not executed, so discarding is the safe
     * outcome.
     *
     * @param string[]|null $reserved
     */
    public static function acceptExtension(string $extension, ?array $reserved = null): string
    {
        $extension = AsciiCase::toLower(trim($extension));

        /* `D`, so that `$` cannot be satisfied by a trailing newline */
        if (preg_match('/^[a-z0-9]*$/D', $extension) !== 1) {
            return '';
        }

        if (strlen($extension) > self::MAX_EXTENSION_LENGTH) {
            return '';
        }

        /* The rule is about the whole value, not a substring */
        if (self::isReservedDeviceComponent($extension, $reserved)) {
            return '';
        }

        return $extension;
    }

    /**
     * The component that decides whether a filename names a Windows device
     *
     * Windows ignores spaces around a device name and everything from the first dot onward, so
     * `" con .txt"` is `con`. Both layers ask this rather than splitting for themselves, so
     * they cannot disagree about where the first component ends.
     */
    public static function deviceComponent(string $filename): string
    {
        return trim(AsciiCase::toLower(explode('.', $filename, 2)[0]), ' ');
    }

    /**
     * Every dot-separated component of a value, lowercased and trimmed, empties dropped
     *
     * A deny-list entry is split with this and a filename with `extensionComponents()`, which
     * drops the first. The two must agree on what a component is, or an entry like `tar.gz`
     * is accepted and then matches nothing.
     *
     * @return string[]
     *
     * @internal Not part of the public API
     */
    public static function normalizeComponents(string $value): array
    {
        $components = [];

        foreach (explode('.', AsciiCase::toLower($value)) as $component) {
            $component = trim($component);

            if ($component !== '') {
                $components[] = $component;
            }
        }

        return $components;
    }

    /**
     * The dot-separated components a deny-list is matched against, lowercased
     *
     * The first is dropped: it is the name itself, and a file called `php` is not a file that
     * runs as PHP. Every one after it is checked, not just the last, because a web server does
     * not necessarily read the name the way `pathinfo()` does — Apache's `AddHandler` matches
     * any dot component, so `evil.php.jpg` is served as PHP where `.php` is mapped.
     *
     * @return string[]
     */
    public static function extensionComponents(string $filename): array
    {
        return array_slice(self::normalizeComponents($filename), 1);
    }

    /**
     * @param string[]|null $reserved
     */
    public static function isReservedDeviceComponent(string $value, ?array $reserved = null): bool
    {
        return in_array(
            AsciiCase::toLower($value),
            $reserved === null ? self::RESERVED_WINDOWS_NAMES : $reserved,
            true
        );
    }

    public static function hasControlCharacters(string $value): bool
    {
        return preg_match('/' . self::CONTROL_CHARACTERS . '/', $value) === 1;
    }

    public static function hasBidiControls(string $value): bool
    {
        return preg_match('/' . self::BIDI_CONTROLS . '/', $value) === 1;
    }

    /**
     * Drop any byte sequence that is not valid UTF-8
     *
     * A client can send a filename that is not valid UTF-8 to begin with, and one that reaches
     * storage makes `json_encode()` return `false` and breaks inserts into `utf8mb4` columns.
     *
     * The guard is here rather than in each caller because `ext-mbstring` is `suggest` and every
     * call below is fatal without it. It sat in `finalize()` alone until a second caller reused
     * the function and not the precondition, and a rejected upload became a fatal error. Returns
     * the input unchanged instead, losing only the UTF-8 guarantee, as `README.md` says.
     */
    private static function forceValidUtf8(string $name): string
    {
        if (
            !function_exists('mb_check_encoding')
            || !function_exists('mb_convert_encoding')
            || !function_exists('mb_substitute_character')
        ) {
            return $name;
        }

        if (mb_check_encoding($name, 'UTF-8')) {
            return $name;
        }

        /* 'none' drops the offending bytes instead of replacing them with a substitute char */
        $substitute = mb_substitute_character();
        mb_substitute_character('none');
        /* Silenced for `symfony/polyfill-mbstring`, which implements this over `iconv()` and
           warns on exactly the input this repairs. Same result as the extension, only noisier,
           and a client-supplied name must not raise a warning. */
        $name = (string)@mb_convert_encoding($name, 'UTF-8', 'UTF-8');
        mb_substitute_character($substitute);

        return $name;
    }
}
