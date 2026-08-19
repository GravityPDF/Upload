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

namespace GravityPdf\Upload\Validation;

use GravityPdf\Upload\AsciiCase;
use GravityPdf\Upload\Exception;
use GravityPdf\Upload\FileInfoInterface;
use GravityPdf\Upload\ValidationInterface;
use InvalidArgumentException;

/**
 * Validate File Extension Against Media Type
 *
 * Checks the extension and the sniffed media type together, and requires them to agree.
 *
 * `Extension` and `Mimetype` used side by side check two independent allow-lists, so a file
 * satisfies both without the two answers describing the same format: given
 * `Extension(['png', 'gif'])` and `Mimetype(['image/png', 'image/gif'])`, content sniffed as
 * `image/gif` stored as `avatar.png` passes. This class pairs them instead, so the bytes on
 * disk have to match what the stored extension claims.
 *
 * Describe one format per call. Both sides accept a list, so `['jpg', 'jpeg']` can share
 * `image/jpeg`, but two formats in a single call pair every extension with every media type
 * and hand back the looseness this class exists to remove.
 *
 * @example new FileType(['jpg', 'jpeg'], 'image/jpeg')
 * @example (new FileType('png', 'image/png'))->allow(['tif', 'tiff'], 'image/tiff')
 *
 * @author  Gravity PDF <support@gravitypdf.com>
 * @since   4.0.0
 * @package Upload
 */
class FileType implements ValidationInterface
{
    /**
     * Allowed extensions, each mapped to the media types it may hold
     * @var array<string, string[]>
     */
    protected $allowedTypes = [];

    /**
     * @param string|string[] $extensions Allowed file extensions without leading dots
     * @param string|string[] $mimetypes  Media types the extensions above may hold
     */
    public function __construct($extensions, $mimetypes)
    {
        $this->allow($extensions, $mimetypes);
    }

    /**
     * Pair another set of extensions with the media types they may hold
     *
     * Extensions already registered gain the media types given here rather than replacing them.
     *
     * @param string|string[] $extensions Allowed file extensions without leading dots
     * @param string|string[] $mimetypes  Media types the extensions above may hold
     *
     * @throws InvalidArgumentException If either side is empty, which would silently reject
     *                                  every upload
     */
    public function allow($extensions, $mimetypes): FileType
    {
        $extensions = $this->normalize($extensions, true);
        $mimetypes = $this->normalize($mimetypes, false);

        if (count($extensions) === 0 || count($mimetypes) === 0) {
            throw new InvalidArgumentException('Expected at least one extension and one mimetype');
        }

        foreach ($extensions as $extension) {
            $existing = $this->allowedTypes[$extension] ?? [];

            $this->allowedTypes[$extension] = array_values(array_unique(array_merge($existing, $mimetypes)));
        }

        return $this;
    }

    /**
     * The media types allowed for each extension, as configured
     *
     * @return array<string, string[]>
     */
    public function getAllowedTypes(): array
    {
        return $this->allowedTypes;
    }

    /**
     * Reduce one side of an `allow()` call to the values it actually configures
     *
     * Empty strings are dropped rather than registered. `(array)''` is a list of one, so it
     * would otherwise slip past the guard that `[]` trips and pair the extension with the empty
     * media type `getMimetype()` returns for a file it cannot read — an unreadable `avatar.png`
     * passing a check written to require a real PNG.
     *
     * @param string|string[] $values
     * @param bool $isExtension Extensions are compared against `getExtension()`, which never
     *                          carries a leading dot, so accept and remove one
     * @return string[]
     */
    private function normalize($values, bool $isExtension): array
    {
        $normalized = [];

        foreach ((array)$values as $value) {
            $value = AsciiCase::toLower(trim((string)$value));

            if ($isExtension) {
                $value = ltrim($value, '.');
            }

            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @throws Exception If validation fails
     */
    public function validate(FileInfoInterface $fileInfo): void
    {
        $extension = AsciiCase::toLower(trim($fileInfo->getExtension()));

        if (!isset($this->allowedTypes[$extension])) {
            throw new Exception(
                sprintf(
                    'Invalid file extension. Must be one of: %s',
                    implode(', ', array_keys($this->allowedTypes))
                ),
                $fileInfo
            );
        }

        /* Lowercased to match the configured list, which `normalize()` has already folded. The
           shipped FileInfo lowercases in getMimetype(), a custom FileInfoInterface need not. */
        $mimetype = AsciiCase::toLower(trim($fileInfo->getMimetype()));

        if (!in_array($mimetype, $this->allowedTypes[$extension], true)) {
            throw new Exception(
                sprintf(
                    'File contents do not match the "%s" extension. Must be one of: %s',
                    $extension,
                    implode(', ', $this->allowedTypes[$extension])
                ),
                $fileInfo
            );
        }
    }
}
