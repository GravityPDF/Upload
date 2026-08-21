# Translating with WordPress

The only catalogue that ships is `i18n/upload.pot`, a template with no translations in it.
Messages stay English until you supply your own.

## Wire it up

```php
use GravityPdf\Upload\Translation;

Translation::setTranslator(static function (string $text, string $domain): string {
    return \__($text, 'my-plugin');
});
```

## Produce the catalogue

`wp i18n make-pot` skips `vendor/`, so it never sees these strings. Merge the shipped
catalogue into yours instead:

```bash
wp i18n make-pot . languages/my-plugin.pot \
    --merge=vendor/gravitypdf/upload/i18n/upload.pot
```

The msgids are now in your own `.pot`, under your own text domain. Translate and compile it as
you would any of your plugin's own strings:

```bash
msginit --locale=de_DE --input=languages/my-plugin.pot \
    --output=languages/my-plugin-de_DE.po
# translate, then:
msgfmt --check --output-file=languages/my-plugin-de_DE.mo languages/my-plugin-de_DE.po
```

```php
load_plugin_textdomain('my-plugin', false, 'my-plugin/languages');
```

When you update this library, run the merge again to pick up new and reworded messages.

## Plugins hosted on wordpress.org

**None of the above reaches GlotPress.** translate.wordpress.org runs `wp i18n make-pot` over
your source itself. It ignores the `.pot` you ship, and it never reads `vendor/`.

Write the msgids into your own tree instead, as literal `__()` calls under your own domain.
gettext matches on msgid and domain rather than on the file that declared the call, so the
runtime lookup still finds them. WordPress.org does the same for its own projects.

Run this from your plugin root after each update of this library:

```php
<?php

$domain = 'my-plugin';
$pot = 'vendor/gravitypdf/upload/i18n/upload.pot';

preg_match_all('/^msgid "(.+)"$/m', (string) file_get_contents($pot), $matches);

$calls = '';

foreach ($matches[1] as $msgid) {
    $text = var_export(str_replace('\"', '"', $msgid), true);
    $calls .= sprintf("__( %s, '%s' );\n", $text, $domain);
}

file_put_contents(
    'languages/upload-strings.php',
    "<?php\n\n/* Generated. Never include this file. */\n\n" . $calls
);
```

Nothing includes `languages/upload-strings.php` and nothing calls those functions. It exists so
that `make-pot` sees the strings:

```bash
wp i18n make-pot . languages/my-plugin.pot
```
