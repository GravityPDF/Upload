<?php

/**
 * Runs the PSR-7 bridge from docs/psr7.md against real PSR-7 implementations
 *
 * The page tells a caller to write this code, so it has to keep working: every snippet below is
 * read out of the Markdown at run time rather than copied here. A renamed class, a dropped
 * example or a changed signature fails this before it reaches anyone reading the docs. Kept
 * under tools/ with its own manifest, since the library itself takes no PSR-7 dependency.
 *
 * The `TmpUploadFile` override and the tmp-file cleanup are read out of README.md, where the
 * shared `FileList` path declares them once for both bridge pages.
 *
 * Exits non-zero, and says which expectation broke, on any mismatch.
 */

/* phpcs:disable PSR1.Files.SideEffects -- a script, not a unit: it declares two helpers and
   then runs, which is the whole shape of the file */

require __DIR__ . '/vendor/autoload.php';

$root = __DIR__ . '/../..';
$failures = [];
$tmpDirectory = __DIR__ . '/uploads-tmp';

/** Every fenced PHP snippet in one Markdown file, in the order it appears */
function documentedBlocks(string $file): array
{
    preg_match_all('/```php\n(.*?)```/s', (string) file_get_contents($file), $matches);

    return $matches[1];
}

/** The snippet in $file containing $needle, or a fatal error naming what went missing */
function documentedBlock(string $file, string $needle, string $describes): string
{
    foreach (documentedBlocks($file) as $block) {
        if (strpos($block, $needle) !== false) {
            return $block;
        }
    }

    fwrite(STDERR, sprintf(
        "%s no longer contains the %s example (looked for \"%s\").\n"
        . "The PSR-7 bridge is documented code: update this check alongside it.\n",
        basename($file),
        $describes,
        $needle
    ));

    exit(1);
}

$page = $root . '/docs/psr7.md';
$readme = $root . '/README.md';

/* Needles are the distinctive line of each block, not a word that could appear in another:
   `unlink` alone matches the rollback example in the README's multi-file section */
$bridge = documentedBlock($page, 'function fileListFrom', 'PSR-7 bridge');
$flatten = documentedBlock($page, 'RecursiveArrayIterator', 'flattening');
$fileInfo = documentedBlock($readme, 'class TmpUploadFile', 'isUploadedFile() override');
$cleanup = documentedBlock($readme, 'foreach ($list as $file)', 'tmp-file cleanup');

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

/* A conforming stream that never makes progress and never reports eof. psr/http-message is
   pinned to 2.0, so these signatures are the same on every PHP this runs under. Anonymous,
   because a named class here would need a namespace this script does not have. */
$stalling = new class implements Psr\Http\Message\StreamInterface {
    /** @var int */
    public $reads = 0;

    public function read(int $length): string
    {
        $this->reads++;

        /* A bridge that guards this gives up after two empty reads. Throwing rather than
           spinning means a regression fails the check instead of hanging the job. */
        if ($this->reads > 100) {
            throw new RuntimeException('writeTmpFile() kept reading a stream that never ends');
        }

        return '';
    }

    public function eof(): bool
    {
        return false;
    }

    public function __toString(): string
    {
        return '';
    }

    public function close(): void
    {
    }

    public function detach()
    {
        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        return 0;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
    }

    public function rewind(): void
    {
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        return 0;
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function getContents(): string
    {
        return '';
    }

    public function getMetadata(?string $key = null)
    {
        return null;
    }
};

/* Each of these fails against a bridge that dereferences whatever the array holds, reports
   every failure as INI_SIZE, or loops until read() makes progress. One scenario per try, so a
   throw in the first does not mask the rest. */
$png = (string) file_get_contents(__DIR__ . '/../../tests/Upload/assets/foo.png');
$factory = new Nyholm\Psr7\Factory\Psr17Factory();

/* The loop above removed both directories on its way out */
@mkdir($tmpDirectory, 0755, true);

$storage = new GravityPdf\Upload\Storage\FileSystem($tmpDirectory);
$uploaded = $factory->createUploadedFile(
    $factory->createStream($png),
    strlen($png),
    UPLOAD_ERR_OK,
    'a.png',
    'image/png'
);

$scenario = static function (string $what, callable $run) use (&$failures): void {
    try {
        $run();
    } catch (\Throwable $e) {
        $failures[] = sprintf(
            "%s: the documented bridge threw\n  %s: %s\n  at %s:%d",
            $what,
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        );
    }
};

$scenario('a nested field', static function () use ($check, $storage, $uploaded): void {
    /* getUploadedFiles() nests as deeply as the client's field names do */
    $nested = fileListFrom(['n' => ['deeper' => $uploaded]], $storage, 1 << 20);

    $check('a nested field is reported rather than fatal', 0, count($nested));
    $check(
        'and named as unreadable',
        ['unnamed-file: No file was uploaded'],
        array_values($nested->getErrors())
    );
});

$scenario('a missing tmp directory', static function () use ($check, $storage, $uploaded, $tmpDirectory): void {
    /* A tmp directory that is not there has nothing to do with how large the file is, and
       an unsilenced fopen() puts the absolute path in the log on every request */
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
        $noDirectory = fileListFrom(['a' => $uploaded], $storage, 1 << 20);
    } finally {
        restore_error_handler();
        rename($tmpDirectory . '-moved', $tmpDirectory);
    }

    $check(
        'a server-side failure is not reported as an oversized upload',
        ['cant_write'],
        array_column($noDirectory->getErrorDetails(), 'code')
    );
});

$scenario('a stalled stream', static function () use ($check, $tmpDirectory, $stalling): void {
    $stalledPath = $tmpDirectory . '/stalled';

    $check(
        'a stream that never finishes gives up',
        UPLOAD_ERR_PARTIAL,
        writeTmpFile($stalling, $stalledPath, 1 << 20)
    );
    $check('after two reads, not indefinitely', 2, $stalling->reads);
    $check('and leaves nothing behind', false, file_exists($stalledPath));
});

$empty($tmpDirectory);
@rmdir($tmpDirectory);

if ($failures !== []) {
    fwrite(
        STDERR,
        "The PSR-7 bridge no longer behaves as documented:\n\n" . implode("\n\n", $failures) . "\n"
    );

    exit(1);
}

echo "docs/psr7.md verified against nyholm/psr7 and guzzlehttp/psr7\n";
