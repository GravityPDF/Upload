<?php

/**
 * Upload
 *
 * @author      Josh Lockhart <info@joshlockhart.com>
 * @copyright   2012 Josh Lockhart
 * @link        http://www.joshlockhart.com
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

use ArrayAccess;
use ArrayIterator;
use Countable;
use BadMethodCallException;
use InvalidArgumentException;
use LogicException;
use IteratorAggregate;
use RuntimeException;

/**
 * The uploaded files under one `$_FILES` key, with the validations they must pass
 *
 * `__call()` proxies the `FileInfoInterface` methods listed below to the collection,
 * returning the file's own value for one file, an array of them for more than one and `null`
 * for none. They are declared as `@method` rather than inherited through `@mixin` because a
 * mixin promises the interface's own return type, hiding both halves of that asymmetry.
 *
 * @author  Josh Lockhart <info@joshlockhart.com>
 * @since   1.0.0
 * @package Upload
 *
 * @implements IteratorAggregate<int, FileInfoInterface>
 * @implements ArrayAccess<int, FileInfoInterface>
 *
 * @method string|string[]|null getPathname()
 * @method string|string[]|null getName()
 * @method string|string[]|null getExtension()
 * @method string|string[]|null getNameWithExtension()
 * @method string|string[]|null getMimetype()
 * @method string|string[]|null getHash(string $algorithm = 'sha256')
 * @method int|false|array<int,int|false>|null getSize()
 * @method array<string,float|int>|array<int,array<string,float|int>>|null getDimensions()
 * @method bool|bool[]|null isUploadedFile()
 * @method FileInfoInterface|FileInfoInterface[]|null setName(string $name)
 * @method FileInfoInterface|FileInfoInterface[]|null setExtension(string $extension)
 * @method FileInfoInterface|FileInfoInterface[]|null setNameWithExtension(string $filename)
 */
class File implements ArrayAccess, IteratorAggregate, Countable
{
    /** @var string[] Upload error code messages */
    protected static $errorCodeMessages = [
        1 => 'The uploaded file exceeds the upload_max_filesize directive in php.ini',
        2 => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form',
        3 => 'The uploaded file was only partially uploaded',
        4 => 'No file was uploaded',
        6 => 'Missing a temporary folder',
        7 => 'Failed to write file to disk',
        8 => 'A PHP extension stopped the file upload',
    ];

    /** @var StorageInterface Storage delegate */
    protected $storage;

    /** @var FileInfoInterface[] File information */
    protected $objects = [];

    /** @var ValidationInterface[] */
    protected $validations = [];

    /** @var string[] Validation errors */
    protected $errors = [];

    /** @var bool Has the caller accepted storing files without validating them? */
    protected $allowUnvalidated = false;

    /** @var string[] Errors recorded during construction, which `isValid()` must not discard */
    protected $constructorErrors = [];

    /** @var string[] Destination paths from the most recent `upload()`/`uploadValid()`, by offset */
    protected $uploadedFiles = [];

    /** @var bool Is a validation or upload run already in progress on this object? */
    private $running = false;

    /** @var callable|null */
    protected $beforeValidationCallback;

    /** @var callable|null */
    protected $afterValidationCallback;

    /** @var callable|null */
    protected $beforeUploadCallback;

    /** @var callable|null */
    protected $afterUploadCallback;

