<?php

namespace GravityPdf\Upload\Validation;

use GravityPdf\Upload\Exception;
use GravityPdf\Upload\FileInfo;
use InvalidArgumentException;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

class FileTypeTest extends TestCase
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

    public function testMatchingExtensionAndMimetypePasses(): void
    {
        $file = new FileInfo($this->assetsDirectory . '/foo.png', 'foo.png');
        $validation = new FileType('png', 'image/png');

        $validation->validate($file);

        $this->addToAssertionCount(1);
    }

    public function testExtensionNotOnTheListIsRejected(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid file extension. Must be one of: png, gif');

        $file = new FileInfo($this->assetsDirectory . '/foo.txt', 'foo.txt');

        (new FileType('png', 'image/png'))
            ->allow('gif', 'image/gif')
            ->validate($file);
    }

    /**
     * A file named `.png` whose contents sniff as text/plain, which is the shape a polyglot
     * takes once it is stored under an extension the web server will hand to an interpreter.
     */
    public function testMimetypeMustMatchTheExtension(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('File contents do not match the "png" extension. Must be one of: image/png');

        $file = new FileInfo($this->assetsDirectory . '/foo.txt', 'foo.png');
        $validation = new FileType('png', 'image/png');
        $validation->validate($file);
    }

    /**
     * The gap this class closes: `Extension` and `Mimetype` check independent allow-lists, so
     * a file passes both while the two answers describe different formats. Each `allow()` call
     * covers one format, which is what keeps the pairing from widening into a cross product.
     */
    public function testCrossedPairPassesExtensionAndMimetypeSeparately(): void
    {
        $file = new FileInfo($this->assetsDirectory . '/foo.txt', 'foo.png');

        (new Extension(['png', 'txt']))->validate($file);
        (new Mimetype(['image/png', 'text/plain']))->validate($file);

        $this->expectException(Exception::class);

        (new FileType('png', 'image/png'))
            ->allow('txt', 'text/plain')
            ->validate($file);
    }

    public function testSeveralExtensionsShareOneMimetype(): void
    {
        $validation = new FileType(['txt', 'text'], 'text/plain');

        $validation->validate(new FileInfo($this->assetsDirectory . '/foo.txt', 'foo.txt'));
        $validation->validate(new FileInfo($this->assetsDirectory . '/foo.txt', 'foo.text'));

        $this->addToAssertionCount(1);
    }

    public function testOneExtensionAcceptsSeveralMimetypes(): void
    {
        $validation = new FileType('txt', ['text/csv', 'text/plain']);

        $validation->validate(new FileInfo($this->assetsDirectory . '/foo.txt', 'foo.txt'));

        $this->addToAssertionCount(1);
    }

    public function testRepeatedExtensionGainsMimetypesRatherThanReplacingThem(): void
    {
        $validation = (new FileType('txt', 'text/csv'))->allow('txt', 'text/plain');

        $validation->validate(new FileInfo($this->assetsDirectory . '/foo.txt', 'foo.txt'));

        $this->addToAssertionCount(1);
    }

    public function testInputIsCaseFolded(): void
    {
        $validation = new FileType('TXT', 'TEXT/PLAIN');

        $validation->validate(new FileInfo($this->assetsDirectory . '/foo.txt', 'foo.txt'));

        $this->addToAssertionCount(1);
    }

    /**
     * `FileInfo::setExtension()` blanks an extension it cannot accept, so the file arrives here
     * with nothing to pair against.
     */
    public function testFileWithNoExtensionIsRejected(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid file extension. Must be one of: png');

        $file = new FileInfo($this->assetsDirectory . '/foo.png', 'evil.p-h-p');
        $validation = new FileType('png', 'image/png');
        $validation->validate($file);
    }

    /**
     * @dataProvider providerEmptySides
     *
     * @param string|string[] $extensions
     * @param string|string[] $mimetypes
     */
    public function testAnEmptySideIsRejected($extensions, $mimetypes): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected at least one extension and one mimetype');

        new FileType($extensions, $mimetypes);
    }

    /**
     * @return array<string, array<int, string|string[]>>
     */
    public function providerEmptySides(): array
    {
        return [
            'no extensions' => [[], 'image/png'],
            'no mimetypes' => ['png', []],

            /* `(array)''` is a list of one, so an empty string reaches the same misconfiguration
               the guard exists to catch without tripping the count. It matters because
               getMimetype() answers '' for a file it cannot read, so `FileType('png', '')`
               would accept an unreadable avatar.png as a PNG. */
            'empty mimetype' => ['png', ''],
            'empty extension' => ['', 'image/png'],
            'list of empty strings' => [['', ''], 'image/png'],
            'whitespace only' => ['png', '   '],

            /* A leading dot is all that is left of an extension once it is removed */
            'dot only' => ['.', 'image/png'],
        ];
    }

    /**
     * `getExtension()` never carries a leading dot, so keeping one would pair the media type
     * with an extension no file can have and reject every upload.
     */
    public function testExtensionsAcceptALeadingDot(): void
    {
        $validation = new FileType('.png', 'image/png');

        $validation->validate(new FileInfo($this->assetsDirectory . '/foo.png', 'foo.png'));

        $this->addToAssertionCount(1);
    }

    /**
     * The configured list is folded, so the value compared against it has to be folded too. The
     * shipped FileInfo lowercases in getMimetype(); a custom FileInfoInterface need not.
     */
    public function testMimetypeFromTheFileIsCaseFolded(): void
    {
        $file = $this->getMockBuilder(FileInfo::class)
            ->setConstructorArgs([$this->assetsDirectory . '/foo.png', 'foo.png'])
            ->onlyMethods(['getMimetype'])
            ->getMock();

        $file->method('getMimetype')->willReturn('IMAGE/PNG');

        (new FileType('png', 'image/png'))->validate($file);

        $this->addToAssertionCount(1);
    }

    /**
     * An unreadable file reports no media type, which must not satisfy a pairing.
     */
    public function testFileWithNoDetectableMimetypeIsRejected(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('File contents do not match the "png" extension');

        (new FileType('png', 'image/png'))->validate(new FileInfo('', 'avatar.png'));
    }
}
