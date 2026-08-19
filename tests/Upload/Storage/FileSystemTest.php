<?php

namespace GravityPdf\Upload\Storage;

use InvalidArgumentException;
use GravityPdf\Upload\Exception;
use GravityPdf\Upload\FileInfo;
use GravityPdf\Upload\FileInfoInterface;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

class FileSystemTest extends TestCase
{
    /**
     * @var string
     */
    protected $assetsDirectory;

    /**
     * Scratch directories created by makeWorkingDirectory(), removed in tear_down()
     * @var string[]
     */
    protected $workingDirectories = [];

    /* phpcs:ignore */
    public function set_up()
    {
        parent::set_up();

        // Path to test assets
        $this->assetsDirectory = dirname(__DIR__) . '/assets';

        // Reset $_FILES superglobal
        $_FILES['foo'] = [
            'name' => 'foo.txt',
            'tmp_name' => $this->assetsDirectory . '/foo.txt',
            'error' => 0,
        ];
    }

    /* phpcs:ignore */
    public function tear_down()
    {
        foreach ($this->workingDirectories as $workingDirectory) {
            /* A test is free to remove its own working directory */
            if (is_dir($workingDirectory)) {
                /* scandir() rather than glob(), so a name glob would treat as a pattern is
                   still swept, and no pattern escaping is needed */
                foreach ($this->entriesIn($workingDirectory) as $entry) {
                    @unlink($workingDirectory . '/' . $entry);
                }
            }

            @rmdir($workingDirectory);
            @rmdir(dirname($workingDirectory));
        }

        $this->workingDirectories = [];

        parent::tear_down();
    }

    public function testInstantiationWithValidDirectory(): void
    {
        try {
            $storage = $this->getMockBuilder(FileSystem::class)
                ->setConstructorArgs([$this->assetsDirectory])
                ->getMock();

            $this->assertTrue(true);
        } catch (InvalidArgumentException $e) {
            $this->fail('Unexpected argument thrown during instantiation with valid directory');
        }
    }

    public function testInstantiationWithInvalidDirectory(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Directory does not exist');

        $storage = $this->getMockBuilder(FileSystem::class)
            ->setConstructorArgs(['/foo'])
            ->getMock();
    }

    /**
     * Test won't overwrite existing file
     */
    public function testWillNotOverwriteFile(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('A file named "foo.txt" already exists');

        $storage = new FileSystem($this->assetsDirectory, false);
        $storage->upload(new FileInfo('foo.txt', dirname(__DIR__) . '/assets/foo.txt'));
    }

    /**
     * Test will overwrite existing file
     */
    public function testWillOverwriteFile(): void
    {
        $workingDirectory = $this->makeWorkingDirectory();
        file_put_contents($workingDirectory . '/foo.txt', 'the file that was already there');

        $storage = $this->makeStorage($workingDirectory, true);
        $storage->upload(new FileInfo($this->assetsDirectory . '/foo.txt', 'foo.txt'));

        $this->assertStringEqualsFile(
            $workingDirectory . '/foo.txt',
            (string)file_get_contents($this->assetsDirectory . '/foo.txt')
        );
    }

    /**
     * A `FileInfoInterface` is a public extension point, so the storage layer must not
     * assume the name it is handed was sanitized by `FileInfo`.
     */
    public function testRejectsTraversalInFileName(): void
    {
        $workingDirectory = $this->makeWorkingDirectory();
        $escapeTarget = dirname($workingDirectory) . '/escaped.txt';

        $storage = $this->makeStorage($workingDirectory, true);
        $fileInfo = $this->makeHostileFileInfo('../escaped.txt');

        $storage->upload($fileInfo);

        $this->assertFileDoesNotExist($escapeTarget);
        $this->assertFileExists($workingDirectory . '/escaped.txt');
    }

    /**
     * @dataProvider providerUnusableFileNames
     */
    public function testRejectsUnusableFileNames(string $name): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid destination file name');

