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

use GravityPdf\Upload\ErrorCode;
use GravityPdf\Upload\Exception;
use GravityPdf\Upload\File;
use GravityPdf\Upload\FileInfoInterface;
use GravityPdf\Upload\ValidationInterface;

use function GravityPdf\Upload\__;

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

    /** Bytes per unit, largest first: `scale()` takes the first one the count reaches */
    private const UNITS = [
        'GB' => 1073741824,
        'MB' => 1048576,
        'KB' => 1024,
        'B' => 1,
    ];

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
            throw new Exception(__('File size could not be determined'), $fileInfo, ErrorCode::SIZE_UNKNOWN);
        }

        if ($fileSize < $this->minSize) {
            /* Rounded up, where the maximum rounds down: both directions state a bound the
               file would actually satisfy, so a reader who resizes to the number they were
               given is not rejected a second time. */
            list($amount, $unit) = static::scale($this->minSize, false);

            throw new Exception(
                static::getTooSmallMessages()[$unit],
                $fileInfo,
                ErrorCode::SIZE_TOO_SMALL,
                [$amount]
            );
        }

        if ($fileSize > $this->maxSize) {
            list($amount, $unit) = static::scale($this->maxSize, true);

            throw new Exception(
                static::getTooLargeMessages()[$unit],
                $fileInfo,
                ErrorCode::SIZE_TOO_LARGE,
                [$amount]
            );
        }
    }

    /**
     * The message for a file over the limit, one per unit the limit can be stated in
     *
     * A message id per unit rather than one with the unit as a value, because a value is
     * interpolated after the lookup and a translator never sees it. `MB` is not universal —
     * French writes `Mo` — so the unit has to sit inside the string they translate.
     *
     * A method rather than a property for the reason `File::getUploadErrorMessages()` is one:
     * a PHP 7.3 constant expression cannot call `__()`. Override it to reword, and the
     * replacements are yours to extract.
     *
     * @return array<string,string> Keyed by the unit keys `scale()` returns
     */
    protected static function getTooLargeMessages(): array
    {
        return [
            'GB' =>
                /* translators: %1$s: the largest accepted size, in gigabytes */
                __('File size is too large. Must be no more than %1$s GB'),
            'MB' =>
                /* translators: %1$s: the largest accepted size, in megabytes */
                __('File size is too large. Must be no more than %1$s MB'),
            'KB' =>
                /* translators: %1$s: the largest accepted size, in kilobytes */
                __('File size is too large. Must be no more than %1$s KB'),
            'B' =>
                /* translators: %1$s: the largest accepted size, in bytes */
                __('File size is too large. Must be no more than %1$s bytes'),
        ];
    }

    /**
     * The message for a file under the limit, one per unit the limit can be stated in
     *
     * @see getTooLargeMessages() On why the unit is in the message rather than beside it
     *
     * @return array<string,string> Keyed by the unit keys `scale()` returns
     */
    protected static function getTooSmallMessages(): array
    {
        return [
            'GB' =>
                /* translators: %1$s: the smallest accepted size, in gigabytes */
                __('File size is too small. Must be at least %1$s GB'),
            'MB' =>
                /* translators: %1$s: the smallest accepted size, in megabytes */
                __('File size is too small. Must be at least %1$s MB'),
            'KB' =>
                /* translators: %1$s: the smallest accepted size, in kilobytes */
                __('File size is too small. Must be at least %1$s KB'),
            'B' =>
                /* translators: %1$s: the smallest accepted size, in bytes */
                __('File size is too small. Must be at least %1$s bytes'),
        ];
    }

    /**
     * State a byte count in the largest unit it reaches, as the amount and that unit's key
     *
     * The inverse of `File::humanReadableToBytes()`, and 1024-based like it, so
     * `new Size('5M')` reports `5 MB` rather than `5242880 bytes` or `4.8 MB`.
     *
     * One decimal at most, and the rounding is directional: a maximum rounds **down** and a
     * minimum **up**, so the number shown is always a size the file would pass at. Rounding a
     * maximum of 5,000,000 bytes to `4.8 MB` would name a size still rejected.
     *
     * The decimal separator is a `.`, because choosing one needs a locale and this library
     * takes none. **This is the seam for that**: override it, and a subclass owns both the
     * number and the unit the message is chosen by, which a formatter hook on `Translation`
     * could not do — the unit is picked here, and by the time a value is interpolated the
     * message id naming it has already been chosen.
     *
     * ```php
     * protected static function scale(int $bytes, bool $down): array
     * {
     *     list($amount, $unit) = parent::scale($bytes, $down);
     *
     *     return [number_format_i18n((float) $amount, 1), $unit];
     * }
     * ```
     *
     * Return a unit key `getTooLargeMessages()` and `getTooSmallMessages()` both hold, or
     * override those too. Called through `static::`, so an override is reached.
     *
     * @param bool $down Round towards zero, for an upper bound
     * @return array<int,string> The scaled amount, and the unit key naming its message
     */
    protected static function scale(int $bytes, bool $down): array
    {
        foreach (self::UNITS as $unit => $size) {
            if ($bytes < $size) {
                continue;
            }

            $scaled = $bytes / $size;
            $tenths = $down ? floor($scaled * 10) : ceil($scaled * 10);

            /* Trailing `.0` trimmed, so a round limit reads `5 MB` rather than `5.0 MB`. */
            $amount = rtrim(rtrim(number_format($tenths / 10, 1, '.', ''), '0'), '.');

            return [$amount, $unit];
        }

        return [(string) $bytes, 'B'];
    }
}
