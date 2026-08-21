<?php

namespace GravityPdf\Upload;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

class FilenameTest extends TestCase
{
    /**
     * The last three cases are the whole reason a second method exists: the filename rules would
     * mangle each of them.
     *
     * @dataProvider provideTextToSanitize
     */
    public function testSanitizeForDisplay(string $input, string $expected): void
    {
        $this->assertSame($expected, Filename::sanitizeForDisplay($input));
    }

    /**
     * @return array<string, string[]>
     */
    public function provideTextToSanitize(): array
    {
        return [
            'an ordinary message is untouched' => [
                'Must be one of: image/png',
                'Must be one of: image/png',
            ],
            'a C0 control becomes a space' => ["a\x07b", 'a b'],
            'a newline becomes a space' => ["a\nb", 'a b'],
            'DEL becomes a space' => ["a\x7Fb", 'a b'],
            /* The half a hand-written [\x00-\x1F\x7F] class misses. U+0085 ends a line for
               anything matching on \R. */
            'a C1 control becomes a space' => ["a\u{0085}b", 'a b'],
            'a run collapses to a single space' => ["a\r\n\tb", 'a b'],
            'leading and trailing controls go entirely' => ["\na\n", 'a'],
            'a bidi override is deleted' => ["a\u{202E}b", 'ab'],
            'a zero-width mark is deleted' => ["a\u{200B}b", 'ab'],
            'the BOM is deleted' => ["a\u{FEFF}b", 'ab'],
            /* sanitizeNameWithExtension() would mangle all three */
            'a dot is not rewritten' => ['user.avatar', 'user.avatar'],
            'a reserved device name is not blanked' => ['con', 'con'],
            'a slash and a percent survive' => ['50% of /var', '50% of /var'],
            /* Neither a control nor a bidi mark, so it is left alone where U+200B above is
               deleted and a tab is collapsed to a single U+0020 */
            'a no-break space survives' => ["a\u{00A0}b", "a\u{00A0}b"],
            'an ideographic space survives' => ["a\u{3000}b", "a\u{3000}b"],
            /* Trimmed at the ends, because trim() is ASCII — the same asymmetry as the name */
            'a no-break space is not trimmed' => ["\u{00A0}a\u{00A0}", "\u{00A0}a\u{00A0}"],
            /* Not cut to a filename's 255 bytes: a message naming a long allow-list is long
               and legitimate. */
            'a long message is not cut to a filename budget' => [str_repeat('a', 300), str_repeat('a', 300)],
            'a very long message is cut to the display bound' => [
                str_repeat('a', 5000),
                str_repeat('a', Filename::MAX_DISPLAY_LENGTH),
            ],
        ];
    }

    /**
     * The one step needing `ext-mbstring` or the polyfill. Without either the byte survives,
     * which is the documented degradation.
     *
     * @dataProvider provideInvalidUtf8ToSanitize
     * @group mbstring
     */
    public function testSanitizeForDisplayDropsInvalidUtf8(string $input, string $expected): void
    {
        $this->assertSame($expected, Filename::sanitizeForDisplay($input));
    }

    /**
     * The repair strips a sequence the end of the string cuts short, and a complete one there
     * is not the same shape. `\xC3\x28` is what gets each of the last three past the
     * valid-UTF-8 early return, so the strip runs and has to leave the tail alone.
     *
     * @return array<string, string[]>
     */
    public function provideInvalidUtf8ToSanitize(): array
    {
        return [
            'an invalid byte is dropped' => ["bad\xC3\x28byte", 'bad(byte'],
            'a complete 2-byte tail survives' => ["bad\xC3\x28ok\u{00E9}", "bad(ok\u{00E9}"],
            'a complete 3-byte tail survives' => ["bad\xC3\x28ok\u{20AC}", "bad(ok\u{20AC}"],
            'a complete 4-byte tail survives' => ["bad\xC3\x28ok\u{1F600}", "bad(ok\u{1F600}"],
        ];
    }

    /**
     * `FileList::describeKey()` passes a tighter bound, since a form field name is not prose.
     */
    public function testSanitizeTextTakesATighterBound(): void
    {
        $this->assertSame('aaaaa', Filename::sanitizeForDisplay(str_repeat('a', 300), 5));
    }

