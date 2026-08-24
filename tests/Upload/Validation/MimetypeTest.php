<?php

namespace GravityPdf\Upload\Validation;

use GravityPdf\Upload\Exception;
use GravityPdf\Upload\FileInfo;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

class MimetypeTest extends TestCase
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

    public function testValidMimetype(): void
    {
        $file = new FileInfo($this->assetsDirectory . '/foo.txt', 'foo.txt');
        $validation = new Mimetype(['text/plain']);

        try {
            $validation->validate($file);
            $this->addToAssertionCount(1);
        } catch (Exception $e) {
            $this->fail('Unexpected exception thrown');
        }
    }

    public function testInvalidMimetype(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid mimetype. Must be one of: image/png');

        $file = new FileInfo($this->assetsDirectory . '/foo.txt', 'foo.txt');
        $validation = new Mimetype(['image/png']);
        $validation->validate($file);
    }

    /**
     * A media type is case-insensitive, and `FileInfo::getMimetype()` always answers
     * lowercase, so an allow-list written in any other case matched nothing and rejected
     * every file it was written to accept. `Extension` and `FileType` both fold theirs.
     *
     * @dataProvider provideTheSameMediaTypeInDifferentCases
     */
    public function testTheAllowListIsCaseInsensitive(string $configured): void
    {
        $file = new FileInfo($this->assetsDirectory . '/foo.txt', 'foo.txt');

        (new Mimetype([$configured]))->validate($file);

        $this->addToAssertionCount(1);
    }

    /** @return array<string, array<int, string>> */
    public function provideTheSameMediaTypeInDifferentCases(): array
    {
        return [
            'lowercase' => ['text/plain'],
            'uppercase' => ['TEXT/PLAIN'],
            'mixed case' => ['Text/Plain'],
            'padded' => ['  text/plain  '],
        ];
    }

    /**
     * `getMimetype()` answers `''` for a file it cannot read, so an entry the trim empties
     * would accept exactly those — the drop `FileType::normalize()` makes for the same reason.
     */
    public function testAnEmptyEntryDoesNotAcceptAnUnreadableFile(): void
    {
        $this->expectException(Exception::class);

        $fileInfo = $this->createMock(\GravityPdf\Upload\FileInfoInterface::class);
        $fileInfo->method('getMimetype')->willReturn('');

        (new Mimetype([' ']))->validate($fileInfo);
    }

    /**
     * A custom `FileInfoInterface` is a public extension point and need not lowercase what it
     * sniffs, which is the half `FileType` already folds.
     */
    public function testTheSniffedTypeIsFoldedToo(): void
    {
        $fileInfo = $this->createMock(\GravityPdf\Upload\FileInfoInterface::class);
        $fileInfo->method('getMimetype')->willReturn('IMAGE/PNG');

        (new Mimetype(['image/png']))->validate($fileInfo);

        $this->addToAssertionCount(1);
    }
}
