# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

`gravitypdf/upload` — a standalone PHP library for validating and storing `$_FILES` uploads. It is a maintained fork of the abandoned `codeguy/upload` (declared via `"replace"` in composer.json), renamed to the `GravityPdf\Upload` namespace.

Supports PHP 7.3 through 8.5. Any change must remain syntax- and behaviour-compatible across that whole range — CI runs the test suite on all eight versions. The only required runtime dependency is `ext-fileinfo`. `ext-mbstring` is under `suggest`: without it, filename truncation can split a multibyte character and the valid-UTF-8 guarantee does not hold. All three configurations `composer.json` permits are covered by CI — the extension, `symfony/polyfill-mbstring`, and neither — because nothing else sees the difference: an unguarded `mb_*` call once turned a rejected upload into a fatal error on an install without the extension. `forceValidUtf8()` owns that guard now, so it travels with the function rather than with a caller. The polyfill does **not** ship `mb_strcut()`, which is why `finalize()` gates only its truncation on that and calls `forceValidUtf8()` unconditionally; it is also `iconv()`-backed and warns on the input the repair exists for, so that call is silenced with `@`.

## Commands

```bash
composer phpunit                 # full test suite
vendor/bin/phpunit --filter testConstructionWithSingleFile   # single test
vendor/bin/phpunit tests/Upload/FileInfoTest.php             # single file

composer lint                    # PHPCS, PSR-12, over ./src and ./tests
composer lint:fix                # PHPCBF autofix
composer phpstan                 # PHPStan level 9 over src and tests
composer check-syntax            # parallel-lint across all PHP files
```

`composer psr7-readme` runs the README's PSR-7 bridge against real implementations; see below.

`phpunit`, `lint`, `phpstan`, `check-syntax` and `psr7-readme` each have their own GitHub Actions workflow, run on push to `main` and on every PR. A sixth, `package.yml`, has no composer script behind it: it needs nothing but `git`, and the shipped `composer.json` is not the place for a shell one-liner. Run it by hand with `git archive HEAD | tar -t | sed 's:/.*::' | sort -u`.

`tools/psr7-readme/` holds a second scratch manifest, for the same reason `tools/phpstan/` does: the README documents a PSR-7 bridge as caller code, and `verify.php` runs those snippets — **read out of `README.md` at run time, never copied** — against nyholm/psr7 and guzzlehttp/psr7. Rename a documented class or drop an example and the extraction fails by name. The library still requires nothing but `ext-fileinfo`; the check has its own `composer.json`, its own composer script and its own workflow. `verify.php` is covered by `lint` and `check-syntax` — it is the documented code's only safety net, so it does not get to sit outside the checks everything else passes.

The `phpunit` workflow carries a second job, `cross-file-system`, which mounts a tmpfs and points `UPLOAD_TEST_OTHER_FS` at it so `FileSystemTest::testStoresAFileFromAnotherFileSystem()` runs against two real file systems rather than skipping. It runs the **whole suite** with `--fail-on-skipped` rather than filtering to that test: a `--filter` matching nothing exits 0 with "No tests executed!", so it needed a second guard to prove it had run, and renaming the test was enough to trigger exactly that. That test is the suite's only `markTestSkipped()`, which is what makes one guard sufficient — keep it that way, or the job goes quiet. Tests that cannot run in a given configuration are excluded by group instead: `@group mbstring` marks the ones that need the UTF-8 repair, so the `no-mbstring` job drops them with `--exclude-group` rather than adding a second skip. Set `UPLOAD_TEST_OTHER_FS` to run it locally: on macOS, `hdiutil attach -nomount ram://8192` then `diskutil erasevolume HFS+ UPLOADTMP <disk>`.

`composer phpstan` bootstraps PHPStan from `tools/phpstan/` rather than the root `require-dev`. PHPStan 2.x needs PHP 7.4 to run, and the root manifest has to stay resolvable on 7.3 or the 7.3 test and PHPCS jobs cannot install at all. `phpstan.neon` sets `phpVersion` to the 7.3-8.5 range, so the analysis still covers the whole supported range from whatever version runs it.

## Architecture

**`File` is a collection, not a file.** `new File($key, $storage)` reads `$_FILES[$key]` and normalizes both the single-file shape (`tmp_name` is a string) and the multi-file shape (`tmp_name` is an array) into an array of `FileInfoInterface` objects. `File` implements `ArrayAccess`, `IteratorAggregate` and `Countable` over that array.

