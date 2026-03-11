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

namespace GravityPdf\Upload;

use finfo;
use RuntimeException;
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
    /**
     * Factory method that returns new instance of \FileInfoInterface
     * @var callable|null
     */
    protected static $factory;

    /**
     * File name (without extension)
     * @var string
     */
    protected $name = '';

    /**
     * File extension (without dot prefix)
     * @var string
     */
    protected $extension = '';

    /**
     * File mimetype
     * @var string
     */
    protected $mimetype = '';

    /**
     * Constructor
     *
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

    public static function createFromFactory(string $tmpName, ?string $name = null): FileInfoInterface
    {
        if (is_callable(static::$factory)) {
            $result = call_user_func(static::$factory, $tmpName, $name);
            if ($result instanceof FileInfoInterface === false) {
                throw new RuntimeException(
                    'FileInfo factory must return instance of \GravityPdf\Upload\FileInfoInterface.'
                );
            }

            return $result;
        }

        return new static($tmpName, $name);
    }

    /**
     * Get file name (without extension)
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Set file name (without extension)
     *
     * Sanitize the filename (if outputting the filename to HTML you still need to escape)
     *
     * @param string $name
     * @return FileInfo Self
     *
     * @link https://stackoverflow.com/a/42058764
     * @internal 1. file system reserved https://en.wikipedia.org/wiki/Filename#Reserved_characters_and_words
     * phpcs:ignore
     * @internal 2. control characters http://msdn.microsoft.com/en-us/library/windows/desktop/aa365247%28v=vs.85%29.aspx
     * @internal 3. URI reserved https://www.rfc-editor.org/rfc/rfc3986#section-2.2
     * @internal 4. URL unsafe characters https://www.ietf.org/rfc/rfc1738.txt
     */
    public function setName(string $name): FileInfo
    {
        $this->name = $this->sanitizeName($name);

        return $this;
    }

    protected function sanitizeName(string $name): string
    {
        $name = str_replace(['%20', '+', '.'], '-', $name); //replaces encoded space, +, or .
        $name = (string) preg_replace('/[\r\n\t-]+/', '-', $name); //replace tab or new line characters
        $name = (string) preg_replace(
            '~
        [%<>:"/\\\|?*]|          # @internal 1.
        [\x00-\x1F]|            # @internal 2.
        [#\[\]@!$&\'()+,;=]|    # @internal 3.
        [{}^\~`]                # @internal 4.
        ~x',
            '-',
            $name
        );

        // reduce consecutive characters
        $name = (string) preg_replace(
            [
                '/ +/', // "file   name.zip" becomes "file name.zip"
                '/_+/', // "file___name.zip" becomes "file_name.zip"
                '/ - -+/', // "file - -name.zip" becomes "file--name.zip"
                '/-+/', // "file--name.zip" becomes "file-name.zip"
            ],
            [
                ' ',
                '_',
                '-',
                '-',
            ],
            $name
        );

        $name = trim((string)$name, '.-_ '); //remove dot, hyphen, underscore, or space from start and end of string

        /* Ensure filename is not a reserved Windows name, otherwise remove */
        if (in_array(strtolower($name), $this->getReservedWindowsNames(), true)) {
            $name = '';
        }

        /*
         * Ensure filename is not longer than 255 bytes http://serverfault.com/a/9548/44086, otherwise shorten
         */
        $extension = $this->getExtension();
        $maxLength = 255 - ($extension ? strlen($extension) + 1 : 0);

        /* Use multibyte aware functions, if the server supports it */
        if (function_exists('mb_strcut') && function_exists('mb_detect_encoding')) {
            $name = mb_strcut($name, 0, $maxLength, (string)mb_detect_encoding($name));
        } else {
            $name = substr($name, 0, $maxLength);
        }

        if (empty($name)) {
            $name = 'unnamed-file';
        }

        return $name;
    }

    /**
     * Get file extension (without dot prefix)
     *
     * @return string
     */
    public function getExtension(): string
    {
        return $this->extension;
    }

    /**
     * Set file extension (without dot prefix)
     *
     * Sanitize the extension (lowercase, alphanumeric)
     *
     * @param string $extension
     * @return FileInfo Self
     */
    public function setExtension(string $extension): FileInfo
    {
        $extension = strtolower($extension);
        $extension = trim((string)preg_replace('/[^a-z0-9]/', '', $extension));

        /* Remove Windows reserved extensions */
        $extension = str_replace($this->getReservedWindowsNames(), '', $extension);

        $this->extension = $extension;

        return $this;
    }

    /**
     * Provide a list of reserved extensions / filenames in Windows
     *
     * @link https://docs.microsoft.com/en-us/windows/win32/fileio/naming-a-file#naming-conventions
     *
     * @return string[]
     */
    protected function getReservedWindowsNames(): array
    {
        return [
            'con',
            'prn',
            'aux',
            'nul',
            'com1',
            'com2',
            'com3',
            'com4',
            'com5',
            'com6',
            'com7',
            'com8',
            'com9',
            'lpt1',
            'lpt2',
            'lpt3',
            'lpt4',
            'lpt5',
            'lpt6',
            'lpt7',
            'lpt8',
            'lpt9',
        ];
    }

    /**
     * Get file name with extension
     *
     * @return string
     */
    public function getNameWithExtension(): string
    {
        return $this->extension === '' ? $this->name : sprintf('%s.%s', $this->name, $this->extension);
    }

    /**
     * Set the file name with extension
     *
     * @param string $name
     * @return $this
     */
    public function setNameWithExtension(string $name): FileInfo
    {
        $this->setExtension(pathinfo($name, PATHINFO_EXTENSION));
        $this->setName(pathinfo($name, PATHINFO_FILENAME));

        return $this;
    }

    /**
     * Get mimetype
     *
     * @return string
     */
    public function getMimetype(): string
    {
        if (empty($this->mimetype)) {
            $finfo = new finfo(FILEINFO_MIME);
            $mimetype = $finfo->file($this->getPathname());
            $mimetypeParts = (array)preg_split('/\s*[;,]\s*/', (string)$mimetype);

            if (isset($mimetypeParts[0])) {
                $this->mimetype = strtolower((string)$mimetypeParts[0]);
            }
            unset($finfo);
        }

        return $this->mimetype;
    }

    /**
     * Get md5
     *
     * @return string
     */
    public function getMd5(): string
    {
        return (string)md5_file($this->getPathname());
    }

    /**
     * Get a specified hash
     *
     * @param string $algorithm
     * @return string
     */
    public function getHash(string $algorithm = 'md5'): string
    {
        return hash_file($algorithm, $this->getPathname());
    }

    /**
     * Get image dimensions
     *
     * @return array<string, float|int> formatted array of dimensions
     */
    public function getDimensions(): array
    {
        $imageSize = getimagesize($this->getPathname());
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
     * This is a separate method so that it can be stubbed in unit tests to avoid
     * the hard dependency on the `is_uploaded_file` function.
     *
     * @return bool
     */
    public function isUploadedFile(): bool
    {
        return is_uploaded_file($this->getPathname());
    }
}
