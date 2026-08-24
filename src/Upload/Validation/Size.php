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
use InvalidArgumentException;

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
     * @throws InvalidArgumentException If a bound is not an int of bytes or a size string,
     *                                  if a string bound cannot be parsed, or if the two
     *                                  bounds accept nothing between them
     */
    public function __construct($maxSize, $minSize = 0)
    {
        $this->maxSize = $this->toBytes($maxSize, 'maxSize');
        $this->minSize = $this->toBytes($minSize, 'minSize');

        if ($this->minSize > $this->maxSize) {
            throw new InvalidArgumentException(sprintf(
                'Size was given a minimum of %d bytes and a maximum of %d bytes, which no '
                . 'file can satisfy. The maximum is the first argument.',
                $this->minSize,
                $this->maxSize
            ));
        }
    }

    /**
     * Read one bound as a byte count
     *
     * The types are checked here rather than left to `scale()`, which is declared `int` and
     * raises a `TypeError` when a bound is a float — from inside `validate()`, where
     * `File::runValidations()` absorbs it as `Validation could not be completed` and reports
     * the developer's misconfiguration to whoever submitted the file. A float is what a bound
     * read out of JSON or arrived at by division actually is.
     *
     * `InvalidArgumentException` is a `LogicException`, which that run re-throws, so a bound
     * rejected after construction still reaches the developer.
     *
     * @param mixed $size Whatever the caller passed. Declared wider than the constructor's
     *                    `int|string`, because a docblock is not enforced at runtime and this
     *                    method exists to answer for the values that ignore it
     * @param string $parameter The parameter being read, named in the message
     * @throws InvalidArgumentException If the bound cannot be a byte count
     */
    private function toBytes($size, string $parameter): int
    {
        if (is_string($size)) {
            $size = File::humanReadableToBytes($size);
        }

        if (!is_int($size)) {
            throw new InvalidArgumentException(sprintf(
                'Size::$%s must be an int of bytes or a string such as "5MB", %s given',
                $parameter,
                gettype($size)
            ));
        }

        /* A negative bound reads as generous and rejects every upload, which is the failure
           `File::humanReadableToBytes()` already refuses a string for. */
        if ($size < 0) {
            throw new InvalidArgumentException(sprintf(
                'Size::$%s cannot be negative, %d given',
                $parameter,
                $size
            ));
        }

        return $size;
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
            /* Rounds up; the maximum rounds down. Either way the size named is one the file
               would pass at, so resizing to the number given works. */
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
     * One message per unit the limit can be stated in, for a file over it
     *
     * The unit is part of the message rather than a value interpolated into it, because
     * values are never translated and `MB` is not universal — French writes `Mo`.
     *
     * A method, not a property, because a PHP 7.3 constant expression cannot call `__()`.
     * Override it to reword; your replacements are then yours to extract.
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
     * The same, for a file under the limit
     *
     * @see getTooLargeMessages() On why the unit is inside the message
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
     * State a byte count in the largest unit it reaches
     *
     * The inverse of `File::humanReadableToBytes()` and 1024-based like it, so `new Size('5M')`
     * reports `5 MB`. One decimal at most.
     *
     * A maximum rounds down and a minimum rounds up, so the size named is always one the file
     * would pass at. A 5,000,000 byte maximum shown as `4.8 MB` would name a size still
     * rejected.
     *
     * The separator is a `.`, because picking another needs a locale this library does not
     * take. **Override this to change it** — you get the unit as well as the number, and the
     * unit decides which message is used. Return a key `getTooLargeMessages()` and
     * `getTooSmallMessages()` both hold, or override those too.
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
