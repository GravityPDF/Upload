<?php

/**
 * Runs the README's translator adapters against the libraries they document
 *
 * The README tells a caller to write these closures, so they have to keep working. Every
 * snippet is read out of the documentation at run time rather than copied here: reword one
 * and the extraction fails by name.
 *
 * Each adapter is checked for the same three things:
 *
 * 1. a translated msgid comes back translated;
 * 2. an untranslated one falls back to English rather than to an empty string. Symfony's
 *    `PoFileLoader` loads a partly-translated `.po` as one entry per msgid, empty ones
 *    included, so `trans()` answers `''` for anything not yet done. That blanked the message
 *    until `Translation::translate()` began treating an empty result as no translation;
 * 3. a value still interpolates, since the lookup and the interpolation are separate steps.
 *
 * The catalogue is built by running the commands the pages tell a reader to run — `msginit`
 * to start a `.po` from the shipped `.pot`, `msgfmt` to compile it, `msgmerge` to bring it
 * forward — so the documented pipeline is exercised end to end rather than approximated in
 * PHP. That needs GNU gettext on PATH.
 *
 * @package Upload
 */

declare(strict_types=1);

/* phpcs:disable PSR1.Files.SideEffects -- a script, not a unit: it declares a few helpers and
   then runs, which is the same shape tools/psr7-readme/verify.php has. */

/* illuminate/support 8.x is the newest that runs on PHP 7.3, and it trips implicit-nullable
   deprecations on 8.4+. Ours are covered by the test suite across the whole matrix. */
error_reporting(E_ALL & ~E_DEPRECATED);

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/global-underscore.php';
require __DIR__ . '/marker-import.php';

use GravityPdf\Upload\Translation;

$root = dirname(__DIR__, 2);
$sources = [$root . '/README.md'];

foreach ((array) glob($root . '/docs/translation/*.md') as $page) {
    $sources[] = (string) $page;
}

$blocks = [];

foreach ($sources as $source) {
    preg_match_all('/```php\n(.*?)```/s', (string) file_get_contents($source), $matches);

    $blocks = array_merge($blocks, $matches[1]);
}

/**
 * The documented snippet containing $needle, or a fatal error naming what went missing
 *
 * @param string[] $blocks
 */
function documentedSnippet(array $blocks, string $needle, string $describes): string
{
    foreach ($blocks as $block) {
        if (strpos($block, $needle) !== false) {
            return $block;
        }
    }

    fwrite(STDERR, sprintf(
        "The %s adapter is no longer in the documentation (looked for \"%s\").\n"
        . "These are documented code: update this check alongside them.\n",
        $describes,
        $needle
    ));

    exit(1);
}

/* One string translated and the rest left with an empty msgstr, which is what a catalogue
   looks like part-way through being translated. */
$translated = 'No file was uploaded';
$german = 'Es wurde keine Datei hochgeladen';
$untranslated = 'Unknown error';
$withValue = 'File size is too large. Must be no more than %1$s MB';

$fixtures = __DIR__ . '/fixtures';

if (!is_dir($fixtures) && !mkdir($fixtures, 0755, true)) {
    fwrite(STDERR, "Could not create the fixtures directory.\n");
    exit(1);
}

$po = $fixtures . '/gravitypdf-upload.de.po';
$mo = $fixtures . '/gravitypdf-upload.de.mo';
$pot = $root . '/i18n/upload.pot';

/** Run one of the documented commands, or fail naming it */
function documentedCommand(string $description, string $command): void
{
    exec($command . ' 2>&1', $output, $status);

    if ($status !== 0) {
        fwrite(STDERR, sprintf(
            "The documented %s command failed:\n%s\n%s\n",
            $description,
            $command,
            implode("\n", $output)
        ));
        exit(1);
    }
}

/* The pipeline every page documents: start a catalogue from the shipped template, translate,
   compile. `msgfmt --check` is part of it — it rejects a translation whose conversions do not
   match the original, which is what the pages tell a reader it is for. */
documentedCommand('msginit', sprintf(
    'msginit --no-translator --locale=de --input=%s --output=%s',
    escapeshellarg($pot),
    escapeshellarg($po)
));

$catalogue = (string) file_get_contents($po);

if (strpos($catalogue, 'msgid "' . $translated . '"') === false) {
    fwrite(STDERR, "msginit produced a catalogue without \"$translated\" in it.\n");
    exit(1);
}

