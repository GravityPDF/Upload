<?php

namespace GravityPdf\Upload\Validation;

use GravityPdf\Upload\Exception;
use GravityPdf\Upload\FileInfo;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

class ExtensionTest extends TestCase
{
    /**
     * @var string
     */
    protected $assetsDirectory;

    /* phpcs:ignore */
    public function set_up()
    {
        parent::set_up();

        $this->assetsDirectory = dirname(__DIR__) . '/assets';
    }

    public function testValidExtension(): void
    {
        $file = new FileInfo($this->assetsDirectory . '/foo.txt', 'foo.txt');
        $validation = new Extension('txt');

        try {
            $validation->validate($file);
            $this->addToAssertionCount(1);
        } catch (Exception $e) {
            $this->fail('Unexpected exception thrown');
        }
    }

    public function testInvalidExtension(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid file extension. Must be one of: txt');

        $file = new FileInfo($this->assetsDirectory . '/foo_wo_ext', 'foo_wo_ext');
        $validation = new Extension('txt');
        $validation->validate($file);
    }
}
