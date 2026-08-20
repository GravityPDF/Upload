# Upload 4.0.0

A security release. New protections are on by default and will refuse some uploads that 3.x accepted. [UPGRADE.md](https://github.com/GravityPDF/Upload/blob/main/UPGRADE.md) covers what to check.

## Defaults That Changed

* **An extension deny-list is on.** `FileSystem` refuses to write anything in `FileSystem::getDefaultBlockedExtensions()`: the 59 executable extensions in `EXECUTABLE_EXTENSIONS`, plus 15 markup ones in `MARKUP_EXTENSIONS` (`html`, `htm`, `xhtml`, `xht`, `xhtm`, `svg`, `svgz`, `xml`, `xsl`, `xslt`, `js`, `mjs`, `swf`, `mht`, `mhtml`). A server doesn't execute markup, but serving it from your own origin is stored XSS. **To accept one of them, drop those entries and keep the rest of the list** (`array_diff(FileSystem::getDefaultBlockedExtensions(), ['svg', 'svgz'])`), and sanitize the contents yourself
* **A blocked extension is refused in any dot-separated component of the name**, not only the one `pathinfo()` returns, since a web server does not necessarily treat the last component as the extension. With the shipped `FileInfo` there is only ever one component to check, since `setName()` rewrites interior dots to hyphens as it always has and `archive.config.zip` is stored as `archive-config.zip`. The wider check is there for a `FileInfoInterface` of your own. Trailing dots and spaces are removed first, and the first component is never treated as an extension, so a file called `php` is still stored
* **Stored files get mode `0640`** (`FileSystem::DEFAULT_MODE`). 3.x left the mode to the umask
* **`File::upload()` throws when no validations have been added**
* **`File::upload()` throws on an empty collection**, where it returned `true` having stored nothing
* **`Storage\FileSystem` rejects a destination starting with a dot or containing control characters**, rewrites `<` `>` `:` `"` `|` `?` `*` to `-`, and rejects a Windows device name such as `CON.txt`. Only relevant to a `FileInfoInterface` of your own; the shipped `FileInfo` already rewrote or blanked all of these

## Security Fixes

* **`FileInfo::setExtension()` discards an invalid extension instead of repairing it.** Stripping the disallowed characters could build an extension the client never sent, turning `avatar.p-h-p` into `avatar.php`. An extension validation rejects the repaired name, and 4.0's deny-list refuses it at the write, so this is defence in depth rather than the only thing in the way; it bites where neither applies, such as `Mimetype` or `Size` alone, or `allowUnvalidatedUploads()`. Anything not lowercase alphanumeric once trimmed, or longer than 32 bytes (`Filename::MAX_EXTENSION_LENGTH`), is now discarded and the file stored with **no extension**
* **`FileInfo::setName()` rewrites the C1 controls (`U+0080`–`U+009F`) and `\x7F` to `-`.** The filter covered C0 only, and C1 survived because those bytes are valid UTF-8. One of them ends a line for anything reading `\R`, so a stored name could span what looked like several lines of a log. `Storage\FileSystem` refuses both, in `resolveFilename()`
* **`FileInfo::setName()` deletes bidi and invisible characters** (`Filename::BIDI_CONTROLS`): Unicode's `Bidi_Control` property including `U+061C`, the zero-width marks `U+200B`–`U+200F`, the line and paragraph separators `U+2028` and `U+2029`, `U+206A`–`U+206F`, and the BOM. They let a name display in an admin listing, email or log as something other than what it is. `Storage\FileSystem` refuses a name still carrying one. That check is also in `resolveFilename()`; `Filename::hasControlCharacters()` and `hasBidiControls()` are public so a subclass replacing the seam can reproduce both
* **`Filename::RESERVED_WINDOWS_NAMES` gained `COM0`, `LPT0` and the superscript variants** (`COM¹`, `COM²`, `COM³`, `LPT¹`, `LPT²`, `LPT³`), which Microsoft lists alongside `COM1`–`COM9`. A matching name is blanked to `unnamed-file`
* **Storage refuses a destination that is not a plain filename.** The name is reduced to a `basename()`, and `''`, `.`, `..` and names containing a null byte are rejected. This runs in `resolveFilename()`, the protected naming seam, so a subclass replacing that seam has to reproduce it
* **`FileSystem::upload()` refuses a destination that is a symbolic link**
* **Uploads are staged.** The file is written to a temporary name in the destination directory and moved onto the destination in one operation, which doesn't follow a symlink standing there, so no partial content is readable under the final name
* **The overwrite guard no longer has a check-then-create window.** With `overwrite = false` the existence check and the create are one exclusive operation, so two concurrent requests can no longer both claim the same name, and a placeholder left by a failed move is cleaned up. On POSIX the file created is then verified by inode to be the destination rather than a symlink's target; Windows before PHP 7.4 reports no inode, so that verification does not hold there. A process killed mid-transfer leaves a 0-byte file
* **A failed `chmod()` aborts the upload** rather than storing the file at the umask's mode while reporting `0640`
* **`Validation\FileType::allow()` rejects empty and whitespace-only values.** An empty media type is what `getMimetype()` returns for a file it can't read, and it would have matched one. A lone `.` is rejected as an extension; a leading dot is accepted and removed
* **Every string `getErrors()` returns is sanitized, message as well as filename.** Raw `$_FILES[…]['name']` reached it from the constructor, a name from a custom `FileInfoInterface` from `isValid()`, and a validation failure's message was reported exactly as thrown, so a line break, terminal escape or bidi override could land in a string the README tells you to render. Both halves now go through `Filename::sanitizeForDisplay()`: bidi controls deleted, control characters collapsed to a space, and the result forced to valid UTF-8 where `ext-mbstring` is loaded, so `json_encode(getErrors())` cannot return `false` on one bad byte. No shipped validator puts anything but configuration into a message, so that half is for a validator of your own. The shipped `FileInfo` was unaffected, and sanitizing is still not escaping. Every append goes through one `protected` recorder, `File::recordError()`, so the guarantee is a property of that method rather than of nine call sites remembering — and a `File` subclass recording an error of its own gets it too
* **`getHash()`, `getMimetype()`, `getSize()` and `getDimensions()` handle a missing file**, returning `''`/`false`/`0` where `getSize()` raised a `RuntimeException`, `getHash()` a `TypeError`, and the other two emitted warnings
* **A lifecycle callback can't call back into its own `File`.** `isValid()`, `upload()` and `uploadValid()` throw `\LogicException` when one is already running on that object. Each run resets the error list at its start, and `upload()` and `uploadValid()` reset the locator list too, so a nested call could send a failed file to storage and lose the record of what was already written
* **A validator throwing something other than `Upload\Exception` no longer aborts the batch.** It records `Validation could not be completed` and nothing else: not the message, which can contain server paths, and not the class name. `\LogicException` is re-thrown, since PHP defines that type as a bug in the program
* **A malformed `$_FILES` entry is reported rather than fatal.** A non-string `tmp_name` or `name`, or a `name`/`error` that isn't an array parallel to `tmp_name`, raised an uncaught `TypeError` or a warning. A string `name` in the multi-file shape was the quiet one: indexing it yielded a single character, which passed the per-file check, so the file went through validation under a one-letter name and was stored under it wherever nothing checked the extension. No SAPI builds either shape, but a PSR-7 bridge, test harness or middleware can. Well-formed files in the same request are still collected

## Breaking Changes

* **A developer error no longer throws the type you catch for a failed upload.** `File::upload()` throws `\LogicException` with no validations configured, and `FileInfo::getHash()` throws `\InvalidArgumentException` for an unsupported algorithm, so a misconfigured object can't be mistaken for a rejected file. **Code catching `\GravityPdf\Upload\Exception` around `upload()` needs `\LogicException` too**; `catch (\Exception $e)` is unaffected
* **`upload()` no longer dispatches through the public `isValid()`.** Both entry points validate through a private method that reports which files passed, so `uploadValid()` doesn't validate twice and a validator with a side effect sees each file once. `isValid()` is unchanged when you call it yourself, but **a subclass that overrode it to add a check no longer has that check run by `upload()`**. Move it into a `ValidationInterface`, which both entry points honour
* **`File::__call()` throws `\BadMethodCallException`** for a method the underlying file object does not have, so a typo isn't reported to the end user as a failed upload. `FileInfo::createFromFactory()` throws `\LogicException` where it threw `\RuntimeException`
* **`FileInfoInterface`'s three setters no longer declare a return type.** They declared `: FileInfo`, so an implementation that didn't extend `FileInfo` compiled and then raised a `TypeError` on the first setter call: it couldn't return `$this`, and couldn't narrow the return type either, since covariant returns need PHP 7.4 and this library supports 7.3. **A custom `FileInfoInterface` is only now actually implementable.** An existing subclass declaring `: FileInfo` still satisfies the interface
* **`FileSystem::blockExtensions()` takes a required, non-empty list.** `null` meant the full deny-list and `[]` meant none, so one expression turned a security control off depending on what a config key held. `[]` now throws `InvalidArgumentException`, and `allowAnyExtension()` is the only way to empty the list. Pass `getDefaultBlockedExtensions()` for the old no-argument meaning
* **`'5MB'` now means 5 MiB, not 5 bytes.** `File::humanReadableToBytes()` reads a trailing `B`, where `substr($input, -1)` saw only the `B`. **Every `KB`/`MB`/`GB` bound you pass becomes much larger.** Bounds without the trailing `B` are unchanged
* **An unrecognized unit throws** instead of being read as bytes. `new Size('1T')` was a one-byte bound that rejected every upload while reading as a generous one. **Check any bound whose unit isn't `B`, `K`, `M` or `G`**
* **`FileInfo::getMd5()` is removed** from `FileInfo` and `FileInfoInterface`. `getHash('md5')` returns the same digest
* **`FileInfo::getHash()` defaults to `sha256`**, not `md5`, and is now declared on `FileInfoInterface`
* **The deny-list and reserved-name checks moved from `FileSystem::resolveFilename()` into `upload()`.** They ran inside the naming seam, so a subclass overriding `resolveFilename()`, the documented way to change how names are chosen, dropped both refusals without ever mentioning extensions. Both are now `private` and applied to whatever the seam returns
* **The filename rules moved to `\GravityPdf\Upload\Filename`:** `MAX_EXTENSION_LENGTH`, `RESERVED_WINDOWS_NAMES`, `BIDI_CONTROLS` and `CONTROL_CHARACTERS`, previously public constants on `FileInfo`, which delegates and behaves as before. All four were introduced in this release, so nothing shipped ever referenced them
* **`Filename::BIDI_CONTROLS` is a bare pattern fragment**, matching `CONTROL_CHARACTERS`. It was a complete delimited pattern while its neighbour wasn't, and the README listed the two side by side as if interchangeable
* **Now `private`:** `FileSystem::createStagingPath()`, `discardFailedUpload()`, `releaseReservation()`, `refuseBlockedExtensions()`, `refuseReservedWindowsName()`, `File::getSanitizedFilename()`, `FileInfo::finalizeName()`, `acceptExtension()` and `FileType::normalize()`. Each had one in-class caller and none was an extension seam
* **Methods added earlier in this release were renamed before it shipped:** `File::getUploadedFiles()` to `getUploadedLocators()`, since it returns storage-defined locators and collided with PSR-7's method of the same name; `FileSystem::defaultBlockedExtensions()` to `getDefaultBlockedExtensions()`; `FileSystem::statEntry()` to `lstatEntry()`, since the body is `lstat()`; `File::uploadErrorMessage()` to `formatUploadFailure()`, now `public static` so a `FileList` caller can reach it; `Filename::sanitize()` and `sanitizeWithExtension()` to `sanitizeName()` and `sanitizeNameWithExtension()`, because on a class called `Filename` the short name was the obvious call and the wrong one; and `normalizeExtension()` to `acceptExtension()` on both `Filename` and `FileInfo`, because it validates and discards rather than normalizing
* Windows reserved names are matched against the whole extension rather than as a substring. `doc.conf` kept `f` and `ico.icon` kept `i`; both now keep `conf` and `icon`. `x.aux` is still blanked, since `aux` is itself a device name
* A single file that failed to transfer no longer gets a `FileInfo`, matching what the multi-file branch always did. `count($file)` is `0` rather than `1` for e.g. `UPLOAD_ERR_NO_FILE`, and reading its metadata no longer raises an uncaught `ValueError`
* `isValid()` resets the error list to the errors recorded during construction instead of appending, so the usual `isValid()` then `upload()` sequence no longer reports every error twice. Validations still re-run on each call, so a rename between the two can't skip revalidation
* `File::humanReadableToBytes()` throws `InvalidArgumentException` on input it can't parse. `'abc'` and `''` evaluated to `0`, silently configuring a `Size` bound that rejects every upload; `'-5M'` throws for the same reason
* `File::humanReadableToBytes()` handles fractions (`'0.5M'` is `524288`, was `0`), tolerates whitespace (`' 2 m '` is `2097152`, was `2`) and clamps to `PHP_INT_MAX` instead of raising a `TypeError` on very large input
* `$file[0] = $value` throws `InvalidArgumentException` unless the value is a `FileInfoInterface`. The type was a docblock only, so a string succeeded and then faulted inside `isValid()`
* `FileInfo::getHash()` returns `''` for an unreadable file, where it raised a `TypeError`
* `FileInfo::getSize()` returns `false` for an unreadable file, matching the `int|false` that `FileInfoInterface` documents. On PHP 8 `SplFileInfo::getSize()` throws instead
* Sanitized filenames are valid UTF-8 where `ext-mbstring` is loaded (now listed under `suggest`; `symfony/polyfill-mbstring` also works). Without it, truncation can still split a multibyte character, as in 3.x
* `Exception::__construct()` declares `string $message`, where it was untyped in an otherwise strict codebase

## New Features

* **`Validation\FileType($extensions, $mimetypes)`** requires the extension and the sniffed media type to agree. `Extension` and `Mimetype` check independent lists, so `Extension(['png', 'gif'])` alongside `Mimetype(['image/png', 'image/gif'])` accepts a GIF stored as `avatar.png`. Both sides are folded to lowercase, and either takes a string or an array. Chain `allow($extensions, $mimetypes)` for further formats, one format per call, or every extension is paired with every media type
* **`FileSystem::blockExtensions(array $extensions)`** sets the deny-list applied at the write, independent of your validations. Entries are lowercased, trimmed, stripped of a leading dot and split on any remaining dots, since matching is per component: `'.php'` blocks `php`, and `'tar.gz'` blocks `tar` and `gz` rather than nothing. Empty and duplicate entries are dropped. An allow-list via `FileType` is still the primary control
* **`FileSystem::acceptFilesNotUploadedByPhp()`** and `acceptsFilesNotUploadedByPhp(): bool` store files PHP didn't receive as an upload, which is what a `FileList` needs and a `$_FILES` application never does. **Off by default**: otherwise the write goes through `move_uploaded_file()`, whose refusal is what stops a path someone else influenced landing in your upload directory. It isn't a fallback for a failed `move_uploaded_file()`, which is the branch a manipulated path takes. With it on, the move is a `rename()` with a copy behind it; nothing else about the write changes
* **`FileSystem::allowAnyExtension()`** is the only way to empty the deny-list, so turning that control off is always this call rather than a config value that turned out to be missing
* **`FileSystem::getDefaultBlockedExtensions(): string[]`**: `EXECUTABLE_EXTENSIONS` merged with `MARKUP_EXTENSIONS`. A method rather than a constant because PHP 7.3 constant expressions can't call `array_merge()`
* **`FileSystem::setMode(?int $mode)`**: permissions for stored files, default `FileSystem::DEFAULT_MODE`
* **`FileSystem::getBlockedExtensions()`, `getMode()`, `getOverwrite()`, `File::allowsUnvalidatedUploads()` and `FileType::getAllowedTypes()`** read the configuration back, so a test or a security scan can assert the policy rather than infer it
* **`File::allowUnvalidatedUploads(): File`**: the opt-out for `upload()`'s new requirement that there be something to validate against
* **`File::getUploadedLocators(): string[]`**: the locators written by the most recent upload, emptied at the start of each call so a failure can't hand back an earlier call's paths. Multi-file uploads aren't atomic, so this is how a caller finds out what was already committed when a later file failed. `getErrors()` is empty in that case, since a storage failure isn't a validation failure
* **`File::uploadValid(): bool`** stores the files that pass and leaves the rest in `getErrors()`, where `upload()` is all-or-nothing and discards the nine good photos in a `name="photos[]"` field because the tenth was unreadable. Call it where you'd call `upload()` and check what it returns: nothing throws for a rejected file, so that's the only signal. **The files that passed are already on disk, so clean them up if you abandon the request on `false`.** `getUploadedLocators()` stays keyed by collection offset, sparse after a partial upload, so the locator at `$i` still belongs to `$file[$i]`. A file that never transferred counts as rejected. The `\LogicException` for no validations and the `Exception` for an empty collection are `upload()`'s, and a storage failure still throws part-way through. `upload()` itself is unchanged
* **`\GravityPdf\Upload\FileList`**: `new FileList(array $fileInfos, StorageInterface $storage, array $failures = [])` builds the same collection from `FileInfoInterface` objects you supply, for a PSR-7 request, a worker runtime, a test harness or an upload reassembled from chunks. Extends `File`, so everything after construction is unchanged. Each `$failures` entry is a `[$clientFilename, UPLOAD_ERR_*]` pair for a file that never arrived; a wrong type in either array throws `InvalidArgumentException`. This is the decoupling a PSR-7 bridge needs, not PSR-7 support: no interface, type hint or dependency is added, and the README shows the bridge as caller code
* **`FileList::getSourceKeys(): array`**: the key each file arrived under, by collection offset, so a form field name survives without becoming one. The collection stays keyed `0..n`; writing to an offset or unsetting one drops that key
* **`File::formatUploadFailure(string $clientFilename, int $errorCode): string`**: static, the `getErrors()` string for an `UPLOAD_ERR_*` failure, sanitized the way the `$_FILES` path sanitizes it, so a `FileList` caller reports failed transfers in the same words. An unrecognized code, `UPLOAD_ERR_OK` included, reads as `Unknown Error`
* **`\GravityPdf\Upload\Filename`** holds the filename rules in one place, so the layers that apply them can't drift apart. Constants `MAX_LENGTH`, `MAX_EXTENSION_LENGTH`, `FALLBACK`, `CONTROL_CHARACTERS`, `BIDI_CONTROLS` and `RESERVED_WINDOWS_NAMES`; `sanitizeNameWithExtension()` for a whole filename, `sanitizeForDisplay()` for prose rather than a name (same character sets; controls collapse to a space, whitespace is trimmed, and none of the length, device-name or `%`/`/` handling applies), plus `acceptExtension()`, `deviceComponent()`, `extensionComponents()`, `normalizeComponents()`, `isReservedDeviceComponent()`, `hasControlCharacters()` and `hasBidiControls()` for reproducing the storage refusals in an implementation of your own. The two character-set constants are bare pattern fragments, so use the predicates rather than passing them to `preg_match()`
* **`File::init(StorageInterface $storage): void`**: `protected`, the tail both constructors share, so anything added to the `$_FILES` constructor still holds for `FileList`. Not an extension seam: `protected` only because a subclass constructor can't call a parent's `private` method
* **`FileInfo::resetFactory(): void`** clears a factory installed with `setFactory()`. There was no supported way to undo it in a long-lived process or between tests
* **Three new `protected` members on `Storage\FileSystem`**: `resolveFilename()` decides the stored name and is the only one meant to be overridden; `reserveDestination()` and `lstatEntry()` exist so a test can reach branches that otherwise need a file-system race. `FileInfo::isReadableFile()` is the guard the metadata accessors share
* New storage exception messages: `'Invalid destination file name'`, `'Destination is a symbolic link'`, `'Permissions could not be applied to the stored file'` and `'Could not generate a temporary file name'`
* `Exception::__construct()` accepts `$code` and `$previous`, so the exception chain is no longer discarded

## Deprecations

Both still work and neither raises a runtime notice.

* `Validation\Extension` and `Validation\Mimetype` are deprecated in favour of `Validation\FileType`. Used side by side they check independent allow-lists, which is the gap `FileType` closes

## Bug Fixes

* **Case folding no longer follows the host locale.** `strtolower()` follows `LC_CTYPE` before PHP 8.2, so on four of the eight supported versions a `setlocale()` call elsewhere in the application changed what counted as the same extension, media type or device name. Under a Turkish locale `strtolower('TIFF')` is `tıff`, which 4.0's stricter `setExtension()` discards, storing `photo.TIFF` with no extension at all
* `ext-mbstring` is detected with `function_exists()` rather than `extension_loaded()`, so `symfony/polyfill-mbstring` satisfies it as the README has always said it does. The polyfill is userland and registers no extension, so every polyfilled install was silently on the byte-wise fallback. The check is per function rather than one flag covering five: the polyfill does not ship `mb_strcut()`, so a single flag denied polyfilled installs the UTF-8 repair as well as character-boundary truncation. Its `mb_convert_encoding()` is `iconv()`-backed and warns on the input the repair exists for, so that call is silenced; the result is the same. CI now covers the extension, the polyfill and neither
* `mb_detect_encoding()` is called with an explicit detect order and falls back to UTF-8, so an application's own `mb_detect_order()` can't change what this library makes of the same bytes. The fallback also covers the orders where detection returns `false` and `mb_strcut()` would raise a `ValueError`
* `setExtension()` re-divides the 255 byte filename budget. `setName()` split it against whatever extension was set at the time, so `setName()` then `setExtension()` produced an over-long name and a write that failed with a message about the destination directory
* A file named `0` keeps its name. `empty('0')` is true, so it was replaced with `unnamed-file`
* A field named `f[0][0]` no longer emits "Array to string conversion" or reports the file as `"Array: …"`. It nests `$_FILES` a level deeper than either shape `File` understands, so the entry is an array where a path belongs; those entries are now skipped with an error recorded
* `afterValidate` fires for a file that fails the uploaded-file check. `beforeValidate` was running without its pair, so a caller using the two to open and close a per-file resource leaked on exactly the files that failed
* The storage collision message names the file: `'File already exists'` is now `'A file named "report.txt" already exists'`. Sanitizing is many-to-one, so two names a caller sees as distinct can resolve to one destination, and the old message gave them nothing to work with. Basename only, never the path, and put through `Filename::sanitizeForDisplay()` on the way in: `resolveFilename()` is a seam a subclass can replace with one that keeps the control and bidi characters the shipped one refuses, and a storage message is written to a log, which is what those characters forge a line in. A name with nothing left after that reports `'A file with that name already exists'` rather than an empty pair of quotes. **Update any code matching on the old string**
* `FileSystem::upload()` throws `'Destination file could not be created'` for a destination it couldn't create, rather than reporting it as a collision. A directory removed or de-permissioned after the constructor checked it would otherwise send the caller into a rename-and-retry loop that can't succeed
* `FileInfo::getDimensions()` no longer emits an `E_NOTICE` for an upload shorter than the 12-byte header `getimagesize()` reads. An empty file is ordinary input, and the notice put the tmp file's absolute path in front of the caller's error handler, and into the response on a site with `display_errors` on. It returned the documented `0`/`0` either way
* `Validation\Size` reports a file whose size can't be determined as a validation error instead of letting `SplFileInfo::getSize()`'s `RuntimeException` escape
* `@throws RuntimeException` on the shipped validators corrected to `@throws \GravityPdf\Upload\Exception`, which is what they actually throw
* `StorageInterface::upload()` documents its return value: a locator the implementation defines, an absolute path only for `Storage\FileSystem`. The interface declared none, and its `@throws` read "If validation fails", which storage doesn't do. `unlink()` is wrong for a storage that returns a key rather than a path, which the README's rollback example now says
* Documented that a storage exception message belongs in your logs rather than in front of whoever submitted the file. It distinguishes a name that already exists from a destination that couldn't be created, which is an existence check on your upload directory. `getErrors()` remains the list written to be rendered

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
