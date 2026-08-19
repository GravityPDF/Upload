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
        $exception = new Exception('File size could not be determined', null, 7, $previous);

        $this->assertSame(7, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
    }

    /**
     * Consumers on v1/v2 catch \RuntimeException, so the parent must not change.
     */
    public function testRemainsARuntimeException(): void
    {
        $this->assertInstanceOf(RuntimeException::class, new Exception('File already exists'));
    }
}