    /**
     * Constructor
     *
     * @param string $key The $_FILES[] key
     * @param StorageInterface $storage The upload delegate instance
     * @throws RuntimeException                  If file uploads are disabled in the php.ini file
     * @throws InvalidArgumentException          If $_FILES[] does not contain key
     */
    public function __construct(string $key, StorageInterface $storage)
    {
        if (!ini_get('file_uploads')) {
            throw new RuntimeException('File uploads are disabled in your PHP.ini file');
        }

        if (isset($_FILES[$key]) === false) {
            throw new InvalidArgumentException("Cannot find uploaded file(s) identified by key: $key");
        }

        /* The SAPI always builds the entry as an array with these three keys; a PSR-7 bridge,
           a test harness or middleware need not, and a string here made `['tmp_name']` an
           uncaught TypeError on PHP 8. */
        if (
            is_array($_FILES[$key]) === false
            || array_key_exists('tmp_name', $_FILES[$key]) === false
            || array_key_exists('name', $_FILES[$key]) === false
            || array_key_exists('error', $_FILES[$key]) === false
        ) {
            $this->errors[] = 'An uploaded file was sent in a format that cannot be read';
        } elseif (is_array($_FILES[$key]['tmp_name']) === true) {
            /* The SAPI builds every key of a multi-file entry as an array of the same length.
               Indexing one that is not is worse than useless: a string `name` yields a single
               character, which passes the check below and stores the file as `s`. Reduced to
               `[]` so that check sees a missing entry instead. */
            $names = is_array($_FILES[$key]['name']) ? $_FILES[$key]['name'] : [];
            $errorCodes = is_array($_FILES[$key]['error']) ? $_FILES[$key]['error'] : [];

            foreach ($_FILES[$key]['tmp_name'] as $index => $tmpName) {
                $name = $names[$index] ?? null;
                $errorCode = $errorCodes[$index] ?? null;

                /* A field named `f[0][0]` nests one level deeper than the two shapes this class
                   knows, leaving an array where a path belongs: no single upload to represent,
                   and casting it warns "Array to string conversion" on remote input. Same for
                   the other two keys, which a ragged or mistyped entry leaves short. */
                if (is_string($tmpName) === false || is_string($name) === false || is_int($errorCode) === false) {
                    $this->errors[] = 'An uploaded file was sent in a format that cannot be read';
                    continue;
                }

                if ($errorCode !== UPLOAD_ERR_OK) {
                    $this->errors[] = $this->formatUploadError($name, $errorCode);
                    continue;
                }

                $this->objects[] = FileInfo::createFromFactory($tmpName, $name);
            }
        } elseif (
            is_string($_FILES[$key]['tmp_name']) === false
            || is_string($_FILES[$key]['name']) === false
            || is_int($_FILES[$key]['error']) === false
        ) {
            /* The multi-file guard above, for the same reason: a non-string here is an uncaught
               TypeError out of createFromFactory(). The code is checked too because `(int) [0]`
               is 1, which reported the file as exceeding `upload_max_filesize`. */
            $this->errors[] = 'An uploaded file was sent in a format that cannot be read';
        } elseif ($_FILES[$key]['error'] !== UPLOAD_ERR_OK) {
            /* No file behind a failed upload: `tmp_name` is '' for UPLOAD_ERR_NO_FILE */
            $this->errors[] = $this->formatUploadError(
                $_FILES[$key]['name'],
                $_FILES[$key]['error']
            );
        } else {
            $this->objects[] = FileInfo::createFromFactory(
                $_FILES[$key]['tmp_name'],
                $_FILES[$key]['name']
            );
        }

        $this->constructorErrors = $this->errors;
        $this->storage = $storage;
    }

    /**
     * Build an error string for a `$_FILES` entry that never became an upload, from the raw
     * client-supplied `$name` and an `UPLOAD_ERR_*` code
     *
     * Sanitizes the filename so every string in `$this->errors` carries the same guarantee:
     * the README encourages callers to render `getErrors()` directly.
     */
    private function formatUploadError(string $name, int $errorCode): string
    {
        return sprintf(
            '%s: %s',
            Filename::sanitizeNameWithExtension($name),
            static::$errorCodeMessages[$errorCode] ?? 'Unknown Error'
        );
    }

    /**
     * A file's name, sanitized for use in an error string
     *
     * `FileInfoInterface` is a public extension point, so a name one returns has not
     * necessarily been through `FileInfo::setName()`: without this a custom implementation
     * could put a line break, a terminal escape or a bidi override into a string callers
     * render. Sanitizing is not escaping — the result still needs escaping where it lands.
     */
    private function getSanitizedFilename(FileInfoInterface $fileInfo): string
    {
        return Filename::sanitizeNameWithExtension($fileInfo->getNameWithExtension());
    }

