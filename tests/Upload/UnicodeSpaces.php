<?php

namespace GravityPdf\Upload;

/**
 * The space characters that are not `U+0020`, for the tests that pin what happens to one
 *
 * Two layers treat a space as significant — `trim()` at the ends of a name and of a deny-list
 * component, `rtrim($filename, " .")` in `Storage\FileSystem::resolveFilename()` — and both are
 * ASCII-only. Nothing here is trimmed by either, so a name is stored carrying the character and
 * a deny-list component is matched carrying it. That is the answer these tests record, in one
 * place, because it has to hold at both layers or they disagree about what a filename is.
 *
 * `U+200B` is deliberately absent: it is a zero-width mark, so it is in
 * `Filename::BIDI_CONTROLS` and is deleted from a name and refused at the write. The tests pin
 * that separately.
 *
 * Not a test case, so nothing autoloads it — `tests/bootstrap.php` requires it.
 */
final class UnicodeSpaces
{
    /**
     * One row per character, keyed by the name a failure should print
     *
     * `U+2000` and `U+200A` are the ends of the general-punctuation space block, which
     * `Filename::BIDI_CONTROLS` matches from `U+200B`; `U+202F` and `U+205F` are the two that
     * sit just outside its other byte ranges. A change to those ranges shows up here.
     *
     * @return array<string, array<int, string>>
     */
    public static function providerRows(): array
    {
        return [
            'U+00A0 no-break space' => ["\u{00A0}"],
            'U+1680 ogham space mark' => ["\u{1680}"],
            'U+180E mongolian vowel separator' => ["\u{180E}"],
            'U+2000 en quad' => ["\u{2000}"],
            'U+2009 thin space' => ["\u{2009}"],
            'U+200A hair space' => ["\u{200A}"],
            'U+202F narrow no-break space' => ["\u{202F}"],
            'U+205F medium mathematical space' => ["\u{205F}"],
            'U+3000 ideographic space' => ["\u{3000}"],
        ];
    }
}
