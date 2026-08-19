<?php

namespace GravityPdf\Upload\Validation;

use GravityPdf\Upload\Exception;
use GravityPdf\Upload\FileInfo;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

class SizeTest extends TestCase
{
    /**
     * @var string
     */
    private $assetsDirectory;

    /* phpcs:ignore */
    public function set_up()
    {
        parent::set_up();

        $this->assetsDirectory = dirname(__DIR__) . '/assets';
    }

    public function testValidFileSize(): void
    {
        $file = new FileInfo($this->assetsDirectory . '/foo.txt', 'foo.txt');
        $validation = new Size(500);

        try {
            $validation->validate($file);
            $this->assertTrue(true);
        } catch (Exception $e) {
            $this->fail('Unexpected exception thrown');
        }
    }

    public function testValidFileSizeWithHumanReadableArgument(): void
    {
        $file = new FileInfo($this->assetsDirectory . '/foo.txt', 'foo.txt');
        $validation = new Size('500B');

        try {
            $validation->validate($file);
            $this->assertTrue(true);
        } catch (Exception $e) {
            $this->fail('Unexpected exception thrown');
        }
    }

    public function testInvalidFileSize(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('File size is too large. Must be less than or equal to: 400');

        $file = new FileInfo($this->assetsDirectory . '/foo.txt', 'foo.txt');
        $validation = new Size(400);
        $validation->validate($file);
    }

    public function testInvalidFileSizeWithHumanReadableArgument(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('File size is too large. Must be less than or equal to: 400');

        $file = new FileInfo($this->assetsDirectory . '/foo.txt', 'foo.txt');
        $validation = new Size('400B');
        $validation->validate($file);
    }

    /**
     * A file that no longer stats reports as a validation failure rather than escaping. On
     * PHP 8 `SplFileInfo::getSize()` throws instead of returning false, and the bound is not
     * what rejected it — a file below the minimum and one that cannot be measured must not
     * report the same way.
     */
    public function testAnUnmeasurableFileIsNotReportedAsTooSmall(): void
    {
        $file = new FileInfo($this->assetsDirectory . '/does-not-exist.txt', 'gone.txt');

        try {
            (new Size(500, 100))->validate($file);
            $this->fail('Expected an unmeasurable file to fail validation');
        } catch (Exception $e) {
            $this->assertSame('File size could not be determined', $e->getMessage());
            $this->assertSame($file, $e->getFileInfo());
        }
    }
}
