<?php

namespace GravityPdf\Upload;

use LogicException;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

class TranslationTest extends TestCase
{
    /* phpcs:ignore */
    public function tear_down(): void
    {
        /* Process-wide state: `phpunit.xml`'s backupGlobals does not restore a static */
        Translation::resetTranslator();

        parent::tear_down();
    }

    public function testReturnsTheEnglishSourceWithNoTranslatorInstalled(): void
    {
        $this->assertFalse(Translation::hasTranslator());
        $this->assertSame('No file was uploaded', Translation::render('No file was uploaded'));
    }

    public function testInterpolatesTheEnglishSourceWithNoTranslatorInstalled(): void
    {
        $this->assertSame(
            'File size is too large. Must be no more than 500 bytes',
            Translation::render('File size is too large. Must be no more than %1$s bytes', [500])
        );
    }

    public function testUsesTheInstalledTranslator(): void
    {
        Translation::setTranslator(static function (string $text, string $domain): string {
            return $text === 'No file was uploaded' ? 'Aucun fichier n\'a ete envoye' : $text;
        });

        $this->assertTrue(Translation::hasTranslator());
        $this->assertSame('Aucun fichier n\'a ete envoye', Translation::render('No file was uploaded'));
    }

    public function testInterpolatesIntoTheTranslation(): void
    {
        Translation::setTranslator(static function (string $text, string $domain): string {
            return 'Trop volumineux. Maximum : %1$s';
        });

        $this->assertSame(
            'Trop volumineux. Maximum : 500',
            Translation::render('File size is too large. Must be no more than %1$s bytes', [500])
        );
    }

    /**
     * The values are interpolated after the lookup and never before it, so a `%` in a filename
     * or a configured value cannot be read as a conversion specifier.
     */
    public function testValuesAreNeverReadAsAFormat(): void
    {
        $this->assertSame(
            'File contents do not match the "%d%s" extension. Must be one of: text/plain',
            Translation::render(
                'File contents do not match the "%1$s" extension. Must be one of: %2$s',
                ['%d%s', 'text/plain']
            )
        );
    }

    /**
     * A message with no values is not a format string. A custom validator reporting
     * `50% of your quota is used` has no placeholders and must not be read as having one.
     */
    public function testAMessageWithNoValuesIsNotAFormatString(): void
    {
        $this->assertSame('50% of your quota is used', Translation::render('50% of your quota is used'));
    }

    /**
     * @dataProvider provideUnusableTranslations
     */
    public function testFallsBackToEnglishWhenTheTranslationCannotBeUsed(string $translation): void
    {
        Translation::setTranslator(static function (string $text, string $domain) use ($translation): string {
            return $translation;
        });

        $this->assertSame(
            'File size is too large. Must be no more than 500 bytes',
            Translation::render('File size is too large. Must be no more than %1$s bytes', [500])
        );
    }

    /** @return array<string,array<int,string>> */
    public function provideUnusableTranslations(): array
    {
        return [
            'too few placeholders' => ['Trop volumineux : %1$s et %2$s'],
            'unknown specifier' => ['Trop volumineux : %q'],
        ];
    }

    /**
     * A translator is application code reached on the failure path, which is the worst place
     * to discover it is broken. Everything it does is absorbed.
     */
    public function testAbsorbsAThrowingTranslator(): void
    {
        Translation::setTranslator(static function (string $text, string $domain): string {
            throw new \RuntimeException('the catalogue is missing');
        });

        $this->assertSame('No file was uploaded', Translation::render('No file was uploaded'));
    }

    /**
     * The one place in this library where `LogicException` is not treated as a bug worth
     * surfacing: an untranslated message is a correct outcome, and a rejected upload must not
     * become a fatal error because a catalogue is wrong.
     */
    public function testAbsorbsALogicExceptionFromTheTranslator(): void
    {
        Translation::setTranslator(static function (string $text, string $domain): string {
            throw new LogicException('the developer wired this up wrong');
        });

        $this->assertSame('No file was uploaded', Translation::render('No file was uploaded'));
    }

