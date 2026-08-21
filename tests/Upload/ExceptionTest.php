<?php

namespace GravityPdf\Upload;

use RuntimeException;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

class ExceptionTest extends TestCase
{
    public function testCarriesTheOffendingFileInfo(): void
    {
        $fileInfo = new FileInfo(__DIR__ . '/assets/foo.txt', 'foo.txt');
        $exception = new Exception('Invalid file extension', $fileInfo);

        $this->assertSame('Invalid file extension', $exception->getMessage());
        $this->assertSame($fileInfo, $exception->getFileInfo());
    }

    public function testFileInfoIsOptional(): void
    {
        $this->assertNull((new Exception('File validation failed'))->getFileInfo());
    }

    public function testPreservesCodeAndExceptionChain(): void
    {
        $previous = new RuntimeException('stat failed');
        $exception = new Exception(
            'File size could not be determined',
            null,
            ErrorCode::SIZE_UNKNOWN,
            [],
            7,
            $previous
        );

        $this->assertSame(7, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testDefaultsToNoErrorCodeAndNoMessageArgs(): void
    {
        $exception = new Exception('File validation failed');

        $this->assertSame(ErrorCode::NONE, $exception->getErrorCode());
        $this->assertSame('File validation failed', $exception->getMessageId());
        $this->assertSame([], $exception->getMessageArgs());
    }

    public function testCarriesTheErrorCodeAndTheMessageParts(): void
    {
        $exception = new Exception(
            'File size is too large. Must be no more than %1$s bytes',
            null,
            ErrorCode::SIZE_TOO_LARGE,
            [500]
        );

        $this->assertSame(ErrorCode::SIZE_TOO_LARGE, $exception->getErrorCode());
        $this->assertSame('File size is too large. Must be no more than %1$s bytes', $exception->getMessageId());
        $this->assertSame([500], $exception->getMessageArgs());
    }

    /**
     * The message is composed once, in English, so `getMessage()` and `__toString()` — which
     * reads the internal property rather than the getter — cannot disagree, and a caller who
     * logs the exception logs something they can search for.
     */
    public function testComposesTheEnglishMessageFromItsParts(): void
    {
        $exception = new Exception(
            'File size is too large. Must be no more than %1$s bytes',
            null,
            ErrorCode::SIZE_TOO_LARGE,
            [500]
        );

        $this->assertSame('File size is too large. Must be no more than 500 bytes', $exception->getMessage());
        $this->assertStringContainsString(
            'File size is too large. Must be no more than 500 bytes',
            (string) $exception
        );
    }

    /**
     * A translator is not consulted here, so an exception reads the same in every locale.
     */
    public function testIsNeverTranslated(): void
    {
        Translation::setTranslator(static function (string $messageId): string {
            return 'TRANSLATED: ' . $messageId;
        });

        try {
            $this->assertSame('File validation failed', (new Exception('File validation failed'))->getMessage());
        } finally {
            Translation::resetTranslator();
        }
    }

    /**
     * Consumers on v1/v2 catch \RuntimeException, so the parent must not change.
     */
    public function testRemainsARuntimeException(): void
    {
        $this->assertInstanceOf(RuntimeException::class, new Exception('File already exists'));
    }
}
