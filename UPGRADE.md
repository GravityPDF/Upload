# Upgrading from 3.x to 4.0

4.0 is a security release. The API is mostly unchanged, but new protections are on by
default and will refuse some uploads that 3.x accepted. Read this before deploying. The
[changelog](CHANGELOG.md) has the full list.

Coming from `codeguy/upload`? Replace the `\Upload` namespace with `\GravityPdf\Upload`
to get the 3.x API, then continue here.

## 1. Update the package

```bash
composer require gravitypdf/upload:^4.0
```

PHP 7.3 through 8.5, still requiring only `ext-fileinfo`. `ext-mbstring` is now suggested:
without it, sanitized filenames aren't guaranteed to be valid UTF-8. See
[Requirements](README.md#requirements).

## 2. `upload()` needs at least one validation

With no validations configured, `upload()` throws `\LogicException`. That's a different
type from the `\GravityPdf\Upload\Exception` a rejected file throws, so a misconfigured
object can't end up in the branch where you handle a bad upload.

**If you catch `\GravityPdf\Upload\Exception` around `upload()`, add `\LogicException`.**
A `catch (\Exception $e)` needs no change.

```php
use GravityPdf\Upload\Validation\FileType;

$file->addValidation(new FileType('pdf', 'application/pdf'));

// Or keep the 3.x behaviour of storing whatever arrives
$file->allowUnvalidatedUploads();
```

## 3. Executable and markup extensions are refused

`Storage\FileSystem` won't write a file whose extension is in
`FileSystem::getDefaultBlockedExtensions()`: things a server executes or reads as config
(`php`, `cgi`, `exe`, `htaccess`, `config`) and markup a browser renders (`html`, `svg`,
`js`, `xml`). Full table in the [README](README.md#extensions-blocked-by-default).

The refusal throws `\GravityPdf\Upload\Exception` from `upload()`. It isn't a validation
error, so it doesn't appear in `getErrors()`.

Only the last dot-separated component is an extension. `FileInfo::setName()` rewrites
interior dots to hyphens as it did in 3.x, so `release.config.zip` stores as
`release-config.zip` and is fine. `release.config` and `settings.ini` are refused. A file
named just `php`, with no extension, is still stored. (`upload()` checks every component
regardless, since a custom `FileInfoInterface` can hand it a name `setName()` never saw.)

To keep a blocked extension:

```php
use GravityPdf\Upload\Storage\FileSystem;

// Drop the entries you need, keeping the rest of the list
$storage->blockExtensions(
    array_diff(FileSystem::getDefaultBlockedExtensions(), ['config'])
);

// Accepting SVG means dropping two entries, not the whole markup group.
// Sanitize the contents yourself
$storage->blockExtensions(
    array_diff(FileSystem::getDefaultBlockedExtensions(), ['svg', 'svgz'])
);

// Or restore the 3.x behaviour of writing any extension
$storage->allowAnyExtension();
```

Two things to check if you pass your own list:

* **Entries are split on dots.** `'tar.gz'` never matched anything in 3.x; it now blocks
  `tar` and `gz` separately. Your list may block more than you meant.
* **`blockExtensions()` requires a non-empty array** and throws on `[]`, so a missing
  config value can't quietly disable the check. `allowAnyExtension()` is the only way to
  empty the list.

## 4. Stored files get mode `0640`

3.x left permissions to the umask, usually producing a world-readable `0644`. If another
account serves or processes the uploads, give it access:

```php
$storage->setMode(0644);  // or null to leave it to the umask, as 3.x did
```

## 5. `'5MB'` now means 5 MiB, not 5 bytes

`File::humanReadableToBytes()`, used by `Validation\Size`, now understands a trailing `B`.
3.x read only the last character, so `'5MB'` was 5 bytes.

**Check every size bound using a `KB`/`MB`/`GB` suffix**, since each becomes much larger.
Bounds without the trailing `B` are unchanged.

**Check any bound using a unit other than `B`, `K`, `M` or `G` as well.** 3.x read the unit
as bytes, so `new Size('1T')` was a one-byte limit that rejected everything while reading
as a generous one. It now throws.

Unparseable input like `'abc'` throws `InvalidArgumentException` instead of becoming `0`,
a negative size like `'-5M'` throws, and fractions like `'0.5M'` work.

## 6. `getMd5()` is gone; `getHash()` means SHA-256

```php
$hash = $fileInfo->getMd5();        // 3.x
$hash = $fileInfo->getHash('md5');  // 4.0: the same digest
$hash = $fileInfo->getHash();       // 4.0: SHA-256
```

Removed from `FileInfo` and `FileInfoInterface`. If you compare uploads against digests
stored by 3.x, pass `'md5'` or migrate the stored values.

## 7. A failed transfer leaves the collection empty

When nothing was selected, or the file exceeded PHP's own limits, 3.x still built a
`FileInfo` for a single-file field and reading its metadata raised an uncaught error. 4.0
leaves it out, matching what multi-file fields always did: `count($file)` is `0`, forwarded
metadata calls return `null`, and the message is already in `getErrors()`.

```php
if (count($file) === 0 || $file->isValid() === false) {
    // getErrors() explains what went wrong
    return;
}
```

`upload()` throws on an empty collection rather than returning `true` having stored
nothing, so check the count if an empty field is a valid outcome for your form.

A multi-file field where only *some* of the files failed behaves as it did in 3.x:
`upload()` stores all of them or none. Nothing to change unless you want the other
behaviour. To keep the files that passed, call `uploadValid()` where you call `upload()`
and check what it returns instead of catching a validation failure.

```php
if ($file->uploadValid() === false) {
    // getErrors() names the files that were rejected. The rest are already stored, so
    // if you abandon the request here, undo getUploadedLocators() yourself
}
```

Nothing throws for a rejected file, so that return value is the only signal one was. A
single-file field gains nothing from the swap, since it's stored whole or not at all.

## 8. Invalid extensions are discarded, not repaired

`setExtension()` lowercases and trims, then discards the extension if anything other than
letters and digits remains. 3.x deleted the offending characters, which could build an
extension the client never sent. Files that used to land with a repaired extension now land
with none; add a `FileType` validation to reject them up front.

## 9. Swap `Extension` and `Mimetype` for `FileType` (recommended)

`Validation\Extension` and `Validation\Mimetype` still work and raise no notice, but they're
deprecated. Side by side they check two independent allow-lists, so a file can pass both
while the two answers describe different formats. `FileType` requires the extension and the
sniffed contents to agree:

```php
// 3.x
$file->addValidations([
    new Extension(['png', 'gif']),
    new Mimetype(['image/png', 'image/gif']),
]);

// 4.0
$file->addValidation(
    (new FileType('png', 'image/png'))->allow('gif', 'image/gif')
);
```

One format per `allow()` call, or every extension is paired with every media type.

## 10. If you implement the library's interfaces

**`FileInfoInterface::getHash(string $algorithm = 'sha256'): string`** is now declared, and
`getMd5()` is not.

**`setName()`, `setExtension()` and `setNameWithExtension()` no longer declare a return
type.** They declared `: FileInfo`, so an implementation that didn't extend `FileInfo`
couldn't satisfy them: omitting the type was a fatal, and matching it compiled and then
raised a `TypeError` on the first call. **Delete `: FileInfo` from your own setters and
return `$this`.** Leaving it keeps the same `TypeError`, since the declaration is your
class's. `FileInfo` keeps it on its own methods, so existing subclasses need no change.

**`StorageInterface::upload()` now specifies its return value:** a locator your storage
defines, passed back unchanged by `File::getUploadedLocators()`. Only `FileSystem` promises
an absolute local path, so the `unlink()` rollback in the README suits it and not
necessarily yours. Never return `''`; throw instead.

**Throw `\GravityPdf\Upload\Exception` from custom validators.** A `\LogicException`
propagates out of `isValid()` untouched, since it signals broken code rather than a rejected
file. Anything else is still caught, but neither its message (which can contain server
paths) nor its class name reaches `getErrors()`. Catch it in the validator and rethrow an
`Upload\Exception` if either belongs in what the user sees. `FileInfo::getHash()` throws
`\InvalidArgumentException` for an unsupported algorithm for the same reason: a misspelled
algorithm is broken code, not a rejected file.

**`Storage\FileSystem` no longer trusts the name a `FileInfoInterface` returns.** It reduces
the name to its `basename()` and rewrites the characters Windows disallows (`<` `>` `:` `"`
`|` `?` `*`) to `-`. It refuses a name that starts with a dot, contains control or bidi
characters, resolves to a Windows device such as `CON.txt`, or points at a symlinked
destination. A name can no longer select a subdirectory; construct the `FileSystem` with
it instead. The shipped `FileInfo` already rewrites or blanks all of these.

**Other changes:**

* `blockExtensions()` takes a required, non-empty array. No argument no longer means the
  default list: pass `FileSystem::getDefaultBlockedExtensions()`.
* `File::__call()` throws `\BadMethodCallException` and `FileInfo::createFromFactory()`
  throws `\LogicException`, both previously `\GravityPdf\Upload\Exception`.
* `Exception::__construct()` declares `string $message` and accepts `$code` and `$previous`.
  Subclasses need no change: PHP exempts constructors from signature compatibility, and 3.x
  already declared `strict_types=1`.
* Both validation callbacks now fire for every file, including one that fails the
  uploaded-file check. `afterValidate` was previously skipped for those.
* The filename rules moved to the new `GravityPdf\Upload\Filename`:
  `MAX_EXTENSION_LENGTH`, `RESERVED_WINDOWS_NAMES`, `BIDI_CONTROLS` and
  `CONTROL_CHARACTERS`. `FileInfo`'s behaviour is unchanged and its
  `getReservedWindowsNames()` override seam still works.

## Everything else

Each of these is listed in full in the [changelog](CHANGELOG.md).

* **`upload()` no longer runs an `isValid()` override.** Both entry points validate through
  a private method that reports which files passed, so `uploadValid()` doesn't validate a
  second time and let a validator with a side effect see every file twice. Validation still
  runs on every `upload()` call, and `isValid()` is unchanged when you call it yourself. But
  a `File` subclass that overrode `isValid()` to add a check of its own (a quota, a
  per-tenant policy, an extra scan) no longer has that check run by `upload()`. **Move it
  into a `ValidationInterface`**, which both entry points honour.
* **A `Storage\FileSystem` subclass that overrides `resolveFilename()` no longer decides
  which names are refused.** `upload()` applies every refusal to whatever the seam returns:
  `''`, `.`, `..`, a leading dot, control characters and bidi controls, on top of the device
  names and blocked extensions it already checked. The shipped seam refused these itself, so
  nothing changes unless you replaced it — but if you did, names it used to store are now
  refused with `'Invalid destination file name'`. That is the point: `.htaccess` was one of
  them, since a dotfile presents no extension for the deny-list to match. Reproducing the
  checks in your override is no longer needed, and is harmless if you already do.
* **The storage collision message changed.** `'File already exists'` is now
  `'A file named "report.txt" already exists'`, since sanitizing is many-to-one and the old
  wording couldn't say which name collided. The name is sanitized for display first, and a
  name left with nothing by that reports `'A file with that name already exists'`, so there
  are two wordings to match rather than one. **Update anything matching the old string.** Log
  storage messages rather than showing them to whoever submitted the file: the wording
  distinguishes a name that exists from a destination that couldn't be created, which is an
  existence check on your upload directory. `getErrors()` is the list written to be rendered.
* Uploads are written to a temporary name in the destination directory and moved into
  place, so no partial content is readable under the final name. Watch for transient
  `upload-<random>.part` entries if you monitor the directory. With `overwrite` off (the
  unchanged default) the name is claimed first by an empty file, so a process killed
  mid-transfer leaves a 0-byte file that collides with the next upload of that name until
  it's cleared. A failed `chmod()` now abandons the upload rather than storing the file at
  the umask's mode.
* With `overwrite` off, the existence check and the create are one exclusive operation, so
  two concurrent requests can't both win the same destination.
* `getHash()`, `getMimetype()`, `getSize()` and `getDimensions()` return `''`/`false`/`0`
  for an unreadable file instead of raising an error.
* `isValid()` resets the error list on each call, so the usual `isValid()` then `upload()`
  sequence no longer reports every error twice.
* `$file[0] = $value` throws `InvalidArgumentException` unless the value is a
  `FileInfoInterface`.
* Sanitized filenames are valid UTF-8 where `ext-mbstring` is available, so they survive
  `json_encode()` and `utf8mb4` columns. Without it the guarantee doesn't hold, as in 3.x.
* Windows reserved names are matched against the whole extension, so `doc.conf` keeps
  `conf` where 3.x cut it to `f`.
* `Validation\FileType` rejects an empty string on either side, not just an empty array.
  A leading dot on an extension is accepted and removed, in both `FileType::allow()` and
  `FileSystem::blockExtensions()`.
* `setExtension()` discards an extension longer than 32 bytes
  (`Filename::MAX_EXTENSION_LENGTH`). `setName()` strips `\x7F` along with bidi overrides,
  zero-width marks, line and paragraph separators, and the BOM.
* A `$_FILES` entry that isn't one of the two shapes the SAPI builds is reported through
  `getErrors()` rather than warning or raising a `TypeError`. Only relevant if something
  other than the SAPI populates `$_FILES`.
* Nothing to change, but new: `FileList` takes `FileInfoInterface` objects directly, so
  feeding the library from PSR-7, a worker runtime or a test no longer means faking
  `$_FILES`. Two things are yours to answer there, neither a default: override
  `FileInfo::isUploadedFile()`, and call `FileSystem::acceptFilesNotUploadedByPhp()`. See
  [Uploads from another source](README.md#uploads-from-another-source).