    /********************************************************************************
     * Helpers
     *******************************************************************************/

    /**
     * Convert a human readable file size ("10K", "0.5M", "5MB") into bytes
     *
     * Anything unparseable raises, an unrecognized unit included, rather than evaluating to
     * zero or a handful of bytes — either configures a `Size` bound that rejects every upload
     * while reading as a generous one.
     *
     * @throws InvalidArgumentException If the input cannot be parsed as a file size
     */
    public static function humanReadableToBytes(string $input): int
    {
        if (preg_match('/^\s*(\d+(?:\.\d+)?)\s*([bkmg])?b?\s*$/i', $input, $matches) !== 1) {
            throw new InvalidArgumentException(
                sprintf(
                    'Could not parse "%s" as a file size. Expected a positive number, '
                    . 'optionally followed by B, K, M or G.',
                    $input
                )
            );
        }

        $units = [
            'b' => 1,
            'k' => 1024,
            'm' => 1048576,
            'g' => 1073741824,
        ];

        /* The pattern admits only these four units, so the fallback is the no-unit case and
           nothing else. Matching `[a-z]` made `'1T'` a one byte limit that rejected every
           upload — the failure the throw above exists to prevent. */
        $unit = AsciiCase::toLower($matches[2] ?? '');
        $number = (float)$matches[1] * ($units[$unit] ?? 1);

        /* Returning a float here is a TypeError; a bound larger than PHP can count is PHP_INT_MAX */
        if ($number >= (float)PHP_INT_MAX) {
            return PHP_INT_MAX;
        }

        return (int)$number;
    }

    /** Set the `beforeValidation` callable, which receives one `FileInfoInterface` */
    public function beforeValidate(callable $callable): File
    {
        $this->beforeValidationCallback = $callable;

        return $this;
    }

    /** Set the `afterValidation` callable, which receives one `FileInfoInterface` */
    public function afterValidate(callable $callable): File
    {
        $this->afterValidationCallback = $callable;

        return $this;
    }

    /** Set the `beforeUpload` callable, which receives one `FileInfoInterface` */
    public function beforeUpload(callable $callable): File
    {
        $this->beforeUploadCallback = $callable;

        return $this;
    }

    /** Set the `afterUpload` callable, which receives one `FileInfoInterface` */
    public function afterUpload(callable $callable): File
    {
        $this->afterUploadCallback = $callable;

        return $this;
    }

    /********************************************************************************
     * Validation and Error Handling
     *******************************************************************************/

    /** @param ValidationInterface[] $validations */
    public function addValidations(array $validations): File
    {
        foreach ($validations as $validation) {
            $this->addValidation($validation);
        }

        return $this;
    }

    public function addValidation(ValidationInterface $validation): File
    {
        $this->validations[] = $validation;

        return $this;
    }

    /** @return ValidationInterface[] */
    public function getValidations(): array
    {
        return $this->validations;
    }

    /**
     * Permit `upload()` to run with no validations configured
     *
     * `upload()` otherwise refuses, because an endpoint that validates nothing is usually
     * unfinished rather than deliberate. Calling this makes the decision greppable.
     */
    public function allowUnvalidatedUploads(): File
    {
        $this->allowUnvalidated = true;

        return $this;
    }

    /**
     * Whether `upload()` will run with no validations configured
     *
     * `getValidations()` shows the list is empty; this shows whether that was a decision.
     */
    public function allowsUnvalidatedUploads(): bool
    {
        return $this->allowUnvalidated;
    }

    /** @return string[] Validation errors */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Proxy an unknown method to the collection
     *
     * Returns a scalar for one file, an array for more than one and null for none. The
     * asymmetry is v1 API compatibility, not an oversight.
     *
     * @param array<int,mixed> $arguments
     * @return mixed
     */
    public function __call(string $name, array $arguments)
    {
        $count = count($this->objects);
        $result = null;

        if ($count) {
            if ($count > 1) {
                $result = [];
                foreach ($this->objects as $object) {
                    $callable = [$object, $name];
                    if (!is_callable($callable)) {
                        throw new BadMethodCallException('Method does not exist in FileInfoInterface: ' . $name);
                    }

                    $result[] = call_user_func_array($callable, $arguments);
                }
            } else {
                $callable = [$this->objects[0], $name];
                if (!is_callable($callable)) {
                    throw new BadMethodCallException('Method does not exist in FileInfoInterface: ' . $name);
                }
                $result = call_user_func_array($callable, $arguments);
            }
        }

        return $result;
    }

