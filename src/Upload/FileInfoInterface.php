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

/**
 * FileInfo Interface
 *
 * @author  Josh Lockhart <info@joshlockhart.com>
 * @since   2.0.0
 * @package Upload
 */
interface FileInfoInterface
{
    /**
     * @return string
     */
    public function getPathname();

    public function getName(): string;

    /**
     * No native return type on the three setters below, so an implementation that is not a
     * `FileInfo` can return `$this`. Declaring `: FileInfo` here made the interface depend on its
     * own implementation, and no other class could narrow its return type to match, because
     * covariant returns arrived in PHP 7.4 and this library supports 7.3. `FileInfo` still declares
     * `: FileInfo` on its own methods; adding a return type where the interface declares none has
     * always been allowed.
     *
     * @return FileInfoInterface
     */
    public function setName(string $name);

    public function getExtension(): string;

    /**
     * @return FileInfoInterface
     */
    public function setExtension(string $extension);

    public function getNameWithExtension(): string;

    /**
     * @return FileInfoInterface
     */
    public function setNameWithExtension(string $filename);

    public function getMimetype(): string;

    /**
     * @return int|false
     */
    public function getSize();

    /**
     * @throws \InvalidArgumentException If this PHP build does not support the algorithm.
     *                                   `File::isValid()` lets that escape rather than
     *                                   reporting it as a rejected file.
     */
    public function getHash(string $algorithm = 'sha256'): string;

    /**
     * @return array<string, int|float>
     */
    public function getDimensions(): array;

    public function isUploadedFile(): bool;
}
