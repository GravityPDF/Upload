<?php

declare(strict_types=1);

namespace Upload\Validation;

use Upload\Exception;
use Upload\FileInfoInterface;
use Upload\ValidationInterface;

class Dimensions implements ValidationInterface
{
    /**
     * @var int
     */
    protected $width;

    /**
     * @var int
     */
    protected $height;

    /**
     * @param int $expectedWidth
     * @param int $expectedHeight
     */
    public function __construct(int $expectedWidth, int $expectedHeight)
    {
        $this->width = $expectedWidth;
        $this->height = $expectedHeight;
    }

    /**
     * @param FileInfoInterface $fileInfo
     * @return void
     */
    public function validate(FileInfoInterface $fileInfo): void
    {
        $dimensions = $fileInfo->getDimensions();
        $filename = $fileInfo->getNameWithExtension();

        if (!$dimensions) {
            throw new Exception(sprintf('%s: Could not detect image size.', $filename));
        }

        if ($dimensions['width'] !== $this->width) {
            throw new Exception(
                sprintf(
                    '%s: Image width(%dpx) does not match required width(%dpx)',
                    $filename,
                    $dimensions['width'],
                    $this->width
                )
            );
        }

        if ($dimensions['height'] !== $this->height) {
            throw new Exception(
                sprintf(
                    '%s: Image height(%dpx) does not match required height(%dpx)',
                    $filename,
                    $dimensions['height'],
                    $this->height
                )
            );
        }
    }
}
