<?php

namespace GravityPdf\Upload;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

use function GravityPdf\Upload\__;

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
        $this->assertSame('No file was uploaded', __('No file was uploaded'));
    }

    /**
     * The domain is for whatever reads these calls, not for the runtime: the marker discards
     * it and `render()` looks every message up under `Translation::DOMAIN` regardless.
     */
    public function testTheDomainChangesNothingAboutTheAnswer(): void
    {
        $this->assertSame('No file was uploaded', __('No file was uploaded', Translation::DOMAIN));
        $this->assertSame('No file was uploaded', __('No file was uploaded', 'someone-elses-plugin'));
    }

    /**
     * It shares WordPress's name but not its behaviour: the lookup happens where the message
     * is rendered, so `Exception::getMessage()` stays English, `getErrorDetails()` keeps the
     * msgid, and a locale switched after validating is the one the reader sees.
     */
    public function testTheMarkerNeverTranslates(): void
    {
        Translation::setTranslator(static function (string $text, string $domain): string {
            return 'traduit';
        });

        $this->assertSame('No file was uploaded', __('No file was uploaded'));
    }

    /**
     * `__()` is namespaced, so an unqualified call from another namespace falls back to the
     * global one. Here nothing defines a global `__()`, so that is a fatal on the failure
     * path; under WordPress the global exists and the call silently translates at the throw
     * instead, which nothing reports. Reading the source keeps either off the table.
     *
     * One test over every file rather than one per file: per-file, it could only assert about
     * a file that marks something, so renaming the marker made every case vacuous and the
     * suite stayed green. The marker has been renamed twice.
     */
    public function testEveryCallerCanReachTheMarker(): void
    {
        $reachable = [];

        foreach ($this->sourceFiles() as $file) {
            $source = (string) file_get_contents($file);

            if (preg_match('/(?<![\w\\\\])__\s*\(/', $source) !== 1) {
                continue;
            }

            $reachable[basename($file)] = strpos($source, 'namespace GravityPdf\Upload;') !== false
                || strpos($source, 'use function GravityPdf\Upload\__;') !== false;
        }

        $this->assertNotSame([], $reachable, 'Nothing under src/ marks a string — has the marker been renamed?');
        $this->assertSame(
            array_fill_keys(array_keys($reachable), true),
            $reachable,
            'A file calls __() but neither sits in GravityPdf\Upload nor imports it'
        );
    }

    /**
     * The fatal above only exists while nothing defines a global `__()`. A WordPress stub in
     * `tests/bootstrap.php` would absorb it, leaving the source scan as the only guard.
     */
    public function testNoGlobalMarkerCanMaskAMissingImport(): void
    {
        $this->assertFalse(
            function_exists('__'),
            'A global __() would let a file that forgets the import pass its tests'
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
