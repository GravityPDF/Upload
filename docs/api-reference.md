# API reference

Every class lives in the `GravityPdf\Upload` namespace. The
[README](../README.md) covers the usage path; this is the signature-by-signature reference.

## File

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
| `uploadValid(): bool` | Re-validates, then stores only the files that passed, leaving the rest in `getErrors()`. Returns `true` when every file was stored and `false` when at least one was rejected, counting a file that failed to transfer. Nothing throws for a rejected file, so cleaning up what was already stored is yours on the `false` branch. Throws the same `LogicException` with no validations configured, the same `Exception` on an empty collection, and whatever storage throws. |
| `getUploadedLocators(): string[]` | Locators returned by the most recent `upload()` or `uploadValid()`, in whatever form the storage backend defines. Multi-file uploads are not atomic, so after a failure this is what needs rolling back. Keyed by collection offset, so the array is sparse after `uploadValid()` and the locator at `$i` still belongs to `$file[$i]`. |
| `allowUnvalidatedUploads(): File` | Let `upload()` and `uploadValid()` proceed with no validations configured. What that leaves standing is under [Turning the defaults off](turning-the-defaults-off.md). |
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
returns `null`. A method the file object does not have raises `BadMethodCallException`, so a
typo reaches you rather than the end user as a failed upload. Reading an offset that is not
there gives `null`; writing one raises `InvalidArgumentException` unless the value is a
`FileInfoInterface`.

## FileList

`new FileList(array $fileInfos, StorageInterface $storage, array $failures = [])` builds the
same collection from files you supply rather than from `$_FILES`. Throws
`InvalidArgumentException` for an entry that is not a `FileInfoInterface`, or a `$failures`
entry that is not a `[string, int]` pair.

| Method | Description |
|---|---|
| `getSourceKeys(): array` | The key each file arrived under, by collection offset, so `getSourceKeys()[$i]` names `$list[$i]` and `getUploadedLocators()[$i]`. Writing to an offset or unsetting one drops that key. |