/* A translator would do this in a PO editor. */
file_put_contents($po, str_replace(
    'msgid "' . $translated . '"' . "\nmsgstr \"\"",
    'msgid "' . $translated . '"' . "\nmsgstr \"" . $german . "\"",
    $catalogue
));

documentedCommand('msgfmt', sprintf(
    'msgfmt --check --output-file=%s %s',
    escapeshellarg($mo),
    escapeshellarg($po)
));

/* The update path the pages document, run against the catalogue it would be run against. */
documentedCommand('msgmerge', sprintf(
    'msgmerge --quiet --update %s %s',
    escapeshellarg($po),
    escapeshellarg($pot)
));

/* Laravel keys its JSON by the source string, which the pages show being read out of the
   catalogue. `msgattrib --translated` leaves only entries that have one. */
exec(sprintf('msgattrib --translated %s', escapeshellarg($po)), $translatedOnly, $status);

if ($status !== 0) {
    fwrite(STDERR, "msgattrib failed.\n");
    exit(1);
}

if (strpos(implode("\n", $translatedOnly), $german) === false) {
    fwrite(STDERR, "msgattrib --translated dropped the entry that was translated.\n");
    exit(1);
}

file_put_contents(
    $fixtures . '/de.json',
    (string) json_encode([$translated => $german], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

$failures = [];

/**
 * Assert the three properties every adapter has to hold
 *
 * @param string[] $failures
 */
function check(
    array &$failures,
    string $library,
    string $translated,
    string $german,
    string $untranslated
): void {
    $rendered = Translation::render($translated);

    if ($rendered !== $german) {
        $failures[] = sprintf(
            '%s: a translated msgid rendered as "%s", expected "%s"',
            $library,
            $rendered,
            $german
        );
    }

    $fallback = Translation::render($untranslated);

    if ($fallback !== $untranslated) {
        $failures[] = sprintf(
            '%s: an untranslated msgid rendered as "%s", expected the English to be kept',
            $library,
            $fallback
        );
    }

    $filled = Translation::render('File size is too large. Must be no more than %1$s MB', ['5']);

    if (strpos($filled, '5 MB') === false) {
        $failures[] = sprintf('%s: a value did not interpolate; got "%s"', $library, $filled);
    }
}

/* ------------------------------------------------------------------ Symfony */

$symfony = new Symfony\Component\Translation\Translator('de');
$symfony->addLoader('po', new Symfony\Component\Translation\Loader\PoFileLoader());
$symfony->addResource('po', $fixtures . '/gravitypdf-upload.de.po', 'de', Translation::DOMAIN);

$translator = $symfony;

eval(documentedSnippet($blocks, '$translator->trans($text, [], $domain)', 'Symfony'));
check($failures, 'Symfony', $translated, $german, $untranslated);

Translation::resetTranslator();

/* ------------------------------------------------------------------ Laravel */

$laravel = new Illuminate\Translation\Translator(
    new Illuminate\Translation\FileLoader(new Illuminate\Filesystem\Filesystem(), $fixtures),
    'de'
);

eval(documentedSnippet($blocks, 'return \\__($text);', 'Laravel'));
check($failures, 'Laravel', $translated, $german, $untranslated);

Translation::resetTranslator();

/* --------------------------------------------------------------- php-gettext */

$moFixture = $fixtures . '/gravitypdf-upload.de.mo';
$snippet = documentedSnippet($blocks, '$gettext->gettext($text)', 'php-gettext');

eval(str_replace("'es.mo'", var_export($moFixture, true), $snippet));
check($failures, 'php-gettext', $translated, $german, $untranslated);

Translation::resetTranslator();

/* ------------------------------------- the WordPress recipe's leading backslash */

if (\GravityPdf\Upload\ReadmeCheck\markerIsImported() !== true) {
    $failures[] = 'The fixture that proves the backslash matters no longer imports the marker, '
        . 'so it proves nothing';
}

if (\GravityPdf\Upload\ReadmeCheck\globalUnderscoreWins() !== 'WORDPRESS') {
    $failures[] = 'WordPress: `\\__()` reached this library\'s marker instead of the global one, '
        . 'so a translator written the documented way would silently do nothing';
}

if ($failures !== []) {
    fwrite(
        STDERR,
        "The documented translator adapters no longer behave as documented:\n\n"
        . implode("\n", $failures) . "\n"
    );

    exit(1);
}

echo "Translator adapters verified against symfony/translation, illuminate/translation "
    . "and php-gettext\n";
