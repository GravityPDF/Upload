<?php

namespace GravityPdf\Upload;

use GravityPdf\Upload\Storage\FileSystem;
use GravityPdf\Upload\Validation\Mimetype;
use GravityPdf\Upload\Validation\Size;
use GravityPdf\Upload\ValidationInterface;
use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

class FileTest extends TestCase
{
    /**
     * @var string
     */
    protected $assetsDirectory;

    /**
     * @var StorageInterface
     */
    protected $storage;

    /* phpcs:ignore */
    public function set_up()
    {
        parent::set_up();

        // Set FileInfo factory
        $this->stubUploadedFileCheck(true);

        // Path to test assets
        $this->assetsDirectory = __DIR__ . '/assets';

        // Mock storage
        $this->storage = $this->getMockBuilder(FileSystem::class)
            ->setConstructorArgs([$this->assetsDirectory])
            ->onlyMethods(['upload'])
            ->getMock();

        // Prepare uploaded files
        $_FILES['multiple'] = [
            'name' => [
                'foo.txt',
                'bar.txt',
            ],
            'tmp_name' => [
                $this->assetsDirectory . '/foo.txt',
                $this->assetsDirectory . '/bar.txt',
            ],
            'error' => [
                UPLOAD_ERR_OK,
                UPLOAD_ERR_OK,
            ],
        ];
        $_FILES['single'] = [
            'name' => 'single.txt',
            'tmp_name' => $this->assetsDirectory . '/single.txt',
            'error' => UPLOAD_ERR_OK,
        ];
        $_FILES['bad'] = [
            'name' => 'single.txt',
            'tmp_name' => $this->assetsDirectory . '/single.txt',
            'error' => UPLOAD_ERR_INI_SIZE,
        ];
    }

    /**
     * Install a `FileInfo` factory whose `isUploadedFile()` answers `$result`
     *
     * `is_uploaded_file()` is false for anything a test writes itself, so every test needs the
     * double. `tear_down()` calls `resetFactory()`, since the factory is process-wide.
     *
     * @param bool $result
     * @return void
     */
    protected function stubUploadedFileCheck(bool $result): void
    {
        FileInfo::setFactory(function ($tmpName, $name) use ($result) {
            $fileInfo = $this->getMockBuilder(FileInfo::class)
                ->setConstructorArgs([$tmpName, $name])
                ->onlyMethods(['isUploadedFile'])
                ->getMock();

            $fileInfo->method('isUploadedFile')->willReturn($result);

            return $fileInfo;
        });
    }

    /* phpcs:ignore */
    public function tear_down()
    {
        /* The factory is process-wide state; leaving it set leaks into every later test */
        FileInfo::resetFactory();

        parent::tear_down();
    }

    /********************************************************************************
     * Construction tests
     *******************************************************************************/

    public function testConstructionWithMultipleFiles(): void
    {
        $file = new File('multiple', $this->storage);
        $this->assertCount(2, $file);
        $this->assertSame('foo.txt', $file[0]->getNameWithExtension()); /* @phpstan-ignore-line */
        $this->assertSame('bar.txt', $file[1]->getNameWithExtension()); /* @phpstan-ignore-line */

        /* Test __call() magic method */
        $this->assertSame(['foo.txt', 'bar.txt'], $file->getNameWithExtension());
        $this->assertSame(['foo', 'bar'], $file->getName());
        $this->assertSame(['txt', 'txt'], $file->getExtension());

        $file->setName('foobar');
        $this->assertSame(['foobar', 'foobar'], $file->getName());

        $file->setExtension('rtf');
        $this->assertSame(['rtf', 'rtf'], $file->getExtension());

        $file->setNameWithExtension('this.txt');
        $this->assertSame(['this.txt', 'this.txt'], $file->getNameWithExtension());

        /* Test array accessor */
        foreach ($file as $i => $upload) {
            $upload->setNameWithExtension(sprintf('file-%d.doc', $i + 1));
        }

        $this->assertCount(2, $file);
        $this->assertSame('file-1.doc', $file[0]->getNameWithExtension()); /* @phpstan-ignore-line */
        $this->assertSame('file-2.doc', $file[1]->getNameWithExtension()); /* @phpstan-ignore-line */
    }

    public function testConstructionWithSingleFile(): void
    {
        $file = new File('single', $this->storage);
        $this->assertCount(1, $file);
        $this->assertSame('single.txt', $file[0]->getNameWithExtension()); /* @phpstan-ignore-line */

        /* Test __call() magic method */
        $this->assertSame('single.txt', $file->getNameWithExtension());
        $this->assertSame('single', $file->getName());
        $this->assertSame('txt', $file->getExtension());

        $file->setName('foobar');
        $this->assertSame('foobar', $file->getName());

        $file->setExtension('rtf');
        $this->assertSame('rtf', $file->getExtension());

        $file->setNameWithExtension('this.txt');
        $this->assertSame('this.txt', $file->getNameWithExtension());
    }

    public function testConstructionWithInvalidKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot find uploaded file(s) identified by key: bar');

