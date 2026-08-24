<?php

namespace GravityPdf\Upload;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

class I18nTest extends TestCase
{
    /* phpcs:ignore */
    public function tear_down(): void
    {
        Translation::resetTranslator();

        parent::tear_down();
    }

    public function testTheMarkerReturnsItsArgument(): void
    {
        $this->assertSame('No file was uploaded', Translation::__('No file was uploaded'));
    }

    /**
     * The domain is for whatever reads these calls, not for the runtime: the marker discards
     * it and `render()` looks every message up under `Translation::DOMAIN` regardless.
     */
    public function testTheDomainChangesNothingAboutTheAnswer(): void
    {
        $this->assertSame(
            'No file was uploaded',
            Translation::__('No file was uploaded', Translation::DOMAIN)
        );
        $this->assertSame(
            'No file was uploaded',
            Translation::__('No file was uploaded', 'someone-elses-plugin')
        );
    }

    /**
     * Same name as WordPress's, different behaviour. The lookup happens where the message is
     * rendered, which is what keeps `Exception::getMessage()` English and the msgid intact.
     */
    public function testTheMarkerNeverTranslates(): void
    {
        Translation::setTranslator(static function (string $text, string $domain): string {
            return 'traduit';
        });

        $this->assertSame('No file was uploaded', Translation::__('No file was uploaded'));
    }

    /**
     * A file that forgets `use GravityPdf\Upload\Translation;` fatals at the call — a class
     * name never falls back to the global namespace the way a function name does. That is
     * still only reached on an error path, so the import is checked here rather than left to
     * the first rejected upload.
     *
     * One test over the whole corpus, not one per file. Per-file it could only assert about a
     * file that marks something, so renaming the marker made every case vacuous while the
     * suite stayed green. The marker has been renamed twice.
     */
    public function testEveryCallerCanReachTheMarker(): void
    {
        $reachable = [];

        foreach ($this->sourceFiles() as $file) {
            $source = (string) file_get_contents($file);

            if (strpos($source, 'Translation::__(') === false) {
                continue;
            }

            $reachable[basename($file)] = strpos($source, 'namespace GravityPdf\Upload;') !== false
                || strpos($source, 'use GravityPdf\Upload\Translation;') !== false;
        }

        $this->assertNotSame([], $reachable, 'Nothing under src/ marks a string — has the marker been renamed?');
        $this->assertSame(
            array_fill_keys(array_keys($reachable), true),
            $reachable,
            'A file calls Translation::__() but neither sits in GravityPdf\Upload nor imports the class'
        );
    }

    /**
     * The marker is a static method rather than a function so that an autoloader which only
     * indexes classes still reaches it. Composer's `files` entry is the only thing that loads
     * a bare function, and an autoloader built over a php-scoper'd tree does not run it.
     */
    public function testTheMarkerTravelsWithTheClass(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2) . '/src/Upload/i18n.php');
        $this->assertFalse(
            function_exists('GravityPdf\\Upload\\__'),
            'A bare marker function would shadow the global __() a WordPress or Laravel '
            . 'translator calls, in any file that imported it'
        );

        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true);

        $this->assertIsArray($composer);
        $this->assertArrayNotHasKey(
            'files',
            $composer['autoload'],
            'A `files` autoload entry is unreachable from a classmap over a scoped tree'
        );
    }

    /** @return array<int,string> */
    private function sourceFiles(): array
    {
        $files = [];

        /** @var \SplFileInfo $file */
        foreach (
            new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src')
            ) as $file
        ) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
