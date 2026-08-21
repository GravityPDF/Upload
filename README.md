# Upload

[![codecov](https://codecov.io/gh/GravityPDF/Upload/branch/main/graph/badge.svg)](https://codecov.io/gh/GravityPDF/Upload)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](https://opensource.org/licenses/MIT)

A PHP library to validate and save uploaded files.

**Why was this library forked?**

* Original library was abandoned (untouched since 2018)
* Safe defaults: existing files are never overwritten, executable and markup extensions are
  never written, stored files get mode `0640`, and `upload()` requires validation
* `Validation\FileType` requires the extension and mimetype to match
* Sanitizes filenames and extensions, with UTF-8 filename support
* Stops path traversal, symlinked destinations, dotfiles and control characters in
  filenames, and writes through a staged file moved into place rather than writing to the
  destination directly
* Strict type checking
* Added `FileSystem::getDirectory()` and `FileInfo::setNameWithExtension()` methods
* Included unreleased code from upstream repo
* Bumped minimum PHP version to 7.3+
* PSR-12 Code Formatting
* Automated tools: PHPUnit, PHPStan, PHPCS, and PHP Syntax Checker

## Installation

```
composer require gravitypdf/upload
```

### Requirements

PHP 7.3 to 8.5 and `ext-fileinfo`. Optional but recommended: `ext-mbstring` (or `symfony/polyfill-mbstring`) so filenames can be guaranteed UTF-8.

Migrating from `codeguy/upload`? Version 3.x of this package is a drop-in replacement:
update your imports from `\Upload\…` to `\GravityPdf\Upload\…`.

Upgrading from 3.x? Version 4.0 turns new protections on by default. The
[upgrade guide](https://github.com/GravityPDF/Upload/blob/main/UPGRADE.md) covers what
changed and what to check.

## Usage

### Single-file upload

Assume a file is uploaded with this HTML form:

```html
<form method="POST" enctype="multipart/form-data">
    <input type="file" name="avatar"/>
    <input type="submit" value="Upload File"/>
</form>
```

Server-side, validate the upload, rename it, and store it:

```php
use GravityPdf\Upload\File;
use GravityPdf\Upload\Storage\FileSystem;
use GravityPdf\Upload\Validation\FileType;
use GravityPdf\Upload\Validation\Size;

// Store uploads where the web server will not execute or serve them directly
$storage = new FileSystem('/path/to/uploads');

// Reads $_FILES['avatar']
$file = new File('avatar', $storage);

// upload() refuses to run unless at least one validation is added
$file->addValidations([
    new FileType('png', 'image/png'), // extension and file contents must both say PNG
    new Size('2M'),                   // max 2 MiB ("B", "K", "M" or "G")
]);

// isValid() also checks is_uploaded_file(), so call it before reading any metadata
if ($file->isValid() === false) {
    foreach ($file->getErrors() as $message) {
        echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'), '<br>'; // always escape on output
    }

    return;
}

// Store under a random name; keep the client's (sanitized) name for display only
$displayName = $file->getNameWithExtension();
$file->setName(bin2hex(random_bytes(16)));

try {
    $file->upload();

    $storedPath = $file->getUploadedLocators()[0];
} catch (\Exception $e) {
    // Validation has already passed, so this is a storage failure: the destination
    // exists, the extension is blocked, or the disk is full
}
```

`FileType` accepts any registered
[IANA media type](https://www.iana.org/assignments/media-types/media-types.xhtml), such as
`image/png` or `application/pdf`.

### Reading file metadata

```php
$data = [
    'name'       => $file->getNameWithExtension(), // sanitized client name; display only
    'extension'  => $file->getExtension(),
    'mime'       => $file->getMimetype(),          // sniffed from the contents, not the client's claim
    'size'       => $file->getSize(),              // bytes, or false if the file is unreadable
    'hash'       => $file->getHash(),              // sha256 unless you pass another algorithm
    'dimensions' => $file->getDimensions(),        // ['width' => int, 'height' => int]
];
```

These calls are forwarded to every file in the collection. With one file you get the value
back, with several you get an array of values, and with none you get `null`. When a field
accepts multiple files, read metadata per file instead.

### Multi-file upload

```html
<form method="POST" enctype="multipart/form-data">
    <input type="file" name="photos[]" multiple/>
    <input type="submit" value="Upload Files"/>
</form>
```

The `$_FILES` key drops the brackets: `new File('photos', $storage)`. `File` acts as a
collection of the individual files, so `count()`, `foreach` and array offsets all work. A
file that failed to transfer (too large, nothing selected) is left out, and its error
message is already in `getErrors()`.

```php
use GravityPdf\Upload\File;
use GravityPdf\Upload\Storage\FileSystem;
use GravityPdf\Upload\Validation\FileType;
use GravityPdf\Upload\Validation\Size;

$storage = new FileSystem('/path/to/uploads');
$file = new File('photos', $storage);

$file->addValidations([
    // One format per call, otherwise every extension is paired with every media type
    (new FileType(['jpg', 'jpeg'], 'image/jpeg'))
        ->allow('png', 'image/png')
        ->allow('webp', 'image/webp'),
    new Size('10M'),
]);

// An empty collection has nothing to fail validation, so check the count as well
if (count($file) === 0 || $file->isValid() === false) {
    foreach ($file->getErrors() as $message) {
        echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'), '<br>';
    }

    return;
}

// Rename each file server-side, keeping the client names for display
$manifest = [];
foreach ($file as $photo) {
    $displayName = $photo->getNameWithExtension();
    $photo->setName(bin2hex(random_bytes(16)));

    $manifest[] = [
        'display' => $displayName,
        'stored'  => $photo->getNameWithExtension(),
        'hash'    => $photo->getHash(),
    ];
}

try {
    $file->upload();
} catch (\Exception $e) {
    // Multi-file uploads are not atomic: earlier files may already be on disk.
    // getUploadedLocators() lists what was written so the batch can be rolled back.
    // unlink() is right for Storage\FileSystem, which returns local paths. A storage
    // of your own returns whatever locator it defines, so undo it the way it stores.
    foreach ($file->getUploadedLocators() as $uploadedPath) {
        unlink($uploadedPath);
    }

    return;
}
```

Individual files are also reachable by offset. Check with `isset($file[0])` first, because
files that failed to transfer are missing from the collection.

#### Storing only the files that passed

`upload()` is all-or-nothing: if any file fails validation, nothing is stored. Call
`uploadValid()` in its place to store each file that passed. It returns `false` when at least
one was rejected.

```php
// No isValid() bail-out here: uploadValid() validates, stores what passed, and
// reports the rest afterwards
if (count($file) === 0) {
    // A transfer that failed outright (too large, nothing selected) leaves the
    // collection empty, and its message is already in getErrors()
    foreach ($file->getErrors() as $message) {
        echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'), '<br>';
    }

    return;
}

try {
    if ($file->uploadValid() === false) {
        // At least one file was rejected; the rest are stored
        foreach ($file->getErrors() as $message) {
            echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'), '<br>';
        }
    }
} catch (\Exception $e) {
    // Storage failures only, which still abort the batch
    return;
}

// Loop over the files that were stored, keyed by collection offset
foreach ($file->getUploadedLocators() as $offset => $storedPath) {
    // $file[$offset] is the file stored at $storedPath
}
```

A file that never transferred counts as rejected, since its message is in `getErrors()`.
Storage failures (destination exists, blocked extension, disk full) still throw part-way
through the batch, which is what the `catch` is for: `getUploadedLocators()` lists what was
written before the throw.

Nothing throws for a rejected file, so the `false` return is the only signal that one was.
**The files that passed are already on disk**, so undo them yourself if you abandon the
request there, the way the `catch` above does.

### Uploads from another source

`File` reads `$_FILES`. `FileList` takes the files directly, so they can come from a PSR-7
request, a worker runtime (RoadRunner, Swoole, Bref, Octane), a test harness, or an upload
reassembled from chunks:

```php
use GravityPdf\Upload\FileList;

$list = new FileList($fileInfos, $storage, $failures);
```

It extends `File`, so everything after construction is unchanged: validations, callbacks,
`isValid()`, `upload()`, `uploadValid()`, `getUploadedLocators()`, `count()`, `foreach` and
offsets.

| Argument | |
|---|---|
| `array $fileInfos` | One `FileInfoInterface` per file, in a **flat** array. A nested array throws `InvalidArgumentException`, as does anything else that is not a `FileInfoInterface`. PSR-7's `getUploadedFiles()` is a tree, so flatten it first (see the bridge below). Keys are not used as offsets. |
| `StorageInterface $storage` | As `File` takes it. |
| `array $failures = []` | `[$clientFilename, UPLOAD_ERR_*]` pairs for files that never arrived. Each becomes a `getErrors()` entry in the same words as the `$_FILES` path, and counts as a rejection for `uploadValid()`. Keys are discarded. |

`getSourceKeys()` returns the key each file arrived under, by collection offset:

```php
$list = new FileList(['avatar' => $avatarFile, 'banner' => $bannerFile], $storage);
$list->addValidation(new FileType(['jpg', 'jpeg'], 'image/jpeg'));

$keys = $list->getSourceKeys();   // ['avatar', 'banner']

// An empty collection throws, as on the $_FILES path: check before uploading
if (count($list) === 0) {
    return $list->getErrors();
}

$list->uploadValid();

foreach ($list->getUploadedLocators() as $offset => $storedPath) {
    // $keys[$offset] is the field this file was submitted under
}
```

`$failures` takes the pairs themselves, not finished strings — the collection words them for
you. `File::formatUploadFailure($clientFilename, $errorCode)` is for the other case: reporting
a failed transfer somewhere that is not a `FileList` at all. Its return value is a rendered
line, so passing it back into `$failures` raises `InvalidArgumentException`.

#### Two decisions this path needs from you

On the `$_FILES` path PHP guarantees the file arrived in this request's `multipart/form-data`
body. It checks it twice: `is_uploaded_file()` on the way in, `move_uploaded_file()` on the way
out. That is what stops a manipulated path storing `/etc/passwd` or another user's upload as a
file of your own. Off that path PHP cannot make the guarantee, so both ends refuse the file
until you replace them, and every upload fails with `This file was not received as an upload`.

**1. Say where the file came from.** Override `isUploadedFile()`, usually to check the path is
inside a directory only your bridge writes to:

```php
class TmpUploadFile extends GravityPdf\Upload\FileInfo
{
    /** A tmp directory only this application writes to. Declared once: the bridge below
        writes into it, and this check trusts nothing outside it. */
    public const DIRECTORY = '/var/lib/myapp/uploads-tmp';

    public function isUploadedFile(): bool
    {
        $tmp = realpath(self::DIRECTORY);           // resolve both sides, or a symlinked
        $path = realpath($this->getPathname());     // mount never matches

        return $tmp !== false && $path !== false
            && strpos($path, $tmp . '/') === 0      // trailing /, or uploads-tmp-old passes
            && is_file($path);
    }
}
```

`return true;` disables the check. Only do that where nothing outside your own code can
influence the path.

**2. Say you are willing to store it.**

```php
$storage = (new FileSystem('/path/to/uploads'))->acceptFilesNotUploadedByPhp();
```

Nothing else about the write changes: the staged write, the reservation, the extension
deny-list, the symlink refusals and the mode all still apply. `acceptsFilesNotUploadedByPhp()`
reads the setting back.

#### A PSR-7 bridge

No PSR-7 dependency is involved. This is caller code:

```php
use GravityPdf\Upload\FileList;
use GravityPdf\Upload\StorageInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;

/** @param UploadedFileInterface[] $uploadedFiles $request->getUploadedFiles()['photos'] */
function fileListFrom(array $uploadedFiles, StorageInterface $storage, int $maxBytes): FileList
{
    $fileInfos = [];
    $failures = [];

    foreach ($uploadedFiles as $field => $uploadedFile) {
        $clientFilename = (string) $uploadedFile->getClientFilename();

        if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
            $failures[] = [$clientFilename, $uploadedFile->getError()];
            continue;
        }

        $path = TmpUploadFile::DIRECTORY . '/' . bin2hex(random_bytes(16));

        if (writeTmpFile($uploadedFile->getStream(), $path, $maxBytes) === false) {
            $failures[] = [$clientFilename, UPLOAD_ERR_INI_SIZE];
            continue;
        }

        $fileInfos[$field] = new TmpUploadFile($path, $clientFilename);   // getSourceKeys()
    }

    return new FileList($fileInfos, $storage, $failures);
}

/** A PSR-7 body may be in memory with no path at all, so write it to a tmp file. Cap the
    bytes as you go: Validation\Size only bounds a file that already exists. */
function writeTmpFile(StreamInterface $stream, string $path, int $maxBytes): bool
{
    $out = fopen($path, 'xb');

    if ($out === false) {
        return false;
    }

    for ($written = 0; !$stream->eof(); ) {
        $chunk = $stream->read(8192);
        $written += strlen($chunk);

        if ($written > $maxBytes || fwrite($out, $chunk) === false) {
            fclose($out);
            unlink($path);

            return false;
        }
    }

    fclose($out);

    return true;
}
```

`getUploadedFiles()` returns a tree, since `docs[front]` nests, and the collection is flat.
Flatten it on the way in:

```php
$flat = new RecursiveIteratorIterator(
    new RecursiveArrayIterator($request->getUploadedFiles(), RecursiveArrayIterator::CHILD_ARRAYS_ONLY)
);

$list = fileListFrom(iterator_to_array($flat, false), $storage, 10 * 1024 * 1024);
```

Neither argument is optional. Without `CHILD_ARRAYS_ONLY` the iterator descends into the
`UploadedFileInterface` objects and yields their properties, which for the usual implementation
is nothing at all, since those properties are private. Without the `false`, keys are leaf names
only, so `docs[front]` and `scans[front]` collide and one file disappears. Build composite keys
such as `docs.front` yourself if you want field names back from `getSourceKeys()`.

Storing a file moves it, so the tmp files behind stored uploads are gone afterwards. The ones
behind rejected uploads are not, and nothing else will remove them:

```php
foreach ($list as $file) {
    @unlink($file->getPathname());   // no-op for the ones storage already moved
}
```

Never call `UploadedFileInterface::moveTo()` alongside this: the library does its own storing,
and `moveTo()` deletes the source under a CLI SAPI.

### Lifecycle callbacks

Four optional hooks fire once per file, each receiving that file's `FileInfoInterface`:
`beforeValidate`, `afterValidate`, `beforeUpload` and `afterUpload`. Use them for per-file
work like renaming or audit logging without writing your own loops.

The two validation hooks are a matched pair: `afterValidate` fires for every file
`beforeValidate` fired for, including one that failed, so they can safely open and close a
per-file resource. The upload hooks are not a pair: a storage failure throws out of
`upload()` before `afterUpload` runs. Under `uploadValid()` the upload hooks fire only for
the files being stored; the validation hooks still fire for every file.

```php
use GravityPdf\Upload\FileInfoInterface;

$file->beforeUpload(static function (FileInfoInterface $fileInfo): void {
    $fileInfo->setName(bin2hex(random_bytes(16)));
});

$file->afterUpload(static function (FileInfoInterface $fileInfo): void {
    error_log(sprintf('Stored upload as %s', $fileInfo->getNameWithExtension()));
});
```

**`beforeUpload` runs after validation, not before it**, so a name set there is never
validated: only the storage deny-list and `FileSystem`'s filename rules apply. `setName()`
is safe there, since it cannot change the extension. `setExtension()` and
`setNameWithExtension()` are not: given anything derived from user input, they can store a
file under an extension your validations would have rejected, and the deny-list covers only
[the formats below](#extensions-blocked-by-default).

Rename in `beforeValidate` if the final name has to be the validated one.

### Custom validation rules

Implement `ValidationInterface` and throw `GravityPdf\Upload\Exception` to reject a file.
The exception message is what `getErrors()` shows the end user:

```php
use GravityPdf\Upload\Exception;
use GravityPdf\Upload\FileInfoInterface;
use GravityPdf\Upload\ValidationInterface;

class MaxDimensions implements ValidationInterface
{
    private $maxWidth;
    private $maxHeight;

    public function __construct(int $maxWidth, int $maxHeight)
    {
        $this->maxWidth = $maxWidth;
        $this->maxHeight = $maxHeight;
    }

    public function validate(FileInfoInterface $fileInfo): void
    {
        $size = $fileInfo->getDimensions();

        if ($size['width'] > $this->maxWidth || $size['height'] > $this->maxHeight) {
            throw new Exception(
                __('Image must be no larger than %1$sx%2$s pixels', 'my-plugin'),
                $fileInfo,
                'max_dimensions',
                [$this->maxWidth, $this->maxHeight]
            );
        }
    }
}

$file->addValidation(new MaxDimensions(2048, 2048));
```

Failures accumulate rather than abort: every validation runs against every file, and
`getErrors()` reports them all at once.

The message, its values and the code are kept apart rather than assembled here, so your rule
behaves like a shipped one: the message goes through the
[translator](#translating-error-messages) if one is installed, the values are interpolated
after the lookup — a `%` in one can never be read as a conversion — and the code you chose
shows up in `getErrorDetails()` for a caller branching on it. Pass a finished string with no
values if you would rather; `getErrorDetails()` then reports the code
`ErrorCode::VALIDATION_REJECTED` for it, so every entry has one.

`__()` here is `GravityPdf\Upload\__()`, which marks the string for an extractor and hands it
straight back. It is **not** WordPress's `__()` and it never translates. Using it is optional:
add `use function GravityPdf\Upload\__;`, or leave the literal bare and use your own marker if
your rule's wording lives in your own catalogue. Either way the string reaches the translator,
since the marker returns its argument.

The second argument names a catalogue for whatever reads these calls. The marker discards it
and this library always looks a message up under `Translation::DOMAIN`, so it tells *your*
extractor where the string belongs and nothing more; your translator closure decides where the
lookup lands. It defaults to `Translation::DOMAIN`, and the library's own calls leave it off.

If you do use the marker, import it in **every** file that calls it. PHP resolves an
unqualified function call against the global namespace when the current one has no match, so in
a WordPress plugin a missing import means `__()` reaches WordPress's function instead: no
error, but the string is translated at the throw rather than at the render, and
`Exception::getMessage()` stops being the English you search your log for. Fully qualified,
`\GravityPdf\Upload\__('…')` cannot take that path, and `xgettext` extracts it just the same.

Your message goes through `Filename::sanitizeForDisplay()` first: bidi controls are deleted,
runs of control characters collapse to a single space, surrounding whitespace is trimmed, and
the result is forced to valid UTF-8 where `ext-mbstring` is loaded. A message built from user
input cannot forge a log line, move a terminal cursor, or make
`json_encode($file->getErrors())` return `false`. It is still not escaped, so escape on
output.

Throwing anything other than `GravityPdf\Upload\Exception` is caught too, but nothing it
carries reaches `getErrors()`: not its message, which can leak server paths, and not its
class name. Catch it in the validator and rethrow an `Upload\Exception` if either belongs in
what the user sees.

`\LogicException` is the exception to that: it propagates out of `isValid()`, since PHP
defines the type as a bug in your program rather than a file that failed. A validator
calling `getHash()` with a misspelled algorithm reaches you, not the end user.

### Custom storage backends

Implement `StorageInterface` to store files somewhere other than the local filesystem:
read from `$fileInfo->getPathname()`, return the destination, and throw
`GravityPdf\Upload\Exception` on failure.

The string you return is a locator you define (a key, a URL, an identifier) and
`File::getUploadedLocators()` hands it back unchanged, so it is what the application rolls
back with. Never return `''`: a caller cannot tell it from a usable value.

A backend of your own has no `move_uploaded_file()` in it, so nothing stops it storing a file
PHP never received. `FileInfo::isUploadedFile()` is where that is decided: validation refuses a
file that answers `false` whichever storage is configured. Leave the check there rather than
reproducing it here.

```php
use GravityPdf\Upload\FileInfoInterface;
use GravityPdf\Upload\StorageInterface;

class ObjectStorage implements StorageInterface
{
    public function upload(FileInfoInterface $fileInfo): string
    {
        $key = 'uploads/' . $fileInfo->getNameWithExtension();

        // ... stream $fileInfo->getPathname() to your object store ...

        return $key;
    }
}
```

The protections under "Security notes" (the deny-list, the `basename()` reduction, the
symlink refusal, the staged write) live in `Storage\FileSystem`. A custom backend needs its
own equivalents.

## Translating error messages

No translations ship with this library, and none are needed to use it: with no translator
installed every message is the English string it has always been. Install one and the
messages `getErrors()` returns are looked up through it.

```php
use GravityPdf\Upload\Translation;

Translation::setTranslator(static function (string $text, string $domain): string {
    return $myCatalogue->lookup($text);
});
```

The English source string **is** the message id, which is also the gettext msgid, so a lookup
that finds nothing returns what this library would have said anyway. The translator receives
the string and the library's text domain and nothing else: values are interpolated on this side
of the seam, after the lookup, so a filename or a configured limit containing `%` can never be
read as a `printf` conversion. Register it once, wherever you bootstrap. It is consulted when a
message is rendered, so a translator that reads the current locale still answers correctly
after a mid-request switch.

In the source, each translatable string is marked rather than translated where it is written —
gettext's `N_()` idiom, under a more familiar name:

```php
throw new Exception(
    /* translators: %1$s: the largest accepted size, in bytes */
    __('File size is too large. Must be no more than %1$s MB'),
    $fileInfo,
    ErrorCode::SIZE_TOO_LARGE,
    [$this->maxSize]
);
```

`__()` returns its argument: it marks the string for an extractor and nothing more, domain
included. Keeping the lookup at one chokepoint is what lets `Exception::getMessage()` stay
English for your log, keeps the untranslated msgid in `getErrorDetails()`, and means the locale
that matters is the one in force when the message is read rather than when the file was
rejected.

A broken catalogue cannot break an upload. A translator that throws, returns a non-string, or —
where there are values to fill — returns a template whose placeholders do not match the
source's is ignored in favour of the English. A message with no values is handed back whole
whatever the catalogue says, since there is no interpolation for a mismatch to spoil.

### What is translated, and what is not

`getErrors()` — the list the README tells you to render — and nothing else. In particular
`Exception::getMessage()` is always English, including the messages `Storage\FileSystem`
throws. Those name the destination and exist for your log; showing one to whoever submitted
the file [hands them an existence oracle for the upload directory](#security-notes). An
exception is also what you search a log for, which it stops being once translated. To show one
in the end user's language, render it from `getMessageId()` and `getMessageArgs()`.

### The catalogue

`i18n/upload.pot` lists every string that gets looked up, with translator comments for the
ones carrying placeholders. It is a template — there are no `.po` or `.mo` files here. Seed
your own catalogue from it and merge it forward when you update the library:

```bash
msgmerge --update my-plugin.po vendor/gravitypdf/upload/i18n/upload.pot
```

Or generate your own from the installed source. One flag names the marker, and you need to
know nothing else about this library:

```bash
xgettext --language=PHP --keyword=__ --add-comments=translators: \
    -o my-plugin.pot $(find vendor/gravitypdf/upload/src -name '*.php')
```

Message wording is API: changing it is a breaking change and appears in
[CHANGELOG.md](CHANGELOG.md), so a reworded message shows up as an untranslated string rather
than silently reverting to English.

### Using an existing translation library

The seam is a plain `callable`, so it fits whatever you already run and costs this library
nothing: `ext-fileinfo` and no packages. It loads no catalogue, chooses no locale, and has no
plural forms to pluralise — your application is already doing all of that, and one lookup is
what is left. All three below hand back the string they were given when a lookup finds nothing,
which is what the seam expects.

[Symfony](https://symfony.com/doc/current/translation.html). `Translation::DOMAIN` maps straight
onto a Symfony translation domain, so `translations/gravitypdf-upload.es.xlf` is found with no
further wiring:

```php
Translation::setTranslator(
    static function (string $text, string $domain) use ($translator): string {
        return $translator->trans($text, [], $domain);
    }
);
```

[Laravel](https://laravel.com/docs/localization). JSON language files are keyed by the source
string, which is what a msgid is, so `lang/es.json` needs no mapping of its own:

```php
Translation::setTranslator(static function (string $text): string {
    return \__($text);
});
```

[php-gettext](https://github.com/php-gettext/Translator), reading a `.mo` compiled from the
catalogue above:

```php
use Gettext\Loader\MoLoader;
use Gettext\Translator;

$gettext = Translator::createFromTranslations((new MoLoader())->loadFile('es.mo'));

Translation::setTranslator(static function (string $text) use ($gettext): string {
    return $gettext->gettext($text);
});
```

WordPress needs one more step, for a reason unrelated to the runtime. It has its own section
below.

### WordPress

The runtime side is four lines:

```php
Translation::setTranslator(static function (string $text, string $domain): string {
    /* phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- ids come from the catalogue */
    return \__($text, 'my-plugin');
});
```

**The leading backslash is doing work.** If the file holding this closure also writes a
validation of your own, it will have imported this library's marker — and `__($text,
'my-plugin')` would then resolve to the marker, which hands back its argument. The translator
becomes a permanent no-op, silently, and every message stays English. `\__()` is unambiguously
WordPress's. The same applies anywhere you call WordPress's `__()` in a file that imports the
marker.

Extraction is one command. `wp i18n make-pot` never looks inside `vendor/`, so merge this
library's catalogue into yours as you build it:

```bash
wp i18n make-pot . languages/my-plugin.pot \
    --merge=vendor/gravitypdf/upload/i18n/upload.pot
```

The msgids land in your project under your own text domain, and from there it is your ordinary
translation pipeline — nothing about this library is special:

```bash
msginit --locale=de_DE --input=languages/my-plugin.pot \
    --output=languages/my-plugin-de_DE.po
# translate, then:
msgfmt --check --output-file=languages/my-plugin-de_DE.mo languages/my-plugin-de_DE.po
```

```php
load_plugin_textdomain('my-plugin', false, 'my-plugin/languages');
```

Re-merge when you update this library. A message that was reworded appears as a new untranslated
string rather than silently reverting to English, because the wording is API here and a change to
it is in [CHANGELOG.md](CHANGELOG.md).

**Plugins distributed on wordpress.org are not covered by this.** translate.wordpress.org builds
a plugin's GlotPress project by running `wp i18n make-pot` over your source itself — it does not
read the `.pot` you ship, and it cannot be pointed at `vendor/`, since `--exclude` merges into
the default rather than replacing it and the importer passes no path arguments at all. So these
strings never reach GlotPress, and shipping your own `.mo` alongside a language pack does not
help either: `WP_Textdomain_Registry` searches `WP_LANG_DIR/plugins` before a plugin's own
directory and stops at the first match, so the language pack wins for every locale it covers.

Getting them into a wordpress.org project means writing the msgids into your own tree as literal
`__()` calls under your own domain, in a file nothing ever includes — gettext resolves by msgid
and domain rather than by declaring file, so the runtime lookup finds them. WordPress.org does
this for itself in `plugin-directory`'s `static_strings()` and the generated
`extra/translation-strings.php` of several of its repositories. `i18n/upload.pot` has everything
such a file would need; generating it is a dozen lines and this library does not ship them.

### Reacting to a failure rather than showing it

Messages are for reading. To branch on *why* a file was rejected, use the error code, which is
stable across releases in a way the wording is not:

```php
use GravityPdf\Upload\ErrorCode;

foreach ($file->getErrorDetails() as $error) {
    if ($error['code'] === ErrorCode::SIZE_TOO_LARGE) {
        $response['retry_with_smaller_file'] = true;
    }
}
```

Each entry carries `code`, the untranslated `message_id` and its `args`, the sanitized
`filename` the failure was about (`null` where it was not about one file), and `message`, the
finished line `getErrors()` returns. The `message_id` and `args` are there so you can render
the message with your own wording and never touch this library's translator:

```php
$message = sprintf(\__($error['message_id'], 'my-plugin'), ...$error['args']);
```

Guard that `sprintf()` if the catalogue is not yours to trust: a translation whose placeholders
do not match throws `ArgumentCountError` on PHP 8, on the failure path. `Translation::render()`
does this for you and falls back to the English.

`Exception::getErrorCode()` answers the same codes for a throw, including from storage.

## Security notes

**Prefer `FileType` over `Mimetype` and `Extension` separately.** Those two check independent
lists, so content sniffed as `image/gif` stored as `avatar.png` satisfies both. `FileType`
requires the extension and the contents to describe the same format.

**Generate storage names server-side.** Sanitizing normalizes client names, so `report.txt`
and `report!.txt` both land on `report.txt`. A server-side name avoids the collision and the
predictable destination:

```php
$file->setName(bin2hex(random_bytes(16)));   // keep the client name as display metadata only
```

**Sanitizing is not escaping.** Unsafe characters are rewritten, not escaped. Escape on
output and use parameterized queries. This applies to `getErrors()`.

**Show `getErrors()`, log the exception.** Every string in `getErrors()` is sanitized and
describes the submitted file. A storage exception message is sanitized too, but it is not
written for the person who uploaded: it distinguishes a name that already exists from a
destination that could not be created, which tells whoever submitted the file what is in
your upload directory. Log it and show something generic.

**Call `isValid()` before reading metadata.** It performs the `is_uploaded_file()` check;
the metadata accessors do not. This matters where `$_FILES` is rebuilt by something other
than the PHP SAPI (PSR-7 bridges, test harnesses, middleware). On the `FileList` path that
check is one you wrote, so metadata is only as trustworthy as it is.

**Serve uploads from a directory the web server won't execute.** The storage defaults below
are backstops, not a substitute for that.

### Extensions blocked by default

`FileSystem` refuses to write these, whatever the validations allowed. The check runs against
the sanitized extension about to be written and throws `\GravityPdf\Upload\Exception` rather
than recording a validation error.

Dots inside the name are not extension separators: `FileInfo::setName()` rewrites them to
hyphens, so `release.config.zip` is stored as `release-config.zip`. `upload()` checks every
dot-separated component regardless, for names reaching it from a `FileInfoInterface` of
your own.

| Group | Extensions |
|---|---|
| PHP | `php` `php2` `php3` `php4` `php5` `php6` `php7` `php8` `phps` `phtml` `phtm` `phar` `pht` `inc` |
| Server-side includes | `shtml` `shtm` `stm` |
| CGI and scripts | `cgi` `fcgi` `pl` `py` `rb` `sh` `bash` `ps1` |
| Java | `jsp` `jspx` `jspf` `jsw` `jsv` `jshtml` `jar` `war` |
| ASP / ASP.NET | `asp` `aspx` `asa` `asax` `ascx` `ashx` `asmx` `cer` `cshtml` `vbhtml` |
| Windows binaries | `exe` `dll` `com` `bat` `cmd` `msi` `scr` `vbs` `ws` `wsf` `hta` |
| Server configuration | `htaccess` `htpasswd` `ini` `conf` `config` |
| Markup and script | `html` `htm` `xhtml` `xht` `xhtm` `svg` `svgz` `xml` `xsl` `xslt` `js` `mjs` `swf` `mht` `mhtml` |

The first seven groups are `FileSystem::EXECUTABLE_EXTENSIONS`, which a server runs. The
last is `FileSystem::MARKUP_EXTENSIONS`, which a browser renders. Serving one from your own
origin is stored XSS; SVG is in that group because it carries `<script>` and event handlers.

The list is not exhaustive. To extend rather than replace it:

```php
$storage->blockExtensions(
    array_merge(\GravityPdf\Upload\Storage\FileSystem::getDefaultBlockedExtensions(), ['csv'])
);
```

To accept SVG, drop just those two entries rather than the whole markup group, and sanitize
the file contents yourself:

```php
$storage->blockExtensions(
    array_diff(
        \GravityPdf\Upload\Storage\FileSystem::getDefaultBlockedExtensions(),
        ['svg', 'svgz']
    )
);
```

Entries are matched one dot-separated component at a time, and an entry containing dots is
split the same way, so `tar.gz` blocks `tar` and `gz` rather than nothing at all. A leading
dot is accepted and removed. Check a custom list against that: it may cover more than you
intended.

### Turning the defaults off

Each is a separate call, so turning one off leaves the others in place:

```php
$storage = new \GravityPdf\Upload\Storage\FileSystem('/path/to/directory', true); // allow overwriting
$storage->allowAnyExtension();   // store any extension, including .php
$storage->setMode(null);         // let the process umask decide, usually world-readable
$storage->acceptFilesNotUploadedByPhp(); // store files PHP did not receive as an upload

$file->allowUnvalidatedUploads(); // store whatever is submitted, without validating it
```

`allowAnyExtension()` is the only way to empty the deny-list. `blockExtensions()` requires a
non-empty list and throws otherwise, so a missing config value cannot silently disable the
check. `getBlockedExtensions()` reports what is actually configured.

`acceptFilesNotUploadedByPhp()` is the one to think hardest about, and a `$_FILES` application
never needs it. Without it the write goes through `move_uploaded_file()`, which refuses any
source PHP did not receive as an upload. It exists for
[uploads from another source](#uploads-from-another-source), and belongs with an
`isUploadedFile()` override.

## API reference

All classes live in the `GravityPdf\Upload` namespace.

### File

The entry point. `new File(string $key, StorageInterface $storage)` reads `$_FILES[$key]`
into a collection of `FileInfoInterface` objects, handling both the single-file and the
`name="foo[]"` multi-file shape. It throws `RuntimeException` when `file_uploads` is disabled in
php.ini, and `InvalidArgumentException` when the key is not in `$_FILES`.

| Method | Description |
|---|---|
| `addValidation(ValidationInterface $validation): File` | Add a single validation rule. Chainable, as are all setters below. |
| `addValidations(array $validations): File` | Add several validation rules at once. |
| `getValidations(): ValidationInterface[]` | The rules added so far. |
| `isValid(): bool` | Runs `is_uploaded_file()` plus every validation against every file, accumulating failures. Each call resets the error list and re-validates, so it is idempotent. |
| `getErrors(): string[]` | All failures from the last validation run (`isValid()`, `upload()` or `uploadValid()`) plus any files that failed to transfer, as `"filename: message"`. A `$_FILES` entry too malformed to name a file is reported without the prefix. Sanitized, but must still be escaped on output. |
| `getErrorDetails(): array` | The same failures as their parts: `code` (an [`ErrorCode`](#errorcode) constant, stable across releases), the untranslated `message_id` and its `args`, the sanitized `filename` or `null`, and the finished `message`. For branching on a failure, or rendering it with your own wording. |
| `upload(): bool` | Re-validates, then stores each file via the storage backend. All-or-nothing: one file failing validation stores none of them; call `uploadValid()` in its place to store the ones that passed. Throws `LogicException` when no validations are configured, and `Exception` when validation fails (details in `getErrors()`), when the collection is empty, or when storage fails (details in the exception message). |
| `uploadValid(): bool` | Re-validates, then stores only the files that passed, leaving the rest in `getErrors()`. Use it in place of `upload()` on a multi-file field where a partial batch is acceptable. Returns `true` when every file was stored and `false` when at least one was rejected, counting a file that failed to transfer. Nothing throws for a rejected file, so that return value is the only signal, and cleaning up what was already stored is yours on the `false` branch. Throws the same `LogicException` with no validations configured, the same `Exception` on an empty collection, and whatever storage throws. |
| `getUploadedLocators(): string[]` | Locators returned by the most recent `upload()` or `uploadValid()`, in whatever form the storage backend defines. Multi-file uploads are not atomic, so after a failure this is what needs rolling back. Keyed by collection offset, so the array is sparse after `uploadValid()` and the locator at `$i` still belongs to `$file[$i]`. |
| `allowUnvalidatedUploads(): File` | Let `upload()` and `uploadValid()` proceed with no validations configured. |
| `allowsUnvalidatedUploads(): bool` | Whether that was allowed. An empty `getValidations()` does not say whether that was a decision. |
| `beforeValidate(callable $callback): File` | Hook run per file before its validations. All four hooks receive that file's `FileInfoInterface`. |
| `afterValidate(callable $callback): File` | Hook run per file after its validations, including a file that failed them. |
| `beforeUpload(callable $callback): File` | Hook run per file before storage. |
| `afterUpload(callable $callback): File` | Hook run per file after storage. |
| `File::humanReadableToBytes(string $input): int` | Static helper that converts `'5M'` to `5242880`. Accepts B/K/M/G with an optional trailing `B`, and fractions like `'0.5M'`. Throws `InvalidArgumentException` on unparseable input. |
| `File::formatUploadFailure(string $clientFilename, int $errorCode): string` | Static: the `getErrors()` string for a file that never arrived, from a client-supplied name and an `UPLOAD_ERR_*` code. Sanitizes the name as the `$_FILES` path does. For reporting a failed transfer outside any collection — a [`FileList`](#filelist)'s `$failures` takes the pairs and words them itself, so it does not need this. A code with no message of its own reads as `Unknown error`. |

`File` also implements `Countable`, `ArrayAccess` and `IteratorAggregate` over its
`FileInfoInterface` objects, and forwards any other method call to them: with one file the
call returns that file's value, with several it returns an array of values, and with none it
returns `null`.

### FileList

`new FileList(array $fileInfos, StorageInterface $storage, array $failures = [])` builds the
same collection from files you supply rather than from `$_FILES`. Throws
`InvalidArgumentException` for an entry that is not a `FileInfoInterface`, or a `$failures`
entry that is not a `[string, int]` pair.

| Method | Description |
|---|---|
| `getSourceKeys(): array` | The key each file arrived under, by collection offset, so `getSourceKeys()[$i]` names `$list[$i]` and `getUploadedLocators()[$i]`. Writing to an offset or unsetting one drops that key. |

Both ends of the provenance check have to be answered before anything is stored; see
[Uploads from another source](#uploads-from-another-source).

### FileInfo

The per-file value object; extends `SplFileInfo` and implements `FileInfoInterface`, so path
accessors such as `getPathname()` are available alongside these:

| Method | Description |
|---|---|
| `getName(): string` / `setName(string $name): FileInfo` | Name without extension. `setName()` **sanitizes**: unsafe characters become `-`, `.` among them; characters that reorder, break or hide the text around them (bidi overrides, zero-width marks, line and paragraph separators, the BOM) are deleted; reserved Windows device names are blanked; and the result is truncated so the name and extension together fit 255 bytes, falling back to `unnamed-file`. With `ext-mbstring` the truncation lands on a character boundary and the result is forced to valid UTF-8; see [Requirements](#requirements). |
| `getExtension(): string` / `setExtension(string $ext): FileInfo` | Extension without the leading dot. `setExtension()` **validates**: it trims and lowercases, then discards the extension entirely if anything other than letters and digits remains, if it exceeds `Filename::MAX_EXTENSION_LENGTH` (32) bytes, or if it is a reserved Windows device name such as `aux`. Discarded rather than stripped, so it cannot turn what the client sent into something else: `photo.PNG` keeps `png`, `avatar.p-h-p` keeps nothing. |
| `getNameWithExtension(): string` / `setNameWithExtension(string $name): FileInfo` | Both parts together; the setter splits the input and applies the two rules above. |
| `getMimetype(): string` | Media type sniffed from the file contents via `ext-fileinfo`, not the client-supplied value. |
| `getSize(): int\|false` | Size in bytes, or `false` when the file cannot be read. |
| `getHash(string $algorithm = 'sha256'): string` | File hash; empty string when the file cannot be read. Throws `\InvalidArgumentException` for an algorithm this PHP build does not support, since a misspelling is broken code rather than a rejected upload. |
| `getDimensions(): array` | `['width' => int, 'height' => int]`; both `0` for non-images. |
| `isUploadedFile(): bool` | Wraps `is_uploaded_file()`. Called by `File::isValid()`. |
| `FileInfo::setFactory(callable $factory): void` | Static: install a factory `File` uses to build each `FileInfoInterface`. The constructor is `final`, so this is the seam for substituting test doubles. Process-wide state. |
| `FileInfo::resetFactory(): void` | Static: clear an installed factory. |

### Storage\FileSystem

The shipped `StorageInterface` implementation; stores files in a local directory.
`new FileSystem(string $directory, bool $overwrite = false)` throws
`InvalidArgumentException` when the directory does not exist or is not writable. The
constructor enables the deny-list and the `0640` file mode; see "Turning the defaults off".

| Method | Description |
|---|---|
| `upload(FileInfoInterface $fileInfo): string` | Store the file and return its destination path. Reduces the name to a `basename()`, drops trailing dots and spaces, and rewrites the characters Windows disallows (`<` `>` `:` `"` `\|` `?` `*`) to `-`. Refuses a name starting with `.`, one carrying control or bidi characters, one Windows resolves to a device such as `CON.txt`, a blocked extension in any dot-separated component, and a symlinked destination. Writes to a staged file in the same directory and moves it into place, so no partial content is readable under the final name. With `$overwrite = false` the destination is claimed first with an empty file at the configured mode, so concurrent requests cannot both win it; a process killed mid-transfer leaves that 0-byte file behind. Throws `Exception` on any refusal. |
| `blockExtensions(array $extensions): FileSystem` | Set the extensions that are never written, checked against the sanitized extension at the write. A leading dot is accepted and removed, and an entry containing dots is split into its components, so `tar.gz` blocks `tar` and `gz`. Throws `InvalidArgumentException` on an empty list; use `allowAnyExtension()` for that. |
| `setMode(?int $mode): FileSystem` | Permissions applied to each stored file, default `FileSystem::DEFAULT_MODE` (`0640`). `null` leaves the mode to the process umask. |
| `acceptFilesNotUploadedByPhp(): FileSystem` | Store files PHP did not receive as an upload, for a `FileList` fed from PSR-7, a worker runtime or reassembled chunks. Off by default: the write otherwise goes through `move_uploaded_file()`, which refuses any other source. Pair it with an `isUploadedFile()` override. |
| `acceptsFilesNotUploadedByPhp(): bool` | Whether that was allowed. |
| `allowAnyExtension(): FileSystem` | Turn the deny-list off. The only way to empty it. |
| `getBlockedExtensions(): string[]` | The extensions currently refused, lowercase and one component each. Empty means the deny-list is off. |
| `getMode(): ?int` | The permissions applied to each stored file, or `null` for the umask. |
| `getOverwrite(): bool` | Whether an existing file at the destination is replaced rather than refused. |
| `getDirectory(): string` | The destination directory, without trailing slash. |
| `FileSystem::getDefaultBlockedExtensions(): string[]` | Static: `EXECUTABLE_EXTENSIONS` merged with `MARKUP_EXTENSIONS`, the table under "Extensions blocked by default". |

### Validations

Each implements `ValidationInterface` and throws `Exception` on failure.

| Class | Description |
|---|---|
| `Validation\FileType($extensions, $mimetypes)` | Requires the file's extension and its sniffed contents to describe the same format. Either argument accepts a string or an array; chain `allow($extensions, $mimetypes)` to accept additional formats, one format per call. |
| `FileType::getAllowedTypes(): array<string, string[]>` | The media types allowed for each extension, as configured. |
| `Validation\Size($maxSize, $minSize = 0)` | Inclusive size bounds, as bytes or human-readable strings (`'5M'`). The message states the bound in the largest of bytes/KB/MB/GB it reaches, rounding a maximum down and a minimum up so the size named is always one the file would pass at. |
| `Validation\Extension($extensions)` | **Deprecated** since 4.0.0. Checks the extension alone, which says nothing about the contents. Use `FileType`. |
| `Validation\Mimetype($mimetypes)` | **Deprecated** since 4.0.0. Checks the sniffed type alone, which a polyglot file satisfies trivially. Use `FileType`. |

#### Stating a size in a locale

`Validation\Size` writes `4.7 MB`, with a `.`, because choosing a separator needs a locale and
this library takes none. `scale()` is the seam for that — it picks both the number and the
unit, and is called through `static::`:

```php
class LocalisedSize extends Size
{
    protected static function scale(int $bytes, bool $down): array
    {
        list($amount, $unit) = parent::scale($bytes, $down);

        return [number_format_i18n((float) $amount, 1), $unit];
    }
}
```

Return a unit key that `getTooLargeMessages()` and `getTooSmallMessages()` both hold — `'B'`,
`'KB'`, `'MB'` or `'GB'` — or override those too, since the unit is named inside the message
rather than interpolated into it. It rarely comes up: `'5M'`, `'500K'` and any binary byte
count divide exactly into the 1024-based ladder and render as whole numbers.

### Filename

`GravityPdf\Upload\Filename` holds the rules for what counts as a usable filename, so the
layers that apply them cannot drift apart. `FileInfo` **rewrites** a client-supplied name;
`Storage\FileSystem` **refuses** one that still breaks a rule, since a `FileInfoInterface` is
a public extension point and inventing a filename is not storage's job.

| Member | Description |
|---|---|
| `Filename::sanitizeNameWithExtension(string $filename): string` | The whole treatment for one string: splits name from extension, rewrites the first, validates the second, fits both to `MAX_LENGTH`. This is what `getErrors()` runs client-supplied names through. |
| `Filename::sanitizeForDisplay(string $value): string` | The same character sets applied to prose rather than to a name: bidi controls deleted, runs of control characters collapsed to a single space, surrounding whitespace trimmed, and the result forced to valid UTF-8 where `ext-mbstring` is loaded. No length limit, no device-name blanking, and `%`, `/` and dots are left alone. **It does not escape** `<`, `>`, `&` or `"`, so escape on output as well. This is what `getErrors()` runs a validation failure's message through; use it for error strings of your own. |
| `Filename::MAX_LENGTH` / `MAX_EXTENSION_LENGTH` | `255` and `32` bytes. The name's budget is `MAX_LENGTH` minus the extension and its dot. |
| `Filename::RESERVED_WINDOWS_NAMES` | The device names `setName()` blanks: `con`, `nul`, `lpt1`, and the `COM0`/`LPT0` and superscript variants. |
| `Filename::BIDI_CONTROLS` / `CONTROL_CHARACTERS` | The text-direction characters `setName()` deletes, and the control bytes it rewrites. `FileSystem` refuses a name still carrying either. |

### Exception

`GravityPdf\Upload\Exception` extends `\RuntimeException` and is thrown by validations, storage and
`File::upload()`.

| Method | Description |
|---|---|
| `getFileInfo(): ?FileInfoInterface` | The offending file, so a caller can tell which file in a multi-file upload failed. `null` where the failure is not about one file. |
| `getErrorCode(): string` | The [`ErrorCode`](#errorcode) constant naming why this was thrown, or `ErrorCode::NONE` for a throw of your own that named none. Branch on this rather than on the message. |
| `getMessageId(): string` | The message with its placeholders still in it — the gettext msgid. |
| `getMessageArgs(): array` | The values those placeholders take. |

`getMessage()` is always English, composed from those two: it is what you log, and
[never translated](#what-is-translated-and-what-is-not).

The constructor is `__construct(string $message, ?FileInfoInterface $fileInfo = null, string
$errorCode = ErrorCode::NONE, array $messageArgs = [], int $code = 0, ?Throwable $previous =
null)`. A validation of your own can pass a message id and its values rather than a finished
string, and they reach `getErrorDetails()` intact.

### Translation

`GravityPdf\Upload\Translation` holds the hook described under
[Translating error messages](#translating-error-messages).

| Method | Description |
|---|---|
| `Translation::setTranslator(callable $translator): void` | Install `function (string $text, string $domain): string`. Process-wide, and read at render time. The domain is always `Translation::DOMAIN`; ignore it and look the string up in your own. |
| `Translation::resetTranslator(): void` | Remove it, returning every message to its English source. Tests that install one need this in teardown; a static is not covered by PHPUnit's `backupGlobals`. |
| `Translation::hasTranslator(): bool` | Whether one is installed. Worth checking before you install yours: the translator is process-wide and last writer wins, so two plugins sharing an unprefixed `vendor/` would otherwise look each other's upload errors up in the wrong catalogue. |
| `Translation::translate(string $text, string $domain = self::DOMAIN): string` | The lookup on its own. Returns the English if the translator throws or returns a non-string. |
| `Translation::render(string $messageId, array $args = []): string` | Translate, then interpolate. What `File` calls when it renders `getErrors()`. |
| `Translation::interpolate(string $template, array $args = []): string` | The interpolation without the lookup, falling back to the template when the values do not fit. What `Exception` composes its English message with. |
| `Translation::DOMAIN` | `'gravitypdf-upload'`. Fixed: WordPress forbids a variable text domain, and no extractor can follow one. |

`GravityPdf\Upload\__(string $text, string $domain = Translation::DOMAIN): string` is the
marker, not a method — gettext's `N_()` idiom under a familiar name and shape, returning
`$text` so an extractor records the msgid while the lookup happens elsewhere. It is not
WordPress's `__()`, it never translates, and the two coexist. The domain is discarded: it
describes the string to your extractor, and this library's own lookup always uses
`Translation::DOMAIN`. Optional, as it is on WordPress's `__()`; the calls in `src` omit it. Outside the library's own namespace, import it with
`use function GravityPdf\Upload\__;` or call it fully qualified; an unqualified call with
neither falls back to the global `__()`.

### ErrorCode

`GravityPdf\Upload\ErrorCode` is a class of string constants naming every failure this library
reports: the eight `UPLOAD_ERR_*` outcomes (`INI_SIZE`, `FORM_SIZE`, `PARTIAL`, `NO_FILE`,
`NO_TMP_DIR`, `CANT_WRITE`, `EXTENSION_STOPPED`, `UNKNOWN_TRANSFER_ERROR`), the collection's
own (`MALFORMED_UPLOAD`, `NOT_AN_UPLOADED_FILE`, `VALIDATION_REJECTED`,
`VALIDATION_INCOMPLETE`, `VALIDATION_FAILED`, `NO_FILES`), the shipped validations'
(`SIZE_UNKNOWN`, `SIZE_TOO_SMALL`, `SIZE_TOO_LARGE`, `EXTENSION_NOT_ALLOWED`,
`MIMETYPE_NOT_ALLOWED`, `FILE_CONTENTS_MISMATCH`) and storage's (`DESTINATION_IS_SYMLINK`,
`DESTINATION_EXISTS`, `DESTINATION_NOT_CREATED`, `INVALID_DESTINATION_NAME`,
`BLOCKED_EXTENSION`, `MOVE_FAILED`, `CHMOD_FAILED`, `STAGING_NAME_FAILED`). `ErrorCode::NONE`
is the empty string, which is what a throw of your own that named no code answers.

`ErrorCode::forUploadError(int $errorCode): string` maps an `UPLOAD_ERR_*` constant to its
code.

### Interfaces

| Interface | Contract |
|---|---|
| `StorageInterface` | `upload(FileInfoInterface $fileInfo): string` stores the file and throws on failure. The string is a locator the implementation defines: `FileSystem` returns its directory joined to the stored filename, absolute only if you constructed it with an absolute directory. `File::getUploadedLocators()` passes these back unchanged. Never return `''`; throw instead. |
| `ValidationInterface` | `validate(FileInfoInterface $fileInfo): void` signals failure by throwing `Exception`, never by returning a value. |
| `FileInfoInterface` | The per-file value object contract listed under `FileInfo`, for supplying your own implementation via `FileInfo::setFactory()`. |

## Authors

* [Josh Lockhart](https://github.com/codeguy)
* [Gravity PDF](https://github.com/GravityPDF)

## License

MIT Public License
