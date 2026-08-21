<?php

namespace GravityPdf\Upload\Validation;

/**
 * A `Size` that writes the separator German uses
 *
 * The documented answer to "the decimal separator is a `.` and cannot be anything else": it
 * cannot be, here, because choosing one needs a locale this library does not take — so
 * `scale()` is protected and a caller who has a locale overrides it. This is that override,
 * and `SizeTest` runs it.
 */
class GermanSize extends Size
{
    /**
     * @param bool $down
     * @return array<int,string>
     */
    protected static function scale(int $bytes, bool $down): array
    {
        list($amount, $unit) = parent::scale($bytes, $down);

        return [str_replace('.', ',', $amount), $unit];
    }
}
