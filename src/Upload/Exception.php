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

    public function __construct(
        string $message,
        ?FileInfoInterface $fileInfo = null,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        $this->fileInfo = $fileInfo;

        parent::__construct($message, $code, $previous);
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
}
