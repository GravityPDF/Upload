# Turning the defaults off

`Storage\FileSystem` and `File` apply five protections without being asked. Each has its own
opt-out, listed here so that disabling one is a decision with a name rather than a
configuration value that happened to be missing.


> [!WARNING]
> Every call on this page removes a control that is on because an upload is attacker-supplied
> input. None of them is needed to accept uploads: the defaults are a working configuration, and
> every example in the [README](../README.md) runs under them. Each row below says what one
> stops applying.

Each is a separate call, so turning one off leaves the others in place:

```php
$storage = new \GravityPdf\Upload\Storage\FileSystem('/path/to/directory', true);
$storage->allowAnyExtension();
$storage->setMode(null);
$storage->acceptFilesNotUploadedByPhp();

$file->allowUnvalidatedUploads();
```

| Call | What stops applying |
|---|---|
| `new FileSystem($dir, true)` | The destination is no longer claimed before the write, so a file already at that name is replaced instead of the upload being refused, and two concurrent requests for one name no longer conflict. The staged write and the symlink refusal still apply. |
| `allowAnyExtension()` | The deny-list empties, so `.php`, `.htaccess` and `.svg` can all be written. Whether that becomes code execution or stored XSS depends on how your server treats that directory — which is why the README's [security notes](../README.md#security-notes) say not to serve it. |
| `setMode(null)` | The `0640` mode gives way to the process umask, which at the usual `022` stores every upload world-readable. |
| `acceptFilesNotUploadedByPhp()` | `move_uploaded_file()`'s refusal of any source PHP did not receive as an upload. A path an attacker steered is then stored like any other. |
| `allowUnvalidatedUploads()` | `upload()` and `uploadValid()` stop requiring a validation, so whatever is submitted is stored, subject only to the storage rules still in force. |

`allowAnyExtension()` is the only way to empty the deny-list. `blockExtensions()` requires a
non-empty list and throws otherwise, so a missing config value cannot silently disable the
check.

`acceptFilesNotUploadedByPhp()` has no use in a `$_FILES` application. It exists for
[uploads from another source](../README.md#uploads-from-another-source), and belongs with an
`isUploadedFile()` override that asserts where the file came from. Overriding it to
`return true;` gives the check up rather than replacing it, which leaves nothing at either end.