        $storage = $this->makeStorage($this->makeWorkingDirectory(), true);
        $storage->upload($this->makeHostileFileInfo($name));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function providerUnusableFileNames(): array
    {
        return [
            'empty' => [''],
            'this directory' => ['.'],
            'parent directory' => ['..'],
            'separator only' => ['/'],
            'traversal only' => ['../..'],
            /* PHP 8 raises a ValueError for a null byte in a path, so reject it as our own error */
            'null byte' => ["shell.php\0.png"],

            /* Hidden from the shell globbing and directory listings a cleanup script uses */
            'dotfile' => ['.env'],
            'dotfile with an allowed extension' => ['.hidden.txt'],

            /* A name that can forge a line in a log, or move the cursor in a terminal */
            'newline' => ["report\n2024-01-01 INFO all clear.txt"],
            'escape sequence' => ["report\x1b[2Kfake.txt"],
            'delete' => ["report\x7f.txt"],

            /* C1, as UTF-8: U+0085 ends a line for anything matching on `\R`, U+009B is the
               CSI introducer a terminal acts on. Both are valid UTF-8, so the encoding repair
               in `FileInfo` keeps them and only an explicit screen removes them. */
            'C1 next line' => ["report\u{0085}2026-01-01 INFO all clear.txt"],
            'C1 control sequence introducer' => ["report\u{009B}2Kfake.txt"],

            /* Windows reads the component before the first dot, so `CON.txt` opens the console
               rather than creating a file, and ignores the spaces around it */
            'device with an extension' => ['CON.txt'],
            'device on its own' => ['nul'],
            'device padded with spaces' => ['  aux  .txt'],
            'device before further dots' => ['CON.tar.gz'],
            'numbered device' => ['COM0.log'],
            'superscript device' => ["LPT\u{00B9}.log"],
        ];
    }

    public function testRefusesToWriteThroughASymlink(): void
    {
        $workingDirectory = $this->makeWorkingDirectory();
        $target = $workingDirectory . '/target.txt';
        symlink($target, $workingDirectory . '/link.txt');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Destination is a symbolic link');

        $storage = $this->makeStorage($workingDirectory, true);
        $storage->upload($this->makeHostileFileInfo('link.txt'));
    }

    /**
     * `x` mode resolves the path through PHP's stream layer, so it follows a dangling symlink
     * and creates the target. The inode comparison is what catches that.
     */
    public function testExclusiveCreateDetectsDanglingSymlink(): void
    {
        $workingDirectory = $this->makeWorkingDirectory();
        symlink($workingDirectory . '/does-not-exist', $workingDirectory . '/dangling.txt');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Destination is a symbolic link');

        $storage = $this->makeStorage($workingDirectory, false);
        $storage->upload($this->makeHostileFileInfo('dangling.txt'));
    }

    public function testFailedMoveDoesNotLeaveAPlaceholderBehind(): void
    {
        $workingDirectory = $this->makeWorkingDirectory();

        $storage = $this->getMockBuilder(FileSystem::class)
            ->setConstructorArgs([$workingDirectory, false])
            ->onlyMethods(['moveUploadedFile'])
            ->getMock();

        $storage
            ->method('moveUploadedFile')
            ->willReturn(false);

        try {
            $storage->upload(new FileInfo($this->assetsDirectory . '/foo.txt', 'foo.txt'));
            $this->fail('Expected the failed move to throw');
        } catch (Exception $e) {
            $this->assertSame('File could not be moved to final destination.', $e->getMessage());
        }

        $this->assertFileDoesNotExist($workingDirectory . '/foo.txt');

        /* Neither the reserved destination nor the staging file the upload was headed for */
        $this->assertSame([], $this->entriesIn($workingDirectory));
    }

    /**
     * Nothing checked before the write can be relied on at the moment of the write: the entry
     * can change in between. What keeps the upload out of a symlinked target is that the bytes
     * go to a staged name and are `rename()`d on, which replaces the entry rather than
     * resolving it. The stub stands in for an attacker winning that race.
     */
    public function testSymlinkPlantedDuringTheWriteIsReplacedNotFollowed(): void
    {
        $workingDirectory = $this->makeWorkingDirectory();
        $target = $workingDirectory . '/target.txt';
        file_put_contents($target, 'the file the attacker chose');

        $storage = $this->getMockBuilder(FileSystem::class)
            ->setConstructorArgs([$workingDirectory, false])
            ->onlyMethods(['moveUploadedFile'])
            ->getMock();

        $storage
            ->method('moveUploadedFile')
            ->willReturnCallback(
                static function (string $source, string $destination) use ($workingDirectory, $target): bool {
                    unlink($workingDirectory . '/upload.txt');
                    symlink($target, $workingDirectory . '/upload.txt');

                    return copy($source, $destination);
                }
            );

        $storage->upload($this->makeHostileFileInfo('upload.txt'));

        $this->assertStringEqualsFile($target, 'the file the attacker chose');
        $this->assertFalse(is_link($workingDirectory . '/upload.txt'));
        $this->assertStringEqualsFile(
            $workingDirectory . '/upload.txt',
            (string)file_get_contents($this->assetsDirectory . '/foo.txt')
        );
    }