    /**
     * Upload every file, or none of them (delegated to storage object)
     *
     * All-or-nothing: one file failing validation stores none of them. A caller who would
     * rather keep what passed calls `uploadValid()` in place of this. Neither is atomic: a
     * storage failure throws part-way through either, with `getUploadedLocators()` listing
     * what was already committed.
     *
     * @return bool
     * @throws Exception If validation fails, or if there is nothing to upload
     * @throws LogicException If nothing has been configured to validate against
     */
    public function upload(): bool
    {
        $this->guardNotReentrant();
        $this->running = true;

        try {
            $valid = $this->prepareUpload();

            if (!empty($this->errors)) {
                throw new Exception('File validation failed');
            }

            $this->guardCollectionNotEmpty();

            /* Nothing failed, so this is the whole collection */
            $this->store($valid);
        } finally {
            $this->running = false;
        }

        return true;
    }

    /**
     * Upload the files that pass validation, leaving the rest in `getErrors()`
     *
     * `upload()` is all-or-nothing, and for a single-file field that is the whole story. For
     * `name="photos[]"` it means one unreadable photo out of ten discards the other nine, and
     * the submitter has to re-select every one of them. This stores each file that passed and
     * reports the failures the way validation already behaves: they accumulate across the
     * batch rather than aborting it.
     *
     * `upload()` keeps all-or-nothing, which is a legitimate thing to depend on for a set of
     * files that is only meaningful complete. A caller uses one method or the other, so the
     * choice is visible at the call rather than in a flag set somewhere else.
     *
     * Nothing throws for a rejected file, so the return value is the only signal that one
     * was: a caller that abandons the request on `false` owns whatever is already on disk,
     * and `getUploadedLocators()` is the list to undo.
     *
     * @return bool True when every file was stored, false when at least one was rejected — a
     *              file that never transferred counts as rejected
     * @throws Exception If there is nothing to upload, or if storage fails
     * @throws LogicException If nothing has been configured to validate against
     */
    public function uploadValid(): bool
    {
        $this->guardNotReentrant();
        $this->running = true;

        try {
            $valid = $this->prepareUpload();

            $this->guardCollectionNotEmpty();
            $this->store($valid);
        } finally {
            $this->running = false;
        }

        return empty($this->errors);
    }

    /**
     * The steps both entry points take before either stores anything: forget the previous
     * call's locators, refuse an object with nothing to validate against, and validate
     *
     * The locators go first, before the guards rather than after: a throw would otherwise
     * leave the previous call's paths in place, and the documented rollback loop would unlink
     * files an earlier successful upload legitimately stored.
     *
     * @return FileInfoInterface[] The files that passed, keyed by their collection offset
     * @throws LogicException If nothing has been configured to validate against
     */
    private function prepareUpload(): array
    {
        $this->uploadedFiles = [];

        /* LogicException, not Upload\Exception: the developer forgot to configure the object,
           no file failed. Catching Upload\Exception is how callers handle a rejected upload,
           and this must not land in that branch. */
        if (count($this->validations) === 0 && $this->allowUnvalidated === false) {
            throw new LogicException(
                'No validations have been added. Add at least one (Validation\FileType and '
                . 'Validation\Size cover most cases), or call allowUnvalidatedUploads() to '
                . 'store whatever is submitted.'
            );
        }

        return $this->runValidations();
    }

