# Translating with php-gettext

The only catalogue that ships is `i18n/upload.pot`, a template with no translations in it.
Messages stay English until you supply your own.

[php-gettext/Translator](https://github.com/php-gettext/Translator) is a gettext runtime in
pure PHP. It needs neither `ext-gettext` nor `setlocale()`, and reads a `.mo` compiled straight
from the shipped catalogue.

## Wire it up

```php
use GravityPdf\Upload\Translation;
use Gettext\Loader\MoLoader;
use Gettext\Translator;

$translations = (new MoLoader())->loadFile('es.mo');

/* Uncomment to keep these strings in their own domain rather than merging them into your
   catalogue, and return dgettext($domain, $text) below instead. */
// $translations->setDomain(Translation::DOMAIN);

$gettext = Translator::createFromTranslations($translations);

Translation::setTranslator(static function (string $text, string $domain) use ($gettext): string {
    /* Returns the original on a miss, so untranslated strings stay English */
    return $gettext->gettext($text);
});
```

## Produce the catalogue

```bash
msginit --locale=de --input=vendor/gravitypdf/upload/i18n/upload.pot \
    --output=de.po
# translate, then:
msgfmt --check --output-file=de.mo de.po
```

Keep `--check`. It rejects a translation whose `printf` conversions do not match the original.
Without it the mismatch reaches runtime, where this library falls back to English.

Without the gettext binaries, `gettext/gettext` does the same from PHP. It is a separate
package from the `gettext/translator` runtime above, and you only need it to build `.mo` files:

```php
use Gettext\Generator\MoGenerator;
use Gettext\Loader\PoLoader;

$catalogue = (new PoLoader())->loadFile('vendor/gravitypdf/upload/i18n/upload.pot');
// translate entries, then:
(new MoGenerator())->generateFile($catalogue, 'de.mo');
```
