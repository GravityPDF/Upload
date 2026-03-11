<?php

declare(strict_types=1);

namespace GravityPdf\Upload;

use RuntimeException;

class Exception extends RuntimeException
{
    /**
     * @var FileInfoInterface|null
     */
    protected $fileInfo;

    /**
     * Constructor
     *
     * @param string $message The Exception message
     * @param FileInfoInterface|null $fileInfo The related file instance
     */
    public function __construct($message, ?FileInfoInterface $fileInfo = null)
    {
        $this->fileInfo = $fileInfo;

        parent::__construct($message);
    }

    /**
     * Get related file
     *
     * @return FileInfoInterface
     */
    public function getFileInfo(): ?FileInfoInterface
    {
        return $this->fileInfo;
    }
}
