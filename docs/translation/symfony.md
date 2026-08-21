# Translating with Symfony

The only catalogue that ships is `i18n/upload.pot`, a template with no translations in it.
Messages stay English until you supply your own.

Symfony reads `.po` files natively, so the shipped catalogue needs no conversion.

## Wire it up

```php
use GravityPdf\Upload\Translation;

Translation::setTranslator(
    static function (string $text, string $domain) use ($translator): string {
        return $translator->trans($text, [], $domain);
    }
);
```

`Translation::DOMAIN` is `gravitypdf-upload`, which is also a valid Symfony domain. Pass it
through and Symfony finds `translations/gravitypdf-upload.<locale>.po` with no configuration.

To keep these strings in your application's own domain instead, drop the argument and call
`$translator->trans($text)`.

## Produce the catalogue

```bash
msginit --locale=de --input=vendor/gravitypdf/upload/i18n/upload.pot \
    --output=translations/gravitypdf-upload.de.po
```

Translate it. The framework bundle registers the `po` loader for you. Outside the framework,
register it yourself:

```php
use GravityPdf\Upload\Translation;
use Symfony\Component\Translation\Loader\PoFileLoader;

$translator->addLoader('po', new PoFileLoader());
$translator->addResource('po', 'translations/gravitypdf-upload.de.po', 'de', Translation::DOMAIN);
```

When you update this library, bring the catalogue forward:

```bash
msgmerge --update translations/gravitypdf-upload.de.po \
    vendor/gravitypdf/upload/i18n/upload.pot
```

## Untranslated entries come back empty

`PoFileLoader` loads every msgid, including those with an empty `msgstr`, so `trans()` answers
`''` for anything you have not translated yet. Gettext would return the original instead.

This library treats an empty result as no translation and falls back to English, so you can
leave untranslated entries in the `.po`.
