<?php

/**
 * A global `__()`, which is what WordPress and Laravel each define
 *
 * Laravel's lives in illuminate/foundation and WordPress's in wp-includes; installing either
 * would pull in a framework to test three lines of closure. This does what both do: hand the
 * key to a translator. With none set it answers with its own name, so `verify.php` can tell
 * which function a call actually reached.
 *
 * Declared in the global namespace, which is where the documented adapters reach it from.
 *
 * @package Upload
 */

if (!function_exists('__')) {
    /**
     * @param array<string,string> $replace
     */
    function __(string $key, array $replace = [], ?string $locale = null): string
    {
        /** @var \Illuminate\Translation\Translator|null $laravel */
        $laravel = $GLOBALS['laravel'] ?? null;

        if ($laravel !== null) {
            /** @var string $translated */
            $translated = $laravel->get($key, $replace, $locale);

            return $translated;
        }

        return 'WORDPRESS';
    }
}
