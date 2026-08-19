<?php

/**
 * Runs the PSR-7 bridge from README.md against real PSR-7 implementations
 *
 * The README tells a caller to write this code, so it has to keep working: every snippet below
 * is read out of README.md at run time rather than copied here. A renamed class, a dropped
 * example or a changed signature fails this before it reaches anyone reading the docs. Kept
 * under tools/ with its own manifest, since the library itself takes no PSR-7 dependency.
 *
 * Exits non-zero, and says which expectation broke, on any mismatch.
 */

/* phpcs:disable PSR1.Files.SideEffects -- a script, not a unit: it declares two helpers and
   then runs, which is the whole shape of the file */

require __DIR__ . '/vendor/autoload.php';

$readme = (string) file_get_contents(__DIR__ . '/../../README.md');

preg_match_all('/```php\n(.*?)```/s', $readme, $matches);

$blocks = $matches[1];
$failures = [];
$tmpDirectory = __DIR__ . '/uploads-tmp';

/** The README snippet containing $needle, or a fatal error naming what went missing */
function readmeBlock(array $blocks, string $needle, string $describes): string
{
    foreach ($blocks as $block) {
        if (strpos($block, $needle) !== false) {
            return $block;
        }
    }

    fwrite(STDERR, sprintf(
        "README.md no longer contains the %s example (looked for \"%s\").\n"
        . "The PSR-7 bridge is documented code: update this check alongside it.\n",
        $describes,
        $needle
    ));

    exit(1);
}

/* Needles are the distinctive line of each block, not a word that could appear in another:
   `unlink` alone matches the rollback example in the multi-file section */
$bridge = readmeBlock($blocks, 'function fileListFrom', 'PSR-7 bridge');
$fileInfo = readmeBlock($blocks, 'class TmpUploadFile', 'isUploadedFile() override');
$flatten = readmeBlock($blocks, 'RecursiveArrayIterator', 'flattening');
$cleanup = readmeBlock($blocks, 'foreach ($list as $file)', 'tmp-file cleanup');

eval($bridge . "\n" . str_replace("'/var/lib/myapp/uploads-tmp'", var_export($tmpDirectory, true), $fileInfo));

$empty = static function (string $directory): void {
    foreach ((array) glob($directory . '/*') as $entry) {
        @unlink((string) $entry);
    }
};

$png = (string) file_get_contents(__DIR__ . '/../../tests/Upload/assets/foo.png');

/* PSR-17 rather than each package's own classes, so a third implementation is one more row
   rather than another branch */
$factories = [
    'nyholm' => new Nyholm\Psr7\Factory\Psr17Factory(),
    'guzzle' => new GuzzleHttp\Psr7\HttpFactory(),
];

foreach ($factories as $implementation => $factory) {
    try {
        $request = $factory->createServerRequest('POST', 'https://example.test/upload');

        $upload = static function (string $body, int $error, string $name) use ($factory) {
            return $factory->createUploadedFile(
                $factory->createStream($body),
                strlen($body),
                $error,
                $name,
                'image/png'
            );
        };

    /* A tree, because getUploadedFiles() returns one: a flat field, a nested branch, a leaf
       name that collides with another branch's, a transfer that failed, and a file whose
       contents do not match its extension */
        $request = $request->withUploadedFiles([
        'avatar' => $upload($png, UPLOAD_ERR_OK, 'avatar.png'),
        'docs' => [
            'front' => $upload($png, UPLOAD_ERR_OK, 'front.png'),
            'back' => $upload($png, UPLOAD_ERR_OK, 'back.png'),
        ],
        'scans' => ['front' => $upload($png, UPLOAD_ERR_OK, 'scan-front.png')],
        'huge' => $upload('', UPLOAD_ERR_INI_SIZE, 'huge.png'),
        'notes' => $upload('plain text, not a png', UPLOAD_ERR_OK, 'notes.png'),
        ]);

        $destination = __DIR__ . '/stored-' . $implementation;

        foreach ([$tmpDirectory, $destination] as $directory) {
            $empty($directory);
            @mkdir($directory, 0755, true);
        }

        $storage = (new GravityPdf\Upload\Storage\FileSystem($destination))->acceptFilesNotUploadedByPhp();

        eval($flatten);   /* $flat, then $list = fileListFrom(...) */

        $list->addValidations([
        new GravityPdf\Upload\Validation\FileType('png', 'image/png'),
        new GravityPdf\Upload\Validation\Size('5M'),
        ]);

        $returned = $list->uploadValid();

        $stored = array_map('basename', $list->getUploadedLocators());
        sort($stored);

        $check = static function (string $what, $expected, $actual) use (&$failures, $implementation): void {
            if ($expected !== $actual) {
                $failures[] = sprintf(
                    "%s: %s\n  expected %s\n  actual   %s",
                    $implementation,
                    $what,
                    json_encode($expected),
                    json_encode($actual)
                );
            }
        };

        $check('the tree was flattened into one file per upload', 5, count($list));
        $check('a rejected file makes uploadValid() false', false, $returned);
        $check('every valid file was stored, nested ones included', [
        'avatar.png',
        'back.png',
        'front.png',
        'scan-front.png',
        ], $stored);
        $check('a failed transfer and a content mismatch are both reported', [
        'huge.png: The uploaded file exceeds the upload_max_filesize directive in php.ini',
        'notes.png: File contents do not match the "png" extension. Must be one of: image/png',
        ], array_values($list->getErrors()));

        eval($cleanup);   /* the documented tmp-file cleanup */

        $check(
            'no tmp file is left behind once the documented cleanup has run',
            [],
            array_values(array_diff((array) scandir($tmpDirectory), ['.', '..']))
        );

        foreach ([$tmpDirectory, $destination] as $directory) {
            $empty($directory);
            @rmdir($directory);
        }
    } catch (\Throwable $e) {
      /* Anything thrown here is the point of the check: the documented code no longer runs.
         Reported with its origin rather than as a stack trace, since what a reader needs is
         which example broke and where. */
        $failures[] = sprintf(
            "%s: the documented code threw\n  %s: %s\n  at %s:%d",
            $implementation,
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        );
    }
}

if ($failures !== []) {
    fwrite(
        STDERR,
        "The README's PSR-7 bridge no longer behaves as documented:\n\n" . implode("\n\n", $failures) . "\n"
    );

    exit(1);
}

echo "README PSR-7 bridge verified against nyholm/psr7 and guzzlehttp/psr7\n";
