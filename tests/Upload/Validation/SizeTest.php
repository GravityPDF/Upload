<?php

namespace GravityPdf\Upload\Validation;

use GravityPdf\Upload\Exception;
use GravityPdf\Upload\FileInfo;
use GravityPdf\Upload\FileInfoInterface;
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
            $this->addToAssertionCount(1);
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
            $this->addToAssertionCount(1);
        } catch (Exception $e) {
            $this->fail('Unexpected exception thrown');
        }
    }

    public function testInvalidFileSize(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('File size is too large. Must be no more than 400 bytes');

        $file = new FileInfo($this->assetsDirectory . '/foo.txt', 'foo.txt');
        $validation = new Size(400);
        $validation->validate($file);
    }

    public function testInvalidFileSizeWithHumanReadableArgument(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('File size is too large. Must be no more than 400 bytes');

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

    /**
     * A limit configured as `5M` reads back as `5 MB`, not `5242880 bytes`. 1024-based, like
     * `File::humanReadableToBytes()` which parsed it.
     *
     * The file's size is stubbed rather than fixtured, since the units worth checking run from
     * a kilobyte to a gigabyte and no assets directory should hold one.
     *
     * @dataProvider provideSizesAndTheirUnits
     * @param int|string $limit
     */
    public function testStatesTheLimitInTheLargestUnitItReaches($limit, string $expected): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage($expected);

        (new Size($limit))->validate($this->fileOfSize(PHP_INT_MAX));
    }

    /** @return array<string,array<int,int|string>> */
    public function provideSizesAndTheirUnits(): array
    {
        return [
            'gigabytes' => ['1G', 'File size is too large. Must be no more than 1 GB'],
            'exact megabytes' => ['5M', 'File size is too large. Must be no more than 5 MB'],
            'exact kilobytes' => ['500K', 'File size is too large. Must be no more than 500 KB'],
            'a half kilobyte' => [1536, 'File size is too large. Must be no more than 1.5 KB'],
            'under a kilobyte' => [400, 'File size is too large. Must be no more than 400 bytes'],
        ];
    }

    /**
     * A maximum rounds down, a minimum rounds up, so the number named is always one the file
     * would pass at. 5,000,000 bytes is 4.768 MB; a maximum shown as `4.8 MB` would name a
     * size still rejected.
     */
    public function testTheStatedLimitIsAlwaysOneTheFileWouldPassAt(): void
    {
        try {
            (new Size(5000000))->validate($this->fileOfSize(PHP_INT_MAX));
            $this->fail('Expected the file to be rejected');
        } catch (Exception $e) {
            $this->assertSame('File size is too large. Must be no more than 4.7 MB', $e->getMessage());
        }

        try {
            (new Size(PHP_INT_MAX, 5000000))->validate($this->fileOfSize(1));
            $this->fail('Expected the file to be rejected');
        } catch (Exception $e) {
            $this->assertSame('File size is too small. Must be at least 4.8 MB', $e->getMessage());
        }
    }

    /** A file that answers one size and nothing else, since that is all `Size` reads */
    private function fileOfSize(int $bytes): FileInfoInterface
    {
        $fileInfo = $this->createMock(FileInfoInterface::class);
        $fileInfo->method('getSize')->willReturn($bytes);

        return $fileInfo;
    }

    /**
     * Picking a decimal separator needs a locale this library does not take, so `scale()` is
     * the seam for it. An override gets the number and the unit together, so the message
     * chosen still names the right unit.
     */
    public function testASubclassCanFormatTheAmountItsOwnWay(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('File size is too large. Must be no more than 4,7 MB');

        (new GermanSize(5000000))->validate($this->fileOfSize(PHP_INT_MAX));
    }

    /**
     * A bound that is not a byte count used to reach `scale()`, which is declared `int` and
     * raised a `TypeError` from inside `validate()` — where `File::runValidations()` absorbs
     * it as `Validation could not be completed` and shows the developer's misconfiguration to
     * whoever submitted the file. `InvalidArgumentException` is a `LogicException`, which
     * that run re-throws.
     *
     * @dataProvider provideBoundsThatAreNotByteCounts
     *
     * @param mixed $maxSize
     */
    public function testRejectsABoundThatIsNotAByteCount($maxSize, string $expected): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expected);

        new Size($maxSize); /* @phpstan-ignore-line */
    }

    /** @return array<string, array<int, mixed>> */
    public function provideBoundsThatAreNotByteCounts(): array
    {
        return [
            /* What a bound read out of JSON, or arrived at by dividing, actually is */
            'a float' => [1.5, 'must be an int of bytes or a string such as "5MB", double given'],
            'null' => [null, 'must be an int of bytes or a string such as "5MB", NULL given'],
            'an array' => [[5], 'must be an int of bytes or a string such as "5MB", array given'],
            'a bool' => [true, 'must be an int of bytes or a string such as "5MB", boolean given'],
            /* Reads as a generous limit and rejects every upload, which is why
               `File::humanReadableToBytes()` already refuses the string form */
            'a negative int' => [-1, 'Size::$maxSize cannot be negative, -1 given'],
        ];
    }

    public function testRejectsAMinimumThatIsNotAByteCount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Size::$minSize must be an int of bytes');

        new Size(500, 1.5); /* @phpstan-ignore-line */
    }

    /**
     * The maximum is the first argument, so the two are easy to pass the wrong way round —
     * and a pair in that order accepts nothing at all.
     */
    public function testRejectsBoundsNoFileCanSatisfy(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Size was given a minimum of 1000 bytes and a maximum of 10 bytes, which no file '
            . 'can satisfy. The maximum is the first argument.'
        );

        new Size(10, 1000);
    }

    /** Equal bounds accept exactly one size, which is a limit somebody may well mean */
    public function testAcceptsBoundsThatMeet(): void
    {
        $validation = new Size(500, 500);

        $validation->validate($this->fileOfSize(500));

        $this->addToAssertionCount(1);
    }
}
