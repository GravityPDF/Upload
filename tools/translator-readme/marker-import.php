<?php

/**
 * The fixture behind the README's "note the leading backslash"
 *
 * A plugin that installs a translator and also writes a validation of its own imports this
 * library's marker into that file. An unqualified `__()` then reaches the marker, which hands
 * back its argument, and the translator silently does nothing. `\__()` is unambiguously the
 * global one.
 *
 * This file is that situation: the import is present and both calls are made. `verify.php`
 * asserts the import is still here — without it the check proves nothing — and that `\__()`
 * still resolves past it to the global in `global-underscore.php`.
 *
 * @package Upload
 */

declare(strict_types=1);

namespace GravityPdf\Upload\ReadmeCheck;

use function GravityPdf\Upload\__;

/** Is the marker still imported here? Without the import this file proves nothing. */
function markerIsImported(): bool
{
    $source = (string) file_get_contents(__FILE__);

    return strpos($source, 'use function GravityPdf\Upload\__;') !== false;
}

/**
 * An unqualified call reaches the marker; a qualified one reaches the global
 *
 * The first is the mistake the README warns about: it returns its argument unchanged. The
 * second is what the documented recipe does.
 */
function globalUnderscoreWins(): string
{
    $viaMarker = __('marker');

    if ($viaMarker !== 'marker') {
        return 'the unqualified call did not reach the marker: ' . $viaMarker;
    }

    /* Cleared so the global answers with its own name rather than through Laravel. */
    $GLOBALS['laravel'] = null;

    return \__('global');
}