**`__call()` proxies to the collection with an asymmetric return type.** Any method not on `File` is forwarded to the underlying `FileInfo` objects: a scalar is returned when there is exactly one file, an **array** when there is more than one, and `null` when the collection is empty (`File::__call`). This asymmetry is deliberate API compatibility with v1 — don't "fix" it. It is also why `File` carries a `@mixin FileInfoInterface` annotation for PHPStan.

**Three extension points, all interface-driven:**

- `StorageInterface::upload(FileInfoInterface): string` — where the file lands. Only `Storage\FileSystem` ships.
- `ValidationInterface::validate(FileInfoInterface): void` — signals failure by throwing `GravityPdf\Upload\Exception`, never by returning. Four ship: `Extension`, `Mimetype`, `Size` and `FileType`.
- `FileInfoInterface` — the per-file value object. `FileInfo` extends `SplFileInfo` and implements it.

`Validation\FileType` replaces `Extension` + `Mimetype` used side by side: those two check independent allow-lists, so a file passes both while the two answers describe different formats. `FileType` keys media types by extension, so each `allow()` call describes one format. Both older classes are `@deprecated` as of 4.0.0 but still work, with no runtime notice.

**`upload()` requires something to validate against.** It throws `\LogicException` when `$validations` is empty, unless `File::allowUnvalidatedUploads()` was called. The type is the point: `Upload\Exception` is what a caller catches around `upload()` to handle a rejected file, and a misconfigured object must not land in that branch. The check sits in `upload()` rather than `isValid()` for the same reason — it is a configuration error for the developer, not a per-file failure to show an end user through `getErrors()`. `upload()` also throws when the collection is empty, since every validation passes vacuously against nothing. Both guards are shared with `uploadValid()`, which is `upload()` without the all-or-nothing guarantee: it stores the files that passed and leaves the rest in `getErrors()`. The two differ only in what they do with the result of the shared `prepareUpload()` — do not let them grow separate prologues. `store()` keys `$uploadedFiles` by **collection offset** rather than appending: a partial batch renumbered into a list pairs a stored file with a rejected one's metadata in any caller that zips the two by index.

**`FileList` is the same collection, built from files the caller supplies.** `new FileList($fileInfos, $storage, $failures)` skips the `$_FILES` reader entirely — it does not call `parent::__construct()`, because that method checks `file_uploads` and requires `$_FILES[$key]`, neither of which applies to a worker runtime or a PSR-7 bridge. Everything after construction is `File`'s, bar the two `ArrayAccess` writers it overrides: `offsetSet()` and `offsetUnset()` drop that offset's entry from `$sourceKeys`, so a caller's key can never outlive the file it named and `getSourceKeys()[$i]` cannot start describing something else. A malformed entry **throws `InvalidArgumentException`** rather than being recorded, which is the opposite of what the `$_FILES` path does with one, because this array is assembled by the developer rather than sent by a client — the same reasoning as `File::offsetSet()`. Both arguments' keys are discarded as offsets, since `$objects`, `getUploadedLocators()` and the `ArrayAccess<int, FileInfoInterface>` annotation are all offset-keyed; `$fileInfos`' keys are kept beside the collection and read back with `getSourceKeys()`, so a form field name survives without becoming an offset. Do not key the collection by them.

**`init()` is where a constructor invariant goes**, not `File::__construct()`. Two constructors and only one reads the superglobal, so an invariant added to the reader silently does not hold for `FileList` — the drift that produced the pre-4.0.0 filename bug. It is `protected` only because `FileList::__construct()` cannot reach a `private` method of its parent, and it is not an extension seam: it snapshots `$errors` into `$constructorErrors`, which is what `isValid()` resets to. Narrowing `$objects`, `$errors`, `$constructorErrors` or `$storage` to `private` is likewise breaking for an in-repo class now.

**`isUploadedFile()` is deliberately the caller's decision on the `FileList` path.** `runValidations()` rejects any file answering `false`, and on the `$_FILES` path that is PHP's SAPI assertion; nothing off that path can make it, so a `FileList` of plain `FileInfo` objects validates to nothing. That is correct rather than an obstacle — the caller overrides `isUploadedFile()` on a `FileInfo` subclass (the constructor is `final`, the class and the method are not) and asserts what their runtime can, and pairs it with `FileSystem::acceptFilesNotUploadedByPhp()` at the other end, without which storage refuses the file anyway. The library does not pretend to make an assertion it cannot make on someone else's input. This is the first thing the README section says, and it must stay that way.

