<?php

namespace Tests\Unit;

use App\Services\Import\LavaggioProductImageService;
use PHPUnit\Framework\TestCase;

class LavaggioProductImageServiceTest extends TestCase
{
    public function test_transparent_packshots_become_opaque_white_webp_without_changing_dimensions(): void
    {
        $image = imagecreatetruecolor(96, 96);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, 24, 24, 72, 72, imagecolorallocatealpha($image, 30, 136, 229, 0));
        ob_start();
        imagepng($image);
        $source = ob_get_clean();

        $this->assertWhiteBackgroundAndBlueProduct($source);
    }

    public function test_cmyk_jpeg_keeps_its_white_background_and_product_colours(): void
    {
        if (! class_exists(\Imagick::class)) {
            $this->markTestSkipped('Imagick is required to create the CMYK source fixture.');
        }
        $image = new \Imagick;
        $image->newImage(96, 96, new \ImagickPixel('white'), 'png');
        $draw = new \ImagickDraw;
        $draw->setFillColor(new \ImagickPixel('#1e88e5'));
        $draw->rectangle(24, 24, 72, 72);
        $image->drawImage($draw);
        $image->transformImageColorspace(\Imagick::COLORSPACE_CMYK);
        $image->setImageFormat('jpeg');
        $image->setImageCompressionQuality(100);

        $this->assertWhiteBackgroundAndBlueProduct($image->getImageBlob());
    }

    private function assertWhiteBackgroundAndBlueProduct(string $source): void
    {
        $webp = (new LavaggioProductImageService)->webpOnWhite($source);
        $meta = getimagesizefromstring($webp);
        $this->assertSame('image/webp', $meta['mime']);
        $this->assertSame([96, 96], array_slice($meta, 0, 2));
        $image = imagecreatefromstring($webp);
        $corner = imagecolorsforindex($image, imagecolorat($image, 5, 5));
        $this->assertGreaterThanOrEqual(250, min($corner['red'], $corner['green'], $corner['blue']));
        $this->assertSame(0, $corner['alpha']);
        $product = imagecolorsforindex($image, imagecolorat($image, 48, 48));
        $this->assertLessThan(60, $product['red']);
        $this->assertGreaterThan(110, $product['green']);
        $this->assertGreaterThan(200, $product['blue']);
    }
}
