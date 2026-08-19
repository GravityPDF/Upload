<?php

namespace GravityPdf\Upload;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

class FileInfoTest extends TestCase
{
    /**
     * @var FileInfo
     */
    protected $fileWithExtension;

    /**
     * @var FileInfo
     */
    protected $fileWithoutExtension;

    /* phpcs:ignore */
    public function set_up()
    {
        parent::set_up();

        $this->fileWithExtension = new FileInfo(__DIR__ . '/assets/foo.txt', 'foo.txt');
        $this->fileWithoutExtension = new FileInfo(__DIR__ . '/assets/foo_wo_ext', 'foo_wo_ext');
    }

    /* phpcs:ignore */
    public function tear_down()
    {
        /* The factory is process-wide state; leaving it set leaks into every later test */
        FileInfo::resetFactory();

        parent::tear_down();
    }

    public function testGetName(): void
    {
        $this->assertSame('foo', $this->fileWithExtension->getName());
        $this->assertSame('foo_wo_ext', $this->fileWithoutExtension->getName());
    }

    public function testSetName(): void
    {
        $this->fileWithExtension->setName('bar');
        $this->assertSame('bar', $this->fileWithExtension->getName());
    }

    public function testGetNameWithExtension(): void
    {
        $this->assertSame('foo.txt', $this->fileWithExtension->getNameWithExtension());
        $this->assertSame('foo_wo_ext', $this->fileWithoutExtension->getNameWithExtension());
    }

    public function testGetExtension(): void
    {
        $this->assertSame('txt', $this->fileWithExtension->getExtension());
        $this->assertSame('', $this->fileWithoutExtension->getExtension());
    }

    public function testSetExtension(): void
    {
        $this->fileWithExtension->setExtension('csv');
        $this->assertSame('csv', $this->fileWithExtension->getExtension());
    }

    public function testGetMimetype(): void
    {
        $this->assertSame('text/plain', $this->fileWithExtension->getMimetype());
    }

    public function testGetHash(): void
    {
        $sha1Hash = hash_file('sha1', __DIR__ . '/assets/foo.txt');
        $this->assertSame($sha1Hash, $this->fileWithExtension->getHash('sha1'));

        $md5Hash = hash_file('md5', __DIR__ . '/assets/foo.txt');
        $this->assertSame($md5Hash, $this->fileWithExtension->getHash('md5'));
    }

    public function testGetHashDefaultsToSha256(): void
    {
        $sha256Hash = hash_file('sha256', __DIR__ . '/assets/foo.txt');

        $this->assertSame($sha256Hash, $this->fileWithExtension->getHash());
    }

    /**
     * @dataProvider providerSetNameSanitizing
     */
    public function testSetNameSanitizing(string $expectedName, string $expectedExtension, string $filename): void
    {
        $file = new FileInfo(__DIR__ . '/assets/foo.txt', $filename);
        $this->assertSame($expectedName, $file->getName());
        $this->assertSame($expectedExtension, $file->getExtension());
    }

    /**
     * @dataProvider providerSetNameSanitizing
     */
    public function testSetNameWithExtension(string $expectedName, string $expectedExtension, string $filename): void
    {
        $this->fileWithExtension->setNameWithExtension($filename);
        $this->assertSame($expectedName, $this->fileWithExtension->getName());
        $this->assertSame($expectedExtension, $this->fileWithExtension->getExtension());
    }