**The constructor never trusts the shape of `$_FILES`.** A PSR-7 bridge or test harness can supply an entry that is not an array, or one missing `tmp_name`/`name`/`error`, or a multi-file entry whose keys are not parallel arrays. Every such shape is recorded as `'An uploaded file was sent in a format that cannot be read'` rather than warning or raising a `TypeError` — remote input must not warn.

**Validation errors accumulate; they don't abort.** `File::isValid()` runs every validation against every file and collects the failures, so `getErrors()` reports all of them at once. `upload()` throws only after the fact, with the generic message `'File validation failed'` — the detail is in `getErrors()`. An `Upload\Exception`'s message goes through `Filename::sanitizeForDisplay()` before it lands in `getErrors()`, sanitized there rather than in each validator because `getErrors()` is what carries the guarantee. A throw that never crosses a `File` boundary has no such chokepoint and sanitizes at the throw site instead, which is what `Storage\FileSystem`'s collision message does. **No shipped validator can reach that line with anything but configuration** — `Validation\FileType` prints an extension back only after it matches the configured allow-list, and the other three interpolate configuration alone. Do not re-document this as covering a shipped validator: it covers one of your own, and `FileList::describeKey()`, whose key is a field name the client chose. **`File::recordError()` is the only route into `$errors`** — one `protected` method that runs `Filename::sanitizeForDisplay()` over what it is given, so `getErrors()`'s guarantee is structural rather than nine call sites each remembering. It is the display half only: a filename inside a message still goes through `getSanitizedFilename()`/`formatUploadFailure()` at the call site, because those apply the naming rules and would report `user.avatar` as `user-avatar` if the recorder ran them over the whole line. `FileTest::testNothingElseAppendsToTheErrorList()` reads the source to keep it the only route; do not append to `$errors` directly. A validator throwing something other than `Upload\Exception` is absorbed too, with **both** its message and its class name dropped — a PHP runtime message can contain absolute paths, and a class name is the application's internal structure.

**`LogicException` is the exception to that.** It is re-thrown rather than absorbed, because PHP defines the type as a bug in the program. `FileInfo::getHash()` throws `InvalidArgumentException` for an unsupported algorithm precisely so a misspelling reaches the developer instead of the end user as a rejected upload. Absorb it and that guarantee is empty.

`isValid()` resets `$this->errors` to the errors recorded during construction, so it is idempotent. It still **re-runs every validation** on each call, as do `upload()` and `uploadValid()` through the private `runValidations()` the three share; memoizing the result would let a `setExtension()` between `isValid()` and `upload()` skip revalidation. `runValidations()` returns the files that passed rather than a boolean, so `uploadValid()` does not have to validate a second time to find out which ones they were — a validator with a side effect, a virus scanner or a remote lookup, must see each file once.

Four optional callbacks (`beforeValidate`, `afterValidate`, `beforeUpload`, `afterUpload`) fire per file, each receiving that file's `FileInfoInterface`. None may call back into the `File`: a run owns `$errors` and `$uploadedFiles`, so `isValid()`, `upload()` and `uploadValid()` refuse a re-entrant call with `\LogicException` (`guardNotReentrant()`). The lock spans the storing, not just the validating, because `afterUpload` fires after the validations finish. The two validation hooks are a matched pair — `afterValidate` fires even for a file that fails the `is_uploaded_file()` check, so a caller can open and close a per-file resource across them. The upload hooks are not: a storage failure throws before `afterUpload`.

`beforeUpload` runs **after** validation, so a name set there is never validated. Only the storage deny-list and `FileSystem`'s own filename rules apply to it.

**`Exception` carries the offending `FileInfoInterface`** (`getFileInfo()`), so callers can tell which file in a multi-file upload failed.

### Testability seams

Four methods exist purely so tests can reach what a test otherwise cannot — preserve them:

- `FileInfo::isUploadedFile()` wraps `is_uploaded_file()`.
- `FileSystem::moveUploadedFile()` (protected) wraps `move_uploaded_file()`.
- `FileSystem::lstatEntry()` (protected) wraps `@lstat()`, so a test can exercise the branch
  where the stat does not answer.
- `FileSystem::reserveDestination()` (protected) is reachable from `tests/Upload/Storage/ExposedFileSystem.php`,
  because `upload()`'s own `is_link()` check rejects a planted symlink before the reservation runs.

None of the four is an extension seam; `resolveFilename()` is the only protected method on
`FileSystem` that is.

