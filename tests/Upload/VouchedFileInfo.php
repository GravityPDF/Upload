<?php

namespace GravityPdf\Upload;

/**
 * The provenance decision a caller building a `FileList` has to make for themselves
 *
 * `File`'s validation run rejects any file whose `isUploadedFile()` answers false, and on the
 * `$_FILES` path that is PHP's SAPI assertion. Nothing off that path can make it, so a caller
 * asserts what their own runtime can vouch for instead — here, that the path is a file the test
 * wrote. `FileInfo::__construct()` is final, but the class and this method are not, which is
 * the whole extension point `FileList` relies on.
 */
class VouchedFileInfo extends FileInfo
{
    public function isUploadedFile(): bool
    {
        return $this->getPathname() !== '' && is_file($this->getPathname());
    }
}
