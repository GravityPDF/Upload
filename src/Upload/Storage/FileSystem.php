<?php

/**
 * Upload
 *
 * @author      Josh Lockhart <info@joshlockhart.com>
 * @copyright   2012 Josh Lockhart
 * @link        http://www.joshlockhart.com
 * @version     2.0.0
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

namespace GravityPdf\Upload\Storage;

use InvalidArgumentException;
use GravityPdf\Upload\Exception;
use GravityPdf\Upload\FileInfoInterface;
use GravityPdf\Upload\StorageInterface;

/**
 * FileSystem Storage
 *
 * This class uploads files to a designated directory on the filesystem.
 *
 * @author  Josh Lockhart <info@joshlockhart.com>
 * @since   1.0.0
 * @package Upload
 */
class FileSystem implements StorageInterface
{
    /**
     * Path to upload destination directory (with trailing slash)
     * @var string
     */
    protected $directory;

    /**
     * Overwrite existing files?
     * @var bool
     */
    protected $overwrite;

    /**
     * Constructor
     *
     * @param string $directory Relative or absolute path to upload directory
     * @param bool $overwrite Should this overwrite existing files?
     * @throws InvalidArgumentException            If directory does not exist
     * @throws InvalidArgumentException            If directory is not writable
     */
    public function __construct(string $directory, bool $overwrite = false)
    {
        if (!is_dir($directory)) {
            throw new InvalidArgumentException('Directory does not exist');
        }

        if (!is_writable($directory)) {
            throw new InvalidArgumentException('Directory is not writable');
        }

        $this->directory = rtrim($directory, '/') . DIRECTORY_SEPARATOR;
        $this->overwrite = $overwrite;
    }

    /**
     * Upload
     *
     * @param FileInfoInterface $fileInfo
     * @return string
     */
    public function upload(FileInfoInterface $fileInfo): string
    {
        $destinationFile = $this->directory . $fileInfo->getNameWithExtension();
        if ($this->overwrite === false && is_file($destinationFile) === true) {
            throw new Exception('File already exists', $fileInfo);
        }

        if ($this->moveUploadedFile($fileInfo->getPathname(), $destinationFile) === false) {
            throw new Exception('File could not be moved to final destination.', $fileInfo);
        }

        return $destinationFile;
    }

    /**
     * Get directory (without trailing slash)
     *
     * @return string
     */
    public function getDirectory(): string
    {
        return rtrim($this->directory, DIRECTORY_SEPARATOR);
    }

    /**
     * Move uploaded file
     *
     * This method allows us to stub this method in unit tests to avoid
     * hard dependency on the `move_uploaded_file` function.
     *
     * @param string $source The source file
     * @param string $destination The destination file
     * @return bool
     */
    protected function moveUploadedFile(string $source, string $destination): bool
    {
        return move_uploaded_file($source, $destination);
    }
}
