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
        ];
    }

    /**
     * The one step needing `ext-mbstring` or the polyfill. Without either the byte survives,
     * which is the documented degradation.
     *
     * @group mbstring
     */
    public function testSanitizeForDisplayDropsInvalidUtf8(): void
    {
        $this->assertSame('bad(byte', Filename::sanitizeForDisplay("bad\xC3\x28byte"));
    }

    /**
     * `sanitizeNameWithExtension()` would cut this to 255 bytes, which is right for a name and
     * wrong for a sentence.
     */
    public function testSanitizeTextDoesNotTruncate(): void
    {
        $long = str_repeat('a', 300);

        $this->assertSame($long, Filename::sanitizeForDisplay($long));
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