    /**
     * @return array<int, array<int,string>>
     */
    public function providerSetNameSanitizing(): array
    {
        return [
            0 => ['àáâãäåæçèéêëìíîïñòóôõöøùúûüýÿ', 'png', 'àáâãäåæçèéêëìíîïñòóôõöøùúûüýÿ.png'],
            1 => ['საბეჭდი_მანქანა', 'txt', 'საბეჭდი_მანქანა.txt'],
            2 => ['unencoded space', '', 'unencoded space.php%20'],
            3 => ['encoded-space', '', 'encoded-space.php%0a'],
            4 => ['plus-space', '', 'plus+space.php%0d%0a'],
            5 => ['multi-space', 'php', 'multi %20 +space.php/'],
            6 => ['file name-php', '', 'file   name.php.\\'],
            7 => ['file_name', '', 'file___name . '],
            8 => ['file-name-php', 'png', 'file - -name.php.png'],
            9 => ['file - name', 'png', 'file - name.png'],
            10 => ['file-name', 'exe', 'file--name.exe'],
            11 => ['file- - name', 'php7', 'file-- - name.php7'],
            12 => ['file - _ - name', 'php5', 'file - _ - name.Php5'],
            13 => ['file- name-php', '', 'file-- name.php...'],
            14 => ['file', '', 'file-- . --.-.--name'],
            15 => ['file -name', '', 'file ..name ..'],
            16 => ['file name', 'txt', ' . file name . .txt'],
            17 => ['file', 'name', ' file . name '],
            18 => ['file', '', '_file . name_'],
            19 => ['a-t-t', '', 'a----t----t'],
            20 => ['a-t-t-php-x00', 'png', 'a----t----t----.php\x00.png'],
            21 => ['a t', '', 'a          t'],
            22 => ['a -t-php-0a', 'png', "a    \n\n\nt.php%0a.png"],
            23 => ['a - 22b', '', 'a % 22b'],
            24 => ['file-nam', 'e', 'file...nam . e'],
            25 => ['unnamed-file', '', ''],
            26 => ['unnamed-file', '', '_'],
            27 => ['sample file', '', ' ../sample file'],
            28 => ['sample file', 'txt', ' ../sample file.txt'],
            29 => ['sample file', '', ' ./sample file'],
            30 => ['unnamed-file', '', ' . sample file'],
            31 => ['Sample File-s', '', '"Sample File\'s'],
            32 => ['S-am-ple-20 - -File', 'txt', 'S@{am}^(ple)!$20 %<>:" \|?*[File]#.txt'],
            33 => [str_repeat('A', 251), 'txt', str_repeat('A', 300) . '.txt'],
            34 => ['unnamed-file', '', '.'],
            35 => ['unnamed-file', '', '..'],
            36 => ['unnamed-file', '', '...'],
            37 => ['here', 'txt', '/file/name/here.txt'],
            38 => ['unnamed-file', 'txt', 'con.txt'],
            39 => ['text', '', 'text.con'],
            40 => ['file-con', 'txt', 'file-con.txt'],
            41 => ['lol', 'png', '../../../tmp/lol.png'],
            42 => ['sleep-10', 'jpg', 'sleep(10)-- -.jpg'],
            43 => ['svg onload-alert-document', '', '<svg onload=alert(document.domain)>'],
            44 => ['sleep 10', '', '; sleep 10;'],
            45 => ['This-Is-My-Sample', 'txt', 'This\\Is\\My\\Sample.txt'],

            /* An extension is never rewritten into a different one; see testExtensionIsNeverSynthesized */
            46 => ['evil', '', 'evil.p-h-p'],
            47 => ['evil', '', 'evil.p h p'],
            48 => ['evil', '', 'evil.p;h;p'],

            /* Reserved Windows names are matched whole, not as substrings */
            49 => ['doc', 'conf', 'doc.conf'],
            50 => ['ico', 'icon', 'ico.icon'],
            51 => ['x', '', 'x.aux'], /* `aux` is a reserved device name, so blanking is correct */
            52 => ['archive-tar', 'gz', 'archive.tar.gz'],

            /* Trailing whitespace should not cost the file its extension */
            53 => ['report', 'txt', 'report.txt '],

            /* DEL is the byte the storage layer rejects outright, so the sanitizer has to be
               the thing that removes it */
            54 => ['report', 'txt', "report\x7f.txt"],
            55 => ['a-t', 'txt', "a\x7f\x7ft.txt"],

            /* Characters that reorder or hide what renders around them. The name is the part a
               person reads in an admin listing, an email or a log line. */
            56 => ['resumegpj', 'exe', "resume\u{202E}gpj.exe"],
            57 => ['invoice', 'pdf', "in\u{200B}voice.pdf"],
            58 => ['report', 'txt', "\u{FEFF}report.txt"],
            59 => ['photo', 'jpg', "\u{2066}photo\u{2069}.jpg"],

            /* The rest of Unicode's Bidi_Control property. U+061C is the Arabic counterpart of
               the marks above, and the isolate block runs past the four isolate controls. */
            77 => ['invoice', 'pdf', "in\u{061C}voice.pdf"],
            78 => ['photo', 'jpg', "\u{206A}photo\u{206F}.jpg"],
            79 => ['report', 'txt', "re\u{206C}port.txt"],

            /* Neighbours of the deleted ranges, which are ordinary characters and must survive */
            80 => ["\u{0627}\u{0644}\u{0641}", 'txt', "\u{0627}\u{0644}\u{0641}.txt"],
            81 => ["x\u{2070}", 'txt', "x\u{2070}.txt"],

            /* Line terminators for anything that honours them, the same reason \x00-\x1F goes */
            63 => ['reportlog', 'txt', "report\u{2028}log.txt"],
            64 => ['report', 'txt', "report\u{2029}.txt"],

            /* `0` is a name, not the absence of one */
            60 => ['0', 'txt', '0.txt'],
            61 => ['0', '', '0'],

            /* An alphanumeric extension has nothing else bounding its length, so it would
               otherwise spend the whole 255 byte budget and push the name past the limit */
            62 => ['photo', '', 'photo.' . str_repeat('a', 300)],

            /* Microsoft's list runs from 0, and each digit has a superscript twin resolving to
               the same device */
            65 => ['unnamed-file', 'txt', 'COM0.txt'],
            66 => ['unnamed-file', 'txt', 'LPT0.txt'],
            67 => ['unnamed-file', 'txt', "COM\u{00B9}.txt"],
            68 => ['unnamed-file', 'pdf', "lpt\u{00B3}.pdf"],

            /* The list ends at one digit, so this is an ordinary name */
            69 => ['com10', 'txt', 'com10.txt'],

            /* The reserved-name test runs after the invalid bytes are dropped, so one junk byte
               no longer smuggles a device name through */
            70 => ['unnamed-file', 'txt', "con\xC3.txt"],
            71 => ['unnamed-file', '', "nul\x80"],
            72 => ['unnamed-file', '', "com1\x80_"],

            /* C1 controls, as UTF-8. U+0085 ends a line for anything matching on `\R` and
               U+009B is what a terminal reads as the CSI introducer. */
            73 => ['a-b', 'jpg', "a\xC2\x85b.jpg"],
            74 => ['a-b', 'jpg', "a\xC2\x9Bb.jpg"],

            /* Neither is a control character, so both survive */
            75 => ["caf\u{00E9}", 'txt', "caf\u{00E9}.txt"],
            76 => ["10\u{20AC}", 'txt', "10\u{20AC}.txt"],
        ];
    }