    /**
     * An upload that cannot be staged must not leave the finished file readable anyway, and a
     * mode that could not be applied is not the `0640` the class documents.
     */
    public function testFileIsNotStoredWhenTheModeCannotBeApplied(): void
    {
        $workingDirectory = $this->makeWorkingDirectory();

        $storage = $this->getMockBuilder(FileSystem::class)
            ->setConstructorArgs([$workingDirectory, false])
            ->onlyMethods(['moveUploadedFile'])
            ->getMock();

        /* Report the move as done without producing the staged file, so the chmod that follows
           has nothing to act on */
        $storage
            ->method('moveUploadedFile')
            ->willReturn(true);

        try {
            $storage->upload($this->makeHostileFileInfo('private.txt'));
            $this->fail('Expected the failed chmod to throw');
        } catch (Exception $e) {
            $this->assertSame('Permissions could not be applied to the stored file', $e->getMessage());
        }

        $this->assertSame([], $this->entriesIn($workingDirectory));
    }

    /**
     * The last step of the staged-write path, and the one the symlink story rests on: the
     * bytes are only safe because they arrive under the staging name and are moved by a
     * `rename()` that resolves no link. A failure there must leave nothing behind.
     *
     * The mode is turned off so the `@chmod` two lines above does not fail first — with no
     * staged file, both would.
     */
    public function testNothingIsLeftBehindWhenTheFinalMoveFails(): void
    {
        $workingDirectory = $this->makeWorkingDirectory();

        /* Report the move as done without producing the staged file, so the rename has
           nothing to move */
        $storage = $this->makeStorage($workingDirectory, false, static function (): bool {
            return true;
        });
        $storage->setMode(null);

        try {
            $storage->upload($this->makeHostileFileInfo('foo.txt'));
            $this->fail('Expected the failed rename to throw');
        } catch (Exception $e) {
            $this->assertSame('File could not be moved to final destination.', $e->getMessage());
        }

        /* The reservation placeholder included: leaving it holds the name against every
           later upload of it */
        $this->assertSame([], $this->entriesIn($workingDirectory));
    }

    /**
     * With overwriting on, whatever is at the destination belongs to the caller and this upload
     * has not touched it. Dropping the conditional in `discard()` would delete it on a failure
     * that never reached the write.
     */
    public function testAFailedOverwritingUploadLeavesTheExistingFileAlone(): void
    {
        $workingDirectory = $this->makeWorkingDirectory();
        file_put_contents($workingDirectory . '/foo.txt', 'the original');

        $storage = $this->makeStorage($workingDirectory, true, static function (): bool {
            return false;
        });

        try {
            $storage->upload($this->makeHostileFileInfo('foo.txt'));
            $this->fail('Expected the failed move to throw');
        } catch (Exception $e) {
            $this->assertSame('File could not be moved to final destination.', $e->getMessage());
        }

        $this->assertSame('the original', file_get_contents($workingDirectory . '/foo.txt'));
        $this->assertSame(['foo.txt'], $this->entriesIn($workingDirectory));
    }

    /**
     * The other half of the same conditional: with overwriting off, the destination holds this
     * upload's own placeholder, so it has to go.
     */
    public function testAFailedUploadClearsThePlaceholderItReserved(): void
    {
        $workingDirectory = $this->makeWorkingDirectory();

        $storage = $this->makeStorage($workingDirectory, false, static function (): bool {
            return false;
        });

        try {
            $storage->upload($this->makeHostileFileInfo('foo.txt'));
            $this->fail('Expected the failed move to throw');
        } catch (Exception $e) {
            $this->assertSame('File could not be moved to final destination.', $e->getMessage());
        }

        $this->assertSame([], $this->entriesIn($workingDirectory));
    }

    /**
     * `x` mode fails for reasons other than the name being taken. Reporting those as a
     * collision sends the caller into a rename-and-retry loop that cannot succeed.
     */
    public function testCreateFailureThatIsNotACollisionIsReportedAsItself(): void
    {
        $workingDirectory = $this->makeWorkingDirectory();
        $storage = $this->makeStorage($workingDirectory, false);

        /* Gone since the constructor checked it */
        rmdir($workingDirectory);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Destination file could not be created');

        $storage->upload($this->makeHostileFileInfo('foo.txt'));
    }

