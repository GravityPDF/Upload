<?php

/**
 * Runs the base64 bridge from docs/base64-uploads.md
 *
 * The page tells a caller to write this code, so it has to keep working: every snippet below is
 * read out of the Markdown at run time rather than copied here. A renamed class, a dropped
 * example or a changed signature fails this before it reaches anyone reading the docs.
 *
 * No manifest of its own, unlike tools/psr7-readme/: a base64 payload arrives as a string in the
 * request body, so there is no third-party implementation to run the bridge against. The
 * `TmpUploadFile` override and the tmp-file cleanup are read out of README.md, where the PSR-7
 * bridge declares them once.
 *
 * Exits non-zero, and says which expectation broke, on any mismatch.
 */

/* phpcs:disable PSR1.Files.SideEffects -- a script, not a unit: it declares two helpers and
   then runs, which is the whole shape of the file */

require __DIR__ . '/../../vendor/autoload.php';

$root = __DIR__ . '/../..';
$failures = [];
$tmpDirectory = __DIR__ . '/uploads-tmp';
$destination = __DIR__ . '/stored';

/** The snippet in $file containing $needle, or a fatal error naming what went missing */
function documentedBlock(string $file, string $needle, string $describes): string
{
    preg_match_all('/```php\n(.*?)```/s', (string) file_get_contents($file), $matches);

    foreach ($matches[1] as $block) {
        if (strpos($block, $needle) !== false) {
            return $block;
        }
    }

    fwrite(STDERR, sprintf(
        "%s no longer contains the %s example (looked for \"%s\").\n"
        . "The base64 bridge is documented code: update this check alongside it.\n",
        basename($file),
        $describes,
        $needle
    ));

    exit(1);
}

$page = $root . '/docs/base64-uploads.md';
$readme = $root . '/README.md';

$bridge = documentedBlock($page, 'function fileListFromBase64', 'base64 bridge');
$caller = documentedBlock($page, 'addValidations', 'validate-and-store');
$fileInfo = documentedBlock($readme, 'class TmpUploadFile', 'isUploadedFile() override');
$cleanup = documentedBlock($readme, 'foreach ($list as $file)', 'tmp-file cleanup');

eval($bridge . "\n" . str_replace("'/var/lib/myapp/uploads-tmp'", var_export($tmpDirectory, true), $fileInfo));

$empty = static function (string $directory): void {
    foreach ((array) glob($directory . '/*') as $entry) {
        @unlink((string) $entry);
    }
};

foreach ([$tmpDirectory, $destination] as $directory) {
    $empty($directory);
    @mkdir($directory, 0755, true);
}

$check = static function (string $what, $expected, $actual) use (&$failures): void {
    if ($expected !== $actual) {
        $failures[] = sprintf(
            "%s\n  expected %s\n  actual   %s",
            $what,
            json_encode($expected),
            json_encode($actual)
        );
    }
};

$png = (string) file_get_contents($root . '/tests/Upload/assets/foo.png');