        $file = new File('bar', $this->storage);
    }

    /********************************************************************************
     * Callback tests
     *******************************************************************************/

    /**
     * Test callbacks
     *
     * This test will make sure callbacks are called for each FileInfoInterface
     * object in the correct order.
     */
    public function testCallbacks(): void
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

        $callbackBeforeValidate = function (FileInfoInterface $fileInfo) {
            echo 'BeforeValidate: ' . $fileInfo->getName(), PHP_EOL;
        };

        $callbackAfterValidate = function (FileInfoInterface $fileInfo) {
            echo 'AfterValidate: ' . $fileInfo->getName(), PHP_EOL;
        };

        $callbackBeforeUpload = function (FileInfoInterface $fileInfo) {
            echo 'BeforeUpload: ' . $fileInfo->getName(), PHP_EOL;
        };

        $callbackAfterUpload = function (FileInfoInterface $fileInfo) {
            echo 'AfterUpload: ' . $fileInfo->getName(), PHP_EOL;
        };

        $file = new File('multiple', $this->storage);
        $file->beforeValidate($callbackBeforeValidate);
        $file->afterValidate($callbackAfterValidate);
        $file->beforeUpload($callbackBeforeUpload);
        $file->afterUpload($callbackAfterUpload);
        $file->allowUnvalidatedUploads();
        $file->upload();
    }

    /********************************************************************************
     * Validation tests
     *******************************************************************************/

    public function testAddSingleValidation(): void
    {
        $file = new File('single', $this->storage);
        $file->addValidation(
            new Mimetype([
                'text/plain',
            ])
        );

        $this->assertCount(1, $file->getValidations());
    }

    public function testAddMultipleValidations(): void
    {
        $file = new File('single', $this->storage);
        $file->addValidations([
            new Mimetype([
                'text/plain',
            ]),
            new Size(50) // minimum bytesize
        ]);

        $this->assertCount(2, $file->getValidations());
    }

    public function testIsValidIfNoValidations(): void
    {
        $file = new File('single', $this->storage);
        $this->assertTrue($file->isValid());
    }

    public function testIsValidWithPassingValidations(): void
    {
        $file = new File('single', $this->storage);
        $file->addValidation(
            new Mimetype([
                'text/plain',
            ])
        );
        $this->assertTrue($file->isValid());
    }

    public function testIsMultipleValidWithPassingValidations(): void
    {
        $file = new File('multiple', $this->storage);
        $file->addValidation(
            new Mimetype([
                'text/plain',
            ])
        );
        $this->assertTrue($file->isValid());
    }

    public function testIsInvalidWithFailingValidations(): void
    {
        $file = new File('single', $this->storage);
        $file->addValidation(
            new Mimetype([
                'text/csv',
            ])
        );
        $this->assertFalse($file->isValid());
    }

    public function testIsInvalidIfHttpErrorCode(): void
    {
        $file = new File('bad', $this->storage);
        $this->assertFalse($file->isValid());
    }

    public function testIsInvalidIfNotUploadedFile(): void
    {
        $this->stubUploadedFileCheck(false);

        $file = new File('single', $this->storage);
        $this->assertFalse($file->isValid());
    }

    /********************************************************************************
     * Error message tests
     *******************************************************************************/

    public function testGetErrors(): void
    {
        $file = new File('single', $this->storage);
        $file->addValidation(
            new Mimetype([
                'text/csv',
            ])
        );
        $file->isValid();
        $this->assertCount(1, $file->getErrors());
    }

    /**
     * `getErrors()` is documented as something callers render directly, and a
     * `FileInfoInterface` is a public extension point, so the name one returns has not
     * necessarily been through `FileInfo::setName()`. A line break here forges a log line.
     */
    public function testNameFromACustomFileInfoIsSanitizedBeforeReachingGetErrors(): void
    {
        FileInfo::setFactory(function ($tmpName, $name) {
            $fileInfo = $this->getMockBuilder(FileInfo::class)
                ->setConstructorArgs([$tmpName, $name])
                ->onlyMethods(['isUploadedFile', 'getNameWithExtension'])
                ->getMock();

            $fileInfo->method('isUploadedFile')->willReturn(false);
            $fileInfo
                ->method('getNameWithExtension')
                ->willReturn("report\n2024-01-01 INFO all clear.txt");

            return $fileInfo;
        });

        $file = new File('single', $this->storage);
        $file->addValidation(new Mimetype(['text/plain']));

        $this->assertFalse($file->isValid());

        /* The line break is gone; the rest of the name survives, so the message still says
           which file it is about */
        $this->assertSame(
            ['report-2024-01-01 INFO all clear.txt: Is not an uploaded file'],
            $file->getErrors()
        );
    }

    public function testMultipleGetErrors(): void
    {
        $file = new File('multiple', $this->storage);
        $file->addValidation(
            new Mimetype([
                'text/csv',
            ])
        );
        $file->isValid();
        $this->assertCount(2, $file->getErrors());
    }

    /********************************************************************************
     * Upload tests
     *******************************************************************************/

    public function testWillUploadIfValid(): void
    {
        $file = new File('single', $this->storage);
        $file->addValidation(new Mimetype(['text/plain']));
        $this->assertTrue($file->isValid());

        try {
            $file->upload();
            $this->addToAssertionCount(1);
        } catch (\Exception $e) {
            $this->fail('Unexpected exception thrown');
        }
    }

    public function testWillNotUploadIfInvalid(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('File validation failed');

        $file = new File('bad', $this->storage);
        $file->addValidation(new Mimetype(['text/plain']));
        $this->assertFalse($file->isValid());
        $file->upload();
    }

    /**
     * An endpoint that validates nothing stores whatever is submitted, which is more often an
     * unfinished endpoint than a decision.
     */
    public function testUploadRefusesWhenNothingHasBeenConfiguredToValidate(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No validations have been added');

        (new File('single', $this->storage))->upload();
    }


    public function testAllowUnvalidatedUploadsMakesItADecision(): void
    {
        $file = new File('single', $this->storage);
        $file->allowUnvalidatedUploads();

        $this->assertTrue($file->upload());
    }

    /********************************************************************************
     * Helper tests
     *******************************************************************************/

    public function testParsesHumanFriendlyFileSizes(): void
    {
        $this->assertEquals(100, File::humanReadableToBytes('100'));
        $this->assertEquals(102400, File::humanReadableToBytes('100K'));
        $this->assertEquals(104857600, File::humanReadableToBytes('100M'));
        $this->assertEquals(107374182400, File::humanReadableToBytes('100G'));
    }

    /**
     * The README used to promise the opposite. `upload()` validates and only then enters the
     * loop that fires the hook, so a name set there is never validated — the storage deny-list
     * and `FileSystem`'s filename rules are all that still apply to it.
     */
    public function testBeforeUploadRunsAfterValidation(): void
    {
        $order = [];

        $storage = $this->getMockBuilder(FileSystem::class)
            ->setConstructorArgs([$this->assetsDirectory])
            ->onlyMethods(['upload'])
            ->getMock();

        $storage->method('upload')->willReturnCallback(static function () use (&$order) {
            $order[] = 'store';

            return '/tmp/uploads/foo.txt';
        });

        $file = new File('single', $storage);
        $file->addValidation(new Mimetype(['text/plain']));
        $file->beforeValidate(static function () use (&$order): void {
            $order[] = 'beforeValidate';
        });
        $file->beforeUpload(static function () use (&$order): void {
            $order[] = 'beforeUpload';
        });

        $file->upload();

        $this->assertSame(['beforeValidate', 'beforeUpload', 'store'], $order);
    }

    /**
     * The two validation hooks are documented as a matched pair firing once per file, so a
     * caller can use them to open and close a per-file resource. A file that fails the
     * uploaded-file check must not fire only the first half of the pair.
     */
    public function testAfterValidateFiresForAFileThatFailsTheUploadedFileCheck(): void
    {
        $this->stubUploadedFileCheck(false);

        $order = [];

        $file = new File('single', $this->storage);
        $file->addValidation(new Mimetype(['text/plain']));
        $file->beforeValidate(static function () use (&$order): void {
            $order[] = 'before';
        });
        $file->afterValidate(static function () use (&$order): void {
            $order[] = 'after';
        });

        $this->assertFalse($file->isValid());
        $this->assertSame(['before', 'after'], $order);
    }

    /**
     * A multi-file entry whose other keys are not arrays of the same length. `name` as a string
     * is the dangerous one: indexing it yields a single character, which is a string, so it
     * passed the per-file check and would have stored the file under a one-letter name.
     *
     * @dataProvider providerMalformedMultiFileEntries
     *
     * @param array<string, mixed> $entry
     */
    public function testMalformedMultiFileEntryIsReportedNotFatal(array $entry): void
    {
        $_FILES['multiple'] = $entry;

        $file = new File('multiple', $this->storage);

        $this->assertFalse($file->isValid());
        $this->assertCount(0, $file);
        $this->assertSame(
            ['An uploaded file was sent in a format that cannot be read'],
            $file->getErrors()
        );
    }

    /**
     * The entry itself, before any of its keys. A `$_FILES[$key]` that is not an array made
     * `['tmp_name']` an uncaught TypeError on PHP 8, and a missing key raised a warning that
     * a framework error handler turns into an exception out of the constructor.
     *
     * @dataProvider providerMalformedEntries
     *
     * @param mixed $entry
     */
    public function testMalformedEntryIsReportedWithoutWarningOrFatal($entry): void
    {
        $_FILES['single'] = $entry;

        $raised = [];
        set_error_handler(static function ($number, $message) use (&$raised): bool {
            $raised[] = $message;

            return true;
        });

        try {
            $file = new File('single', $this->storage);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $raised, 'A malformed entry must not raise a PHP warning');
        $this->assertCount(0, $file);
        $this->assertSame(
            ['An uploaded file was sent in a format that cannot be read'],
            $file->getErrors()
        );
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function providerMalformedEntries(): array
    {
        $tmpName = __DIR__ . '/assets/single.txt';

        return [
            'entry is a string' => ['not-an-array'],
            'entry is an int' => [42],
            'tmp_name absent' => [['name' => 'a.txt', 'error' => UPLOAD_ERR_OK]],
            'name absent' => [['tmp_name' => $tmpName, 'error' => UPLOAD_ERR_OK]],
            'error absent' => [['tmp_name' => $tmpName, 'name' => 'a.txt']],
            'error is an array' => [['tmp_name' => $tmpName, 'name' => 'a.txt', 'error' => [0]]],
            'error is a numeric string' => [['tmp_name' => $tmpName, 'name' => 'a.txt', 'error' => '0']],
            'tmp_name is an int' => [['tmp_name' => 123, 'name' => 'a.txt', 'error' => UPLOAD_ERR_OK]],
            'name is an int' => [['tmp_name' => $tmpName, 'name' => 42, 'error' => UPLOAD_ERR_OK]],
        ];
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function providerMalformedMultiFileEntries(): array
    {
        $tmpName = __DIR__ . '/assets/foo.txt';

        return [
            'error is a scalar' => [[
                'name' => ['foo.txt'],
                'tmp_name' => [$tmpName],
                'error' => UPLOAD_ERR_OK,
            ]],
            'name is a scalar' => [[
                'name' => 'foo.txt',
                'tmp_name' => [$tmpName],
                'error' => [UPLOAD_ERR_OK],
            ]],
            'error is absent' => [[
                'name' => ['foo.txt'],
                'tmp_name' => [$tmpName],
            ]],
            'error is a numeric string' => [[
                'name' => ['foo.txt'],
                'tmp_name' => [$tmpName],
                'error' => ['0'],
            ]],
            'name array is short' => [[
                'name' => [],
                'tmp_name' => [$tmpName],
                'error' => [UPLOAD_ERR_OK],
            ]],
            'error array is short' => [[
                'name' => ['foo.txt'],
                'tmp_name' => [$tmpName],
                'error' => [],
            ]],
        ];
    }

    /**
     * One malformed entry does not cost the well-formed ones in the same request.
     */
    public function testAWellFormedFileSurvivesAMalformedSiblingEntry(): void
    {
        $_FILES['multiple'] = [
            'name' => ['foo.txt', 'bar.txt'],
            'tmp_name' => [
                $this->assetsDirectory . '/foo.txt',
                $this->assetsDirectory . '/bar.txt',
            ],
            'error' => [UPLOAD_ERR_OK, 'nonsense'],
        ];

        $file = new File('multiple', $this->storage);

        $this->assertCount(1, $file);
        $this->assertSame('foo', $file[0]->getName()); /* @phpstan-ignore-line */
        $this->assertSame(
            ['An uploaded file was sent in a format that cannot be read'],
            $file->getErrors()
        );
    }


    /**
     * A LogicException is a bug in the program, not a file that failed. Absorbing it would
     * report a validator's own mistake to the end user as a rejected upload and leave the
     * developer nothing to go on.
     */
    public function testALogicExceptionFromAValidatorReachesTheDeveloper(): void
    {
        $validation = $this->createMock(ValidationInterface::class);
        $validation
            ->method('validate')
            ->willThrowException(new \InvalidArgumentException('Unsupported hashing algorithm: sha255'));

        $file = new File('single', $this->storage);
        $file->addValidation($validation);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported hashing algorithm: sha255');

        $file->isValid();
    }

    /**
     * The other side of the same catch: a runtime failure inside a validator is still absorbed,
     * so one flaky dependency cannot abort the batch.
     */
    public function testARuntimeFailureFromAValidatorIsStillAbsorbed(): void
    {
        $validation = $this->createMock(ValidationInterface::class);
        $validation
            ->method('validate')
            ->willThrowException(new \RuntimeException('clamd unreachable'));

        $file = new File('single', $this->storage);
        $file->addValidation($validation);

        $this->assertFalse($file->isValid());
        $this->assertSame(['single.txt: Validation could not be completed'], $file->getErrors());
    }

    /**
     * The message from a non-Upload throwable is dropped because a PHP runtime message can hold
     * an absolute path. The class name is the application's internal structure and reaches the
     * same string, so it goes too.
     */
    public function testAnUnexpectedValidatorFailureLeaksNothingAboutTheValidator(): void
    {
        $validation = $this->createMock(ValidationInterface::class);
        $validation
            ->method('validate')
            ->willThrowException(new \RuntimeException('/var/secret/path exploded'));

        $file = new File('single', $this->storage);
        $file->addValidation($validation);
        $file->isValid();

        $reported = implode("\n", $file->getErrors());

        $this->assertStringNotContainsString('/var/secret/path', $reported);
        $this->assertStringNotContainsString('RuntimeException', $reported);
    }

    /**
     * The list is reset before the guards, not after them, so a throw cannot hand back paths
     * that an earlier successful call stored — which the documented rollback loop would unlink.
     */
    public function testFailedUploadDoesNotReportAnEarlierCallsFiles(): void
    {
        $storage = $this->getMockBuilder(FileSystem::class)
            ->setConstructorArgs([$this->assetsDirectory])
            ->onlyMethods(['upload'])
            ->getMock();

        $storage->method('upload')->willReturn('/tmp/uploads/foo.txt');

        $file = new File('single', $storage);
        $file->allowUnvalidatedUploads();
        $file->upload();

        $this->assertSame(['/tmp/uploads/foo.txt'], $file->getUploadedLocators());

        $file->addValidation(new Mimetype(['image/png']));

        try {
            $file->upload();
            $this->fail('Expected the second upload to fail validation');
        } catch (Exception $e) {
            $this->assertSame('File validation failed', $e->getMessage());
        }

        $this->assertSame([], $file->getUploadedLocators());
    }

    public function testParsesFractionalAndSuffixedFileSizes(): void
    {
        $this->assertSame(524288, File::humanReadableToBytes('0.5M'));
        $this->assertSame(1536, File::humanReadableToBytes('1.5K'));

        /* The Size docblock has always advertised "5MB"; it used to evaluate to 5 bytes */
        $this->assertSame(5242880, File::humanReadableToBytes('5MB'));
        $this->assertSame(102400, File::humanReadableToBytes('100KB'));
        $this->assertSame(100, File::humanReadableToBytes(' 100 '));
    }

    public function testOversizedFileSizeIsClampedRatherThanFatal(): void
    {
        $this->assertSame(PHP_INT_MAX, File::humanReadableToBytes('1000000000000G'));
    }

    /**
     * @dataProvider providerUnparseableFileSizes
     */
    public function testRejectsUnparseableFileSizes(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);

        File::humanReadableToBytes($input);
    }

    /**
     * @return array<int, array<int, string>>
     */
    public function providerUnparseableFileSizes(): array
    {
        return [
            ['abc'],
            ['-5M'],
            [''],
            ['M'],

            /* 3.x read an unrecognized unit as bytes, so `Size('1T')` was a one byte bound that
               rejected every upload while reading as a generous one */
            ['1T'],
            ['10P'],
            ['100F'],
            ['5MiB'],
        ];
    }

    /********************************************************************************
     * Hardening regression tests
     *******************************************************************************/

    /**
     * Every other filename reaching getErrors() has been sanitized. The constructor's error
     * path was the exception, and the README tells callers to display getErrors().
     */
    public function testConstructorErrorSanitizesTheClientFilename(): void
    {
        $_FILES['xss'] = [
            'name' => '<img src=x onerror=alert(document.domain)>.png',
            'tmp_name' => '',
            'error' => UPLOAD_ERR_INI_SIZE,
        ];

        $errors = (new File('xss', $this->storage))->getErrors();

        $this->assertCount(1, $errors);
        $this->assertStringNotContainsString('<', $errors[0]);
        $this->assertStringNotContainsString('>', $errors[0]);
        $this->assertStringContainsString('exceeds the upload_max_filesize', $errors[0]);
    }

    /**
     * Submitting a form with an empty file input yields UPLOAD_ERR_NO_FILE and an empty
     * tmp_name. The single-file branch used to build a FileInfo for it anyway, so the
     * README's documented "read the metadata" flow raised an uncaught ValueError.
     */
    public function testEmptyFileInputDoesNotProduceAFileInfo(): void
    {
        $_FILES['empty'] = [
            'name' => '',
            'tmp_name' => '',
            'error' => UPLOAD_ERR_NO_FILE,
        ];

        $file = new File('empty', $this->storage);

        $this->assertCount(0, $file);
        $this->assertFalse($file->isValid());
        $this->assertNull($file->getMimetype());
        $this->assertCount(1, $file->getErrors());
    }

    /**
     * The single-file and multi-file shapes must agree for the same logical condition.
     */
    public function testEmptyFileInputBehavesTheSameForBothFormShapes(): void
    {
        $_FILES['single_empty'] = ['name' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE];
        $_FILES['multi_empty'] = ['name' => [''], 'tmp_name' => [''], 'error' => [UPLOAD_ERR_NO_FILE]];

        $single = new File('single_empty', $this->storage);
        $multi = new File('multi_empty', $this->storage);

        $this->assertSame(count($multi), count($single));
        $this->assertSame($multi->getErrors(), $single->getErrors());
    }

    /**
     * Every validation passes when there is nothing to run it against, so `upload()` reported
     * success having written nothing, and a caller reading that as "the file arrived" was
     * misled.
     */
    public function testUploadRefusesWhenThereIsNothingToStore(): void
    {
        $_FILES['none'] = ['name' => [], 'tmp_name' => [], 'error' => []];

        $file = new File('none', $this->storage);
        $file->allowUnvalidatedUploads();

        $this->assertCount(0, $file);
        $this->assertTrue($file->isValid());
        $this->assertSame([], $file->getErrors());

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('There are no files to upload');

        $file->upload();
    }

    /**
     * That guard sits after isValid(), so a transfer that failed is still reported the way it
     * always was, with the detail in getErrors() rather than in the exception message.
     */
    public function testFailedTransferIsStillReportedAsAValidationFailure(): void
    {
        $file = new File('bad', $this->storage);
        $file->allowUnvalidatedUploads();

        try {
            $file->upload();
            $this->fail('Expected the failed transfer to throw');
        } catch (Exception $e) {
            $this->assertSame('File validation failed', $e->getMessage());
        }

        $this->assertCount(1, $file->getErrors());
    }

    /**
     * A field named `f[0][0]` nests $_FILES one level deeper than either shape this class
     * knows. It is remote input, so it has to fail without emitting "Array to string
     * conversion" and without reporting the file as "Array".
     */
    public function testNestedFileInputIsRejectedWithoutAWarning(): void
    {
        $_FILES['nested'] = [
            'name' => [['evil.txt']],
            'tmp_name' => [[$this->assetsDirectory . '/foo.txt']],
            'error' => [[UPLOAD_ERR_OK]],
        ];

        /** @var string[] $raised */
        $raised = [];
        set_error_handler(static function (int $severity, string $message) use (&$raised): bool {
            $raised[] = $message;

            return true;
        });

        try {
            $file = new File('nested', $this->storage);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $raised);
        $this->assertCount(0, $file);
        $this->assertFalse($file->isValid());
        $this->assertCount(1, $file->getErrors());
        $this->assertStringNotContainsString('Array', $file->getErrors()[0]);
    }

    public function testIsValidDoesNotDuplicateErrorsWhenCalledTwice(): void
    {
        $file = new File('single', $this->storage);
        $file->addValidation(new Mimetype(['text/csv']));

        $this->assertFalse($file->isValid());
        $this->assertFalse($file->isValid());

        $this->assertCount(1, $file->getErrors());
    }

    public function testConstructorErrorsSurviveRevalidation(): void
    {
        $file = new File('bad', $this->storage);

        $this->assertFalse($file->isValid());
        $this->assertFalse($file->isValid());

        $this->assertCount(1, $file->getErrors());
    }

    /**
     * ValidationInterface is an extension point, so a validator throwing something other
     * than Upload\Exception must not abort the batch and bypass error accumulation.
     */
    public function testValidatorThrowingUnexpectedlyIsRecordedNotPropagated(): void
    {
        $file = new File('multiple', $this->storage);
        $file->addValidation(new class implements ValidationInterface {
            public function validate(FileInfoInterface $fileInfo): void
            {
                throw new \RuntimeException('stat failed for /private/tmp/phpXXXX');
            }
        });

        $this->assertFalse($file->isValid());

        $errors = $file->getErrors();
        $this->assertCount(2, $errors);
        foreach ($errors as $error) {
            $this->assertStringContainsString('Validation could not be completed', $error);
            /* PHP runtime messages can contain absolute paths */
            $this->assertStringNotContainsString('/private/tmp', $error);
        }
    }

    public function testOffsetSetRejectsNonFileInfo(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Value must be an instance of');

        $file = new File('single', $this->storage);
        $file[0] = 'not-a-file-info';
    }

    /**
     * A multi-file upload is not atomic, so a caller needs to know what was already written
     * when a later file fails.
     */
    public function testGetUploadedFilesReportsWhatWasWrittenBeforeAFailure(): void
    {
        $storage = $this->getMockBuilder(FileSystem::class)
            ->setConstructorArgs([$this->assetsDirectory])
            ->onlyMethods(['upload'])
            ->getMock();

        $storage
            ->method('upload')
            ->willReturnOnConsecutiveCalls(
                '/tmp/uploads/foo.txt',
                $this->throwException(new Exception('File already exists'))
            );

        $file = new File('multiple', $storage);
        $file->allowUnvalidatedUploads();

        try {
            $file->upload();
            $this->fail('Expected the second file to fail');
        } catch (Exception $e) {
            $this->assertSame('File already exists', $e->getMessage());
        }

        $this->assertSame(['/tmp/uploads/foo.txt'], $file->getUploadedLocators());
        $this->assertCount(0, $file->getErrors());
    }

    public function testGetUploadedFilesOnSuccess(): void
    {
        $storage = $this->getMockBuilder(FileSystem::class)
            ->setConstructorArgs([$this->assetsDirectory])
            ->onlyMethods(['upload'])
            ->getMock();

        $storage
            ->method('upload')
            ->willReturnOnConsecutiveCalls('/tmp/uploads/foo.txt', '/tmp/uploads/bar.txt');

        $file = new File('multiple', $storage);
        $file->allowUnvalidatedUploads();
        $file->upload();

        $this->assertSame(['/tmp/uploads/foo.txt', '/tmp/uploads/bar.txt'], $file->getUploadedLocators());
    }

    /********************************************************************************
     * Partial upload tests
     *******************************************************************************/

    /**
     * A storage double with only `upload()` replaced
     *
     * `$this->storage` is one of these already, but it is typed as the interface, so a test
     * that needs to configure or count the call needs its own.
     *
     * @return FileSystem&MockObject
     */
    protected function mockStorage()
    {
        return $this->getMockBuilder(FileSystem::class)
            ->setConstructorArgs([$this->assetsDirectory])
            ->onlyMethods(['upload'])
            ->getMock();
    }

    /**
     * A storage double that reports where each file would have landed, so a test can assert
     * which of them were handed over and in what order
     */
    protected function storageNamingWhatItStored(): StorageInterface
    {
        $storage = $this->mockStorage();

        $storage->method('upload')->willReturnCallback(static function (FileInfoInterface $fileInfo): string {
            return '/tmp/uploads/' . $fileInfo->getNameWithExtension();
        });

        return $storage;
    }

    /**
     * Install a `FileInfo` factory whose `isUploadedFile()` answers false for the named files
     *
     * `stubUploadedFileCheck()` answers the same for every file; a partial batch needs one
     * file to fail the check while its sibling passes.
     *
     * @param string[] $failing
     */
    protected function stubUploadedFileCheckByName(array $failing): void
    {
        FileInfo::setFactory(function ($tmpName, $name) use ($failing) {
            $fileInfo = $this->getMockBuilder(FileInfo::class)
                ->setConstructorArgs([$tmpName, $name])
                ->onlyMethods(['isUploadedFile'])
                ->getMock();

            $fileInfo->method('isUploadedFile')->willReturn(in_array($name, $failing, true) === false);

            return $fileInfo;
        });
    }

    /**
     * A validation that rejects the named files and passes the rest
     *
     * @param string[] $reject
     */
    protected function rejectByName(array $reject): ValidationInterface
    {
        $validation = $this->createMock(ValidationInterface::class);
        $validation->method('validate')->willReturnCallback(
            static function (FileInfoInterface $fileInfo) use ($reject): void {
                if (in_array($fileInfo->getNameWithExtension(), $reject, true)) {
                    throw new Exception('Rejected');
                }
            }
        );

        return $validation;
    }

    /**
     * The point of the method: one rejected file in a `name="photos[]"` field no longer
     * discards the ones that were fine.
     */
    public function testUploadValidStoresTheFilesThatPassedAndReportsTheRest(): void
    {
        $file = new File('multiple', $this->storageNamingWhatItStored());
        $file->addValidation($this->rejectByName(['bar.txt']));

        $this->assertFalse($file->uploadValid());
        $this->assertSame(['/tmp/uploads/foo.txt'], $file->getUploadedLocators());
        $this->assertSame(['bar.txt: Rejected'], $file->getErrors());
    }

    public function testUploadValidReturnsTrueWhenEveryFilePassed(): void
    {
        $file = new File('multiple', $this->storageNamingWhatItStored());
        $file->addValidation(new Mimetype(['text/plain']));

        $this->assertTrue($file->uploadValid());
        $this->assertSame(['/tmp/uploads/foo.txt', '/tmp/uploads/bar.txt'], $file->getUploadedLocators());
        $this->assertSame([], $file->getErrors());
    }

    /**
     * Nothing passed, so nothing is handed to storage: the return value and an empty
     * getUploadedLocators() are how the caller tells this from a partial success.
     */
    public function testUploadValidStoresNothingWhenEveryFileFails(): void
    {
        $storage = $this->mockStorage();
        $storage->expects($this->never())->method('upload');

        $file = new File('multiple', $storage);
        $file->addValidation($this->rejectByName(['foo.txt', 'bar.txt']));

        $this->assertFalse($file->uploadValid());
        $this->assertSame([], $file->getUploadedLocators());
        $this->assertCount(2, $file->getErrors());
    }

    /**
     * A file that never transferred is reported by the constructor rather than by a
     * validation, but it is still a file the caller asked for and did not get, so the return
     * value has to say so while its siblings are stored.
     */
    public function testUploadValidCountsAFailedTransferAsRejected(): void
    {
        $_FILES['partial'] = [
            'name' => ['foo.txt', 'huge.txt'],
            'tmp_name' => [$this->assetsDirectory . '/foo.txt', ''],
            'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_INI_SIZE],
        ];

        $file = new File('partial', $this->storageNamingWhatItStored());
        $file->addValidation(new Mimetype(['text/plain']));

        $this->assertFalse($file->uploadValid());
        $this->assertSame(['/tmp/uploads/foo.txt'], $file->getUploadedLocators());
        $this->assertSame(
            ['huge.txt: The uploaded file exceeds the upload_max_filesize directive in php.ini'],
            $file->getErrors()
        );
    }

    /** The upload hooks fire per stored file, so a rejected one must not reach them */
    public function testUploadValidRunsTheUploadHooksOnlyForTheFilesItStores(): void
    {
        $stored = [];

        $file = new File('multiple', $this->storageNamingWhatItStored());
        $file->addValidation($this->rejectByName(['bar.txt']));
        $file->beforeUpload(static function (FileInfoInterface $fileInfo) use (&$stored): void {
            $stored[] = 'before:' . $fileInfo->getNameWithExtension();
        });
        $file->afterUpload(static function (FileInfoInterface $fileInfo) use (&$stored): void {
            $stored[] = 'after:' . $fileInfo->getNameWithExtension();
        });

        $file->uploadValid();

        $this->assertSame(['before:foo.txt', 'after:foo.txt'], $stored);
    }

    /**
     * Both entry points share the guard: storing what passed is not a reason to store it
     * against nothing.
     */
    public function testUploadValidRefusesWhenNothingHasBeenConfiguredToValidate(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No validations have been added');

        (new File('multiple', $this->storage))->uploadValid();
    }

    /**
     * An empty collection has no valid file to keep, so this is the vacuous success that
     * upload()'s own count check exists to prevent rather than a partial one.
     */
    public function testUploadValidRefusesWhenThereIsNothingToStore(): void
    {
        $_FILES['none'] = ['name' => [], 'tmp_name' => [], 'error' => []];

        $file = new File('none', $this->storage);
        $file->allowUnvalidatedUploads();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('There are no files to upload');

        $file->uploadValid();
    }

    /** The locator list is emptied at the start of the call, as it is for upload() */
    public function testUploadValidDoesNotReportAnEarlierCallsFiles(): void
    {
        $file = new File('multiple', $this->storageNamingWhatItStored());
        $file->addValidation(new Mimetype(['text/plain']));

        $this->assertTrue($file->uploadValid());
        $this->assertSame(['/tmp/uploads/foo.txt', '/tmp/uploads/bar.txt'], $file->getUploadedLocators());

        $file->addValidation($this->rejectByName(['foo.txt', 'bar.txt']));

        $this->assertFalse($file->uploadValid());
        $this->assertSame([], $file->getUploadedLocators());
    }

    /**
     * Each file is validated once. Running the validations again to find out which passed
     * would let a validator with a side effect — a virus scanner, a remote lookup — see every
     * file twice.
     */
    public function testUploadValidRunsEachValidationOncePerFile(): void
    {
        $validation = $this->createMock(ValidationInterface::class);
        $validation->expects($this->exactly(2))->method('validate');

        $file = new File('multiple', $this->storageNamingWhatItStored());
        $file->addValidation($validation);

        $this->assertTrue($file->uploadValid());
    }

    /**
     * The guarantee uploadValid() opts out of: a set of files that is only meaningful
     * complete stays all-or-nothing, and storage is not called at all.
     */
    public function testUploadIsStillAllOrNothing(): void
    {
        $storage = $this->mockStorage();
        $storage->expects($this->never())->method('upload');

        $file = new File('multiple', $storage);
        $file->addValidation($this->rejectByName(['bar.txt']));

        try {
            $file->upload();
            $this->fail('Expected the rejected file to fail the whole batch');
        } catch (Exception $e) {
            $this->assertSame('File validation failed', $e->getMessage());
        }

        $this->assertSame([], $file->getUploadedLocators());
    }

    /**
     * With one file there is no partial outcome to keep — the field is stored whole or not at
     * all — so all `uploadValid()` changes is how the rejection arrives: a `false` return
     * where `upload()` throws. Nothing here may start throwing for a rejected file.
     */
    public function testUploadValidReportsASingleRejectedFileWithoutThrowing(): void
    {
        $storage = $this->mockStorage();
        $storage->expects($this->never())->method('upload');

        $file = new File('single', $storage);
        $file->addValidation(new Mimetype(['image/png']));

        $this->assertFalse($file->uploadValid());
        $this->assertSame([], $file->getUploadedLocators());
        $this->assertSame(
            ['single.txt: Invalid mimetype. Must be one of: image/png'],
            $file->getErrors()
        );
    }

    /** The other half of the single-file case: indistinguishable from `upload()` */
    public function testUploadValidStoresASingleValidFile(): void
    {
        $file = new File('single', $this->storageNamingWhatItStored());
        $file->addValidation(new Mimetype(['text/plain']));

        $this->assertTrue($file->uploadValid());
        $this->assertSame(['/tmp/uploads/single.txt'], $file->getUploadedLocators());
        $this->assertSame([], $file->getErrors());
    }

    /**
     * The locators keep the offsets the files have in the collection, so `$file[1]` and its
     * locator stay together. Appended to a list instead, a caller pairing the locators with a
     * manifest built by iterating the collection would attribute this stored file to the
     * metadata of the one that was rejected.
     */
    public function testUploadValidKeysTheLocatorsByCollectionOffset(): void
    {
        $file = new File('multiple', $this->storageNamingWhatItStored());
        $file->addValidation($this->rejectByName(['foo.txt']));

        $this->assertFalse($file->uploadValid());
        $this->assertSame([1 => '/tmp/uploads/bar.txt'], $file->getUploadedLocators());
    }

    /**
     * The exclusion that matters most, and the one with the least ordinary control flow: the
     * uploaded-file check does not `continue`, so a file that fails it falls through the rest
     * of the loop body like any other. It must still be left out of what is stored.
     */
    public function testUploadValidDoesNotStoreAFileThatFailsTheUploadedFileCheck(): void
    {
        $this->stubUploadedFileCheckByName(['bar.txt']);

        $file = new File('multiple', $this->storageNamingWhatItStored());
        $file->addValidation(new Mimetype(['text/plain']));

        $this->assertFalse($file->uploadValid());
        $this->assertSame(['/tmp/uploads/foo.txt'], $file->getUploadedLocators());
        $this->assertSame(['bar.txt: Is not an uploaded file'], $file->getErrors());
    }

    /**
     * A validator that fails for a reason of its own — a scanner that cannot be reached, an
     * API that timed out — must fail the file closed rather than storing it unchecked.
     */
    public function testUploadValidDoesNotStoreAFileWhoseValidatorFailedUnexpectedly(): void
    {
        $validation = $this->createMock(ValidationInterface::class);
        $validation->method('validate')->willReturnCallback(
            static function (FileInfoInterface $fileInfo): void {
                if ($fileInfo->getNameWithExtension() === 'bar.txt') {
                    throw new \RuntimeException('clamd unreachable');
                }
            }
        );

        $file = new File('multiple', $this->storageNamingWhatItStored());
        $file->addValidation($validation);

        $this->assertFalse($file->uploadValid());
        $this->assertSame(['/tmp/uploads/foo.txt'], $file->getUploadedLocators());
        $this->assertSame(['bar.txt: Validation could not be completed'], $file->getErrors());
    }

    /**
     * A LogicException is a bug in the program, so it reaches the developer rather than being
     * recorded against a file — and it must do so before anything has been stored.
     */
    public function testUploadValidStoresNothingWhenAValidatorRaisesALogicException(): void
    {
        $storage = $this->mockStorage();
        $storage->expects($this->never())->method('upload');

        $validation = $this->createMock(ValidationInterface::class);
        $validation
            ->method('validate')
            ->willThrowException(new InvalidArgumentException('Unsupported hashing algorithm'));

        $file = new File('multiple', $storage);
        $file->addValidation($validation);

        try {
            $file->uploadValid();
            $this->fail('Expected the LogicException to reach the developer');
        } catch (\LogicException $e) {
            $this->assertSame('Unsupported hashing algorithm', $e->getMessage());
        }

        $this->assertSame([], $file->getUploadedLocators());
    }

    /**
     * Validation runs to completion before anything is stored, so a callback that throws
     * part-way through leaves nothing on disk.
     *
     * @dataProvider provideValidationCallbackNames
     */
    public function testUploadValidStoresNothingWhenAValidationCallbackThrows(string $callback): void
    {
        $storage = $this->mockStorage();
        $storage->expects($this->never())->method('upload');

        $file = new File('multiple', $storage);
        $file->addValidation(new Mimetype(['text/plain']));
        $file->$callback(static function (): void {
            throw new \RuntimeException('per-file resource unavailable');
        });

        try {
            $file->uploadValid();
            $this->fail('Expected the callback failure to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('per-file resource unavailable', $e->getMessage());
        }

        $this->assertSame([], $file->getUploadedLocators());
    }


    /**
     * A run owns the error list and the locator list. A callback that re-enters the object
     * resets both underneath it, which can put a failed file into the set that is stored, so
     * the nested call is refused rather than allowed to corrupt the run.
     */
    public function testACallbackCannotReEnterTheCollectionDuringValidation(): void
    {
        $storage = $this->mockStorage();
        $storage->expects($this->never())->method('upload');

        $file = new File('multiple', $storage);
        $file->addValidation(new Mimetype(['text/plain']));
        $file->beforeValidate(static function () use (&$file): void {
            $file->isValid();
        });

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('cannot be called while one of them is already running');

        $file->uploadValid();
    }

    /**
     * The lock spans the storing too: `afterUpload` fires once the validations are over, so a
     * guard that covered only the validating would let a nested call reset the locator list
     * half way through a batch.
     */
    public function testACallbackCannotReEnterTheCollectionDuringStorage(): void
    {
        $file = new File('multiple', $this->storageNamingWhatItStored());
        $file->addValidation(new Mimetype(['text/plain']));
        $file->afterUpload(static function () use (&$file): void {
            $file->upload();
        });

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('cannot be called while one of them is already running');

        $file->upload();
    }

    /** The lock is released on the way out, including when the run threw */
    public function testTheCollectionIsUsableAgainAfterARunThrows(): void
    {
        $file = new File('multiple', $this->storageNamingWhatItStored());
        $file->addValidation(new Mimetype(['text/plain']));
        $file->beforeValidate(static function (): void {
            throw new \RuntimeException('per-file resource unavailable');
        });

        try {
            $file->uploadValid();
            $this->fail('Expected the callback failure to propagate');
        } catch (\RuntimeException $e) {
            $this->addToAssertionCount(1);
        }

        $file->beforeValidate(static function (): void {
        });

        $this->assertTrue($file->uploadValid());
    }

    /** @return array<string,string[]> */
    public function provideValidationCallbackNames(): array
    {
        return [
            'beforeValidate' => ['beforeValidate'],
            'afterValidate' => ['afterValidate'],
        ];
    }
}
