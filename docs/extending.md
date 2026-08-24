# Extending the library

Two interfaces are the seams: `ValidationInterface` decides whether a file is acceptable, and
`StorageInterface` decides where it lands. Neither needs a subclass of anything this library
ships.

Overriding `File::isValid()` is not a third seam: it, `upload()` and `uploadValid()` are
`final`, since `upload()` validates through a private method that an override never reaches.
A check of your own goes in a `ValidationInterface`, which all three run.

## Custom validation rules

Implement `ValidationInterface` and throw `GravityPdf\Upload\Exception` to reject a file.
The exception message is what `getErrors()` shows the end user:

```php
use GravityPdf\Upload\Exception;
use GravityPdf\Upload\FileInfoInterface;
use GravityPdf\Upload\Translation;
use GravityPdf\Upload\ValidationInterface;

class MaxDimensions implements ValidationInterface
{
    private $maxWidth;
    private $maxHeight;

    public function __construct(int $maxWidth, int $maxHeight)
    {
        $this->maxWidth = $maxWidth;
        $this->maxHeight = $maxHeight;
    }

    public function validate(FileInfoInterface $fileInfo): void
    {
        $size = $fileInfo->getDimensions();

        if ($size['width'] > $this->maxWidth || $size['height'] > $this->maxHeight) {
            throw new Exception(
                Translation::__('Image must be no larger than %1$sx%2$s pixels', 'my-plugin'),
                $fileInfo,
                'max_dimensions',
                [$this->maxWidth, $this->maxHeight]
            );
        }
    }
}

$file->addValidation(new MaxDimensions(2048, 2048));
```

Failures accumulate rather than abort: every validation runs against every file, and
`getErrors()` reports them all at once.

The message, its values and the code are kept apart rather than assembled here: the message
goes through the [translator](translation/README.md) if one is installed, the values are
interpolated after the lookup, so a `%` in one is never read as a conversion, and the code you
chose shows up in `getErrorDetails()` for a caller branching on it. Pass a finished string with
no values if you would rather; `getErrorDetails()` then reports the code
`ErrorCode::VALIDATION_REJECTED` for it, so every entry has one.

`Translation::__()` marks the string for an extractor and hands it straight back. It is
**not** WordPress's `__()` and it never translates. Using it is optional — leave the literal
bare, or use your own marker if your rule's wording lives in your own catalogue. Its second
argument names a catalogue for whatever reads these calls; the marker discards it, and this
library looks the message up under `Translation::DOMAIN` regardless.

`xgettext -k__:1` extracts it exactly as it extracts a bare `__()`: the keyword matches the
trailing identifier and ignores the class prefix, so an aliased `T::__()` and a fully
qualified `\GravityPdf\Upload\Translation::__()` are read the same way.

Your message goes through `Filename::sanitizeForDisplay()` first: bidi controls are deleted,
runs of control characters collapse to a single space, the line is cut to
`Filename::MAX_DISPLAY_LENGTH`, the result is forced to valid UTF-8 where `ext-mbstring` is
loaded, and surrounding whitespace is trimmed. A message built from user input cannot forge a
log line, move a terminal cursor, or make `json_encode($file->getErrors())` return `false`. It
is sanitized, not escaped, so escape it where it lands.

Throwing anything other than `GravityPdf\Upload\Exception` is caught too, but nothing it
carries reaches `getErrors()`: not its message, which can leak server paths, and not its
class name. Catch it in the validator and rethrow an `Upload\Exception` if either belongs in
what the user sees.

`\LogicException` is the exception to that: it propagates out of `isValid()`, since PHP
defines the type as a bug in your program rather than a file that failed. A validator
calling `getHash()` with a misspelled algorithm reaches you, not the end user.

## Custom storage backends

Implement `StorageInterface` to store files somewhere other than the local filesystem:
read from `$fileInfo->getPathname()`, return the destination, and throw
`GravityPdf\Upload\Exception` on failure.

The string you return is a locator you define (a key, a URL, an identifier) and
`File::getUploadedLocators()` hands it back unchanged, so it is what the application rolls
back with. Never return `''`: a caller cannot tell it from a usable value.

A backend of your own has no `move_uploaded_file()` in it, so nothing stops it storing a file
PHP never received. `FileInfo::isUploadedFile()` is where that is decided: validation refuses a
file that answers `false` whichever storage is configured. Leave the check there rather than
reproducing it here.

```php
use GravityPdf\Upload\FileInfoInterface;
use GravityPdf\Upload\StorageInterface;

class ObjectStorage implements StorageInterface
{
    public function upload(FileInfoInterface $fileInfo): string
    {
        $key = 'uploads/' . $fileInfo->getNameWithExtension();

        // ... stream $fileInfo->getPathname() to your object store ...

        return $key;
    }
}
```

The protections under "Security notes" (the deny-list, the `basename()` reduction, the
symlink refusal, the staged write) live in `Storage\FileSystem`. A custom backend needs its own
equivalents, and does not have to restate the rules to get them: [`Filename`](api-reference.md#filename) is
public for this, with `hasControlCharacters()`, `hasBidiControls()`, `exceedsMaxLength()`,
`deviceComponent()`, `isReservedDeviceComponent()` and `extensionComponents()` answering
exactly what the shipped storage asks before it writes.