    /**
     * The 255 byte budget divides between the name and whatever extension is set at the time,
     * so these two setters called in this order have to re-divide it. `setNameWithExtension()`
     * happens to call them in the other order and was always safe.
     */
    public function testExtensionSetAfterTheNameStillFitsTheLimit(): void
    {
        $file = (new FileInfo('', 'x'))->setName(str_repeat('c', 300))->setExtension('jpeg');

        $this->assertSame(255, strlen($file->getNameWithExtension()));
        $this->assertStringEndsWith('.jpeg', $file->getNameWithExtension());
    }

    /**
     * @dataProvider providerExtensionLengths
     */
    public function testExtensionLengthIsCapped(int $length, string $expected): void
    {
        $file = new FileInfo(__DIR__ . '/assets/foo.txt', 'photo.' . str_repeat('a', $length));

        $this->assertSame($expected, $file->getExtension());
    }

    /**
     * @return array<string, array<int, int|string>>
     */
    public function providerExtensionLengths(): array
    {
        return [
            'under the cap' => [8, str_repeat('a', 8)],
            'at the cap' => [Filename::MAX_EXTENSION_LENGTH, str_repeat('a', Filename::MAX_EXTENSION_LENGTH)],
            'over the cap' => [Filename::MAX_EXTENSION_LENGTH + 1, ''],
            'far over the cap' => [300, ''],
        ];
    }

