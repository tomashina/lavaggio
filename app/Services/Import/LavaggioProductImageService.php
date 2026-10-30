<?php

namespace App\Services\Import;

use RuntimeException;
use Spatie\Image\Image;

class LavaggioProductImageService
{
    public const VERSION = 2;

    /** Keep the source resolution and product pixels, compositing transparency onto white. */
    public function webpOnWhite(string $source): string
    {
        $path = tempnam(sys_get_temp_dir(), 'lavaggio-image-');
        if ($path === false) {
            throw new RuntimeException('Cannot prepare product image.');
        }
        $output = $path.'.webp';
        try {
            // A CMYK packshot must become RGB before compositing it onto an RGB canvas.
            // Otherwise white can turn black and the product colours are inverted.
            if (class_exists(\Imagick::class)) {
                $image = new \Imagick;
                $image->readImageBlob($source);
                if ($image->getImageColorspace() === \Imagick::COLORSPACE_CMYK) {
                    $image->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
                    if ($image->getImageProfiles('icc') !== []) {
                        $image->removeImageProfile('icc');
                    }
                    $image->setImageFormat('png');
                    $source = $image->getImageBlob();
                }
                $image->clear();
            }
            file_put_contents($path, $source);
            Image::load($path)->background('ffffff')->quality(92)->format('webp')->save($output);

            return file_get_contents($output);
        } finally {
            @unlink($path);
            @unlink($output);
        }
    }
}