    /**
     * The cut lands on a byte rather than a character, so the repair has to run after it or a
     * split sequence is returned.
     *
     * @group mbstring
     */
    public function testSanitizeTextRepairsASequenceTheBoundSplits(): void
    {
        $value = str_repeat('a', Filename::MAX_DISPLAY_LENGTH - 1) . "\u{00E9}";

        $this->assertSame(
            str_repeat('a', Filename::MAX_DISPLAY_LENGTH - 1),
            Filename::sanitizeForDisplay($value)
        );
    }

    /**
     * @dataProvider provideNamesToSplit
     *
     * @param string[] $expected
     */
    public function testExtensionComponents(string $filename, array $expected): void
    {
        $this->assertSame($expected, Filename::extensionComponents($filename));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function provideNamesToSplit(): array
    {
        return [
            'an ordinary name' => ['evil.php', ['php']],
            'every component after the first' => ['a.php.jpg', ['php', 'jpg']],
            'a name alone is not an extension' => ['php', []],
            'components are lowercased' => ['a.PHP', ['php']],
            'padding is trimmed' => ['a. php ', ['php']],
            'an empty component is dropped' => ['a..php', ['php']],

            /* The regression: the name is empty once trimmed, and was dropped as an empty
               component before the first field was */
            'a name of only spaces' => [' .php', ['php']],
            'a name of only spaces, hidden extension' => [' .php.jpg', ['php', 'jpg']],
            /* Refused for its leading dot before the deny-list runs, but the split is right */
            'a dotfile' => ['.htaccess', ['htaccess']],

            /* trim() is ASCII, so only U+0020 is padding around a component. Anywhere else a
               space is part of the component, and `php` beside one is not `php`. */
            'a no-break space is part of the component' => ["evil.php\u{00A0}", ["php\u{00A0}"]],
            'an ideographic space is part of the component' => ["evil.\u{3000}php", ["\u{3000}php"]],
            'an ASCII space is padding' => ['evil. php ', ['php']],
            /* The first field is dropped whatever it holds, so the extension is still matched */
            'a name of only a no-break space' => ["\u{00A0}.php", ['php']],
        ];
    }

    /**
     * Windows resolves `" con .txt"` to the console because it ignores the spaces around the
     * name — `U+0020`, and no other. `\u{00A0}con` is a file.
     *
     * @dataProvider provideDeviceComponents
     */
    public function testDeviceComponent(string $filename, string $expected, bool $reserved): void
    {
        $this->assertSame($expected, Filename::deviceComponent($filename));
        $this->assertSame($reserved, Filename::isReservedDeviceComponent($expected));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function provideDeviceComponents(): array
    {
        return [
            'a device name' => ['con.txt', 'con', true],
            'ASCII spaces are ignored around it' => [' con .txt', 'con', true],
            'a no-break space before it is not' => ["\u{00A0}con.txt", "\u{00A0}con", false],
            'a no-break space after it is not' => ["con\u{00A0}.txt", "con\u{00A0}", false],
            'an ideographic space before it is not' => ["\u{3000}con.txt", "\u{3000}con", false],
        ];
    }

    /**
     * `ext-mbstring` is `suggest`, and `forceValidUtf8()` calls three functions that do not
     * exist without it. A subprocess, because the only way to ask is an interpreter that lacks
     * them. Never skipped, even if `shell_exec()` is missing: `cross-file-system` runs the suite
     * with `--fail-on-skipped` and relies on there being exactly one skip.
     */
    public function testSanitizeTextSurvivesWithoutMbstring(): void
    {
        $script = sprintf(
            'require %s; echo \GravityPdf\Upload\Filename::sanitizeForDisplay("a\nb");',
            var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true)
        );

        $output = shell_exec(sprintf(
            '%s -d disable_functions=mb_check_encoding,mb_convert_encoding,mb_substitute_character'
            . ' -r %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($script)
        ));

        /* Still sanitized; only the UTF-8 repair is lost, as it is for a filename */
        $this->assertSame('a b', (string) $output);
    }
}