`tests/Upload/VouchedFileInfo.php` is a `FileInfo` subclass overriding `isUploadedFile()`, which is the pattern the README documents for `FileList` as well as what `FileListTest` needs to reach storage; like `ExposedFileSystem` it is `require`d from `tests/bootstrap.php`, since nothing autoloads it.

`FileInfo::__construct` is `final`, so PHPUnit cannot subclass it freely for construction. Tests instead install a factory via the static `FileInfo::setFactory(callable)`, which `File` calls through `FileInfo::createFromFactory()`. This static is process-wide state; `FileInfo::resetFactory()` clears it and `FileTest`/`FileInfoTest` call it in `tear_down()`. `phpunit.xml` sets `backupGlobals="true"` so `$_FILES` fixtures set in `set_up()` don't leak.

`File::formatUploadFailure()` sanitizes through `Filename` rather than through a `FileInfo` from the factory: sanitizing an error-path filename is the library's own guarantee and must not depend on a caller-installed implementation. It is the one implementation both paths use — the `$_FILES` constructor calls it, and so does a caller assembling a `FileList`'s `$failures` — so the two cannot describe the same failure differently. It is `public static` for that second caller; there is deliberately no private wrapper in front of it.

### Filename sanitizing

**`Filename` owns the rules; `FileInfo` and `Storage\FileSystem` apply them.** Those two layers
have different outcomes on purpose — `FileInfo` rewrites a client-supplied name, storage refuses
one that still breaks a rule — but they must not disagree about what the rules *are*. That is
what went wrong before 4.0.0: both control-character filters covered C0 alone and each had to be
found separately. `MAX_LENGTH`, `MAX_EXTENSION_LENGTH`, `CONTROL_CHARACTERS`, `BIDI_CONTROLS` and
`RESERVED_WINDOWS_NAMES` are declared once, on `Filename`, and both layers call its splitters
(`deviceComponent()`, `extensionComponents()`, `normalizeComponents()`) rather than splitting for
themselves. Add a rule there, not in a caller.

`Filename::sanitizeForDisplay()` is the same rules applied to prose rather than to a name — the two
character sets, with controls collapsed to a space instead of `-` and none of the filename
budget, device-name or `%`/`/` handling. `File` runs a validator's message through it and
`FileList::describeKey()` runs a caller's array key through it, so neither assembles a pattern
out of the constants for itself. A key is deliberately **not** put through the filename rules:
those report `user.avatar` as `user-avatar` and a key called `con` as `unnamed-file`, and the
developer has to recognise it in their own array.

`FileInfo::setName()` sanitizes rather than validates: unsafe characters in the **name** are rewritten, never rejected. The steps, in order:

1. Delete the characters that reorder, break or hide the text around them — `Filename::BIDI_CONTROLS`, which is Unicode's `Bidi_Control` property plus the zero-width marks, `U+2028`/`U+2029`, `U+206A`–`U+206F` and the BOM. Deleted, not rewritten, because they carry no visual content.
2. Rewrite the unsafe characters to `-`: the Windows-disallowed set, `%`, `/`, `\`, C0 and C1 controls, `\x7F`, and the sub-delimiters. Interior dots go too, so `release.config.zip` is stored as `release-config.zip`.
3. Truncate to fit 255 bytes **shared with the extension** — `255 - (strlen($extension) + 1)`, floored at 222 because `Filename::MAX_EXTENSION_LENGTH` is 32.
4. Force valid UTF-8, for names that arrived invalid. Nothing above produces invalid UTF-8 from valid input; don't re-document it as repairing this class's own damage.
5. Blank a reserved Windows device name (`con`, `nul`, `lpt1`, …) — `Filename::RESERVED_WINDOWS_NAMES`.
6. Fall back to `unnamed-file`.

**Step 5 must stay after step 4.** Dropping an invalid byte can produce a name that was not reserved when the bytes arrived: `con\xC3.txt` is not `con` until that trailing byte goes.

`setExtension()` re-runs steps 3–6 (`Filename::finalize()`), or `setName(300 chars)` followed by `setExtension('jpeg')` stores a 260-byte name.

**`setExtension()` is the opposite: it validates, it does not rewrite.** It trims and lowercases first, then discards the extension entirely if anything other than letters and digits remains, if it exceeds `Filename::MAX_EXTENSION_LENGTH`, or if it is a reserved device name. `photo.PNG` keeps `png`; `doc.aux` keeps nothing. Do not "fix" this back into a strip: stripping builds an extension the client never sent, turning `avatar.p-h-p` into `avatar.php`. An extension validation rejects that name and the storage deny-list refuses it at the write, so this is defence in depth rather than the last line — it is what stands where neither applies, `Mimetype` or `Size` alone, or `allowUnvalidatedUploads()`. Do not restate it as the only thing in the way.

**Case folding goes through `AsciiCase::toLower()`, never `strtolower()`.** `strtolower()` follows `LC_CTYPE` before PHP 8.2, so an application calling `setlocale()` changed what counted as the same extension, media type or device name — under `tr_TR`, `strtolower('TIFF')` is `tıff`, which `setExtension()` then discards. Everything this library folds is ASCII by definition.

Sanitizing is **not** escaping — output still needs HTML escaping. The exact transformations are pinned by `tests/Upload/FileInfoTest.php`; change the regexes only alongside those assertions.

### Storage invariants

`Storage\FileSystem::upload()` does not trust `FileInfoInterface`, which is a public extension point that `FileInfo::setFactory()` lets any code in the process supply. Everything below is a backstop for an implementation that is not the shipped `FileInfo` — keep the two in step, but don't assume one makes the other redundant.

**The write is staged, never direct.** The bytes go to `upload-<32 hex>.part` in the destination directory and are then `rename()`d onto the destination. `move_uploaded_file()` falls back to a stream copy across file systems, and that copy follows a symlink at the destination; sending it to an unguessable name is what removes the race. `rename()` replaces the directory entry rather than following it. **Don't "simplify" this back into a direct write.**

With `overwrite = false`, `reserveDestination()` claims the name first with an exclusive `fopen(…, 'xb')` — not `is_file()` then a move, which two concurrent requests both pass. PHP resolves the path through its own stream layer before the create, so `x` **follows a dangling symlink and creates the target**; the `fstat`/`lstat` inode comparison that follows is what catches it, and `releaseReservation()` removes the file created at the far end. That comparison is POSIX-only: before PHP 7.4 Windows reports `ino` as 0. The placeholder carries the configured mode, so the name is briefly held by a 0-byte file — a process killed mid-transfer leaves it behind.

`resolveFilename()` reduces the name to a `basename()`, strips trailing dots and spaces (Windows resolves `evil.php.` to `evil.php`), and rewrites `<>:"|?*` to `-` — rewritten, not refused, because POSIX allows all of them, and `:` would otherwise name an NTFS alternate data stream. That is all it does: it decides what the name **is**, and `upload()` decides whether it may be written.

**No refusal is in there.** `resolveFilename()` is protected — the seam for changing how names are chosen — and while a refusal sat inside it, an override that said nothing about the subject dropped it. `upload()` runs all three against whatever the seam returns, and all three are `private`: `refuseUnsafeName()` (`''`, a leading `.`, `Filename::CONTROL_CHARACTERS`, `Filename::BIDI_CONTROLS`), `refuseReservedWindowsName()` and `refuseBlockedExtensions()`. The leading `.` is in the first of those rather than left to the deny-list because the deny-list cannot see one: `extensionComponents()` drops the empty component before the dot along with the first real one, so `.htaccess` presents nothing to match and `.env` is not on the list at all. A refusal rather than a rewrite throughout — inventing a filename is the value object's job, not storage's. **Do not move any of them back into the seam**, and do not answer "a subclass can reproduce it": `return basename($fileInfo->getNameWithExtension())` is the override people actually write. The collision message in `reserveDestination()` treats the seam's output the same way, running the basename through `Filename::sanitizeForDisplay()` before quoting it — the base `resolveFilename()` refuses control and bidi characters, an override need not, and a storage message is written to a log. A name left with nothing by that says `'A file with that name already exists'` rather than quoting an empty string. A blocked extension is refused in **any** dot-separated component, because a web server does not necessarily treat the last component as the extension. Do not narrow that to one component, and do not move the two refusals back.