    /**
     * The extension is part of the same 255 byte budget as the name, including when the name
     * falls back to `unnamed-file` after the budget has been divided up.
     *
     * @dataProvider providerOversizedNames
     */
    public function testStoredNameStaysWithin255Bytes(string $filename): void
    {
        $file = new FileInfo(__DIR__ . '/assets/foo.txt', $filename);

        $this->assertLessThanOrEqual(255, strlen($file->getNameWithExtension()));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function providerOversizedNames(): array
    {
        return [
            'long name' => [str_repeat('A', 300) . '.txt'],
            'long extension' => ['photo.' . str_repeat('a', 300)],
            'long both' => [str_repeat('A', 300) . '.' . str_repeat('a', 300)],
            'long extension, no name left' => ['.' . str_repeat('a', 300)],
            'long extension, name blanked' => ['con.' . str_repeat('a', 300)],
        ];
    }

    /**
     * Deleting the disallowed characters normalizes, so it can turn a harmless extension into a
     * dangerous one. Discarding cannot produce an extension the client never sent.
     *
     * @dataProvider providerSynthesizableExtensions
     */
    public function testExtensionIsNeverSynthesized(string $filename): void
    {
        $file = new FileInfo(__DIR__ . '/assets/foo.txt', $filename);

        $this->assertSame('', $file->getExtension());
        $this->assertStringEndsNotWith('.php', $file->getNameWithExtension());
    }

    /**
     * @return array<int, array<int,string>>
     */
    public function providerSynthesizableExtensions(): array
    {
        return [
            ['evil.p-h-p'],
            ['evil.p"h"p'],
            ['evil.p<h>p'],
            ['evil.p;h;p'],
            ['evil.p+h+p'],
            ['evil.p h p'],
            ["evil.p\x00hp"],
            ['evil.php%20'],
        ];
    }

    /**
     * An extension that is already alphanumeric is kept verbatim, including the multi-extension
     * case where only the final part counts.
     */
    public function testLegitimateExtensionsSurvive(): void
    {
        $file = new FileInfo(__DIR__ . '/assets/foo.txt', 'evil.p.h.p');

        $this->assertSame('p', $file->getExtension());
        $this->assertSame('evil-p-h.p', $file->getNameWithExtension());
    }

    public function testSanitizedNameIsAlwaysValidUtf8(): void
    {
        /* "\xC3" opens a 2-byte sequence completed by "(", which is stripped as URI reserved */
        $file = new FileInfo(__DIR__ . '/assets/foo.txt', "\xC3\x28.png");

        $this->assertTrue(mb_check_encoding($file->getName(), 'UTF-8'));
        $this->assertTrue(mb_check_encoding($file->getNameWithExtension(), 'UTF-8'));
        $this->assertNotSame(false, json_encode($file->getNameWithExtension()));
    }

    public function testMetadataAccessorsDegradeOnUnreadableFile(): void
    {
        $file = new FileInfo('', 'missing.txt');

        $this->assertSame('', $file->getMimetype());
        $this->assertSame('', $file->getHash());
        $this->assertSame('', $file->getHash('md5'));
        $this->assertSame(false, $file->getSize());
        $this->assertSame(['width' => 0, 'height' => 0], $file->getDimensions());
    }

    /**
     * A misspelled algorithm is broken code, not a file the end user should be told about.
     * Upload\Exception is the type File::isValid() formats into getErrors().
     */
    public function testGetHashRejectsUnsupportedAlgorithm(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported hashing algorithm: bogus');

        $this->fileWithExtension->getHash('bogus');
    }

    public function testResetFactory(): void
    {
        /* A distinguishable subclass, so the assertions can tell the factory from the
           default `new static()` path */
        FileInfo::setFactory(static function ($tmpName, $name) {
            return new class ($tmpName, $name) extends FileInfo {
            };
        });

        $fromFactory = FileInfo::createFromFactory(__DIR__ . '/assets/foo.txt', 'foo.txt');
        $this->assertNotSame(FileInfo::class, get_class($fromFactory));

        FileInfo::resetFactory();

        $afterReset = FileInfo::createFromFactory(__DIR__ . '/assets/foo.txt', 'foo.txt');
        $this->assertSame(FileInfo::class, get_class($afterReset));
    }
}
