# Uploads from a PSR-7 request

A PSR-7 server request carries its files as `UploadedFileInterface` objects rather than in
`$_FILES`, so `File` has nothing to read. Write each one to a temporary file and hand those to
[`FileList`](../README.md#uploads-from-another-source), which takes the same validations,
callbacks and storage as the `$_FILES` path.

This library adds no PSR-7 interface, type hint or package; the bridge below is caller code.

The `TmpUploadFile` used below is the `isUploadedFile()` override from
[Two decisions this path needs from you](../README.md#two-decisions-this-path-needs-from-you).
Storage needs `acceptFilesNotUploadedByPhp()`.

## The bridge

```php
use GravityPdf\Upload\FileList;
use GravityPdf\Upload\StorageInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * @param array<int|string, mixed> $uploadedFiles Flat; see "Flatten the tree first" below.
 *        Shapes are not assumed: the field names a client sends decide the nesting.
 */
function fileListFrom(array $uploadedFiles, StorageInterface $storage, int $maxBytes): FileList
{
    $fileInfos = [];
    $failures = [];

    foreach ($uploadedFiles as $field => $uploadedFile) {
        /* A client chooses the field names, so `photos[0][0]` nests one level deeper than a
           caller passing one field's array expects, leaving an array here. */
        if (!$uploadedFile instanceof UploadedFileInterface) {
            $failures[] = ['', UPLOAD_ERR_NO_FILE];
            continue;
        }

        $clientFilename = (string) $uploadedFile->getClientFilename();

        if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
            $failures[] = [$clientFilename, $uploadedFile->getError()];
            continue;
        }

        try {
            $path = TmpUploadFile::DIRECTORY . '/' . bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            /* random_bytes() throws where the platform has no source of randomness */
            $failures[] = [$clientFilename, UPLOAD_ERR_CANT_WRITE];
            continue;
        }

        $error = writeTmpFile($uploadedFile->getStream(), $path, $maxBytes);

        if ($error !== UPLOAD_ERR_OK) {
            $failures[] = [$clientFilename, $error];
            continue;
        }

        $fileInfos[$field] = new TmpUploadFile($path, $clientFilename);   // getSourceKeys()
    }

    return new FileList($fileInfos, $storage, $failures);
}

/**
 * A PSR-7 body may be in memory with no path at all, so write it to a tmp file. Cap the bytes
 * as you go: Validation\Size only bounds a file that already exists.
 *
 * @return int UPLOAD_ERR_OK, or the code describing what stopped it. Returning a bool would
 *         report a missing tmp directory to the submitter as a file that was too large.
 */
function writeTmpFile(StreamInterface $stream, string $path, int $maxBytes): int
{
    /* Silenced: the warning carries the absolute tmp path into the log on every request */
    $out = @fopen($path, 'xb');

    if ($out === false) {
        return UPLOAD_ERR_CANT_WRITE;
    }

    $error = UPLOAD_ERR_OK;

    for ($written = 0, $stalled = 0; $error === UPLOAD_ERR_OK && !$stream->eof(); ) {
        $chunk = $stream->read(8192);

        if ($chunk === '') {
            /* read() need not return anything and eof() need not ever become true, so a
               stream that does neither spins here forever. One empty read is how some
               wrappers reach eof; two in a row is a stream that will not finish. */
            $error = ++$stalled > 1 ? UPLOAD_ERR_PARTIAL : UPLOAD_ERR_OK;
            continue;
        }

        $stalled = 0;
        $written += strlen($chunk);

        if ($written > $maxBytes) {
            $error = UPLOAD_ERR_INI_SIZE;
        } elseif (fwrite($out, $chunk) !== strlen($chunk)) {
            /* Not `=== false`: a full disk writes fewer bytes than asked and truncates the
               file rather than failing outright. */
            $error = UPLOAD_ERR_CANT_WRITE;
        }
    }

    fclose($out);

    if ($error !== UPLOAD_ERR_OK) {
        unlink($path);
    }

    return $error;
}
```

## Flatten the tree first

`getUploadedFiles()` returns a tree, since `docs[front]` nests. The collection is flat:

```php
$flat = new RecursiveIteratorIterator(
    new RecursiveArrayIterator($request->getUploadedFiles(), RecursiveArrayIterator::CHILD_ARRAYS_ONLY)
);

$list = fileListFrom(iterator_to_array($flat, false), $storage, 10 * 1024 * 1024);
```

Neither argument is optional. Without `CHILD_ARRAYS_ONLY` the iterator descends into the
`UploadedFileInterface` objects and yields their properties, which for nyholm/psr7 and
guzzlehttp/psr7 is nothing at all, since those properties are private. Without the `false`,
keys are leaf names only, so `docs[front]` and `scans[front]` collide and one file disappears.
Build composite keys such as `docs.front` yourself if you want field names back from
`getSourceKeys()`.

## Cap the stream, not only the file

`writeTmpFile()` counts the bytes as it writes them and unlinks the partial file when it goes
over, so `$maxBytes` bounds the stored file exactly. Nothing is buffered whole: the stream is
read in 8 KB chunks.

`$maxBytes` bounds one file, not the batch. Ten fields each just under it put ten times that in
the tmp directory before a single validation runs, so bound the count as well —
`array_slice($flat, 0, 20, true)` before the call, or reject the request outright. There is no
`UPLOAD_ERR_*` that means "too many files", which is why the bridge does not report it for
you.

## Do not also call `moveTo()`

PSR-7 requires `moveTo()` to remove the original on completion and to raise on a second call,
so calling it leaves the bridge nothing to hand over. `FileList` does the storing here.

## Clean up

Storing a file moves it. The temporary files behind rejected uploads stay where the bridge wrote
them, and the [cleanup loop](../README.md#cleaning-up-the-temporary-files) removes them.
