<?php

/**
 * Upload
 *
 * @author      Gravity PDF
 * @copyright   2026 Gravity PDF
 * @link        https://github.com/GravityPDF/Upload
 *
 * MIT LICENSE
 *
 * Permission is hereby granted, free of charge, to any person obtaining
 * a copy of this software and associated documentation files (the
 * "Software"), to deal in the Software without restriction, including
 * without limitation the rights to use, copy, modify, merge, publish,
 * distribute, sublicense, and/or sell copies of the Software, and to
 * permit persons to whom the Software is furnished to do so, subject to
 * the following conditions:
 *
 * The above copyright notice and this permission notice shall be
 * included in all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
 * EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
 * MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
 * NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE
 * LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION
 * OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION
 * WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 */

declare(strict_types=1);

namespace GravityPdf\Upload;

use InvalidArgumentException;

/**
 * A `File` built from files the caller supplies, rather than from `$_FILES`
 *
 * `File` reads the superglobal, which is right for a traditional SAPI request and useless for
 * anything else — a PSR-7 `ServerRequest`, a worker runtime, a test harness, a resumable upload
 * assembled from chunks. Those callers had to fake `$_FILES`, which is exactly why `File`'s
 * constructor carries its defensive shape checks. They build the `FileInfoInterface` objects
 * themselves now and hand them over.
 *
 * Everything after construction is inherited and unchanged: validations, the lifecycle
 * callbacks, `isValid()`, `upload()`, `uploadValid()`, `getUploadedLocators()`, `ArrayAccess`,
 * `IteratorAggregate`, `Countable`, and `__call()`'s scalar/array/null asymmetry.
 *
 * **`isUploadedFile()` is the caller's decision on this path, and that is the point.**
 * `File`'s validation run rejects any file answering `false`, and on the `$_FILES` path that is
 * PHP's own SAPI assertion — the guard that stops an upload being pointed at an arbitrary
 * server file. A plain `FileInfo` over a path the SAPI did not write answers `false`, so a
 * `FileList` of plain `FileInfo` objects validates to nothing. The caller asserts provenance
 * the way their own runtime can, by overriding `isUploadedFile()` on a `FileInfo` subclass —
 * the constructor is `final`, the class and that method are not. Overriding it to
 * `return true;` unconditionally gives up a real control; see the README before doing so.
 *
 * @package Upload
 * @since   4.0.0
 */
class FileList extends File
{
    /** @var array<int, int|string> The caller's own key for each file, by collection offset */
    protected $sourceKeys = [];