    /**
     * WordPress runs every lookup through the `gettext` filter, which is any plugin in the
     * process, so the return is not necessarily a string.
     */
    public function testIgnoresATranslatorThatDoesNotReturnAString(): void
    {
        /* @phpstan-ignore-next-line — deliberately the wrong return type */
        Translation::setTranslator(static function (string $text, string $domain) {
            return ['not', 'a', 'string'];
        });

        $this->assertSame('No file was uploaded', Translation::render('No file was uploaded'));
    }

    public function testResetTranslatorRestoresTheEnglishSource(): void
    {
        Translation::setTranslator(static function (string $text, string $domain): string {
            return 'traduit';
        });
        Translation::resetTranslator();

        $this->assertFalse(Translation::hasTranslator());
        $this->assertSame('No file was uploaded', Translation::render('No file was uploaded'));
    }

    public function testPassesTheLibrarysTextDomainToTheTranslator(): void
    {
        Translation::setTranslator(static function (string $text, string $domain): string {
            return $domain . '|' . $text;
        });

        $this->assertSame(
            'gravitypdf-upload|No file was uploaded',
            Translation::render('No file was uploaded')
        );
    }

    /**
     * Caught before the interpolation rather than in it, because this is the last point where
     * the English is still in hand to fall back to. Left to `interpolate()`, the same
     * catalogue would report the message with the number missing from it.
     */
    public function testKeepsTheEnglishWhenATranslationsPlaceholdersDoNotMatch(): void
    {
        Translation::setTranslator(static function (string $text, string $domain): string {
            return 'Trop volumineux';
        });

        $this->assertSame(
            'File size is too large. Must be no more than 500 bytes',
            Translation::render('File size is too large. Must be no more than %1$s bytes', [500])
        );
    }

    /**
     * With nothing to interpolate, a mismatch cannot hurt — the template is returned whole
     * either way. Checking anyway rejected correct translations: `% o` counts as a conversion
     * (`vsprintf('50% of', [])` raises), and a translation that avoids the collision counts
     * none.
     */
    public function testATranslationIsKeptWhenThereIsNothingToInterpolate(): void
    {
        Translation::setTranslator(static function (string $text, string $domain): string {
            return 'Ihr Kontingent ist zu 50 Prozent aufgebraucht';
        });

        $this->assertSame(
            'Ihr Kontingent ist zu 50 Prozent aufgebraucht',
            Translation::render('50% of your quota is used')
        );
    }

    public function testALiteralPercentIsNotCountedAsAPlaceholder(): void
    {
        Translation::setTranslator(static function (string $text, string $domain): string {
            return '100%% traduit';
        });

        $this->assertSame('100%% traduit', Translation::render('100%% translated'));
    }

    public function testInterpolateDoesNotTranslate(): void
    {
        Translation::setTranslator(static function (string $text, string $domain): string {
            return 'Trop volumineux : %1$s';
        });

        $this->assertSame(
            'File size is too large. Must be no more than 500 bytes',
            Translation::interpolate('File size is too large. Must be no more than %1$s bytes', [500])
        );
    }

    public function testInterpolateFallsBackToTheMessageIdWhenTheValuesDoNotFit(): void
    {
        $this->assertSame(
            'Needs %1$s and %2$s',
            Translation::interpolate('Needs %1$s and %2$s', ['one'])
        );
    }

    /**
     * A previously installed error handler has to survive the guard around `vsprintf()`.
     */
    public function testRestoresTheCallersErrorHandler(): void
    {
        $handler = static function (int $severity, string $message): bool {
            return true;
        };

        set_error_handler($handler);

        try {
            Translation::render('Needs %1$s and %2$s', ['one']);

            $this->assertSame($handler, set_error_handler(null));
            restore_error_handler();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * An empty `msgstr` means untranslated in gettext, but not every loader applies the rule.
     * Symfony's `PoFileLoader` reads a partly-translated `.po` into one catalogue entry per
     * msgid, empty ones included, so `trans()` answers `''` for anything not yet done.
     * Rendering that would show a blank message rather than the English.
     */
    public function testAnEmptyTranslationIsTreatedAsNoTranslation(): void
    {
        Translation::setTranslator(static function (string $text, string $domain): string {
            return '';
        });

        $this->assertSame('No file was uploaded', Translation::translate('No file was uploaded'));
        $this->assertSame('No file was uploaded', Translation::render('No file was uploaded'));
    }
}
