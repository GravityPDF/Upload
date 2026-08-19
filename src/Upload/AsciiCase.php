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

/**
 * Case folding that does not depend on the host's locale
 *
 * `strtolower()` follows `LC_CTYPE` before PHP 8.2, so an application that calls
 * `setlocale()` changes what this library considers the same string. Under a Turkish locale
 * `strtolower('TIFF')` is `t<U+0131>ff`, which `FileInfo::setExtension()` then discards as
 * containing something other than letters and digits — a valid upload stored with no
 * extension, on four of the eight supported versions (7.3, 7.4, 8.0 and 8.1).
 *
 * Every value folded in this library is an extension, a media type, a hash algorithm or a
 * reserved device name: ASCII by definition, so folding only ASCII loses nothing.
 *
 * @internal Not part of the public API
 *
 * @since   4.0.0
 *
 * @package Upload
 */
final class AsciiCase
{
    /**
     * Lowercase the ASCII letters in a string and leave every other byte alone
     *
     * @param string $value
     * @return string
     */
    public static function toLower(string $value): string
    {
        return strtr(
            $value,
            'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
            'abcdefghijklmnopqrstuvwxyz'
        );
    }
}
