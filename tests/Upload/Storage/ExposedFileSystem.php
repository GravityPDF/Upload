<?php

namespace GravityPdf\Upload\Storage;

use GravityPdf\Upload\FileInfoInterface;

/**
 * Exposes the reservation step, which `upload()` only reaches once its own checks have passed.
 *
 * A symlink already at the destination is refused by `upload()` before the reservation runs, so
 * the branch that cleans up after `x` mode followed one is only reachable by racing the file
 * system. This calls it directly instead.
 */
class ExposedFileSystem extends FileSystem
{
    public function reserve(string $destinationFile, FileInfoInterface $fileInfo): void
    {
        $this->reserveDestination($destinationFile, $fileInfo);
    }
}
