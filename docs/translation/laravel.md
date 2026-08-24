# Translating with Laravel

The only catalogue that ships is `i18n/upload.pot`, a template with no translations in it.
Messages stay English until you supply your own.

Laravel's JSON language files are keyed by the source string, which is what a msgid is. No key
scheme to invent, no mapping to maintain.

## Wire it up

```php
use GravityPdf\Upload\Translation;

Translation::setTranslator(static function (string $text): string {
    return \__($text);
});
```

`__()` lives in `illuminate/foundation`. Using the Translator standalone, call
`$translator->get($text)` instead.

## Produce the catalogue

`lang/de.json` takes the msgids as keys:

```json
{
    "No file was uploaded": "Es wurde keine Datei hochgeladen",
    "File size is too large. Must be no more than %1$s MB": "Die Datei ist zu groß. Höchstens %1$s MB"
}
```

To list them from the shipped catalogue:

```bash
msgattrib --no-obsolete vendor/gravitypdf/upload/i18n/upload.pot \
    | grep '^msgid "' | sed 's/^msgid //'
```

A missing translation returns the key. The key is the English string, so anything you have not
translated yet shows in English.

## Placeholders

These use `printf` conversions (`%1$s`) rather than Laravel's `:name`, because they come from a
gettext catalogue. Keep them as they are. This library fills the values in after the lookup, so
Laravel never sees them, and `__()`'s `$replace` argument goes unused.
