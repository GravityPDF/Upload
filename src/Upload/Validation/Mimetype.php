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
use GravityPdf\Upload\ErrorCode;
use GravityPdf\Upload\Exception;
use GravityPdf\Upload\FileInfoInterface;
use GravityPdf\Upload\Translation;
use GravityPdf\Upload\ValidationInterface;

/**
 * Validate Upload Media Type
 *
 * @deprecated 4.0.0 Use GravityPdf\Upload\Validation\FileType, which constrains the stored
 * extension as well and requires the two to agree. `finfo` inspects the leading bytes, which a
 * polyglot satisfies trivially: a file that begins "GIF89a" and continues with PHP is reported
 * as image/gif. This class still works and has no runtime deprecation notice.
 *
 * @author  Josh Lockhart <info@joshlockhart.com>
 * @since   1.0.0
 * @package Upload
 */
class Mimetype implements ValidationInterface
{
    /** @var string[] Valid media types, lowercased */
    protected $mimetypes;

    /**
     * @param string|string[] $mimetypes
     */
    public function __construct($mimetypes)
    {
        if (is_string($mimetypes)) {
            $mimetypes = [$mimetypes];
        }

        /* Folded, as `Extension` and `FileType` fold theirs. A media type is case-insensitive
           and `FileInfo::getMimetype()` answers lowercase, so `'IMAGE/PNG'` matched nothing.
           An entry left empty by the trim is dropped rather than registered, for the reason
           `FileType::normalize()` drops one: `getMimetype()` answers `''` for a file it cannot
           read, so `' '` in the list would accept exactly those. */
        $this->mimetypes = [];

        foreach ($mimetypes as $mimetype) {
            $mimetype = AsciiCase::toLower(trim($mimetype));

            if ($mimetype !== '') {
                $this->mimetypes[] = $mimetype;
            }
        }
    }

    /**
     * @throws Exception If validation fails
     */
    public function validate(FileInfoInterface $fileInfo): void
    {
        /* The shipped FileInfo lowercases in getMimetype(); a custom FileInfoInterface need not */
        $mimetype = AsciiCase::toLower(trim($fileInfo->getMimetype()));

        if (!in_array($mimetype, $this->mimetypes, true)) {
            throw new Exception(
                /* translators: %1$s: comma-separated list of the accepted media types */
                Translation::__('Invalid mimetype. Must be one of: %1$s'),
                $fileInfo,
                ErrorCode::MIMETYPE_NOT_ALLOWED,
                [implode(', ', $this->mimetypes)]
            );
        }
    }
}
