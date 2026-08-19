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

namespace GravityPdf\Upload\Validation;

use GravityPdf\Upload\Exception;
use GravityPdf\Upload\File;
use GravityPdf\Upload\FileInfoInterface;
use GravityPdf\Upload\ValidationInterface;

/**
 * Validate Upload File Size
 *
 * Bounds are inclusive. Give each as an integer of bytes or a human-readable string ("5MB").
 *
 * @author  Josh Lockhart <info@joshlockhart.com>
 * @since   1.0.0
 * @package Upload
 */
class Size implements ValidationInterface
{
    /** @var int Minimum acceptable file size (bytes) */
    protected $minSize;

    /** @var int Maximum acceptable file size (bytes) */
    protected $maxSize;

    /**
     * @param int|string $maxSize Maximum acceptable file size in bytes (inclusive)
     * @param int|string $minSize Minimum acceptable file size in bytes (inclusive)
     * @throws \InvalidArgumentException If a string bound cannot be parsed as a file size
     */
    public function __construct($maxSize, $minSize = 0)
    {
        if (is_string($maxSize)) {
            $maxSize = File::humanReadableToBytes($maxSize);
        }

        $this->maxSize = $maxSize;

        if (is_string($minSize)) {
            $minSize = File::humanReadableToBytes($minSize);
        }

        $this->minSize = $minSize;
    }

    /**
     * @throws Exception If validation fails
     */
    public function validate(FileInfoInterface $fileInfo): void
    {
        $fileSize = $fileInfo->getSize();

        /* On PHP 8 SplFileInfo::getSize() throws instead of returning false. File::isValid()
           would absorb that as the generic "Validation could not be completed"; this reports
           what actually went wrong with the file. */
        if ($fileSize === false) {
            throw new Exception('File size could not be determined', $fileInfo);
        }

        if ($fileSize < $this->minSize) {
            throw new Exception(
                sprintf('File size is too small. Must be greater than or equal to: %s', $this->minSize),
                $fileInfo
            );
        }

        if ($fileSize > $this->maxSize) {
            throw new Exception(
                sprintf('File size is too large. Must be less than or equal to: %s', $this->maxSize),
                $fileInfo
            );
        }
    }
}