**`move_uploaded_file()` is the storage half of the provenance decision.** It refuses any source PHP did not receive as an upload, so `Storage\FileSystem` cannot store a `FileList` file — the class validates and then fails at the write — until `acceptFilesNotUploadedByPhp()` says otherwise. That is deliberate, and the pairing is the point: `FileInfo::isUploadedFile()` says where the file came from, this says the caller is willing to store it, and neither is a default. Do not make the fallback automatic on a failed `move_uploaded_file()` — a path an attacker steered would take exactly that branch, which is the whole attack the SAPI check exists to stop. With the opt-in on, `moveFile()` renames, with a copy behind it. The copy is **not** the cross-file-system path, whatever the PSR-7 adapter plan says: `rename(2)` does fail with `EXDEV` between a tmpfs tmp directory and a disk upload directory, but PHP's plain-files wrapper catches that itself and copies, so `rename()` returns `true` — verified against two real mounts, and pinned by a test that skips when it cannot find a second file system. The fallback covers what PHP does not absorb, a stream-wrapper source among it. Both send the bytes at the **staging** path rather than the destination, so the copy's symlink-following is closed off by the unguessable name the same way `move_uploaded_file()`'s own copy fallback is. `moveFile()` is `protected` for the same testability reason `moveUploadedFile()` is; `moveIntoStaging()` picks between them and is `private`.

`tests/Upload/Storage/FileSystemTest.php` covers both routes against an **unmocked** `FileSystem`. Every other test there stubs `moveUploadedFile()`, which is what let the `FileList` path report green while it could not store a byte — a test that mocks the move cannot see that a caller's file is not a POST upload either.

**Three protections are on by default, each with its own opt-out**, applied in the constructor: `$overwrite = false`, `blockExtensions()` (turn off with `allowAnyExtension()`, the only route to the empty list — `blockExtensions()` takes a required, non-empty argument and throws otherwise, so a missing config key cannot quietly disable it) and `setMode(self::DEFAULT_MODE)` (turn off with `setMode(null)`). The deny-list default is `getDefaultBlockedExtensions()`, a **method** rather than a constant because PHP 7.3 constant expressions cannot call `array_merge()` on the two constants it joins. `EXECUTABLE_EXTENSIONS` is what a server runs; `MARKUP_EXTENSIONS` is what a browser renders, which is stored XSS rather than RCE. A caller who needs SVG `array_diff()`s `svg` and `svgz` out of `getDefaultBlockedExtensions()` — passing `EXECUTABLE_EXTENSIONS` alone unblocks all fifteen markup extensions to let one through, so do not document that as the way to accept a markup format. Entries passed to `blockExtensions()` are lowercased, trimmed, stripped of a leading dot and **split on any remaining dots**, because the list is matched one component at a time — `'tar.gz'` has to block `tar` and `gz` separately or it blocks nothing. The README table is pinned to `getDefaultBlockedExtensions()` by `FileSystemTest::testReadmeDocumentsTheDefaultDenyList()`.

## Conventions

- `declare(strict_types=1);` at the top of every file in `src/`. Tests do not declare it.
- PSR-12, enforced. Suppress narrowly with `/* phpcs:ignore */` (used on the polyfill `set_up()` methods) rather than relaxing the standard.
- PHPStan level 9 covers `src` **and** `tests`. `/* @phpstan-ignore-line */` is used sparingly — where `ArrayAccess` returns a nullable, and where a test deliberately passes the wrong type.
- Property types live in docblocks, not native property type declarations — PHP 7.3 support forbids the latter. Same for parameter/return types beyond what 7.3 allows (no union types, no `mixed`).
- Tests extend `Yoast\PHPUnitPolyfills\TestCases\TestCase` and use the polyfill's snake_case lifecycle hooks (`set_up()`, calling `parent::set_up()`), not `setUp()`.

## Repository notes

- The contributing guide and issue templates live in `.github/`; the README is at the repo root.
- `CHANGELOG.md` must be updated in the same PR as any user-facing change — it is the documented record of breaking changes between major versions.
- PRs target `main`; GitHub defaults new PRs to the upstream `codeguy/upload` repo, so the base repo needs correcting manually.
- `.gitattributes` decides what the dist package contains, which is `src/`, `composer.json` and the four Markdown files. **A new top-level directory or dev-only root file ships into everyone's `vendor/` unless it gets an `export-ignore` line**, so the `package` workflow diffs the archive's top level against that list and fails either way round — a dev directory that starts shipping, or a file a consumer needs that stops. It reads the **committed** attributes rather than the working tree's, since that is the copy a tag carries. Top level only, so a new `src/Upload/*.php` is not a manifest edit. It is also why `tests/` fixtures such as `VouchedFileInfo` are unreachable from an installed tree: they are the pattern a caller copies, not a class to depend on. `export-ignore` governs the archive Packagist serves, so a source install is unaffected; `composer.json`'s `archive.exclude` is not the same mechanism and would not do this.
