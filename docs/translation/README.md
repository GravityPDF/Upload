# Translating error messages

No translations ship, and you do not need any: with no translator installed, every message is
the English string it has always been. Install one and `getErrors()` is looked up through it.

```php
use GravityPdf\Upload\Translation;

Translation::setTranslator(static function (string $text, string $domain): string {
    return $myCatalogue->lookup($text);
});
```

The English string **is** the message id — the gettext msgid — so there is nothing to map.

Your callable gets the string and the text domain. It never gets the values that go into the
string; those are interpolated afterwards, so a filename containing `%` cannot be read as a
`printf` conversion. Register it once at bootstrap. It runs when a message is rendered, so a
locale switched mid-request still gives the right answer.

In the source each string is marked, not translated:

```php
use GravityPdf\Upload\ErrorCode;
use GravityPdf\Upload\Exception;

use function GravityPdf\Upload\__;

throw new Exception(
    /* translators: %1$s: the largest accepted size, in megabytes */
    __('File size is too large. Must be no more than %1$s MB'),
    $fileInfo,
    ErrorCode::SIZE_TOO_LARGE,
    [$amount]
);
```

A broken catalogue cannot break an upload. If your translator throws, returns something other
than a string, or returns a template whose placeholders do not match the original, the English
is used instead.

## What is translated, and what is not

`getErrors()`, and nothing else.

`Exception::getMessage()` is always English. That includes everything `Storage\FileSystem`
throws: those messages name the destination, so showing one to whoever submitted the file
[tells them what is in your upload directory](../../README.md#security-notes). They are for your log, and a
log is easier to search when the text does not change with the locale.

To show an exception's message to an end user, rebuild it from `getMessageId()` and
`getMessageArgs()`.

## The catalogue

`i18n/upload.pot` lists every string that gets looked up, with translator comments on the ones
that take values. It is a template only; there are no `.po` or `.mo` files here. Seed your own
catalogue from it, and merge it forward when you update:

```bash
msgmerge --update my-plugin.po vendor/gravitypdf/upload/i18n/upload.pot
```

Or extract from the installed source yourself. One flag names the marker:

```bash
xgettext --language=PHP --keyword=__ --add-comments=translators: \
    -o my-plugin.pot $(find vendor/gravitypdf/upload/src -name '*.php')
```

Rewording a message retires the msgid that translates it, so every such change is listed in
[CHANGELOG.md](../../CHANGELOG.md) and the old entry turns up as obsolete the next time you
`msgmerge`. That is a translation concern rather than a contract: to branch on a failure, use
[`ErrorCode`](../api-reference.md#errorcode) and never the text.

## Using an existing translation library

The hook is a plain `callable` and adds no dependency: this library still requires only
`ext-fileinfo`. It loads no catalogue, picks no locale, and has no plurals — your application
already does all of that.

| | Catalogue | Notes |
|---|---|---|
| [Symfony](symfony.md) | `.po`, read natively | `Translation::DOMAIN` is a valid Symfony domain |
| [Laravel](laravel.md) | `lang/de.json`, keyed by msgid | no key scheme to invent |
| [php-gettext](php-gettext.md) | `.mo` | pure PHP, no `ext-gettext` |
| [WordPress](wordpress.md) | merged into your own `.pot` | not for wordpress.org-hosted plugins |

An untranslated entry falls back to the English, including when the library returns an empty
string for one — Symfony's `PoFileLoader` does, so a partly-translated catalogue would
otherwise blank the message.

Each page carries a working adapter, the commands to build the catalogue, and what to watch
for. They are checked against the real libraries by `composer translator-readme`.

## Stating a size in a locale

`Validation\Size` writes `4.7 MB` with a `.`, because picking another separator needs a locale
this library does not take. Override `scale()` to change it. It chooses the unit as well as
formatting the number, and is called through `static::` so your override runs:

```php
use GravityPdf\Upload\Validation\Size;

class LocalisedSize extends Size
{
    protected static function scale(int $bytes, bool $down): array
    {
        list($amount, $unit) = parent::scale($bytes, $down);

        return [number_format_i18n((float) $amount, 1), $unit];
    }
}
```

Return one of the unit keys `getTooLargeMessages()` and `getTooSmallMessages()` hold — `'B'`,
`'KB'`, `'MB'` or `'GB'` — or override those too. The unit is part of the message, not a value
put into it.

This rarely comes up. `'5M'`, `'500K'` and any binary byte count divide exactly by 1024 and
print as whole numbers, so no separator appears.