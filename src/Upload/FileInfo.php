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

use finfo;
use LogicException;
use SplFileInfo;

/**
 * File Information
 *
 * @author  Josh Lockhart <info@joshlockhart.com>
 * @since   2.0.0
 * @package Upload
 */
class FileInfo extends SplFileInfo implements FileInfoInterface
{
    /** @var callable|null Installed by `setFactory()`, process-wide */
    protected static $factory;

    /** @var string Name, without extension */
    protected $name = '';

    /** @var string Extension, without dot prefix */
    protected $extension = '';

    /** @var string */
    protected $mimetype = '';

    /**
     * @param string $filePathname Absolute path to uploaded file on disk
     * @param string|null $newName Desired file name (with extension) of uploaded file
     */
    final public function __construct(string $filePathname, ?string $newName = null)
    {
        $desiredName = is_null($newName) ? $filePathname : $newName;
        $this->setNameWithExtension($desiredName);

        parent::__construct($filePathname);
    }

    public static function setFactory(callable $callable): void
    {
        static::$factory = $callable;
    }

    /**
     * Clear any factory previously installed with `setFactory()`
     *
     * The factory is process-wide state, so long-lived processes and test suites need a
     * supported way to put it back.
     */
    public static function resetFactory(): void
    {
        static::$factory = null;
    }

    public static function createFromFactory(string $tmpName, ?string $name = null): FileInfoInterface
    {
        if (is_callable(static::$factory)) {
            $result = call_user_func(static::$factory, $tmpName, $name);
            if ($result instanceof FileInfoInterface === false) {
                throw new LogicException(
                    'FileInfo factory must return instance of \GravityPdf\Upload\FileInfoInterface.'
                );
            }

            return $result;
        }

        return new static($tmpName, $name);
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Sanitize and set the file name, without extension
     *
     * Sanitizing is not escaping: output to HTML still needs escaping. See `Filename` for the
     * rules and why each one is there.
     */
    public function setName(string $name): FileInfo
    {
        $this->name = $this->sanitizeName($name);

        return $this;
    }

    protected function sanitizeName(string $name): string
    {
        return Filename::sanitizeName($name, $this->getExtension(), $this->getReservedWindowsNames());
    }

    /**
     * Refit a sanitized name to the byte budget it shares with the current extension
     *
     * Separate from `sanitizeName()` because `setExtension()` has to redo the fit alone.
     *
     * @link http://serverfault.com/a/9548/44086
     */
    private function finalizeName(string $name): string
    {
        return Filename::finalize($name, $this->getExtension(), $this->getReservedWindowsNames());
    }

    public function getExtension(): string
    {
        return $this->extension;
    }

    /**
     * Set file extension (without dot prefix)
     *
     * Validates rather than rewrites: anything not lowercase-alphanumeric once trimmed is
     * discarded whole, leaving no extension. See `Filename::acceptExtension()` for why.
     */
    public function setExtension(string $extension): FileInfo
    {
        $this->extension = $this->acceptExtension($extension);

        /* The name was fitted against whatever extension was set when setName() ran. Redo it,
           or `setName(300 chars)` then `setExtension('jpeg')` stores a 260 byte name. */
        $this->name = $this->finalizeName($this->name);

        return $this;
    }

    /**
     * The extension this class will accept, or `''` for one it will not
     *
     * Split out from `setExtension()` so `setNameWithExtension()` can set the field without
     * triggering a name re-fit that its own `setName()` call immediately discards.
     */
    private function acceptExtension(string $extension): string
    {
        return Filename::acceptExtension($extension, $this->getReservedWindowsNames());
    }

    /**
     * A `protected` seam so a subclass can override what this class treats as reserved.
     * `Storage\FileSystem` deliberately reads the constant instead, so an override cannot
     * narrow what that layer refuses to write.
     *
     * @return string[]
     */
    protected function getReservedWindowsNames(): array
    {
        return Filename::RESERVED_WINDOWS_NAMES;
    }

    public function getNameWithExtension(): string
    {
        return $this->extension === '' ? $this->name : sprintf('%s.%s', $this->name, $this->extension);
    }

    public function setNameWithExtension(string $name): FileInfo
    {
        /* Not setExtension(): that re-fits the name to the new budget, and the setName() below
           overwrites the result unconditionally. Assign the extension, then let setName() do
           the one fit that survives. */
        $this->extension = $this->acceptExtension(pathinfo($name, PATHINFO_EXTENSION));
        $this->setName(pathinfo($name, PATHINFO_FILENAME));

        return $this;
    }

    public function getMimetype(): string
    {
        if (empty($this->mimetype) && $this->isReadableFile()) {
            $finfo = new finfo(FILEINFO_MIME);
            $mimetype = $finfo->file($this->getPathname());
            $mimetypeParts = (array)preg_split('/\s*[;,]\s*/', (string)$mimetype);

            if (isset($mimetypeParts[0])) {
                $this->mimetype = AsciiCase::toLower((string)$mimetypeParts[0]);
            }
            unset($finfo);
        }

        return $this->mimetype;
    }

    /**
     * Get a specified hash
     *
     * The default is SHA-256. MD5 is still reachable as `getHash('md5')`, but chosen-prefix
     * collisions against it are practical, so it cannot carry an integrity or identity
     * decision about content someone else supplied.
     *
     * @param string $algorithm Any algorithm supported by `hash_algos()`
     * @return string Empty string if the file cannot be read
     * @throws \InvalidArgumentException If the algorithm is not supported by this PHP build
     */
    public function getHash(string $algorithm = 'sha256'): string
    {
        /* Not Upload\Exception: File::isValid() catches that and formats it into getErrors(),
           so a misspelled algorithm would reach the end user as a rejected file instead of the
           developer as broken code. InvalidArgumentException is a LogicException, which
           isValid() deliberately does not absorb. */
        if (!in_array(AsciiCase::toLower($algorithm), hash_algos(), true)) {
            throw new \InvalidArgumentException(
                sprintf('Unsupported hashing algorithm: %s', $algorithm)
            );
        }

        if (!$this->isReadableFile()) {
            return '';
        }

        return (string)hash_file($algorithm, $this->getPathname());
    }

    /**
     * Get file size in bytes
     *
     * `SplFileInfo::getSize()` throws a `RuntimeException` when the file cannot be stat'd.
     * `FileInfoInterface` documents `int|false`, so an unreadable file reports `false`
     * rather than aborting a validation run.
     *
     * @return int|false
     */
    #[\ReturnTypeWillChange]
    public function getSize()
    {
        if (!$this->isReadableFile()) {
            return false;
        }

        return parent::getSize();
    }

    /**
     * @return array<string, float|int>
     */
    public function getDimensions(): array
    {
        $imageSize = $this->isReadableFile() ? getimagesize($this->getPathname()) : false;
        if (!$imageSize) {
            $imageSize = [0,0];
        }

        return [
            'width' => $imageSize[0],
            'height' => $imageSize[1],
        ];
    }

    /**
     * Is this file uploaded with a POST request?
     *
     * A separate method so tests can stub out the `is_uploaded_file()` dependency.
     */
    public function isUploadedFile(): bool
    {
        return is_uploaded_file($this->getPathname());
    }

    /**
     * Is there actually a readable file behind this pathname?
     *
     * A `FileInfo` can legitimately wrap a path that no longer resolves, so the metadata
     * accessors check this first and degrade instead of raising `ValueError`/`RuntimeException`
     * from the underlying extension.
     */
    protected function isReadableFile(): bool
    {
        $pathname = $this->getPathname();

        return $pathname !== '' && is_file($pathname) && is_readable($pathname);
    }
}
