<?php

declare(strict_types=1);

namespace GravityPdf\Upload;

use RuntimeException;
use Throwable;

/**
 * @internal Extends RuntimeException for backwards compatibility with v1/v2. A consumer
 * catching `\RuntimeException` broadly cannot tell a validation failure from a PHP-level
 * runtime error. Catch `\GravityPdf\Upload\Exception` to distinguish them.
 */
class Exception extends RuntimeException
{
    /**
     * @var FileInfoInterface|null
     */
    protected $fileInfo;

    /**
     * @var string
     */
    protected $errorCode;

    /**
     * @var string
     */
    protected $messageId;

    /**
     * @var array<int,string|int|float>
     * @phpstan-var list<string|int|float>
     */
    protected $messageArgs;

    /**
     * @param string $message The English message, or a message id with `%1$s` placeholders
     *                        for `$messageArgs` to fill
     * @param FileInfoInterface|null $fileInfo The file this is about, where it is about one
     * @param string $errorCode An `ErrorCode` constant, for a caller branching on the reason
     * @param array<array-key,string|int|float> $messageArgs Values for `$message`'s placeholders
     * @param int $code
     * @param Throwable|null $previous
     */
    public function __construct(
        string $message,
        ?FileInfoInterface $fileInfo = null,
        string $errorCode = ErrorCode::NONE,
        array $messageArgs = [],
        int $code = 0,
        ?Throwable $previous = null
    ) {
        $this->fileInfo = $fileInfo;
        $this->errorCode = $errorCode;
        $this->messageId = $message;
        $this->messageArgs = array_values($messageArgs);

        /* English, always. `getMessage()` is `final` and `__toString()` reads the internal
           property rather than calling it, so translating behind the getter would give a
           `catch` block one string and an uncaught-exception handler another. An exception is
           the half of this library written for a log; `getErrors()` is the half written to be
           shown. To display this one, re-render it from `getMessageId()`/`getMessageArgs()`. */
        parent::__construct(Translation::interpolate($message, $this->messageArgs), $code, $previous);
    }

    /**
     * Get related file
     *
     * @return FileInfoInterface|null Null when the failure is not about one particular file
     */
    public function getFileInfo(): ?FileInfoInterface
    {
        return $this->fileInfo;
    }

    /**
     * The `ErrorCode` constant naming why this was thrown
     *
     * Stable across releases in a way the message is not: branch on this rather than on
     * `getMessage()`, which is translated for the end user and reworded between versions.
     *
     * @return string An `ErrorCode` constant, or `ErrorCode::NONE` for a throw of your own
     *                that named none
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * The untranslated message, with its placeholders still in it
     *
     * The gettext msgid, so a caller with a catalogue of their own renders this rather than
     * the English `getMessage()` returns.
     */
    public function getMessageId(): string
    {
        return $this->messageId;
    }

    /**
     * The values `getMessageId()`'s placeholders take
     *
     * @return array<int,string|int|float>
     * @phpstan-return list<string|int|float>
     */
    public function getMessageArgs(): array
    {
        return $this->messageArgs;
    }
}
