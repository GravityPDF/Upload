# Upload 4.0.0

A security-hardening release, with new protections enabled by default. Step-by-step upgrade guide in [UPGRADE.md](https://github.com/GravityPDF/Upload/blob/main/UPGRADE.md).

## Defaults That Changed

* **A default extension deny-list is on.** `FileSystem` refuses to write files with the extensions in `FileSystem::getDefaultBlockedExtensions()`
* **The deny-list covers markup as well as executables.** The 15 in `FileSystem::MARKUP_EXTENSIONS` (`html`, `htm`, `xhtml`, `xht`, `xhtm`, `svg`, `svgz`, `xml`, `xsl`, `xslt`, `js`, `mjs`, `swf`, `mht`, `mhtml`) join the 59 in `EXECUTABLE_EXTENSIONS`, for a default list of 74. A server does not execute the markup ones, but serving them from your own origin is a stored XSS risk. **If you accept SVG or HTML uploads, pass `FileSystem::EXECUTABLE_EXTENSIONS` and sanitize the contents yourself**
* **The deny-list is checked against every dot-separated component of the name**, not only the extension `pathinfo()` returns, because a web server does not necessarily read a name the same way. `FileInfo` rewrites dots inside the name to hyphens as it always has, so `archive.config.zip` is stored as `archive-config.zip`; the wider check applies to a `FileInfoInterface` of your own. Trailing dots and spaces are removed before the check and before the write. The first component is not treated as an extension, so a file called `php` is still stored
* **Stored files get mode `0640`** (`FileSystem::DEFAULT_MODE`), where 3.x left the mode to the process umask
* **`File::upload()` refuses when no validations have been added**
* **`File::upload()` refuses when the collection is empty.** Validations pass vacuously with nothing to run against, so it previously returned `true` having stored nothing. A failed transfer is unchanged and still reports `'File validation failed'` with the detail in `getErrors()`
* **`FileSystem::upload()` rejects a destination beginning with a dot, and any name containing control characters.** It also rewrites the characters Windows refuses in a filename (`<`, `>`, `:`, `"`, `|`, `?`, `*`) to `-`, and rejects a name Windows resolves to a device rather than a file, such as `CON.txt`. `FileInfo` already rewrote or blanked all of these, so this only matters for a `FileInfoInterface` of your own

## Security Fixes

* **`FileInfo::setExtension()` no longer rewrites an invalid extension into a valid one.** Stripping the disallowed characters could produce an extension the client never sent. An extension that is not lowercase-alphanumeric once trimmed is now discarded, and the file is stored with **no extension**
* **`FileInfo::setExtension()` discards an extension longer than `Filename::MAX_EXTENSION_LENGTH` (32 bytes)**, which counts against the same 255 byte budget as the name
* **`FileInfo::setName()` rewrites the C1 control characters (`U+0080`–`U+009F`) and `\x7F` to `-`.** Its filter covered only the C0 range, and C1 survived because those bytes are valid UTF-8. One of them ends a line for anything reading `\R`, so a stored name could span what looked like several lines of a log. `Storage\FileSystem` refuses both for the same reason
* **`FileInfo::setName()` removes the characters that reorder, break or hide the text around them** — the whole of Unicode's `Bidi_Control` property including `U+061C`, plus the zero-width marks (`U+200B`–`U+200F`), the line and paragraph separators (`U+2028`, `U+2029`), `U+206A`–`U+206F` and the BOM (`U+FEFF`). Stored names are read by people, in admin listings, emails and log lines, and these let a name display as something other than what it is. The set is `Filename::BIDI_CONTROLS`, and **`Storage\FileSystem` refuses a name still carrying one** — it deletes nothing, since inventing a filename is the value object's job
* **The reserved Windows device names now include `COM0`, `LPT0` and the superscript variants** (`COM¹`, `COM²`, `COM³`, `LPT¹`, `LPT²`, `LPT³`), which Microsoft lists alongside `COM1`–`COM9`. A name matching one is blanked to `unnamed-file`, as the rest of the list always was. The list is now `Filename::RESERVED_WINDOWS_NAMES`
* **`FileSystem::upload()` reduces the destination to a basename** and rejects `''`, `.`, `..` and names containing a null byte
* **`FileSystem::upload()` refuses to write to a destination that is a symbolic link**
* **Uploads are written through a staged file rather than straight to the destination.** The file goes to a temporary name inside the destination directory and is then moved onto the destination in a single operation, which does not follow a symbolic link standing there, so no partial content is ever readable under the final name. With `overwrite = false` the name is claimed first by an empty placeholder at the configured mode, so a process killed mid-transfer leaves a 0-byte file rather than nothing
* **The overwrite guard is atomic with respect to two requests creating the same name.** With `overwrite = false`, the existence check and the create are a single exclusive operation rather than two steps, the file it creates is verified to be the destination itself, and a placeholder left by a failed move is cleaned up
* **A `chmod()` that fails is no longer ignored.** The mode is applied to the staged file and the upload is abandoned if it cannot be set, rather than storing the file at whatever the umask allowed while reporting the documented `0640`
* **`Validation\FileType::allow()` rejects empty and whitespace-only values on either side.** An empty media type is what `getMimetype()` reports for a file it cannot read, and it would match one. A lone `.` is rejected as an extension; a leading dot is accepted and removed
* **A name from a custom `FileInfoInterface` no longer reaches `getErrors()` unsanitized.** `File::isValid()` formatted it into its error strings as-is, so an implementation supplied through `FileInfo::setFactory()` could put a line break, a terminal escape or a bidi override into a string the README encourages callers to render. The shipped `FileInfo` was unaffected
* **Raw `$_FILES[…]['name']` no longer reaches `getErrors()`.** The `File` constructor's error path sanitizes it like every other error string. Escaping on output still applies
* **`getHash()`, `getMimetype()`, `getSize()` and `getDimensions()` handle a missing file**, returning `''`/`false`/`0` where `getSize()` raised a `RuntimeException`, `getHash()` a `TypeError`, and the other two emitted PHP warnings
* **A lifecycle callback that calls back into its own `File` is refused.** `isValid()`, `upload()` and `uploadValid()` throw `\LogicException` when one of them is already running on that object. A run resets the error list and the locator list at its start and decides from the error list which files to store, so a nested call reset both underneath it: a file that had failed could end up in the set handed to storage, and the locator list could lose what was already written. The lock covers the storing as well as the validating, since `afterUpload` fires after the validations are over
* **`File::isValid()` absorbs non-`Upload\Exception` throwables** from validators instead of letting one abort the batch, recording `Validation could not be completed` with nothing appended — neither the message, which can contain server paths, nor the class name, which is the application's internal structure. `\LogicException` is re-thrown, because PHP defines the type as a bug in the program rather than a file that failed
* **The `File` constructor guards a malformed `$_FILES` entry in both of its branches.** A non-string `tmp_name` or `name` in the single-file shape, and a `name` or `error` that is not an array of the same length as `tmp_name` in the multi-file shape, are reported as unreadable rather than raising an uncaught `TypeError` or a warning. A `name` that was a string was the quiet one: indexing it yielded a single character, which passed the per-file check, so the file was stored under a one-letter name. No SAPI builds either shape, but a PSR-7 bridge, test harness or middleware can. A malformed entry costs only itself; well-formed files in the same request are still collected

## Breaking Changes

* **A developer error is no longer thrown as the type callers catch for a failed upload.** `File::upload()` throws `\LogicException` when no validations have been configured, and `FileInfo::getHash()` throws `\InvalidArgumentException` for an algorithm this PHP build does not support. 3.x had no no-validations check at all, and `getHash()` let `hash_file()` raise a `ValueError`. Both are typed now so a misconfigured object cannot be mistaken for a rejected file: `Upload\Exception` is what `File::isValid()` catches and formats into `getErrors()`. **Code catching `\GravityPdf\Upload\Exception` specifically around `upload()` needs `\LogicException` too**; a `catch (\Exception $e)` as shown in the README is unaffected
* **`File::upload()` no longer dispatches through the public `isValid()`.** Both entry points now validate through a private `runValidations()` that reports *which* files passed, so `uploadValid()` does not have to validate a second time to find out — a validator with a side effect, a virus scanner or a remote lookup, must see each file once. Validation still runs on every `upload()` call and `isValid()` is unchanged for anyone calling it directly, but **a subclass that overrode `isValid()` to add a check of its own no longer has that check run by `upload()`**. Move it into a `ValidationInterface`, which both entry points honour
* **`File::__call()` throws `\BadMethodCallException`** for a method that is not on `FileInfoInterface`, so a typo in a method name is no longer reported to the end user as a failed upload. **`FileInfo::createFromFactory()` throws `\LogicException`** when an installed factory returns the wrong type; it threw a plain `\RuntimeException`, which no `Upload\Exception` handler caught either way, so that one is for consistency rather than a migration hazard
* **`FileInfoInterface`'s three setters no longer declare a return type.** They declared `: FileInfo`, the concrete class, so an implementation that did not extend `FileInfo` satisfied the compiler and then raised a `TypeError` on the first setter call: it could not return `$this`, and could not narrow its own return type either, since covariant returns arrived in PHP 7.4 and this library supports 7.3. **A custom `FileInfoInterface` is only now actually implementable.** `FileInfo` is unchanged, and an existing subclass declaring `: FileInfo` still satisfies the interface
* **`FileSystem::blockExtensions()` takes a required, non-empty list.** It previously defaulted to `null` for the full deny-list while `[]` meant none, so one expression turned a security control off depending on what a config key held. It now throws `InvalidArgumentException` on `[]`, and `allowAnyExtension()` is the only way to empty the list. Pass `FileSystem::getDefaultBlockedExtensions()` for the old no-argument meaning
* **`File::humanReadableToBytes()` now understands a trailing `B`, which changes an existing limit.** `'5MB'` previously parsed as 5 bytes, because `substr($input, -1)` saw only the `B`; it now parses as 5 MiB, matching what `Validation\Size`'s docblock always advertised. **If you pass a `MB`/`KB`/`GB` suffixed size anywhere, your effective limit becomes much larger.** Sizes without the trailing `B` are unaffected
* **`File::humanReadableToBytes()` throws on an unrecognized unit** instead of reading it as bytes. `'1T'` evaluated to `1`, so `new Size('1T')` was a one byte bound that rejected every upload while reading as a generous one. `B`, `K`, `M` and `G` are unchanged. **Check any size bound whose unit is not one of those four**
* **`FileInfo::getMd5()` has been removed**, from `FileInfo` and from `FileInfoInterface`. Call `getHash('md5')` for the same digest
* **`FileInfo::getHash()` defaults to `sha256` instead of `md5`**, and is now declared on `FileInfoInterface`. A no-argument call returns a different string than it did in 3.x
* **The extension deny-list and the reserved-name check moved out of `FileSystem::resolveFilename()` into `upload()`.** They ran inside the naming seam, so a subclass overriding `resolveFilename()` — the documented way to change how names are chosen — dropped both refusals without mentioning extensions. Both are now `private` and applied to whatever name the seam returns
* **The filename rules moved to the new `\GravityPdf\Upload\Filename`.** `MAX_EXTENSION_LENGTH`, `RESERVED_WINDOWS_NAMES`, `BIDI_CONTROLS` and `CONTROL_CHARACTERS` were public constants on `FileInfo`. `FileInfo` behaves exactly as before and delegates; only the constants moved, and they were introduced in this same release, so nothing released ever referenced them
* **`Filename::BIDI_CONTROLS` is a bare pattern fragment**, matching `CONTROL_CHARACTERS`. It was a complete delimited pattern while its neighbour was not, and the README listed the two side by side as if interchangeable
* **`FileSystem::createStagingPath()`, `discardFailedUpload()`, `releaseReservation()`, `refuseBlockedExtensions()`, `refuseReservedWindowsName()`, `File::formatUploadError()`, `getSanitizedFilename()`, `FileInfo::finalizeName()`, `acceptExtension()` and `FileType::normalize()` are `private`.** Each had one in-class caller and none was an extension seam; `formatUploadError()` also ran from the constructor, so an override saw a half-built object
* **Several methods added earlier in this release were renamed before it shipped.** `File::getUploadedFiles()` is `getUploadedLocators()`, since it returns storage-defined locators rather than files and collided with PSR-7's method of the same name; `FileSystem::defaultBlockedExtensions()` is `getDefaultBlockedExtensions()`; `FileSystem::statEntry()` is `lstatEntry()`, since the body is `lstat()` and not following the link is the point; `File::uploadErrorMessage()` is `formatUploadError()`; `Filename::sanitize()`/`sanitizeWithExtension()` are `sanitizeName()`/`sanitizeNameWithExtension()`, because on a class called `Filename` the short name was the obvious call and the wrong one; and `normalizeExtension()` is `acceptExtension()` on both `Filename` and `FileInfo`, because it validates and discards rather than normalizing
* `FileInfo::setExtension()` discards non-alphanumeric extensions rather than stripping characters from them (see above). Files that previously landed with a synthesized extension now land with none
* Windows reserved names are matched against the whole extension instead of as a substring. `doc.conf` kept its extension as `f` and `ico.icon` as `i`; both are now preserved intact (`conf`, `icon`). `x.aux` is still blanked, because `aux` is itself a reserved device name
* `File::__construct()` no longer creates a `FileInfo` for a single file that failed to upload, matching what the multi-file branch has always done. `count($file)` is `0` rather than `1` for e.g. `UPLOAD_ERR_NO_FILE`, and reading metadata on it no longer raises an uncaught `ValueError`
* `File::isValid()` resets the error list to the errors recorded during construction instead of appending to whatever the previous call left behind, so the usual `isValid()` then `upload()` sequence no longer reports every error twice. Validations are still re-run on every call, so a rename between the two cannot skip revalidation
* `File::humanReadableToBytes()` throws `InvalidArgumentException` on input it cannot parse. `'abc'` and `''` previously evaluated to `0`, silently configuring a `Size` bound that rejects every upload. A negative size such as `'-5M'` throws for the same reason
* `File::humanReadableToBytes()` supports fractions (`'0.5M'` → `524288`, previously `0`), tolerates surrounding and internal whitespace (`' 2 m '` → `2097152`, previously `2`) and clamps to `PHP_INT_MAX` instead of raising a `TypeError` on very large inputs
* `File::offsetSet()` throws `InvalidArgumentException` unless the value is a `FileInfoInterface`. The type was previously only a docblock, so `$file[0] = 'string';` succeeded and then faulted inside `isValid()`
* `FileInfo::getHash()` returns `''` on an unreadable file, where it previously raised a `TypeError`
* `FileInfo::getSize()` returns `false` for an unreadable file, matching the `int|false` that `FileInfoInterface` documents. On PHP 8 `SplFileInfo::getSize()` throws instead
* Sanitized filenames are valid UTF-8 where `ext-mbstring` is loaded (newly listed under `suggest`; `symfony/polyfill-mbstring` also works). Without it, truncation can still split a multibyte character, as in 3.x
* `Exception::__construct()` declares `string $message`. It was untyped while the rest of the codebase is strict

## New Features

* `Validation\FileType($extensions, $mimetypes)`: pairs the extension with the sniffed media type and requires them to agree. `Extension` and `Mimetype` check two independent allow-lists, so given `Extension(['png', 'gif'])` and `Mimetype(['image/png', 'image/gif'])` a file sniffed as `image/gif` and stored as `avatar.png` satisfies both. Both sides are folded to lowercase, so a custom `FileInfoInterface` returning `IMAGE/PNG` still matches `image/png`. Either side accepts a list, so `new FileType(['jpg', 'jpeg'], 'image/jpeg')` covers one format; chain `allow($extensions, $mimetypes)` for further formats rather than widening a single call, which would pair every extension with every media type
* `FileSystem::blockExtensions(array $extensions)`: the deny-list applied at the write, independent of whichever validations the caller configured. The argument is required and must not be empty — pass `getDefaultBlockedExtensions()` for the built-in set, `allowAnyExtension()` to turn the list off. Each entry is lowercased, trimmed, stripped of a leading dot and split on any remaining dots, because the list is matched one component at a time: `'.php'` blocks `php`, and `'tar.gz'` blocks `tar` and `gz` independently rather than nothing. Empty and duplicate entries are dropped. An allow-list via `Validation\FileType` remains the primary control
* `FileSystem::allowAnyExtension()`: the only way to empty the deny-list, so turning that control off is always this call rather than a config value that turned out to be missing
* `FileSystem::getDefaultBlockedExtensions(): string[]`: `EXECUTABLE_EXTENSIONS` merged with `MARKUP_EXTENSIONS`. A method rather than a constant because constant expressions cannot call `array_merge()` on PHP 7.3
* `FileSystem::setMode(?int $mode)`: permissions for stored files, defaulting to `FileSystem::DEFAULT_MODE`
* `FileSystem::getBlockedExtensions()`, `getMode()`, `getOverwrite()`, `File::allowsUnvalidatedUploads()` and `FileType::getAllowedTypes()`: read back what was configured, so a test or a security scan can assert the policy rather than infer it
* `File::allowUnvalidatedUploads(): File`: the opt-out for the new requirement that `upload()` has something to validate against
* `File::getUploadedLocators(): string[]`: the locators written by the most recent `upload()`, emptied at the start of each call so a failure cannot hand back an earlier call's paths. A multi-file upload is not atomic, so when a later file fails this is how a caller finds out what was already committed. `getErrors()` is empty in that case, because a storage failure is not a validation failure
* `File::uploadValid(): bool`: stores the files that pass validation and leaves the rest in `getErrors()`. Nothing throws for a rejected file, so the return value is the only signal that one was, and a caller who abandons the request on `false` owns what is already on disk. `getUploadedLocators()` is keyed by collection offset for that reason — sparse after a partial upload, so the locator at `$i` still belongs to `$file[$i]` rather than silently renumbering onto a rejected file's metadata. `upload()` is all-or-nothing, so one unreadable photo in a `name="photos[]"` field discards the nine that were fine and the submitter has to re-select every one of them. Call it where you would call `upload()`, and check what it returns rather than catching a validation failure. `upload()` keeps all-or-nothing and is unchanged, since a set of files that is only meaningful complete is a legitimate thing to depend on. Returns `true` when every file in the collection was stored and `false` when at least one was rejected, with `getUploadedLocators()` listing what landed; a file that never transferred counts as rejected. The `\LogicException` for no validations and the `Exception` for an empty collection are the same as `upload()`'s, and a storage failure still throws part-way through
* `\GravityPdf\Upload\Filename`: the rules for what counts as a usable filename, in one place, so the two layers that apply them cannot drift apart. Constants `MAX_LENGTH`, `MAX_EXTENSION_LENGTH`, `FALLBACK`, `CONTROL_CHARACTERS`, `BIDI_CONTROLS` and `RESERVED_WINDOWS_NAMES`; `sanitizeNameWithExtension()` for a whole filename, plus `acceptExtension()`, `deviceComponent()`, `extensionComponents()`, `normalizeComponents()`, `isReservedDeviceComponent()`, `hasControlCharacters()` and `hasBidiControls()` for anyone reproducing the storage refusals in an implementation of their own. `CONTROL_CHARACTERS` and `BIDI_CONTROLS` are bare pattern fragments, not delimited patterns — use the two predicates rather than passing them to `preg_match()`
* `FileInfo::resetFactory(): void`: clears a factory installed with `setFactory()`. There was previously no supported way to undo it in a long-lived process or between tests
* Three new `protected` members on `Storage\FileSystem`: `resolveFilename()` decides the stored name and is the only one meant to be overridden; `reserveDestination()` and `lstatEntry()` exist so a test can reach branches that otherwise need a file-system race. `FileInfo::isReadableFile()` is the guard the metadata accessors share
* New storage exception messages: `'Invalid destination file name'`, `'Destination is a symbolic link'`, `'Permissions could not be applied to the stored file'` and `'Could not generate a temporary file name'`
* `Exception::__construct()` accepts `$code` and `$previous`, so the exception chain is no longer discarded

## Deprecations

Both still work and neither raises a runtime notice.

* `Validation\Extension` and `Validation\Mimetype` are deprecated in favour of `Validation\FileType`. Used side by side they check independent allow-lists, which is the gap `FileType` closes

## Bug Fixes

* Case folding no longer depends on the host's locale. `strtolower()` follows `LC_CTYPE` before PHP 8.2, so on four of the eight supported versions an application calling `setlocale()` changed what counted as the same extension, media type or device name — under a Turkish locale `strtolower('TIFF')` is `tıff`, which 4.0's stricter `setExtension()` discards, storing `photo.TIFF` with no extension at all
* Filename UTF-8 handling detects `ext-mbstring` with `function_exists()` rather than `extension_loaded()`, so `symfony/polyfill-mbstring` satisfies it as the README has always said it does. The polyfill is userland and registers no extension, so an `extension_loaded()` gate would silently drop every polyfilled install onto the byte-wise fallback
* `mb_detect_encoding()` is called with an explicit detect order and falls back to UTF-8, so an application's own `mb_detect_order()` cannot change what this library makes of the same bytes. The fallback covers the orders for which detection returns `false`, where `mb_strcut()` would otherwise raise a `ValueError`
* `FileInfo::setExtension()` re-divides the 255 byte filename budget. `setName()` split it against whatever extension was set at the time, so `setName()` followed by `setExtension()` produced a name past the limit and a write that failed with a message about the destination directory
* A file named `0` keeps its name. `empty('0')` is true, so it was replaced with `unnamed-file`
* A field named `f[0][0]` no longer emits "Array to string conversion" or reports the file as `"Array: …"`. It nests `$_FILES` one level deeper than either shape `File` understands, so the entry is an array where a path belongs; those entries are now skipped with an error recorded
* `afterValidate` fires for a file that fails the uploaded-file check. The four callbacks are documented as firing once per file, and `beforeValidate` was running without its pair, so a caller using the two to open and close a per-file resource leaked on exactly the files that failed
* `FileSystem::upload()` names the file in its collision message: `'File already exists'` is now `'A file named "report.txt" already exists'`. Sanitizing is many-to-one, so two names a caller sees as distinct can resolve to one destination, and the old message gave them nothing to work with. The basename only, never the path. **Update any code matching on the old string**
* `FileSystem::upload()` distinguishes a destination it could not create from one that already exists, throwing `'Destination file could not be created'` for the first. Reporting a directory removed or de-permissioned after the constructor checked it as a collision would send the caller into a rename-and-retry loop that cannot succeed
* `FileInfo::getDimensions()` no longer emits an `E_NOTICE` for an upload shorter than 12 bytes, which is the header `getimagesize()` reads. An empty file is ordinary input, and the notice put the absolute path of the temporary file in front of the caller's error handler — and into the response on a site with `display_errors` on. It returned the documented `0`/`0` either way
* `Validation\Size` reports a file whose size cannot be determined as a validation error instead of letting `SplFileInfo::getSize()`'s `RuntimeException` escape
* Corrected `@throws RuntimeException` annotations on the shipped validators to `@throws \GravityPdf\Upload\Exception`, which is what they actually throw
* `StorageInterface::upload()` documents its return value. The interface declared none, and its `@throws` read "If validation fails", which storage does not do. `File::getUploadedLocators()` is the first API to hand those strings to callers, so what they are is now specified: a locator the implementation defines, an absolute path only for `Storage\FileSystem`. The README's rollback example says the same, because `unlink()` is wrong for a storage that returns a key rather than a path
* Documented that a storage exception message is for your logs rather than for the person who submitted the file. It distinguishes a name that already exists from a destination that could not be created, which is an existence check on your upload directory. `getErrors()` remains the list written to be rendered

# Upload 3.0.0

## Breaking Changes

* Revert removal of `\GravityPdf\Upload\File::__call()` magic method in 2.0.0, restoring API functionality to match v1
* Add `setNameWithExtension()` method to `\GravityPdf\Upload\FileInfoInterface`
* Change type signature `\GravityPdf\Upload\FileInfoInterface::setName(string $name)` 
* Change type signature `\GravityPdf\Upload\FileInfoInterface::setExtension(string $extension)`
* Remove `\GravityPdf\Upload\Validation\Dimensions` validation class

# Upload 2.0.0

## Breaking Changes
* PHP 7.3+ (previously PHP5.3+)
* Namespace Change: `\Upload` -> `\GravityPdf\Upload`
* Sanitize Filename and Extension: replace invalid/unsafe/reserved characters/words, trim, prevent < 255 byte filenames. [See the unit tests for the expected transformations](https://github.com/GravityPDF/Upload/blob/main/tests/Upload/FileInfoTest.php).
* Remove `\GravityPdf\Upload\File::__call()` magic method which would call the underlying `FileInfoInterface` object(s) and return the result as a string or array. Use `foreach($file as FileInfoInterface $fileInfo) { ... }` instead.
* Changed return value of `\GravityPdf\Upload\Storage\FileSystem::upload()` to the destination file path `string` (previously `void`)
* Strict type support added
* Replace `\Upload\Exception\UploadException` with `\GravityPdf\Upload\Excelption`
* `\GravityPdf\Upload\File` no longer extends `\SplFileInfo`
* Signature of `\GravityPdf\Upload\File::__construct()` changed to `__construct(string $key, \GravityPdf\Upload\StorageInterface $storage)`
* Signature of `\GravityPdf\Upload\File::addValidations()` changed to `addValidations(array $validations)`. Use new `addValidation(\GravityPdf\Upload\ValidationInterface $validation)` method to add single validation.
* `\GravityPdf\Upload\File::validate()` was replaced by `\GravityPdf\Upload\File::isValid()`
* Signature of `\GravityPdf\Upload\File::upload($newName = null)` changed to `upload()`. Use `\GravityPdf\Upload\File::setName(string $name)` or `\GravityPdf\Upload\File::setNameWithExtension(string $name)` before calling `upload()` to change the file name.
* `\Upload\Storage\Base` has been removed and `\GravityPdf\Upload\Storage\FileSystem` instead implements `\GravityPdf\Upload\StorageInterface`
* Signature of `\Upload\Storage\FileSystem::upload(\Upload\File $file, $newName = null)` changed to `\GravityPdf\Upload\Storage\FileSystem::upload(\GravityPdf\Upload\FileInfoInterface $fileInfo)`
* `\Upload\Validation\Base` has been removed and `\GravityPdf\Upload\Validation/*` classes instead implements `\GravityPdf\Upload\ValidationInterface`
* Classes that implement `\GravityPdf\Upload\ValidationInterface` must have `validate` method with the signature `validate(\Upload\FileInfoInterface $fileInfo)` and this method should throw the exception `\GravityPdf\Upload\Exception` if the file has failed validation

## New Features
* Support for UTF-8 Filenames
* Enhanced file name and extension sanitizing (**you still need to escape HTML on output or when inserting into database**)
* Added `\GravityPdf\Upload\FileInfo::setNameWithExtension(string $name)` (instead of using `setName()` and `setExtension()` separately)
* Added `\GravityPdf\Upload\Storage\FileSystem::getDirectory()` to return directory that has been set
* Added `beforeValidation(callable $callable)`, `afterValidation(callable $callable)`, `beforeUpload(callable $callable)`, and `afterUpload(callable $callable)` to `\GravityPdf\Upload\File`. The callable will receive a `\GravityPdf\Upload\FileInfoInterface` object as the first parameter.
* `\GravityPdf\Upload\File` implements `\ArrayAccess, \IteratorAggregate, \Countable` which allows you to treat `File` as an array and access the underlying `\GravityPdf\Upload\FileInfo` objects to get info about each individual file for the current `$key`. This is useful if your file upload HTML field accepts multiple files.
* Added `\GravityPdf\Upload\FileInfoInterface` and `\GravityPdf\Upload\FileInfo` objects to represent each individual image. The methods include:
  1. `getPathname()`
  2. `getName()`
  3. `setName(string $name)`
  4. `getExtension()`
  5. `setExtension(string $extension)`
  6. `getNameWithExtension()`
  7. `setNameWithExtension(string $filename)`
  8. `getMimetype()`
  9. `getSize()`
  10. `getMd5()`
  11. `getDimensions()`
  12. `isUploadedFile()`
*

## Bug Fixes
* Resolved PHP 8.1 warnings