Both ends of the provenance check have to be answered before anything is stored; see
[Uploads from another source](../README.md#uploads-from-another-source).

## FileInfo

The per-file value object; extends `SplFileInfo` and implements `FileInfoInterface`, so path
accessors such as `getPathname()` are available alongside these:

| Method | Description |
|---|---|
| `getName(): string` / `setName(string $name): FileInfo` | Name without extension. `setName()` **sanitizes**: unsafe characters become `-`, `.` among them; characters that reorder, break or hide the text around them (bidi overrides, zero-width marks, line and paragraph separators, the BOM) are deleted; reserved Windows device names are blanked; and the result is truncated so the name and extension together fit 255 bytes, falling back to `unnamed-file`. With `ext-mbstring` the truncation lands on a character boundary and the result is forced to valid UTF-8; see [Requirements](../README.md#requirements). |
| `getExtension(): string` / `setExtension(string $ext): FileInfo` | Extension without the leading dot. `setExtension()` **validates**: it trims and lowercases, then discards the extension entirely if anything other than letters and digits remains, if it exceeds `Filename::MAX_EXTENSION_LENGTH` (32) bytes, or if it is a reserved Windows device name such as `aux`. Discarded rather than stripped, so it cannot turn what the client sent into something else: `photo.PNG` keeps `png`, `avatar.p-h-p` keeps nothing. |
| `getNameWithExtension(): string` / `setNameWithExtension(string $name): FileInfo` | Both parts together; the setter splits the input and applies the two rules above. |
| `getMimetype(): string` | Media type sniffed from the file contents via `ext-fileinfo`, not the client-supplied value. |
| `getSize(): int\|false` | Size in bytes, or `false` when the file cannot be read. |
| `getHash(string $algorithm = 'sha256'): string` | File hash; empty string when the file cannot be read. Throws `\InvalidArgumentException` for an algorithm this PHP build does not support, since a misspelling is broken code rather than a rejected upload. |
| `getDimensions(): array` | `['width' => int, 'height' => int]`; both `0` for non-images. |
| `isUploadedFile(): bool` | Wraps `is_uploaded_file()`. Called by `File::isValid()`. |
| `FileInfo::setFactory(callable $factory): void` | Static: install a factory `File` uses to build each `FileInfoInterface`. The constructor is `final`, so this is the seam for substituting test doubles. Process-wide state. |
| `FileInfo::resetFactory(): void` | Static: clear an installed factory. |

## Storage\FileSystem

The shipped `StorageInterface` implementation; stores files in a local directory.
`new FileSystem(string $directory, bool $overwrite = false)` throws
`InvalidArgumentException` when the directory does not exist or is not writable. The
constructor enables the deny-list and the `0640` file mode. Each protection has an opt-out,
and what each one stops applying is in [Turning the defaults off](turning-the-defaults-off.md).

| Method | Description |
|---|---|
| `upload(FileInfoInterface $fileInfo): string` | Store the file and return its destination path. Reduces the name to a `basename()`, drops trailing dots and spaces, and rewrites the characters Windows disallows (`<` `>` `:` `"` `\|` `?` `*`) to `-`. Refuses a name starting with `.`, one carrying control or bidi characters, one Windows resolves to a device such as `CON.txt`, a blocked extension in any dot-separated component, and a symlinked destination. Writes to a staged file in the same directory and moves it into place, so no partial content is readable under the final name. With `$overwrite = false` the destination is claimed first with an empty file at the configured mode, so concurrent requests cannot both win it; a process killed mid-transfer leaves that 0-byte file behind. Throws `Exception` on any refusal. |
| `blockExtensions(array $extensions): FileSystem` | Set the extensions that are never written, checked against the sanitized extension at the write and normalized as [the deny-list section](../README.md#extensions-blocked-by-default) describes. Throws `InvalidArgumentException` on an empty list; use `allowAnyExtension()` for that. |
| `setMode(?int $mode): FileSystem` | Permissions applied to each stored file, default `FileSystem::DEFAULT_MODE` (`0640`). `null` leaves the mode to the process umask. |
| `acceptFilesNotUploadedByPhp(): FileSystem` | Store files PHP did not receive as an upload, for a `FileList` fed from PSR-7, a worker runtime or reassembled chunks. Off by default: the write otherwise goes through `move_uploaded_file()`, which refuses any other source. Pair it with an `isUploadedFile()` override. |
| `acceptsFilesNotUploadedByPhp(): bool` | Whether that was allowed. |
| `allowAnyExtension(): FileSystem` | Turn the deny-list off. The only way to empty it. |
| `getBlockedExtensions(): string[]` | The extensions currently refused, lowercase and one component each. Empty means the deny-list is off. |
| `getMode(): ?int` | The permissions applied to each stored file, or `null` for the umask. |
| `getOverwrite(): bool` | Whether an existing file at the destination is replaced rather than refused. |
| `getDirectory(): string` | The destination directory, without trailing slash. |
| `FileSystem::getDefaultBlockedExtensions(): string[]` | Static: `EXECUTABLE_EXTENSIONS` merged with `MARKUP_EXTENSIONS`, the table under "Extensions blocked by default". |

### Two things it leaves in your upload directory

Both are artefacts of the staged write, and clearing a stale one is the operator's job.

`upload-<32 hex>.part` is the staging file, in the destination directory because `rename()`
is only atomic within one file system. It exists for the length of one transfer and is
removed on any failure. A process killed mid-transfer leaves one behind: the name is
unguessable, so nothing will ever collide with it, and nothing will remove it either.

The other is the 0-byte placeholder, and only with `$overwrite = false`. The destination
name is claimed before the bytes move, so two concurrent requests cannot both win it, and
`rename()` replaces the placeholder with the finished upload. A process killed between the
two leaves a 0-byte file under the caller's name, and every later upload of that name
reports `A file named "…" already exists` until it is cleared. There is no such window with
`$overwrite = true`, which does not reserve the name at all.

A sweep for `upload-*.part` and 0-byte files older than your longest plausible request is
enough. Both are ordinary files; nothing in this library reads them back.

## Validations

Each implements `ValidationInterface` and throws `Exception` on failure.

| Class | Description |
|---|---|
| `Validation\FileType($extensions, $mimetypes)` | Requires the file's extension and its sniffed contents to describe the same format. Either argument accepts a string or an array; chain `allow($extensions, $mimetypes)` to accept additional formats, one format per call. Both sides are trimmed, lowercased and stripped of empty entries, and `allow()` throws `InvalidArgumentException` if that leaves either side empty — an empty media type is what `getMimetype()` answers for a file it cannot read, and it would have matched one. An extension named twice gains the media types rather than replacing them. |
| `FileType::getAllowedTypes(): array<string, string[]>` | The media types allowed for each extension, as configured. |
| `Validation\Size($maxSize, $minSize = 0)` | Inclusive size bounds, as bytes or human-readable strings (`'5M'`, parsed by `File::humanReadableToBytes()`, which throws `InvalidArgumentException` on a unit it does not know). The message states the bound in the largest of bytes/KB/MB/GB it reaches, rounding a maximum down and a minimum up so the size named is always one the file would pass at. |
| `Validation\Extension($extensions)` | **Deprecated** since 4.0.0. Checks the extension alone, which says nothing about the contents. Use `FileType`. |
| `Validation\Mimetype($mimetypes)` | **Deprecated** since 4.0.0. Checks the sniffed type alone, which a polyglot file satisfies trivially. Use `FileType`. |



## Filename

`GravityPdf\Upload\Filename` holds the rules for what counts as a usable filename, so the
layers that apply them cannot drift apart. `FileInfo` **rewrites** a client-supplied name;
`Storage\FileSystem` **refuses** one that still breaks a rule, since a `FileInfoInterface` is
a public extension point and inventing a filename is not storage's job.

| Member | Description |
|---|---|
| `Filename::sanitizeNameWithExtension(string $filename, ?array $reserved = null): string` | The whole treatment for one string: splits name from extension, rewrites the first, validates the second, fits both to `MAX_LENGTH`. This is what `getErrors()` runs client-supplied names through. `$reserved` replaces `RESERVED_WINDOWS_NAMES`, for a `FileInfo` subclass that overrides which names it blanks. |
| `Filename::sanitizeForDisplay(string $value, int $maxLength = Filename::MAX_DISPLAY_LENGTH): string` | The same character sets applied to prose rather than to a name: bidi controls deleted, runs of control characters collapsed to a single space, the result cut to `$maxLength` bytes, forced to valid UTF-8 where `ext-mbstring` is loaded, then trimmed of surrounding whitespace. No device-name blanking, and `%`, `/` and dots are left alone. **It does not escape** `<`, `>`, `&` or `"`, so escape on output as well. This is what `getErrors()` runs a validation failure's message through; use it for error strings of your own. |
| `Filename::acceptExtension(string $extension, ?array $reserved = null): string` | The extension this library will keep, or `''` for one it will not. What `setExtension()` validates with. |
| `Filename::hasControlCharacters(string $value): bool` / `hasBidiControls(string $value): bool` | Two of the refusals `Storage\FileSystem` applies to a name. Use these rather than the constants below. |
| `Filename::exceedsMaxLength(string $filename): bool` | Whether a name and its extension together spend more than `MAX_LENGTH` bytes. The third refusal, and what `FileInfo` truncates to. |
| `Filename::deviceComponent(string $filename): string` / `isReservedDeviceComponent(string $value, ?array $reserved = null): bool` | The component that decides whether a name resolves to a Windows device, and whether it does. Windows ignores spaces around the name and everything from the first dot on, so `" con .txt"` is `con`. |
| `Filename::extensionComponents(string $filename): string[]` | Every dot-separated component after the first, lowercased and trimmed — what a deny-list is matched against. The first is dropped: a file called `php` is not a file that runs as PHP. |
| `Filename::MAX_LENGTH` / `MAX_EXTENSION_LENGTH` | `255` and `32` bytes. The name's budget is `MAX_LENGTH` minus the extension and its dot. |
| `Filename::MAX_DISPLAY_LENGTH` | `2048` bytes, the bound `sanitizeForDisplay()` applies to prose. Longer than any message this library composes, so only a message of your own reaches it. |
| `Filename::RESERVED_WINDOWS_NAMES` | The device names `setName()` blanks: `con`, `nul`, `lpt1`, and the `COM0`/`LPT0` and superscript variants. |
| `Filename::BIDI_CONTROLS` / `CONTROL_CHARACTERS` | The text-direction characters `setName()` deletes, and the control bytes it rewrites. `FileSystem` refuses a name still carrying either. Both are **bare pattern fragments**, not complete patterns: they compose into an alternation, so call the predicates above rather than passing either to `preg_match()`. |

## Exception

`GravityPdf\Upload\Exception` extends `\RuntimeException` and is thrown by validations, storage and
`File::upload()`.

| Method | Description |
|---|---|
| `getFileInfo(): ?FileInfoInterface` | The offending file, so a caller can tell which file in a multi-file upload failed. `null` where the failure is not about one file. |
| `getErrorCode(): string` | The [`ErrorCode`](#errorcode) constant naming why this was thrown, or `ErrorCode::NONE` for a throw of your own that named none. Branch on this rather than on the message. |
| `getMessageId(): string` | The message with its placeholders still in it — the gettext msgid. |
| `getMessageArgs(): array` | The values those placeholders take. |

`getMessage()` is always English, composed from those two: it is what you log, and
[never translated](translation/README.md#what-is-translated-and-what-is-not).

The constructor is `__construct(string $message, ?FileInfoInterface $fileInfo = null, string
$errorCode = ErrorCode::NONE, array $messageArgs = [], int $code = 0, ?Throwable $previous =
null)`. A validation of your own can pass a message id and its values rather than a finished
string, and they reach `getErrorDetails()` intact.

## Translation

`GravityPdf\Upload\Translation` holds the hook described under
[Translating error messages](translation/README.md).

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
marker — a function, not a method. It is gettext's `N_()` idiom: it returns `$text`, so an
extractor records the msgid while the lookup happens elsewhere. It is not WordPress's `__()`,
and the two coexist. The domain is discarded, and is optional as it is on WordPress's `__()`;
the calls in `src` omit it. Outside the library's own namespace, import it with
`use function GravityPdf\Upload\__;` or call it fully qualified; an unqualified call with
neither falls back to the global `__()`.

## ErrorCode

`GravityPdf\Upload\ErrorCode` is a class of string constants naming every failure this library
reports: the seven `UPLOAD_ERR_*` outcomes (`INI_SIZE`, `FORM_SIZE`, `PARTIAL`, `NO_FILE`,
`NO_TMP_DIR`, `CANT_WRITE`, `EXTENSION_STOPPED`) and the `UNKNOWN_TRANSFER_ERROR` that stands
for any other code, the collection's own (`MALFORMED_UPLOAD`, `NOT_AN_UPLOADED_FILE`, `VALIDATION_REJECTED`,
`VALIDATION_INCOMPLETE`, `VALIDATION_FAILED`, `NO_FILES`), the shipped validations'
(`SIZE_UNKNOWN`, `SIZE_TOO_SMALL`, `SIZE_TOO_LARGE`, `EXTENSION_NOT_ALLOWED`,
`MIMETYPE_NOT_ALLOWED`, `FILE_CONTENTS_MISMATCH`) and storage's (`DESTINATION_IS_SYMLINK`,
`DESTINATION_EXISTS`, `DESTINATION_NOT_CREATED`, `INVALID_DESTINATION_NAME`,
`BLOCKED_EXTENSION`, `MOVE_FAILED`, `CHMOD_FAILED`, `STAGING_NAME_FAILED`). `ErrorCode::NONE`
is the empty string, which is what a throw of your own that named no code answers.

`ErrorCode::forUploadError(int $errorCode): string` maps an `UPLOAD_ERR_*` constant to its
code. Anything else answers `UNKNOWN_TRANSFER_ERROR`, `UPLOAD_ERR_OK` included, since this is
only ever asked about an entry that failed — which is the pairing for the `Unknown error`
message `formatUploadFailure()` gives the same entry.

## Interfaces

| Interface | Contract |
|---|---|
| `StorageInterface` | `upload(FileInfoInterface $fileInfo): string` stores the file and throws on failure. The string is a locator the implementation defines: `FileSystem` returns its directory joined to the stored filename, absolute only if you constructed it with an absolute directory. `File::getUploadedLocators()` passes these back unchanged. Never return `''`; throw instead. |
| `ValidationInterface` | `validate(FileInfoInterface $fileInfo): void` signals failure by throwing `Exception`, never by returning a value. |
| `FileInfoInterface` | The per-file value object contract, for supplying your own implementation via `FileInfo::setFactory()` or to a [`FileList`](#filelist). The methods are those listed under [`FileInfo`](#fileinfo) with one difference: `setName()`, `setExtension()` and `setNameWithExtension()` declare **no return type** on the interface, so an implementation that does not extend `FileInfo` can return `$this`. Covariant returns need PHP 7.4 and this library supports 7.3; declaring `: FileInfo` on your own class still satisfies the interface. |