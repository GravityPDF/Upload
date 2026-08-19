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

use GravityPdf\Upload\AsciiCase;
use GravityPdf\Upload\Exception;
use GravityPdf\Upload\FileInfoInterface;
use GravityPdf\Upload\ValidationInterface;

/**
 * Validate File Extension
 *
 * @deprecated 4.0.0 Use GravityPdf\Upload\Validation\FileType, which constrains the contents
 * as well and requires the two to agree. Checking the extension on its own says nothing about
 * what the file holds. This class still works and has no runtime deprecation notice.
 *
 * @author  Alex Kucherenko <kucherenko.email@gmail.com>
 * @since   1.0.0
 * @package Upload
 */
class Extension implements ValidationInterface
{
    /** @var string[] Acceptable file extensions, without leading dots */
    protected $allowedExtensions;

    /**
     * @param string|string[] $allowedExtensions Allowed file extensions, without leading dots
     *
     * ```php
     * new \GravityPdf\Upload\Validation\Extension(['png', 'jpg', 'gif']);
     * ```
     */
    public function __construct($allowedExtensions)
    {
        if (is_string($allowedExtensions)) {
            $allowedExtensions = [$allowedExtensions];
        }

        $this->allowedExtensions = array_map([AsciiCase::class, 'toLower'], $allowedExtensions);
    }

    /**
     * @throws Exception If validation fails
     */
    public function validate(FileInfoInterface $fileInfo): void
    {
        $fileExtension = AsciiCase::toLower($fileInfo->getExtension());

        if (!in_array($fileExtension, $this->allowedExtensions, true)) {
            throw new Exception(
                sprintf('Invalid file extension. Must be one of: %s', implode(', ', $this->allowedExtensions)),
                $fileInfo
            );
        }
    }
}