try {
    /* A data URI, a bare base64 string, a payload whose bytes are not what its name says, and
       two that never become files */
    $payloads = [
        'avatar' => ['filename' => 'avatar.png', 'data' => 'data:image/png;base64,' . base64_encode($png)],
        'banner' => ['filename' => 'banner.png', 'data' => chunk_split(base64_encode($png), 76, "\n")],
        'notes' => ['filename' => 'notes.png', 'data' => base64_encode('plain text, not a png')],
        'broken' => ['filename' => 'broken.png', 'data' => 'data:image/png;base64,not base64 at all!'],
        'missing' => ['filename' => 'missing.png'],
    ];

    eval(str_replace("'/path/to/uploads'", var_export($destination, true), $caller));

    $stored = array_map('basename', $list->getUploadedLocators());
    sort($stored);

    $check('a payload that never became a file is not in the collection', 3, count($list));
    $check('the keys survive as source keys', ['avatar', 'banner', 'notes'], $list->getSourceKeys());
    $check('a data URI and a bare base64 string both store', ['avatar.png', 'banner.png'], $stored);
    /* The two that never became files were recorded by the constructor, so they come first */
    $check('the declared media type is not what FileType matches', [
        'broken.png: The uploaded file was only partially uploaded',
        'missing.png: No file was uploaded',
        'notes.png: File contents do not match the "png" extension. Must be one of: image/png',
    ], array_values($errors));

    eval($cleanup);   /* the tmp-file cleanup the PSR-7 bridge documents */

    $check(
        'no tmp file is left behind once the documented cleanup has run',
        [],
        array_values(array_diff((array) scandir($tmpDirectory), ['.', '..']))
    );

    /* The cap is on the encoded payload, so it is reached before anything is written */
    $capped = fileListFromBase64(
        ['avatar' => ['filename' => 'avatar.png', 'data' => base64_encode($png)]],
        $storage,
        64
    );

    $check('a payload over the cap is rejected before it is decoded', 0, count($capped));
    $check('and reported as an oversized upload', [
        'avatar.png: The uploaded file exceeds the upload_max_filesize directive in php.ini',
    ], array_values($capped->getErrors()));
    $check(
        'nothing was written for it',
        [],
        array_values(array_diff((array) scandir($tmpDirectory), ['.', '..']))
    );

    /* The cap rounds up to four base64 characters, so the encoded check alone admits two bytes
       over. The decoded check is what makes the bound exact. */
    $overBy = fileListFromBase64(
        ['f' => ['filename' => 'a.bin', 'data' => base64_encode(str_repeat('A', 66))]],
        $storage,
        64
    );

    $check('a payload two bytes over the cap is still refused', 0, count($overBy));
    $check('and reported as oversized', [
        'a.bin: The uploaded file exceeds the upload_max_filesize directive in php.ini',
    ], array_values($overBy->getErrors()));

    /* A JSON body carries whatever the client put in it. Casting either field to string
       instead of testing it raises "Array to string conversion" on remote input. */
    $raised = null;
    set_error_handler(static function (int $severity, string $message): bool {
        /* A handler is called even for a diagnostic the @ operator suppressed, so honour it
           here: the bridge silences its own fopen() deliberately. */
        if ((error_reporting() & $severity) === 0) {
            return true;
        }

        throw new RuntimeException($message);
    });

    try {
        $shapes = fileListFromBase64([
            'array-name' => ['filename' => ['a'], 'data' => base64_encode($png)],
            'array-data' => ['filename' => 'a.png', 'data' => ['x']],
            'int-data' => ['filename' => 'a.png', 'data' => 123],
            'scalar-entry' => 'not-an-object',
            'null-entry' => null,
        ], $storage, 10 * 1024 * 1024);
    } catch (\Throwable $e) {
        $raised = get_class($e) . ': ' . $e->getMessage();
    } finally {
        restore_error_handler();
    }

    $check('no shape of JSON body raises', null, $raised);

    if ($raised === null) {
        $check('an array filename does not become one file', 1, count($shapes));
        /* `(string) ['a']` is the string "Array", which is what a cast would have stored */
        $check(
            'nor the literal name "Array"',
            'unnamed-file',
            count($shapes) ? $shapes[0]->getNameWithExtension() : null
        );
        $check('and the rest are reported, not fatal', [
            'a.png: No file was uploaded',
            'a.png: No file was uploaded',
            'unnamed-file: No file was uploaded',
            'unnamed-file: No file was uploaded',
        ], array_values($shapes->getErrors()));

        foreach ($shapes as $file) {
            @unlink($file->getPathname());
        }
    }

    /* A tmp directory that is not there is not an oversized upload, and an unsilenced fopen()
       puts the absolute path in the log on every request. In its own try, so a throw here does
       not mask the checks after it. */
    try {
        rename($tmpDirectory, $tmpDirectory . '-moved');
        set_error_handler(static function (int $severity, string $message): bool {
            /* A handler is called even for a diagnostic the @ operator suppressed, so honour it
               here: the bridge silences its own fopen() deliberately. */
            if ((error_reporting() & $severity) === 0) {
                return true;
            }

            throw new RuntimeException($message);
        });

        try {
            $noDirectory = fileListFromBase64(
                ['a' => ['filename' => 'a.png', 'data' => base64_encode($png)]],
                $storage,
                10 * 1024 * 1024
            );
        } finally {
            restore_error_handler();
            rename($tmpDirectory . '-moved', $tmpDirectory);
        }

        $check(
            'a missing tmp directory is reported as a write failure, silently',
            ['cant_write'],
            array_column($noDirectory->getErrorDetails(), 'code')
        );
    } catch (\Throwable $e) {
        $failures[] = sprintf(
            "a missing tmp directory: the documented bridge threw\n  %s: %s",
            get_class($e),
            $e->getMessage()
        );
    }

    /* A data URI without `;base64` is not base64: decoding it anyway wrote rubbish to disk */
    $notBase64 = fileListFromBase64(
        ['f' => ['filename' => 'a.txt', 'data' => 'data:text/plain,hello world']],
        $storage,
        1024
    );

    $check('a data URI that is not base64 is refused', 0, count($notBase64));
    $check(
        'nothing was written for it either',
        [],
        array_values(array_diff((array) scandir($tmpDirectory), ['.', '..']))
    );
} catch (\Throwable $e) {
    /* Anything thrown here is the point of the check: the documented code no longer runs.
       Reported with its origin rather than as a stack trace, since what a reader needs is which
       example broke and where. */
    $failures[] = sprintf(
        "the documented code threw\n  %s: %s\n  at %s:%d",
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    );
}

foreach ([$tmpDirectory, $destination] as $directory) {
    $empty($directory);
    @rmdir($directory);
}

if ($failures !== []) {
    fwrite(
        STDERR,
        "The base64 bridge no longer behaves as documented:\n\n" . implode("\n\n", $failures) . "\n"
    );

    exit(1);
}

echo "docs/base64-uploads.md verified\n";