    /**
     * Constructor
     *
     * Deliberately does not call `parent::__construct()`. That method is the `$_FILES` reader:
     * it checks the `file_uploads` ini setting and requires `$_FILES[$key]`, neither of which
     * applies to a caller-supplied list — a worker runtime may have `file_uploads` off and
     * still handle uploads perfectly well.
     *
     * Array keys on both arguments are discarded as *offsets*. `$objects` is a list keyed
     * `0..n`, `getUploadedLocators()` is keyed by collection offset, and the class is
     * annotated `ArrayAccess<int, FileInfoInterface>`; keying the collection by a caller's
     * `'avatar'` would contradict all three. A caller's keys are usually form field names
     * they still need, so `$fileInfos`' keys are kept beside the collection instead and read
     * back through `getSourceKeys()`. `$failures`' keys are not: a failure is a name and a
     * code, not a member of the collection, so there is no offset to hang it on.
     *
     * A malformed entry raises rather than being recorded through `getErrors()`, which is the
     * opposite of what the `$_FILES` path does with one. `$_FILES` is remote input, so a
     * malformed entry there costs only itself; this array is assembled by the developer, so a
     * wrong type is a bug in the program — the same reasoning, and the same exception type, as
     * `File::offsetSet()`.
     *
     * @param array<int|string, mixed> $fileInfos The files this collection holds, each a
     *        `FileInfoInterface`. As on `File::offsetSet()`, the element type is checked at
     *        runtime rather than declared.
     * @param StorageInterface $storage The upload delegate instance
     * @param array<int|string, mixed> $failures `[$clientFilename, $errorCode]` pairs — a
     *        `string` and an `UPLOAD_ERR_*` `int` — for entries that never became files.
     *        Reported through `getErrors()` in exactly the words the `$_FILES` path uses.
     * @throws InvalidArgumentException If an entry is not a `FileInfoInterface`, or a failure
     *                                  pair is not a `[string, int]`
     */
    public function __construct(array $fileInfos, StorageInterface $storage, array $failures = [])
    {
        foreach ($fileInfos as $key => $fileInfo) {
            if (!$fileInfo instanceof FileInfoInterface) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Entry "%s" must be an instance of %s',
                        $this->describeKey($key),
                        FileInfoInterface::class
                    )
                );
            }

            $this->objects[] = $fileInfo;
            $this->sourceKeys[] = $key;
        }

        foreach ($failures as $key => $failure) {
            if (
                is_array($failure) === false
                || is_string($failure[0] ?? null) === false
                || is_int($failure[1] ?? null) === false
            ) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Failure "%s" must be a [string $clientFilename, int $errorCode] pair',
                        $this->describeKey($key)
                    )
                );
            }

            $this->recordUploadFailure($failure[0], $failure[1]);
        }

        /* The tail `File::__construct()` also runs, so an invariant added there holds here */
        $this->init($storage);
    }

    /**
     * A key, named in an exception message so the developer can find the entry
     *
     * Sanitized, because a key here is often a `multipart/form-data` field name the client
     * chose: this message is developer-facing and normally lands in a log, which is exactly
     * where a control character or a bidi override does its work.
     *
     * Not the filename rules, because the developer has to recognise the key in their own array
     * and those rules report `user.avatar` as `user-avatar` and a key called `con` as
     * `unnamed-file`.
     *
     * `MAX_LENGTH` is the one filename rule worth keeping: a field name is recognisable in
     * 255 bytes, and `MAX_DISPLAY_LENGTH` is more than one needs.
     *
     * @param int|string $key
     */
    private function describeKey($key): string
    {
        return Filename::sanitizeForDisplay((string) $key, Filename::MAX_LENGTH);
    }

    /**
     * Keep the caller's keys in step with a collection the caller mutates
     *
     * `offsetSet()` and `offsetUnset()` are inherited and public, so the collection is not
     * frozen after construction. A stale key left behind by either would name a file that is
     * gone or a file that arrived some other way, and the pairing this class exists for —
     * `getSourceKeys()[$i]` names `$list[$i]` — would quietly start lying. Dropped rather
     * than reassigned: a file the caller put here directly has no key of their own for this
     * class to report.
     *
     * @param int $offset
     * @param mixed $value A FileInfoInterface; the type is checked at runtime, not declared
     * @throws InvalidArgumentException If the value is not a `FileInfoInterface`
     */
    public function offsetSet($offset, $value): void
    {
        parent::offsetSet($offset, $value);

        unset($this->sourceKeys[$offset]);
    }

    /** @param int $offset */
    public function offsetUnset($offset): void
    {
        parent::offsetUnset($offset);

        unset($this->sourceKeys[$offset]);
    }

    /**
     * The key each file arrived under, by the collection offset it ended up at
     *
     * A caller assembling the list from a PSR-7 request or a form has keys that mean
     * something — `'avatar'`, `'photos.2'`, a chunk id — and the collection cannot be keyed by
     * them: `getUploadedLocators()`, the array offsets and the `ArrayAccess<int,
     * FileInfoInterface>` annotation are all offset-based, and a partial `uploadValid()` batch
     * relies on that. They are kept here instead, so `$list->getSourceKeys()[$i]` names the
     * file at `$list[$i]` and the locator at `getUploadedLocators()[$i]`.
     *
     * A list-keyed `$fileInfos` therefore gives back `[0, 1, 2, …]`, which is the collection's
     * own offsets and says nothing new. Entries rejected by the constructor never get here,
     * because the constructor throws instead of skipping them. An offset the caller has
     * written to or unset since has no entry at all, rather than one naming a file that is no
     * longer there.
     *
     * @return array<int, int|string>
     */
    public function getSourceKeys(): array
    {
        return $this->sourceKeys;
    }
}
