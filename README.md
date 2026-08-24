# Upload

[![codecov](https://codecov.io/gh/GravityPDF/Upload/branch/main/graph/badge.svg)](https://codecov.io/gh/GravityPDF/Upload)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](https://opensource.org/licenses/MIT)

A PHP library to validate and save uploaded files.

**Why was this library forked?**

* The original was abandoned, untouched since 2018
* Safe defaults: nothing is overwritten, executable and markup extensions are never written,
  stored files get mode `0640`, and `upload()` refuses to run with nothing to validate against
* `Validation\FileType` requires the extension and the sniffed contents to describe the same
  format, where `Extension` and `Mimetype` check independent lists
* One set of filename rules, applied by both the value object and storage, with UTF-8 support
* Storage refuses path traversal, symlinked destinations, dotfiles, control and bidi
  characters, and stages every write instead of writing to the destination directly
* `FileList` takes uploads that never reach `$_FILES`, and `uploadValid()` stores the files
  that passed instead of discarding the batch
* Every string `getErrors()` returns can be translated, and every failure carries a stable
  `ErrorCode` to branch on

## Installation

```
composer require gravitypdf/upload
```

### Requirements

PHP 7.3 to 8.5 and `ext-fileinfo`. Optional but recommended: `ext-mbstring` (or `symfony/polyfill-mbstring`), which is what guarantees a sanitized filename is valid UTF-8 and lets truncation land on a character boundary. The same repair runs over the strings `getErrors()` returns, so without it neither is guaranteed and `json_encode()` can refuse a name that arrived as invalid UTF-8.

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

    // $displayName is what you show; $storedPath is where the bytes are
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

### Optional upload fields

A field the submitter left empty is still sent: PHP fills `$_FILES` with
`UPLOAD_ERR_NO_FILE`, which is recorded as an error, so `isValid()` returns `false` and
`upload()` throws. Count the collection before validating to tell "nothing was chosen" apart
from "what was chosen is unacceptable":

```php
use GravityPdf\Upload\File;
use GravityPdf\Upload\Storage\FileSystem;
use GravityPdf\Upload\Validation\FileType;

$storage = new FileSystem('/path/to/uploads');

// A form posted without the field at all leaves no key, which the constructor throws
// InvalidArgumentException for
if (isset($_FILES['attachment']) === false) {
    return;
}

$file = new File('attachment', $storage);

// Empty for a field with nothing selected, whether it takes one file or many
if (count($file) === 0) {
    return;
}

$file->addValidations([new FileType('pdf', 'application/pdf')]);

if ($file->isValid() === false) {
    // Something was chosen and it was rejected: report $file->getErrors()
    return;
}

$file->upload();
```

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

Individual files are reachable by offset, but the offsets are the collection's own rather than
the form's: a file that failed to transfer is left out and everything after it moves up, so
`$file[0]` is the first file that arrived and not necessarily the first input on the page.
Reading an offset that is not there gives `null`. Where your own key has to survive, build the
collection with [`FileList`](#uploads-from-another-source) and read `getSourceKeys()`.

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

Nothing throws for a rejected file, so the `false` return is the only signal that one was. A
file that never transferred counts as rejected. **The files that passed are already on disk**,
so undo them yourself if you abandon the request there.

Storage failures (destination exists, blocked extension, disk full) still throw part-way
through the batch: `getUploadedLocators()` lists what was written before the throw.

### Uploads from another source

`File` reads `$_FILES`. `FileList` takes the files directly, so they can come from a PSR-7
request, a base64 payload in a JSON body, a worker runtime (RoadRunner, Swoole, Bref, Octane), a
test harness, or an upload reassembled from chunks:

```php
use GravityPdf\Upload\FileList;

$list = new FileList($fileInfos, $storage, $failures);
```

It extends `File`, so everything after construction is unchanged: validations, callbacks,
`isValid()`, `upload()`, `uploadValid()`, `getUploadedLocators()`, `count()`, `foreach` and
offsets.

| Argument | |
|---|---|
| `array $fileInfos` | One `FileInfoInterface` per file, in a **flat** array. A nested array throws `InvalidArgumentException`, as does anything else that is not a `FileInfoInterface`. PSR-7's `getUploadedFiles()` is a tree, so [flatten it first](docs/psr7.md#flatten-the-tree-first). Keys are not used as offsets. |
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

`File::formatUploadFailure($clientFilename, $errorCode)` renders that `getErrors()` entry for a
failed transfer outside any `FileList`. It returns a finished string, so passing it back into
`$failures` raises `InvalidArgumentException`.

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
    /** A tmp directory only this application writes to. Declared once: the bridge writes
        into it, and this check trusts nothing outside it. */
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

#### Cleaning up the temporary files

Storing a file moves it, so the tmp files behind stored uploads are gone afterwards. The ones
behind rejected uploads are not, and nothing else will remove them:

```php
foreach ($list as $file) {
    @unlink($file->getPathname());   // no-op for the ones storage already moved
}
```

Put it in a `finally` around the validating and storing. Nothing else removes these files, so
an exception on the way past leaks every one of them.

#### Bridges

A bridge is caller code: write or decode the incoming file to a tmp path, wrap that path in the
`TmpUploadFile` above, and hand the result to `FileList`. No dependency, interface or type hint
is added for either of the two below, and each page's snippets are read out of the Markdown and
run against this library on every push, so what they show is what works.

| Source | |
|---|---|
| [PSR-7 request](docs/psr7.md) | `$request->getUploadedFiles()`, flattened out of the tree it arrives in, with each stream written to a tmp file under a byte cap. Run against nyholm/psr7 and guzzlehttp/psr7 by `composer psr7-readme`. |
| [base64 in a JSON body](docs/base64-uploads.md) | `{"filename": "avatar.png", "data": "data:image/png;base64,…"}`, decoded under a cap on the encoded length, with the payload's own media type read for nothing. Run by `composer base64-docs`. |

Both end at the same place: a `FileList` you validate, upload and clean up exactly as above.

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

### Extending the library

`ValidationInterface` decides whether a file is acceptable; `StorageInterface` decides where it
lands. Reject a file by throwing `GravityPdf\Upload\Exception`; say where one went by returning
a locator. [docs/extending.md](docs/extending.md) works through both, including what a validator
of your own may put in front of an end user and the `Filename` predicates a storage backend
needs to reproduce the shipped refusals.

## Translating error messages

No translations ship, and you do not need any: with no translator installed, every message is
the English string it has always been. The English string **is** the message id, so there is
nothing to map — install a `callable` with `Translation::setTranslator()` and `getErrors()` is
looked up through it. Nothing else is: `Exception::getMessage()` stays English for your log.

[docs/translation/](docs/translation/README.md) covers the hook, the catalogue, the `__()`
marker and what a broken translation cannot do, with a working adapter for Symfony, Laravel,
php-gettext and WordPress.

## Reacting to a failure rather than showing it

Messages are for reading. To branch on *why* a file was rejected, use the error code. Codes are
stable across releases; wording is not:

```php
use GravityPdf\Upload\ErrorCode;

foreach ($file->getErrorDetails() as $error) {
    if ($error['code'] === ErrorCode::SIZE_TOO_LARGE) {
        $response['retry_with_smaller_file'] = true;
    }
}
```

Each entry holds `code`, the untranslated `message_id` and its `args`, the sanitized `filename`
(`null` if the failure was not about one file), and `message`, the finished line `getErrors()`
returns. Use `message_id` and `args` to write the message yourself, without this library's
translator:

```php
$message = sprintf(\__($error['message_id'], 'my-plugin'), ...$error['args']);
```

Guard that `sprintf()` if you do not control the catalogue. On PHP 8 a translation whose
placeholders do not match throws `ArgumentCountError` — on the failure path, of all places.
`Translation::render()` handles this and falls back to English.

`Exception::getErrorCode()` returns the same codes, including for storage failures.

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
| Apache mod_asis | `asis` |
| CGI and scripts | `cgi` `fcgi` `pl` `py` `rb` `sh` `bash` `ps1` `erb` `rhtml` |
| Java | `jsp` `jspx` `jspf` `jsw` `jsv` `jshtml` `jar` `war` |
| ASP / ASP.NET | `asp` `aspx` `asa` `asax` `ascx` `ashx` `asmx` `cer` `cshtml` `vbhtml` |
| Legacy IIS | `htr` `idc` `printer` |
| ColdFusion | `cfm` `cfml` `cfc` |
| Windows binaries | `exe` `dll` `com` `bat` `cmd` `msi` `scr` `vbs` `ws` `wsf` `hta` |
| Server configuration | `htaccess` `htpasswd` `ini` `conf` `config` |
| Markup and script | `html` `htm` `xhtml` `xht` `xhtm` `svg` `svgz` `xml` `xsl` `xslt` `js` `mjs` `swf` `mht` `mhtml` |

Every group but the last is `FileSystem::EXECUTABLE_EXTENSIONS`, which a server runs. The
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
intended. `getBlockedExtensions()` reports what a call actually configured.

## API reference

[docs/api-reference.md](docs/api-reference.md) lists every public method, what it takes and what
it throws. The entry points:

| Class | |
|---|---|
| [`File`](docs/api-reference.md#file) | Reads `$_FILES[$key]` into a collection: validations, callbacks, `upload()` and `uploadValid()`. |
| [`FileList`](docs/api-reference.md#filelist) | The same collection, built from files you supply. |
| [`FileInfo`](docs/api-reference.md#fileinfo) | The per-file value object — name, extension, sniffed media type, size, hash, dimensions. |
| [`Storage\FileSystem`](docs/api-reference.md#storagefilesystem) | The shipped backend: where a file lands, and the four protections it applies on the way. |
| [Validations](docs/api-reference.md#validations) | `FileType` and `Size`, plus the two classes deprecated in 4.0. |
| [`Filename`](docs/api-reference.md#filename) | The filename rules both layers read, and the predicates for reproducing them. |
| [`Exception`](docs/api-reference.md#exception), [`ErrorCode`](docs/api-reference.md#errorcode) | What a failure carries, and the stable code to branch on. |

## Authors

* [Josh Lockhart](https://github.com/codeguy)
* [Gravity PDF](https://github.com/GravityPDF)

## License

MIT Public License
