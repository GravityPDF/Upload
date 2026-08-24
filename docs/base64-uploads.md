# Uploads from a base64 payload

A JSON request body carrying files as base64 never reaches `$_FILES`, so `File` has nothing to
read. Decode each entry to a temporary file and hand those to
[`FileList`](../README.md#uploads-from-another-source), which takes the same validations,
callbacks and storage as the `$_FILES` path.

One entry per file:

```json
{"avatar": {"filename": "avatar.png", "data": "data:image/png;base64,…"}}
```

The `TmpUploadFile` used below is the `isUploadedFile()` override from
[Two decisions this path needs from you](../README.md#two-decisions-this-path-needs-from-you).
Storage needs `acceptFilesNotUploadedByPhp()`.

## The bridge

```php
use GravityPdf\Upload\FileList;
use GravityPdf\Upload\StorageInterface;

/**
 * @param array<int|string, mixed> $payloads The decoded request body, one entry per file.
 *        Shapes are not assumed: this is a JSON body a client sent.
 */
function fileListFromBase64(array $payloads, StorageInterface $storage, int $maxBytes): FileList
{
    $fileInfos = [];
    $failures = [];

    foreach ($payloads as $field => $payload) {
        /* Tested rather than cast. `{"filename": ["a"]}` is valid JSON, and casting an array
           to string raises "Array to string conversion" instead of rejecting the payload. */
        $clientFilename = isset($payload['filename']) && is_string($payload['filename'])
            ? $payload['filename']
            : '';
        $encoded = isset($payload['data']) && is_string($payload['data']) ? $payload['data'] : '';

        $header = strpos($encoded, ',');
        $isBase64Uri = $header !== false
            && preg_match('#^data:[^,]*;base64$#i', substr($encoded, 0, $header)) === 1;

        /* Drop a data URI's header. Nothing reads the media type in it, and `;base64` has to be
           there: `data:text/plain,hello` is not base64 and would decode to rubbish. */
        if ($isBase64Uri) {
            $encoded = (string) substr($encoded, $header + 1);
        }

        if ($encoded === '') {
            $failures[] = [$clientFilename, UPLOAD_ERR_NO_FILE];
            continue;
        }

        /* Cap the encoded length first, so an oversized payload is never decoded into a second
           copy in memory. Base64 carries 3 bytes in 4. */
        if (strlen($encoded) > (int) ceil($maxBytes / 3) * 4) {
            $failures[] = [$clientFilename, UPLOAD_ERR_INI_SIZE];
            continue;
        }

        $bytes = base64_decode($encoded, true);   // strict: without it, stray bytes are skipped

        /* Not NO_FILE: something arrived, and it could not be read */
        if ($bytes === false || $bytes === '') {
            $failures[] = [$clientFilename, UPLOAD_ERR_PARTIAL];
            continue;
        }

        /* Exact, now the length is known: the check above rounds up to four characters */
        if (strlen($bytes) > $maxBytes) {
            $failures[] = [$clientFilename, UPLOAD_ERR_INI_SIZE];
            continue;
        }

        try {
            $path = TmpUploadFile::DIRECTORY . '/' . bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            /* random_bytes() throws where the platform has no source of randomness */
            $failures[] = [$clientFilename, UPLOAD_ERR_CANT_WRITE];
            continue;
        }

        /* Exclusive, so an existing path fails. Silenced: the warning carries the absolute
           tmp path into the log on every request. */
        $out = @fopen($path, 'xb');

        if ($out === false || fwrite($out, $bytes) !== strlen($bytes)) {
            if ($out !== false) {
                fclose($out);
                unlink($path);
            }

            $failures[] = [$clientFilename, UPLOAD_ERR_CANT_WRITE];
            continue;
        }

        fclose($out);

        $fileInfos[$field] = new TmpUploadFile($path, $clientFilename);   // getSourceKeys()
    }

    return new FileList($fileInfos, $storage, $failures);
}
```

## Validate and store

```php
use GravityPdf\Upload\Storage\FileSystem;
use GravityPdf\Upload\Validation\FileType;
use GravityPdf\Upload\Validation\Size;

$storage = (new FileSystem('/path/to/uploads'))->acceptFilesNotUploadedByPhp();
$list = fileListFromBase64($payloads, $storage, 10 * 1024 * 1024);

$list->addValidations([
    (new FileType('png', 'image/png'))->allow('jpg', 'image/jpeg'),
    new Size(10 * 1024 * 1024),
]);

/* Every payload can fail to decode, and upload() throws on an empty collection */
if (count($list) > 0) {
    $list->uploadValid();
}

$errors = $list->getErrors();
```

`$maxBytes` bounds the payload in the bridge and the decoded file in `Size`.

## Cap the payload, not only the file

`Validation\Size` runs against a file on disk, so without the check in the bridge every payload
is decoded and written before anything rejects it. `post_max_size` bounds the whole request body
rather than any one file in it, so it is not this cap either.

There are two checks. The first is on `strlen($encoded)` and runs before the decode, so an
oversized payload is never expanded into a second copy in memory; four base64 characters carry
three bytes, so it rounds up and admits up to two bytes more than `$maxBytes`. The second is on
the decoded length, and is exact.

Only the first counts the line breaks a wrapped payload carries. Strip the whitespace before
calling the bridge if a payload near the limit has to be accepted.

Both bound one payload, not the batch. Ten entries each just under `$maxBytes` put ten times
that in the tmp directory before a single validation runs, so bound the count as well —
`array_slice($payloads, 0, 20, true)` before the call, or reject the request outright. There is
no `UPLOAD_ERR_*` that means "too many files", which is why the bridge does not report it for
you.

## The client controls both fields

Neither field is necessarily a string — `{"filename": ["a"]}` is valid JSON — so the bridge
tests both with `is_string()` rather than casting, which would raise on an array rather than
reject the payload.

`data:image/png;base64,` is whatever the client typed. `FileInfo::getMimetype()` sniffs the
decoded bytes instead, and `FileType` matches that against the media types registered for the
extension the name carries, so a `.png` name over a PHP payload fails.

The entry's key is client input too. It is kept as the collection's
[`getSourceKeys()`](../README.md#uploads-from-another-source), so escape it wherever you render
it — the library sanitizes a key only when it names one in an exception message.

`new TmpUploadFile($path, $clientFilename)` puts the name through `setNameWithExtension()`, so it
gets the sanitizing rules and the extension validation a `multipart/form-data` name gets. A
payload with no name becomes `unnamed-file` with no extension at all, which `FileType` rejects.
Name it yourself, or derive the extension from the declared media type, which the sniff then
has to agree with.

## Clean up

Storing a file moves it. The temporary files behind rejected payloads stay where the bridge
wrote them, and the [cleanup loop](../README.md#cleaning-up-the-temporary-files) removes them.
