<?php

namespace GravityPdf\Upload;

use GravityPdf\Upload\Storage\FileSystem;
use GravityPdf\Upload\Validation\Mimetype;
use InvalidArgumentException;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * `FileList` is `File` with a different constructor, so this covers the constructor and then
 * one case of each inherited guarantee — enough to show the inherited machinery reaches a
 * caller-supplied collection unchanged. `FileTest` covers that machinery itself.
 */
class FileListTest extends TestCase
{
    /** @var string */
    protected $assetsDirectory;

    /** @var StorageInterface */
    protected $storage;

    /**
     * Scratch directories created by makeWorkingDirectory(), removed in tear_down()
     * @var string[]
     */
    protected $workingDirectories = [];

    /* phpcs:ignore */
    public function set_up()
    {
        parent::set_up();

        $this->assetsDirectory = __DIR__ . '/assets';

        /* Reports where each file would have landed, so a test can assert which of them were
           handed over and in what order. Nothing here writes; the two tests that go all the
           way to disk build a real `FileSystem` of their own. */
        $storage = $this->getMockBuilder(FileSystem::class)
            ->setConstructorArgs([$this->assetsDirectory])
            ->onlyMethods(['upload'])
            ->getMock();

        $storage->method('upload')->willReturnCallback(static function (FileInfoInterface $fileInfo): string {
            return '/tmp/uploads/' . $fileInfo->getNameWithExtension();
        });

        $this->storage = $storage;
    }

    /* phpcs:ignore */
    public function tear_down()
    {
        foreach ($this->workingDirectories as $workingDirectory) {
            /* scandir() rather than glob(), so a name glob would treat as a pattern is still
               swept; @ throughout, since a test is free to clean up after itself */
            $entries = is_dir($workingDirectory) ? scandir($workingDirectory) : false;

            foreach (array_diff($entries === false ? [] : $entries, ['.', '..']) as $entry) {
                @unlink($workingDirectory . '/' . $entry);
            }

            @rmdir($workingDirectory);
            @rmdir(dirname($workingDirectory));
        }

        $this->workingDirectories = [];

        parent::tear_down();
    }

    /********************************************************************************
     * Construction tests
     *******************************************************************************/

    public function testConstructionWithASingleFile(): void
    {
        $list = new FileList([$this->vouchedFile('foo.txt')], $this->storage);

        $this->assertCount(1, $list);
        $this->assertSame('foo.txt', $list[0]->getNameWithExtension()); /* @phpstan-ignore-line */

        /* __call() returns the file's own value when there is exactly one */
        $this->assertSame('foo.txt', $list->getNameWithExtension());
        $this->assertSame('foo', $list->getName());
        $this->assertSame('txt', $list->getExtension());
        $this->assertSame([], $list->getErrors());
    }

    public function testConstructionWithSeveralFiles(): void
    {
        $list = new FileList(
            [$this->vouchedFile('foo.txt'), $this->vouchedFile('bar.txt')],
            $this->storage
        );

        $this->assertCount(2, $list);

        /* …and an array when there is more than one */
        $this->assertSame(['foo.txt', 'bar.txt'], $list->getNameWithExtension());

        $names = [];
        foreach ($list as $index => $fileInfo) {
            $names[$index] = $fileInfo->getNameWithExtension();
        }

        $this->assertSame(['foo.txt', 'bar.txt'], $names);
    }

    public function testConstructionWithAnEmptyList(): void
    {
        $list = new FileList([], $this->storage);

        $this->assertCount(0, $list);
        $this->assertNull($list[0]);
        $this->assertNull($list->getNameWithExtension());
        $this->assertSame([], $list->getErrors());
        $this->assertTrue($list->isValid());
    }