    /**
     * Refuse a call that re-enters this object from one of its own lifecycle callbacks
     *
     * A run owns `$errors` and `$uploadedFiles`: it resets both at the start, and decides from
     * `$errors` which files to hand to storage. A nested call resets them underneath the run
     * in progress, so a file that failed can end up in the set that is stored and the locator
     * list can lose what was already written. The lock spans the storing as well as the
     * validating, since `afterUpload` fires after the validations have finished.
     *
     * A callback is given the `FileInfoInterface` it needs; calling back into the collection
     * is the developer's mistake, not a failed file, so this is `LogicException` for the
     * reason `prepareUpload()` gives.
     *
     * @throws LogicException If a run is already in progress on this object
     */
    private function guardNotReentrant(): void
    {
        if ($this->running === true) {
            throw new LogicException(
                'isValid(), upload() and uploadValid() cannot be called while one of them is '
                . 'already running on this object. A lifecycle callback receives the file it '
                . 'describes; it must not call back into the File.'
            );
        }
    }

    /**
     * Refuse to report success for a collection with nothing in it
     *
     * Validations pass vacuously with nothing to run against, so without this both entry
     * points report success having stored nothing. Each calls it after the validations, so a
     * failed transfer is still reported through `getErrors()`.
     *
     * @throws Exception If the collection is empty
     */
    private function guardCollectionNotEmpty(): void
    {
        if (count($this->objects) === 0) {
            throw new Exception('There are no files to upload');
        }
    }

    /**
     * Hand each file to the storage delegate, recording the locator it returns
     *
     * Keyed by the offset the file has in the collection, not appended: `uploadValid()`
     * stores a subset, and a list would silently renumber it, so a caller pairing the
     * locators with a manifest built by iterating the collection would attribute a stored
     * file to the metadata of one that was rejected.
     *
     * @param FileInfoInterface[] $files Keyed by collection offset
     * @throws Exception From the storage delegate, which aborts the rest of the batch
     */
    private function store(array $files): void
    {
        foreach ($files as $index => $fileInfo) {
            $this->applyCallback('beforeUploadCallback', $fileInfo);
            $this->uploadedFiles[$index] = $this->storage->upload($fileInfo);
            $this->applyCallback('afterUploadCallback', $fileInfo);
        }
    }

    /**
     * Destination paths written by the most recent `upload()` or `uploadValid()` call
     *
     * A multi-file upload is not atomic, and `getErrors()` stays empty when a storage failure
     * aborts it, so this is how a caller finds out what is already on disk. After
     * `uploadValid()` it is the files that passed *and* were stored, which a storage failure
     * part-way through leaves shorter than the set that passed.
     *
     * Keyed by collection offset, so the array is sparse after `uploadValid()`: the locator
     * at `$i` belongs to `$file[$i]`, and a file that was not stored has no entry at all.
     * Iterate it, or read it by offset — do not pair it positionally with the collection.
     *
     * @return string[]
     */
    public function getUploadedLocators(): array
    {
        return $this->uploadedFiles;
    }

    /**
     * Is this collection valid and without errors?
     *
     * Re-runs every validation on every call rather than memoizing the result: `upload()`
     * calls this again, and a `setExtension()` in between must not skip revalidation.
     *
     * @throws LogicException Unabsorbed from a validator: broken code, not a failed file
     * @throws \Throwable From a user callback
     */
    public function isValid(): bool
    {
        $this->guardNotReentrant();
        $this->running = true;

        try {
            $this->runValidations();
        } finally {
            $this->running = false;
        }

        return empty($this->errors);
    }