    public function testBlockedExtensionsAreRefused(): void
    {
        $storage = $this->makeStorage($this->makeWorkingDirectory(), true)
            ->blockExtensions(FileSystem::getDefaultBlockedExtensions());

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Files with the extension "php" cannot be stored');

        $storage->upload($this->makeHostileFileInfo('shell.php'));
    }

    /**
     * @dataProvider providerDefaultBlockedExtensions
     */
    public function testBlockingIsOnByDefault(string $name, string $extension): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage(sprintf('Files with the extension "%s" cannot be stored', $extension));

        $storage = $this->makeStorage($this->makeWorkingDirectory(), true);
        $storage->upload($this->makeHostileFileInfo($name));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function providerDefaultBlockedExtensions(): array
    {
        return [
            'server executes it' => ['shell.php', 'php'],
            'server reads it as configuration' => ['config.ini', 'ini'],
            'browser renders it as markup' => ['stored-xss.html', 'html'],
            'browser runs script inside it' => ['logo.svg', 'svg'],
        ];
    }

    public function testBlockingCanBeTurnedOff(): void
    {
        $workingDirectory = $this->makeWorkingDirectory();
        $storage = $this->makeStorage($workingDirectory, true)->allowAnyExtension();

        $storage->upload($this->makeHostileFileInfo('shell.php'));

        $this->assertFileExists($workingDirectory . '/shell.php');
    }

    public function testBlockedExtensionsAcceptACustomList(): void
    {
        $workingDirectory = $this->makeWorkingDirectory();
        $storage = $this->makeStorage($workingDirectory, true)->blockExtensions(['exe']);

        /* `php` is not on this caller's list, so it is allowed through */
        $storage->upload($this->makeHostileFileInfo('shell.php'));
        $this->assertFileExists($workingDirectory . '/shell.php');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Files with the extension "exe" cannot be stored');
        $storage->upload($this->makeHostileFileInfo('installer.exe'));
    }

    /**
     * `pathinfo()` reads the name the way PHP splits it. A server does not have to agree, and
     * where it does not, the extension that decides how the file is served is one `pathinfo()`
     * never returned.
     *
     * @dataProvider providerNamesThatHideABlockedExtension
     */
    public function testDenyListCoversExtensionsPathinfoDoesNotReturn(string $name): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Files with the extension "php" cannot be stored');

        $storage = $this->makeStorage($this->makeWorkingDirectory(), true);
        $storage->upload($this->makeHostileFileInfo($name));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function providerNamesThatHideABlockedExtension(): array
    {
        return [
            /* Windows resolves both of these to `evil.php` */
            'trailing dot' => ['evil.php.'],
            'trailing space' => ['evil.php '],
            'trailing dots and spaces' => ['evil.php . '],

            /* Apache's AddHandler and AddType match any dot component, not just the last */
            'inner extension' => ['evil.php.jpg'],
            'inner extension among several' => ['evil.php.tar.gz'],
            'inner extension with padding' => ['evil.php .jpg'],
        ];
    }

    /**
     * A name is not a blocked extension, so a file called `php` is storable.
     */
    public function testDenyListDoesNotApplyToTheNameItself(): void
    {
        $workingDirectory = $this->makeWorkingDirectory();
        $storage = $this->makeStorage($workingDirectory, true);

        $storage->upload($this->makeHostileFileInfo('php'));

        $this->assertFileExists($workingDirectory . '/php');
    }