    /**
     * Validations pass vacuously against nothing, so the inherited guard is what stops an
     * empty collection reporting a successful upload.
     *
     * @dataProvider provideUploadEntryPoints
     */
    public function testAnEmptyListStillRefusesToReportSuccess(string $method): void
    {
        $list = new FileList([], $this->storage);
        $list->addValidation(new Mimetype(['text/plain']));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('There are no files to upload');

        $list->$method();
    }

    /** @return array<string, array{0: string}> */
    public function provideUploadEntryPoints(): array
    {
        return [
            'upload()' => ['upload'],
            'uploadValid()' => ['uploadValid'],
        ];
    }

    /**
     * `$_FILES` is remote input, so `File` records a malformed entry there and carries on.
     * This array is assembled by the developer, so a wrong type is a bug in the program —
     * the same reasoning, and the same exception, as `File::offsetSet()`.
     *
     * @dataProvider provideEntriesThatAreNotFileInfos
     *
     * @param mixed $entry
     */
    public function testRejectsAnEntryThatIsNotAFileInfo($entry): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be an instance of ' . FileInfoInterface::class);

        new FileList([$this->vouchedFile('foo.txt'), $entry], $this->storage);
    }

    /** @return array<string, array<int, mixed>> */
    public function provideEntriesThatAreNotFileInfos(): array
    {
        return [
            'a path instead of the object' => [__DIR__ . '/assets/foo.txt'],
            'null' => [null],
            'the raw $_FILES entry' => [['name' => 'foo.txt', 'tmp_name' => '/tmp/php1', 'error' => 0]],
            /* PSR-7's getUploadedFiles() is a tree; the collection is flat, so a caller
               flattens it rather than this class guessing at names for the levels */
            'a nested array of files' => [[new FileInfo(__DIR__ . '/assets/foo.txt', 'foo.txt')]],
            'an unrelated object' => [new \stdClass()],
        ];
    }

    /**
     * @dataProvider provideMalformedFailurePairs
     *
     * @param mixed $failure
     */
    public function testRejectsAMalformedFailurePair($failure): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a [string $clientFilename, int $errorCode] pair');

        new FileList([], $this->storage, [$failure]);
    }

    /** @return array<string, array<int, mixed>> */
    public function provideMalformedFailurePairs(): array
    {
        return [
            'not an array' => ['huge.txt'],
            'no code' => [['huge.txt']],
            'keyed by name' => [['name' => 'huge.txt', 'error' => UPLOAD_ERR_INI_SIZE]],
            'a filename that is not a string' => [[null, UPLOAD_ERR_INI_SIZE]],
            'a code that is not an int' => [['huge.txt', (string) UPLOAD_ERR_INI_SIZE]],
            'the pair the wrong way round' => [[UPLOAD_ERR_INI_SIZE, 'huge.txt']],
        ];
    }

    /**
     * The key naming the offending entry is often a `multipart/form-data` field name the
     * client chose, and this message is written to be logged, so it is sanitized for the same
     * reason every other string this library emits is.
     */
    public function testAHostileKeyIsSanitizedBeforeItReachesTheExceptionMessage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Entry "av atar" must be an instance of');

        new FileList(["av\natar" => 'not a FileInfo'], $this->storage);
    }

    /**
     * The filename rules would report this as `user-avatar`, and a key called `con` as
     * `unnamed-file`. The developer has to recognise what they sent.
     */
    public function testAKeyIsNotPutThroughTheFilenameRules(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Entry "user.avatar" must be an instance of');

        new FileList(['user.avatar' => 'not a FileInfo'], $this->storage);
    }

    /**
     * `$objects` is a list, `getUploadedLocators()` is keyed by collection offset, and the
     * class is annotated `ArrayAccess<int, FileInfoInterface>`; keeping a caller's own keys
     * would contradict all three, so they are discarded.
     */
    public function testKeysOnTheSuppliedArrayAreDiscarded(): void
    {
        $list = new FileList(
            [
                'avatar' => $this->vouchedFile('foo.txt'),
                7 => $this->vouchedFile('bar.txt'),
            ],
            $this->storage
        );

        $this->assertSame('foo.txt', $list[0]->getNameWithExtension()); /* @phpstan-ignore-line */
        $this->assertSame('bar.txt', $list[1]->getNameWithExtension()); /* @phpstan-ignore-line */
        /* The collection is keyed 0..n, with the caller's keys kept beside it */
        $this->assertSame([0, 1], array_keys(iterator_to_array($list)));
        $this->assertSame(['avatar', 7], $list->getSourceKeys());

        $list->allowUnvalidatedUploads();

        $this->assertTrue($list->upload());
        $this->assertSame(
            ['/tmp/uploads/foo.txt', '/tmp/uploads/bar.txt'],
            $list->getUploadedLocators()
        );
    }

    /** A list in gives the collection's own offsets back, which say nothing new */
    public function testTheSourceKeysOfAListAreItsOwnOffsets(): void
    {
        $list = new FileList(
            [$this->vouchedFile('foo.txt'), $this->vouchedFile('bar.txt')],
            $this->storage
        );

        $this->assertSame([0, 1], $list->getSourceKeys());
        $this->assertSame([], (new FileList([], $this->storage))->getSourceKeys());
    }

    /**
     * The collection is not frozen after construction — `offsetSet()` and `offsetUnset()` are
     * inherited and public — so a key must never outlive the file it named.
     */
    public function testTheSourceKeysDoNotOutliveTheFilesTheyName(): void
    {
        $list = new FileList(
            [
                'avatar' => $this->vouchedFile('foo.txt'),
                'banner' => $this->vouchedFile('bar.txt'),
            ],
            $this->storage
        );

        $list[0] = $this->vouchedFile('single.txt');
        unset($list[1]);

        $this->assertSame([], $list->getSourceKeys());
    }

    /********************************************************************************
     * Failed-transfer tests
     *******************************************************************************/

    /**
     * A caller's bridge reports a transfer that never happened through `$failures`, and the
     * README encourages rendering `getErrors()` directly, so the string has to be the one the
     * `$_FILES` path produces — down to the byte, and with the same sanitizing.
     */
    public function testFailuresReadExactlyAsTheFilesPathReportsThem(): void
    {
        $_FILES['bad'] = [
            'name' => "hu\nge.txt",
            'tmp_name' => '',
            'error' => UPLOAD_ERR_INI_SIZE,
        ];

        $list = new FileList([], $this->storage, [["hu\nge.txt", UPLOAD_ERR_INI_SIZE]]);

        $this->assertSame((new File('bad', $this->storage))->getErrors(), $list->getErrors());
        $this->assertSame(
            ['hu-ge.txt: The uploaded file exceeds the upload_max_filesize directive in php.ini'],
            $list->getErrors()
        );
    }

    /**
     * The constructor snapshots its own errors so `isValid()` can reset to them rather than
     * appending, which the `if (!$file->isValid()) {…} $file->upload();` pattern would double.
     */
    public function testValidatingTwiceDoesNotDoubleTheFailures(): void
    {
        $list = new FileList(
            [$this->vouchedFile('foo.txt')],
            $this->storage,
            [['huge.txt', UPLOAD_ERR_INI_SIZE]]
        );
        $list->addValidation(new Mimetype(['text/plain']));

        $this->assertFalse($list->isValid());
        $this->assertCount(1, $list->getErrors());

        $this->assertFalse($list->isValid());
        $this->assertCount(1, $list->getErrors());
    }

    /**
     * A file the caller asked for and did not get is still a rejection, so it decides the
     * return value even though every file that did arrive was stored.
     */
    public function testUploadValidCountsAFailedTransferAsRejected(): void
    {
        $list = new FileList(
            [$this->vouchedFile('foo.txt')],
            $this->storage,
            [['huge.txt', UPLOAD_ERR_INI_SIZE]]
        );
        $list->addValidation(new Mimetype(['text/plain']));

        $this->assertFalse($list->uploadValid());
        $this->assertSame(['/tmp/uploads/foo.txt'], $list->getUploadedLocators());
        $this->assertSame(
            ['huge.txt: The uploaded file exceeds the upload_max_filesize directive in php.ini'],
            $list->getErrors()
        );
    }

    /********************************************************************************
     * Provenance tests
     *******************************************************************************/

    /**
     * The uploaded-file check is live on this path: a plain `FileInfo` over a path the SAPI
     * did not write is rejected, so a caller cannot get files stored without deciding what
     * their runtime can vouch for.
     */
    public function testAPlainFileInfoIsRejectedAsNotAnUploadedFile(): void
    {
        $list = new FileList(
            [new FileInfo($this->assetsDirectory . '/foo.txt', 'foo.txt')],
            $this->storage
        );
        $list->addValidation(new Mimetype(['text/plain']));

        $this->assertFalse($list->isValid());
        $this->assertSame(['foo.txt: Is not an uploaded file'], $list->getErrors());
    }

    /**
     * The whole point of the class, end to end through a real `Storage\FileSystem` with
     * nothing mocked: a `FileInfo` subclass that vouches for provenance validates, is
     * sanitized on the way in, and lands under the storage layer's own rules at the
     * configured mode.
     *
     * Nothing is stubbed here on purpose. Mocking `moveUploadedFile()` — the obvious way to
     * write this, since a test's own file is not a POST upload — hides the fact that a
     * caller's file is not one either, and the whole path reported green while it could not
     * store a byte.
     */
    public function testAVouchedFileIsValidatedSanitizedAndStored(): void
    {
        $workingDirectory = $this->makeWorkingDirectory();
        $tmp = $this->tmpCopyOf('foo.txt');

        $storage = new FileSystem($workingDirectory);
        $storage->acceptFilesNotUploadedByPhp();

        $list = new FileList([new VouchedFileInfo($tmp, 'holiday.photo.txt')], $storage);
        $list->addValidation(new Mimetype(['text/plain']));

        $this->assertTrue($list->upload());

        /* The interior dot is rewritten by `FileInfo::setName()`, as on the `$_FILES` path */
        $stored = $workingDirectory . '/holiday-photo.txt';

        $this->assertSame([$stored], $list->getUploadedLocators());
        $this->assertFileExists($stored);
        $this->assertSame(FileSystem::DEFAULT_MODE, fileperms($stored) & 0777);
        $this->assertStringEqualsFile($stored, (string) file_get_contents($this->assetsDirectory . '/foo.txt'));
    }

    /**
     * Storage without that opt-in cannot move a file PHP never received, so a `FileList`
     * stores nothing at all — the pairing is what makes the class usable, and neither half
     * of it is a default.
     */
    public function testAVouchedFileIsStillRefusedByStorageThatWasNotToldToAcceptIt(): void
    {
        $workingDirectory = $this->makeWorkingDirectory();
        $tmp = $this->tmpCopyOf('foo.txt');

        $list = new FileList(
            [new VouchedFileInfo($tmp, 'foo.txt')],
            new FileSystem($workingDirectory)
        );
        $list->addValidation(new Mimetype(['text/plain']));

        $this->assertTrue($list->isValid());

        try {
            $list->upload();
            $this->fail('Expected storage to refuse a file PHP did not receive as an upload');
        } catch (Exception $e) {
            $this->assertSame('File could not be moved to final destination.', $e->getMessage());
        }

        $this->assertSame([], $list->getUploadedLocators());
        $this->assertFileExists($tmp);
    }

    /********************************************************************************
     * Inherited-behaviour tests
     *******************************************************************************/

    /**
     * The guard is a configuration check on the developer, not a per-file failure, so it
     * reaches a `FileList` for the same reason it reaches a `File`.
     */
    public function testUploadRefusesWhenNothingHasBeenConfiguredToValidate(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No validations have been added');

        (new FileList([$this->vouchedFile('foo.txt')], $this->storage))->upload();
    }

    public function testAllowUnvalidatedUploadsMakesItADecision(): void
    {
        $list = new FileList([$this->vouchedFile('foo.txt')], $this->storage);
        $list->allowUnvalidatedUploads();

        $this->assertTrue($list->upload());
    }

    public function testTheLifecycleCallbacksFirePerFile(): void
    {
        $this->expectOutputString(
            "BeforeValidate: foo\n" .
            "AfterValidate: foo\n" .
            "BeforeValidate: bar\n" .
            "AfterValidate: bar\n" .
            "BeforeUpload: foo\n" .
            "AfterUpload: foo\n" .
            "BeforeUpload: bar\n" .
            "AfterUpload: bar\n"
        );

        $list = new FileList(
            [$this->vouchedFile('foo.txt'), $this->vouchedFile('bar.txt')],
            $this->storage
        );

        foreach (['beforeValidate', 'afterValidate', 'beforeUpload', 'afterUpload'] as $hook) {
            $list->$hook(static function (FileInfoInterface $fileInfo) use ($hook): void {
                echo ucfirst($hook) . ': ' . $fileInfo->getName(), PHP_EOL;
            });
        }

        $list->allowUnvalidatedUploads();
        $list->upload();
    }

    /**
     * A run owns the error and locator lists, so a callback that re-enters the collection is
     * refused here exactly as it is on `File`.
     */
    public function testACallbackCannotReEnterTheCollection(): void
    {
        $list = new FileList([$this->vouchedFile('foo.txt')], $this->storage);
        $list->addValidation(new Mimetype(['text/plain']));
        $list->beforeValidate(static function () use (&$list): void {
            $list->isValid();
        });

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('cannot be called while one of them is already running');

        $list->uploadValid();
    }

    /**
     * `uploadValid()` stores a subset, and the locators stay keyed by collection offset so a
     * caller pairing them with a manifest cannot attribute a stored file to the metadata of
     * one that was rejected. The source keys are offset-keyed for the same reason, so they
     * are still the caller's name for the file a locator belongs to after a partial batch.
     */
    public function testUploadValidKeepsTheLocatorsOffsetKeyed(): void
    {
        $list = new FileList(
            [
                'portrait' => $this->vouchedFile('foo.png'),
                'notes' => $this->vouchedFile('bar.txt'),
            ],
            $this->storage
        );
        $list->addValidation(new Mimetype(['text/plain']));

        $this->assertFalse($list->uploadValid());
        $this->assertCount(1, $list->getErrors());

        $locators = $list->getUploadedLocators();

        $this->assertSame([1 => '/tmp/uploads/bar.txt'], $locators);
        $this->assertSame('notes', $list->getSourceKeys()[array_key_first($locators)]);
    }

    /********************************************************************************
     * Helpers
     *******************************************************************************/

    /**
     * A file from the assets directory that vouches for its own provenance, which is what a
     * caller's bridge has to supply for anything to validate
     */
    protected function vouchedFile(string $name): FileInfoInterface
    {
        return new VouchedFileInfo($this->assetsDirectory . '/' . $name, $name);
    }

    /**
     * A copy of an asset in a tmp directory, standing in for a file a caller's own bridge
     * wrote there
     *
     * Copied rather than used where it lies, because storing it is a *move* and the assets
     * are fixtures the rest of the suite still needs.
     */
    protected function tmpCopyOf(string $name): string
    {
        $tmp = $this->makeWorkingDirectory() . '/' . $name;
        copy($this->assetsDirectory . '/' . $name, $tmp);

        return $tmp;
    }

    protected function makeWorkingDirectory(): string
    {
        $workingDirectory = sys_get_temp_dir() . '/upload-test-' . uniqid('', true) . '/uploads';
        mkdir($workingDirectory, 0777, true);
        $this->workingDirectories[] = $workingDirectory;

        return $workingDirectory;
    }
}