    /**
     * Run the uploaded-file check and every validation against every file, recording each
     * failure in `$this->errors`
     *
     * Returns the files rather than a boolean because `uploadValid()` needs to know *which*
     * files passed, and running the validations twice to find out would let a validator with
     * a side effect — a virus scanner, an API call — see each file twice.
     *
     * @return FileInfoInterface[] The files that passed, keyed by their collection offset
     * @throws LogicException Unabsorbed from a validator: broken code, not a failed file
     * @throws \Throwable From a user callback
     */
    private function runValidations(): array
    {
        /* Reset rather than append: upload() validates again, so the
           `if (!$file->isValid()) {…} $file->upload();` pattern would double every error */
        $this->errors = $this->constructorErrors;

        $valid = [];

        foreach ($this->objects as $index => $fileInfo) {
            /* A file passed iff this iteration recorded nothing for it. Derived rather than
               tracked, so an error site added here cannot forget to mark the file failed and
               hand a rejected upload to storage. Taken before the first hook rather than
               after, so that guarantee covers the whole iteration and not just the part below
               it: `$errors` is `protected`, so a subclass can record from either hook. */
            $errorCount = count($this->errors);

            $this->applyCallback('beforeValidationCallback', $fileInfo);

            /* Not a `continue`: the validation callbacks are documented as a matched pair
               firing once per file, so a caller can open and close a per-file resource with
               them. Skipping the after-hook leaks on exactly the files that failed. */
            if ($fileInfo->isUploadedFile() === false) {
                $this->errors[] = sprintf(
                    '%s: %s',
                    $this->getSanitizedFilename($fileInfo),
                    'Is not an uploaded file'
                );
            } else {
                /* Hoisted: sanitizing is several regex passes, and every failure reuses it */
                $sanitizedFilename = $this->getSanitizedFilename($fileInfo);

                foreach ($this->validations as $validation) {
                    try {
                        $validation->validate($fileInfo);
                    } catch (Exception $e) {
                        $this->errors[] = sprintf('%s: %s', $sanitizedFilename, $e->getMessage());
                    } catch (LogicException $e) {
                        /* By PHP's own definition a bug in the program, not a failed file.
                           Absorbed, `getHash('sha255')` in a validator would be reported as a
                           rejected upload, leaving the developer nothing to go on. */
                        throw $e;
                    } catch (\Throwable $e) {
                        /* ValidationInterface is an extension point: absorb, so one validator
                           cannot abort the batch and bypass error accumulation. Nothing the
                           throwable carries reaches this string — a runtime message can hold an
                           absolute path, a class name is internal structure, and getErrors() is
                           shown to end users. Rethrow an Upload\Exception to surface either. */
                        $this->errors[] = sprintf('%s: Validation could not be completed', $sanitizedFilename);
                    }
                }
            }

            $this->applyCallback('afterValidationCallback', $fileInfo);

            if (count($this->errors) === $errorCount) {
                $valid[$index] = $fileInfo;
            }
        }

        return $valid;
    }

    protected function applyCallback(string $callbackName, FileInfoInterface $file): void
    {
        $allowedCallbackName = [
            'beforeValidationCallback',
            'afterValidationCallback',
            'beforeUploadCallback',
            'afterUploadCallback'
        ];

        if (!in_array($callbackName, $allowedCallbackName, true)) {
            return;
        }

        if (!is_callable($this->$callbackName)) {
            return;
        }

        call_user_func($this->$callbackName, $file);
    }

    /********************************************************************************
     * Array Access Interface
     *******************************************************************************/

    /** @param int $offset */
    public function offsetExists($offset): bool
    {
        return isset($this->objects[$offset]);
    }

    /**
     * @param int $offset
     * @return FileInfoInterface|null
     */
    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return $this->objects[$offset] ?? null;
    }

    /**
     * @param int $offset
     * @param mixed $value A FileInfoInterface; the type is checked at runtime, not declared
     * @throws InvalidArgumentException If the value is not a FileInfoInterface
     */
    public function offsetSet($offset, $value): void
    {
        /* The type in the docblock is not enforced at runtime; anything else faults later, inside isValid() */
        if (!$value instanceof FileInfoInterface) {
            throw new InvalidArgumentException(
                'Value must be an instance of ' . FileInfoInterface::class
            );
        }

        $this->objects[$offset] = $value;
    }

    /** @param int $offset */
    public function offsetUnset($offset): void
    {
        unset($this->objects[$offset]);
    }

    /********************************************************************************
     * Iterator Aggregate Interface
     *******************************************************************************/

    /**
     * @return ArrayIterator<int,FileInfoInterface>
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->objects);
    }

    /********************************************************************************
     * Countable Interface
     *******************************************************************************/

    public function count(): int
    {
        return count($this->objects);
    }
}