    /**
     * `FileInfo` rewrites all of these to hyphens, so reaching the storage layer with one means
     * a `FileInfoInterface` of your own supplied the name. See `FileSystem::resolveFilename()`
     * for why they are rewritten there rather than refused.
     *
     * @dataProvider providerCharactersWindowsRefuses
     */
    public function testCharacterWindowsRefusesIsRewritten(string $name, string $stored): void
    {
        $this->assertStoredAs($stored, $this->makeHostileFileInfo($name));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function providerCharactersWindowsRefuses(): array
    {
        return [
            'alternate data stream' => ['report.txt:payload', 'report.txt-payload'],
            'angle brackets' => ['report<1>.txt', 'report-1-.txt'],
            'double quote' => ['report".txt', 'report-.txt'],
            'pipe' => ['report|tee.txt', 'report-tee.txt'],
            'question mark' => ['report?.txt', 'report-.txt'],
            'wildcard' => ['report*.txt', 'report-.txt'],
        ];
    }

    /**
     * Sanitizing is many-to-one, here and in `FileInfo`, so two names a caller sees as distinct
     * can resolve to one destination. With `overwrite` off that is caught rather than clobbered,
     * and the message has to name the file or the caller cannot tell which name collided.
     */
    public function testCollisionNamesTheFileThatIsInTheWay(): void
    {
        $workingDirectory = $this->makeWorkingDirectory();
        $storage = $this->makeStorage($workingDirectory, false);

        $storage->upload($this->makeHostileFileInfo('report?.txt'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('A file named "report-.txt" already exists');

        /* A different name to the caller; the same destination once resolved */
        $storage->upload($this->makeHostileFileInfo('report*.txt'));
    }

    /**
     * The rewrite runs before the deny-list, so hiding a blocked extension behind one of these
     * does not carry it past the check.
     */
    public function testRewrittenCharacterDoesNotHideABlockedExtension(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Files with the extension "php" cannot be stored');

        $storage = $this->makeStorage($this->makeWorkingDirectory(), true);
        $storage->upload($this->makeHostileFileInfo('report.txt:payload.php'));
    }

    /**
     * The rule is about the whole component, not a prefix of it.
     */
    public function testNameThatMerelyStartsWithADeviceNameIsStored(): void
    {
        $this->assertStoredAs('console.txt', $this->makeHostileFileInfo('console.txt'));
    }

    /**
     * The every-component check is a backstop for a caller-supplied `FileInfoInterface`. A name
     * that arrives through the shipped `FileInfo` has already had its interior dots rewritten to
     * hyphens, so it carries one extension and is stored rather than refused. The README and the
     * upgrade guide both say so, and said the opposite until this test existed.
     *
     * Built on a real `FileInfo` rather than the hostile stub, because the contract under test
     * spans both layers. `FileInfoTest::providerSetNameSanitizing` covers the rewrite itself.
     *
     * @dataProvider providerNamesWithInteriorDots
     */
    public function testInteriorDotsAreHyphenatedRatherThanRefused(string $name, string $stored): void
    {
        $this->assertStoredAs($stored, new FileInfo($this->assetsDirectory . '/foo.txt', $name));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function providerNamesWithInteriorDots(): array
    {
        return [
            /* The example the upgrade guide gives; `config` is on the deny-list */
            'blocked word between dots' => ['release.config.zip', 'release-config.zip'],
            'blocked word before the extension' => ['evil.php.jpg', 'evil-php.jpg'],
            'ordinary multi-dot name' => ['archive.tar.gz', 'archive-tar.gz'],
        ];
    }

    /**
     * Trailing dots and spaces are taken off the stored name too, so that what is on disk is
     * the name that was checked.
     */
    public function testTrailingDotsAndSpacesAreNotStored(): void
    {
        $workingDirectory = $this->makeWorkingDirectory();
        $storage = $this->makeStorage($workingDirectory, true);

        $this->assertSame(
            $workingDirectory . '/report.txt',
            $storage->upload($this->makeHostileFileInfo('report.txt. '))
        );
    }

    /**
     * The values compared against the list never carry a leading dot, so keeping one would
     * block nothing while the caller believes they tightened the list.
     */
    public function testBlockedExtensionsAcceptALeadingDot(): void
    {
        $storage = $this->makeStorage($this->makeWorkingDirectory(), true)->blockExtensions(['.php', '.exe']);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Files with the extension "php" cannot be stored');

        $storage->upload($this->makeHostileFileInfo('pwn.php'));
    }

    /**
     * The deny-list is documented in two places and will drift.
     */
    public function testReadmeDocumentsTheDefaultDenyList(): void
    {
        $readme = (string)file_get_contents(dirname(__DIR__, 3) . '/.github/README.md');

        if (preg_match('/### Extensions blocked by default(.*?)```/s', $readme, $section) !== 1) {
            $this->fail('The README no longer has an "Extensions blocked by default" table');
        }

        /* Backticked lowercase words only, which skips `blockExtensions()` and the constant names */
        preg_match_all('/`([a-z0-9]+)`/', $section[1], $matches);

        $this->assertSame(
            FileSystem::getDefaultBlockedExtensions(),
            $matches[1],
            'The README table and FileSystem::getDefaultBlockedExtensions() have drifted apart'
        );
    }

    public function testSetModeAppliesPermissions(): void
    {
        $workingDirectory = $this->makeWorkingDirectory();
        $storage = $this->makeStorage($workingDirectory, true)->setMode(0600);

        $storage->upload($this->makeHostileFileInfo('private.txt'));

        $this->assertSame('0600', $this->modeOf($workingDirectory . '/private.txt'));
    }

    public function testDefaultModeIsAppliedWithoutBeingAskedFor(): void
    {
        $workingDirectory = $this->makeWorkingDirectory();
        $storage = $this->makeStorage($workingDirectory, true);

        $storage->upload($this->makeHostileFileInfo('default.txt'));

        $this->assertSame('0640', $this->modeOf($workingDirectory . '/default.txt'));
    }

    /**
     * `setMode(null)` hands the mode back to the process umask, which is what
     * `move_uploaded_file()` does on its own.
     */
    public function testModeCanBeHandedBackToTheUmask(): void
    {
        $workingDirectory = $this->makeWorkingDirectory();
        $storage = $this->makeStorage($workingDirectory, true)->setMode(null);

        $umask = umask(0022);
        $storage->upload($this->makeHostileFileInfo('umask.txt'));
        umask($umask);

        $this->assertNotSame('0640', $this->modeOf($workingDirectory . '/umask.txt'));
    }

    protected function modeOf(string $path): string
    {
        /* Before PHP 8.3, chmod() does not invalidate the stat cache, so a path already
           stat'd in this request reports the mode it had then. `reserveDestination()` lstat's
           the placeholder before it chmods it, which is exactly that sequence. */
        clearstatcache(true, $path);

        return substr(sprintf('%o', fileperms($path)), -4);
    }

    /**
     * @return string[]
     */
    protected function entriesIn(string $directory): array
    {
        $entries = scandir($directory);

        return $entries === false ? [] : array_values(array_diff($entries, ['.', '..']));
    }

    /**
     * A scratch directory that is removed again in tear_down()
     */
    protected function makeWorkingDirectory(): string
    {
        $workingDirectory = sys_get_temp_dir() . '/upload-test-' . uniqid('', true) . '/uploads';
        mkdir($workingDirectory, 0777, true);
        $this->workingDirectories[] = $workingDirectory;

        return $workingDirectory;
    }

    /**
     * A real `FileSystem` with only `move_uploaded_file()` swapped, so the destination-resolution
     * logic under test runs unmodified without a POST upload. `$move` defaults to a `copy()`;
     * pass one that returns `true` without writing, or `false`, to reach the failure paths.
     *
     * @param string $directory
     * @param bool $overwrite
     * @param callable|null $move
     * @return FileSystem&\PHPUnit\Framework\MockObject\MockObject
     */
    protected function makeStorage(string $directory, bool $overwrite, $move = null)
    {
        $storage = $this->getMockBuilder(FileSystem::class)
            ->setConstructorArgs([$directory, $overwrite])
            ->onlyMethods(['moveUploadedFile'])
            ->getMock();

        if ($move === null) {
            $move = static function (string $source, string $destination): bool {
                return copy($source, $destination);
            };
        }

        $storage->method('moveUploadedFile')->willReturnCallback($move);

        return $storage;
    }

    /**
     * Upload into a fresh directory and assert the name it landed under, which `upload()` also
     * has to return.
     */
    protected function assertStoredAs(string $stored, FileInfoInterface $fileInfo): void
    {
        $workingDirectory = $this->makeWorkingDirectory();
        $storage = $this->makeStorage($workingDirectory, true);

        $this->assertSame($workingDirectory . '/' . $stored, $storage->upload($fileInfo));
        $this->assertFileExists($workingDirectory . '/' . $stored);
    }

    /**
     * Returns whatever name it is given, standing in for a third-party implementation that
     * does none of `FileInfo`'s sanitizing.
     */
    protected function makeHostileFileInfo(string $name): FileInfo
    {
        $fileInfo = $this->getMockBuilder(FileInfo::class)
            ->setConstructorArgs([$this->assetsDirectory . '/foo.txt', 'foo.txt'])
            ->onlyMethods(['getNameWithExtension'])
            ->getMock();

        $fileInfo->method('getNameWithExtension')->willReturn($name);

        return $fileInfo;
    }

    /**
     * `resolveFilename()` is the naming seam. The deny-list and the device-name check are not,
     * and used to sit inside it: an override that said nothing about extensions dropped both,
     * which is a silent route past a control the class otherwise makes loud.
     */
    public function testOverridingTheNamingSeamDoesNotDropTheRefusals(): void
    {
        $workingDirectory = $this->makeWorkingDirectory();

        $storage = new class ($workingDirectory) extends FileSystem {
            protected function resolveFilename(FileInfoInterface $fileInfo): string
            {
                return basename($fileInfo->getNameWithExtension());
            }

            protected function moveUploadedFile(string $source, string $destination): bool
            {
                return copy($source, $destination);
            }
        };

        try {
            $storage->upload($this->makeHostileFileInfo('shell.php'));
            $this->fail('An overridden resolveFilename() must not bypass the deny-list');
        } catch (Exception $e) {
            $this->assertSame('Files with the extension "php" cannot be stored', $e->getMessage());
        }

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid destination file name');

        $storage->upload($this->makeHostileFileInfo('CON.txt'));
    }

    public function testReturnsUploadedFileName(): void
    {
        $workingDirectory = $this->makeWorkingDirectory();
        $storage = $this->makeStorage($workingDirectory, true);

        $this->assertSame(
            $workingDirectory . '/foo.txt',
            $storage->upload(new FileInfo($this->assetsDirectory . '/foo.txt', 'foo.txt'))
        );
        $this->assertSame($workingDirectory, $storage->getDirectory());
    }

    /**
     * `x` mode resolves the path through PHP's stream layer, so it creates the file at the far
     * end of a dangling symlink. The inode check refuses the upload, which is what protects the
     * victim; this covers the file that create left behind outside the upload directory.
     *
     * Driven through `reserveDestination()` rather than `upload()`, because `upload()`'s own
     * `is_link()` check rejects a link that is already in place. Reaching here means the link
     * was planted after it, which is a race a test cannot stage.
     */
    public function testReservationThroughASymlinkLeavesNothingAtItsTarget(): void
    {
        $workingDirectory = $this->makeWorkingDirectory();
        $outside = dirname($workingDirectory) . '/sentinel.lock';
        $link = $workingDirectory . '/foo.txt';

        @unlink($outside);
        symlink($outside, $link);

        $storage = new ExposedFileSystem($workingDirectory, false);
        $fileInfo = new FileInfo($this->assetsDirectory . '/foo.txt', 'foo.txt');

        try {
            $storage->reserve($link, $fileInfo);
            $this->fail('A reservation onto a symlink should not have been accepted');
        } catch (Exception $e) {
            $this->assertSame('Destination is a symbolic link', $e->getMessage());
        }

        $this->assertFileDoesNotExist($outside);

        /* The link was not this upload's to create, so it is not this upload's to remove */
        $this->assertTrue(is_link($link));

        @unlink($link);
    }

    /**
     * A reservation that cannot be confirmed is a failed create, not a symlink, and it has to
     * take its own placeholder back — otherwise the name is unusable for every later upload.
     */
    public function testAReservationThatCannotBeConfirmedIsNotReportedAsASymlink(): void
    {
        $workingDirectory = $this->makeWorkingDirectory();

        $storage = $this->getMockBuilder(FileSystem::class)
            ->setConstructorArgs([$workingDirectory, false])
            ->onlyMethods(['moveUploadedFile', 'lstatEntry'])
            ->getMock();

        $storage->method('lstatEntry')->willReturn(false);

        try {
            $storage->upload(new FileInfo($this->assetsDirectory . '/foo.txt', 'foo.txt'));
            $this->fail('An unconfirmable reservation should not have been stored');
        } catch (Exception $e) {
            $this->assertSame('Destination file could not be created', $e->getMessage());
        }

        $this->assertFileDoesNotExist($workingDirectory . '/foo.txt');
    }

    /**
     * refuseBlockedExtensions() matches one dot-separated component at a time, so an entry that
     * is itself compound has to be split or it silently blocks nothing.
     */
    public function testCompoundDenyListEntriesBlockEachOfTheirComponents(): void
    {
        $workingDirectory = $this->makeWorkingDirectory();
        $storage = $this->makeStorage($workingDirectory, true);
        $storage->blockExtensions(['tar.gz', '.user.ini']);

        foreach (['backup.tar', 'backup.gz', 'settings.user', 'settings.ini'] as $name) {
            try {
                $storage->upload($this->makeHostileFileInfo($name));
                $this->fail(sprintf('"%s" should have been refused', $name));
            } catch (Exception $e) {
                $this->assertStringContainsString('cannot be stored', $e->getMessage());
            }
        }
    }

    /**
     * The shipped `FileInfo` deletes these, so only an implementation of your own arrives here
     * carrying one — which is the case this layer exists for.
     *
     * @dataProvider providerBidiFileNames
     */
    public function testABidiControlInTheNameIsRefused(string $name): void
    {
        $workingDirectory = $this->makeWorkingDirectory();
        $storage = $this->makeStorage($workingDirectory, true);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid destination file name');

        $storage->upload($this->makeHostileFileInfo($name));
    }

    /**
     * @return array<string, string[]>
     */
    public function providerBidiFileNames(): array
    {
        return [
            'right-to-left override' => ["resume\u{202E}gpj.exe"],
            'zero width space' => ["in\u{200B}voice.pdf"],
            'byte order mark' => ["\u{FEFF}report.txt"],
            'isolate' => ["\u{2066}photo\u{2069}.jpg"],
            'arabic letter mark' => ["in\u{061C}voice.pdf"],
            'inhibit symmetric swapping' => ["\u{206A}photo.jpg"],
        ];
    }

    public function testAnOrdinaryNonAsciiNameIsStillStored(): void
    {
        $this->assertStoredAs(
            "\u{0627}\u{0644}\u{0641}.txt",
            $this->makeHostileFileInfo("\u{0627}\u{0644}\u{0641}.txt")
        );
    }

    /**
     * The placeholder holds the final name for the whole transfer, so it must not sit there at
     * whatever the umask allowed.
     */
    public function testTheReservationPlaceholderCarriesTheConfiguredMode(): void
    {
        $workingDirectory = $this->makeWorkingDirectory();

        $storage = $this->getMockBuilder(FileSystem::class)
            ->setConstructorArgs([$workingDirectory, false])
            ->onlyMethods(['moveUploadedFile'])
            ->getMock();

        $modeDuringTransfer = null;

        $storage
            ->method('moveUploadedFile')
            ->willReturnCallback(function (
                string $source,
                string $destination
            ) use (
                $workingDirectory,
                &$modeDuringTransfer
            ): bool {
                $modeDuringTransfer = $this->modeOf($workingDirectory . '/foo.txt');

                return copy($source, $destination);
            });

        $storage->upload(new FileInfo($this->assetsDirectory . '/foo.txt', 'foo.txt'));

        $this->assertSame('0640', $modeDuringTransfer);
    }

    public function testAllowAnyExtensionClearsTheDenyList(): void
    {
        $workingDirectory = $this->makeWorkingDirectory();
        $storage = $this->makeStorage($workingDirectory, true);

        $this->assertSame(FileSystem::getDefaultBlockedExtensions(), $storage->getBlockedExtensions());

        $storage->allowAnyExtension();

        $this->assertSame([], $storage->getBlockedExtensions());
        $this->assertSame(
            $workingDirectory . '/shell.php',
            $storage->upload($this->makeHostileFileInfo('shell.php'))
        );
    }

    public function testAccessorsReportTheConfiguredPolicy(): void
    {
        $workingDirectory = $this->makeWorkingDirectory();
        $storage = $this->makeStorage($workingDirectory, true);

        $this->assertSame(FileSystem::DEFAULT_MODE, $storage->getMode());
        $this->assertNull($storage->setMode(null)->getMode());
    }

    /**
     * Entries are folded and split at the setter, so an uppercase or compound list still
     * refuses the lowercase single-component name a file actually carries.
     */
    public function testADenyListIsFoldedAndSplitBeforeItIsMatched(): void
    {
        $workingDirectory = $this->makeWorkingDirectory();
        $storage = $this->makeStorage($workingDirectory, true);

        $storage->blockExtensions(['.PHP', 'tar.gz']);

        $this->assertSame(['php', 'tar', 'gz'], $storage->getBlockedExtensions());

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Files with the extension "php" cannot be stored');

        $storage->upload($this->makeHostileFileInfo('shell.php'));
    }

    /**
     * An empty entry would otherwise match the empty component `evil..jpg` splits into, so a
     * stray comma in a caller's list quietly refuses names that carry a doubled dot.
     */
    public function testEmptyDenyListEntriesMatchNothing(): void
    {
        $workingDirectory = $this->makeWorkingDirectory();
        $storage = $this->makeStorage($workingDirectory, true);
        $storage->blockExtensions(['php', '', '.', ' ']);

        $this->assertStoredAs('report..jpg', $this->makeHostileFileInfo('report..jpg'));
    }
}
