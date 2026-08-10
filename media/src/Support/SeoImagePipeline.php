<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Support;

use App\Support\ImageDriverResolver;
use Intervention\Image\Format;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;

/**
 * Pipeline resize/encode chất lượng cao: native Imagick (Lanczos) → Intervention (Imagick/GD).
 */
final class SeoImagePipeline
{
    private const UPSCALE_SHARPEN_LEVEL = 8;

    private const DOWNSCALE_SHARPEN_LEVEL = 4;

    private string $lastDriver = 'unknown';

    public function lastDriver(): string
    {
        return $this->lastDriver;
    }

    /**
     * Giới hạn một chiều theo cấu hình tối ưu ảnh (width hoặc height, không ép cả hai).
     */
    public function applyMaxDimensions(
        string $absolutePath,
        int $maxWidth,
        int $maxHeight,
        bool $limitByWidth,
        bool $limitByHeight,
    ): bool {
        if (! is_file($absolutePath)) {
            return false;
        }

        [$origWidth, $origHeight] = $this->readImageDimensions($absolutePath);
        if ($origWidth <= 0 || $origHeight <= 0) {
            return false;
        }

        $targetWidth = null;
        $targetHeight = null;

        if ($limitByWidth && $maxWidth > 0 && $origWidth > $maxWidth) {
            $targetWidth = $maxWidth;
        }

        if ($limitByHeight && $maxHeight > 0 && $origHeight > $maxHeight) {
            $targetHeight = $maxHeight;
        }

        if ($targetWidth === null && $targetHeight === null) {
            return false;
        }

        $result = $this->resizeFile($absolutePath, $targetWidth, $targetHeight);

        return $result['applied'];
    }

    /**
     * @return array{applied: bool, width: int, height: int, driver: string}
     */
    public function resizeFile(string $absolutePath, ?int $targetWidth, ?int $targetHeight): array
    {
        $failed = [
            'applied' => false,
            'width' => 0,
            'height' => 0,
            'driver' => $this->lastDriver,
        ];

        if (! is_file($absolutePath)) {
            return $failed;
        }

        $width = $targetWidth !== null && $targetWidth > 0 ? $targetWidth : null;
        $height = $targetHeight !== null && $targetHeight > 0 ? $targetHeight : null;

        if ($width === null && $height === null) {
            return $failed;
        }

        $extension = $this->normalizeExtension(pathinfo($absolutePath, PATHINFO_EXTENSION) ?: 'png');

        if ($this->tryResizeWithImagick($absolutePath, $extension, $width, $height)) {
            [$outWidth, $outHeight] = $this->readImageDimensions($absolutePath);

            return [
                'applied' => true,
                'width' => $outWidth,
                'height' => $outHeight,
                'driver' => $this->lastDriver,
            ];
        }

        return $this->resizeWithIntervention($absolutePath, $extension, $width, $height);
    }

    public function encodeFile(string $absolutePath, string $extension, int $quality): bool
    {
        return $this->encodeSourceToPath($absolutePath, $absolutePath, $extension, $quality);
    }

    public function encodeSourceToPath(
        string $sourcePath,
        string $destinationPath,
        string $extension,
        int $quality,
    ): bool {
        if (! is_file($sourcePath)) {
            return false;
        }

        $extension = $this->normalizeExtension($extension);
        $quality = max(10, min(100, $quality));

        if ($this->tryEncodeImagickSourceToPath($sourcePath, $destinationPath, $extension, $quality)) {
            return $this->isEncodedOutputValid($destinationPath);
        }

        try {
            $image = $this->readWithIntervention($sourcePath);
            $encoded = $this->encodeImage($image, $extension, $quality);
            if (@file_put_contents($destinationPath, $encoded) === false) {
                return false;
            }
            $this->lastDriver = 'intervention-'.ImageDriverResolver::driverName();

            return $this->isEncodedOutputValid($destinationPath);
        } catch (\Throwable $exception) {
            logger()->error('Intervention encode failed.', [
                'source' => $sourcePath,
                'destination' => $destinationPath,
                'driver' => ImageDriverResolver::driverName(),
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function isEncodedOutputValid(string $destinationPath): bool
    {
        if (! is_file($destinationPath) || (int) filesize($destinationPath) < 256) {
            return false;
        }

        $info = @getimagesize($destinationPath);

        return is_array($info)
            && (int) ($info[0] ?? 0) > 0
            && (int) ($info[1] ?? 0) > 0;
    }

    /**
     * setImageColorspace chỉ đổi nhãn — dễ ra ảnh trắng khi encode WebP.
     * Dùng transformImageColorspace để convert pixel thật sang sRGB.
     */
    private function prepareImagickColorspace(\Imagick $imagick): void
    {
        try {
            if ($imagick->getImageColorspace() === \Imagick::COLORSPACE_SRGB) {
                return;
            }

            $imagick->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
        } catch (\Throwable) {
            // Không chặn encode nếu host Imagick thiếu transform.
        }
    }

    private function tryResizeWithImagick(
        string $absolutePath,
        string $extension,
        ?int $width,
        ?int $height,
    ): bool {
        if (! ImageDriverResolver::shouldUseNativeImagickPipeline()) {
            return false;
        }

        try {
            $imagick = new \Imagick($absolutePath);
            $this->prepareImagickColorspace($imagick);

            $origWidth = $imagick->getImageWidth();
            $origHeight = $imagick->getImageHeight();
            $dimensions = SeoImageResizeMath::outputDimensions($origWidth, $origHeight, $width, $height);
            $outWidth = $dimensions['width'];
            $outHeight = $dimensions['height'];

            if ($outWidth === $origWidth && $outHeight === $origHeight) {
                $imagick->clear();
                $imagick->destroy();
                $this->lastDriver = 'imagick-native';

                return true;
            }

            // Không ALPHACHANNEL_ACTIVATE — dễ mất pixel khi write WebP/PNG.

            $steps = SeoImageResizeMath::progressiveScaleSteps($origWidth, $origHeight, $outWidth, $outHeight);
            foreach ($steps as $step) {
                $imagick->resizeImage(
                    $step['width'],
                    $step['height'],
                    \Imagick::FILTER_LANCZOS,
                    1,
                );
            }

            $this->applyImagickSharpen($imagick, $origWidth, $origHeight, $outWidth, $outHeight);
            $this->writeImagickToPath($imagick, $absolutePath, $extension, ImageDriverResolver::ENCODE_QUALITY);
            $imagick->clear();
            $imagick->destroy();

            $this->lastDriver = 'imagick-native';

            return true;
        } catch (\Throwable $exception) {
            logger()->warning('Imagick resize failed, falling back to Intervention Image.', [
                'path' => $absolutePath,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function tryEncodeWithImagick(string $absolutePath, string $extension, int $quality): bool
    {
        return $this->tryEncodeImagickSourceToPath($absolutePath, $absolutePath, $extension, $quality);
    }

    private function tryEncodeImagickSourceToPath(
        string $sourcePath,
        string $destinationPath,
        string $extension,
        int $quality,
    ): bool {
        if (! ImageDriverResolver::shouldUseNativeImagickPipeline()) {
            return false;
        }

        $imagick = null;

        try {
            // Fresh decode — không reuse object từ attempt WebP trước.
            $imagick = new \Imagick();
            $imagick->readImage($sourcePath);

            if ($imagick->getNumberImages() > 1) {
                $coalesced = $imagick->coalesceImages();
                $imagick->clear();
                $imagick->destroy();
                $imagick = $coalesced;
            }

            // Làm việc trên object chính — tránh getImage() clone lệch alpha/frame.
            $imagick->setIteratorIndex(0);

            if (method_exists($imagick, 'autoOrientImage')) {
                try {
                    $imagick->autoOrientImage();
                } catch (\Throwable) {
                    // Một số bản Imagick không hỗ trợ / thiếu EXIF.
                }
            }

            $this->prepareImagickColorspace($imagick);

            // Validate pixel TRƯỚC flatten/encode — canvas alpha=0 không được “cứu” bằng flatten.
            $this->assertImagickHasVisibleContent($imagick);

            $this->writeImagickToPath($imagick, $destinationPath, $extension, $quality);
            $this->lastDriver = 'imagick-native';

            return true;
        } catch (\Throwable $exception) {
            logger()->warning('Imagick encode failed, falling back to Intervention Image.', [
                'source' => $sourcePath,
                'destination' => $destinationPath,
                'extension' => $extension,
                'message' => $exception->getMessage(),
            ]);

            return false;
        } finally {
            if ($imagick instanceof \Imagick) {
                $imagick->clear();
                $imagick->destroy();
            }
        }
    }

    /**
     * Sample nhanh — throw nếu toàn alpha=0 (blank canvas).
     */
    private function assertImagickHasVisibleContent(\Imagick $imagick): void
    {
        $width = max(1, $imagick->getImageWidth());
        $height = max(1, $imagick->getImageHeight());
        $grid = 8;
        $visible = 0;

        for ($gy = 0; $gy < $grid; $gy++) {
            for ($gx = 0; $gx < $grid; $gx++) {
                $x = (int) round(($gx / max(1, $grid - 1)) * ($width - 1));
                $y = (int) round(($gy / max(1, $grid - 1)) * ($height - 1));
                $color = ImagickPixelColor::normalized($imagick->getImagePixelColor($x, $y));
                $a = array_key_exists('a', $color) ? (float) $color['a'] : 1.0;
                if ($a > 0.02) {
                    $visible++;
                }
            }
        }

        if ($visible === 0) {
            throw new \RuntimeException('Working canvas fully transparent — refuse encode/flatten.');
        }
    }

    /**
     * @return array{applied: bool, width: int, height: int, driver: string}
     */
    private function resizeWithIntervention(
        string $absolutePath,
        string $extension,
        ?int $width,
        ?int $height,
    ): array {
        $failed = [
            'applied' => false,
            'width' => 0,
            'height' => 0,
            'driver' => $this->lastDriver,
        ];

        try {
            $image = $this->readWithIntervention($absolutePath);
            $origWidth = $image->width();
            $origHeight = $image->height();
            $dimensions = SeoImageResizeMath::outputDimensions($origWidth, $origHeight, $width, $height);
            $outWidth = $dimensions['width'];
            $outHeight = $dimensions['height'];

            $steps = SeoImageResizeMath::progressiveScaleSteps($origWidth, $origHeight, $outWidth, $outHeight);
            foreach ($steps as $step) {
                $image->resize(width: $step['width'], height: $step['height']);
            }

            if (SeoImageResizeMath::isUpscale($origWidth, $origHeight, $outWidth, $outHeight)) {
                $image->sharpen(self::UPSCALE_SHARPEN_LEVEL);
            } elseif ($outWidth < $origWidth || $outHeight < $origHeight) {
                $image->sharpen(self::DOWNSCALE_SHARPEN_LEVEL);
            }

            $encoded = $this->encodeImage($image, $extension, ImageDriverResolver::ENCODE_QUALITY);
            file_put_contents($absolutePath, $encoded);

            $this->lastDriver = 'intervention-'.ImageDriverResolver::driverName();

            return [
                'applied' => true,
                'width' => $image->width(),
                'height' => $image->height(),
                'driver' => $this->lastDriver,
            ];
        } catch (\Throwable $exception) {
            logger()->error('Intervention Image resize failed.', [
                'path' => $absolutePath,
                'driver' => ImageDriverResolver::driverName(),
                'message' => $exception->getMessage(),
            ]);

            return $failed;
        }
    }

    private function applyImagickSharpen(
        \Imagick $imagick,
        int $origWidth,
        int $origHeight,
        int $outWidth,
        int $outHeight,
    ): void {
        if (SeoImageResizeMath::isUpscale($origWidth, $origHeight, $outWidth, $outHeight)) {
            $imagick->unsharpMaskImage(1, 0.5, 1.0, 0.05);

            return;
        }

        if ($outWidth < $origWidth || $outHeight < $origHeight) {
            $imagick->unsharpMaskImage(0.8, 0.4, 0.8, 0.03);
        }
    }

    private function writeImagickToPath(\Imagick $imagick, string $absolutePath, string $extension, int $quality): void
    {
        if ($extension === 'png') {
            $imagick->setImageFormat('png');
            $imagick->setOption('png:compression-level', '3');
            $imagick->setImageCompressionQuality(100);

            if (! $imagick->writeImage($absolutePath)) {
                throw new \RuntimeException('Imagick writeImage(png) returned false.');
            }

            return;
        }

        if ($extension === 'webp') {
            // KHÔNG gọi ALPHACHANNEL_ACTIVATE — trên nhiều host Imagick nó tạo WebP toàn alpha=0
            // dù RGB còn nội dung (paste-….webp ~756B, 800×437).
            $this->prepareImagickAlphaForWebp($imagick);
            $imagick->setImageFormat('webp');
            $imagick->setOption('webp:method', '4');
            $imagick->setImageCompressionQuality(max(10, min(100, $quality)));
            if (! $imagick->writeImage($absolutePath)) {
                throw new \RuntimeException('Imagick writeImage(webp) returned false.');
            }

            return;
        }

        if ($extension === 'gif') {
            $imagick->setImageFormat('gif');
            $imagick->writeImage($absolutePath);

            return;
        }

        // JPEG: chỉ flatten khi đã có visible pixels (assert trước đó) + có alpha thật.
        $this->flattenImagickOntoWhiteInPlace($imagick);
        $imagick->setImageFormat('jpeg');
        $imagick->setImageCompressionQuality(max(10, min(100, $quality)));
        if (! $imagick->writeImage($absolutePath)) {
            throw new \RuntimeException('Imagick writeImage(jpeg) returned false.');
        }
    }

    /**
     * WebP: nếu alpha không dùng (gần như opaque) → remove alpha.
     * Nếu có transparency hợp lệ → giữ nguyên, set alpha-quality — không ACTIVATE.
     */
    private function prepareImagickAlphaForWebp(\Imagick $imagick): void
    {
        $hasUsefulAlpha = $this->imagickHasUsefulTransparency($imagick);

        try {
            if (! $hasUsefulAlpha) {
                $imagick->setImageBackgroundColor(new \ImagickPixel('white'));
                $imagick->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);

                return;
            }

            $imagick->setOption('webp:alpha-quality', '100');
        } catch (\Throwable) {
            // Host thiếu alpha API — để Magick tự xử lý.
        }
    }

    private function imagickHasUsefulTransparency(\Imagick $imagick): bool
    {
        try {
            $type = $imagick->getImageAlphaChannel();
            // 0 / UNDEFINED = không có alpha hữu dụng.
            if ($type === 0) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        $width = max(1, $imagick->getImageWidth());
        $height = max(1, $imagick->getImageHeight());
        $grid = 8;
        $transparentSamples = 0;
        $opaqueSamples = 0;

        for ($gy = 0; $gy < $grid; $gy++) {
            for ($gx = 0; $gx < $grid; $gx++) {
                $x = (int) round(($gx / max(1, $grid - 1)) * ($width - 1));
                $y = (int) round(($gy / max(1, $grid - 1)) * ($height - 1));
                $color = ImagickPixelColor::normalized($imagick->getImagePixelColor($x, $y));
                $a = array_key_exists('a', $color) ? (float) $color['a'] : 1.0;
                if ($a < 0.98) {
                    $transparentSamples++;
                } else {
                    $opaqueSamples++;
                }
            }
        }

        // Có cả pixel trong suốt lẫn nhìn thấy → transparency hợp lệ.
        return $transparentSamples > 0 && $opaqueSamples > 0;
    }

    /**
     * Composite lên nền trắng in-place — không clear()/destroy object đang encode.
     */
    private function flattenImagickOntoWhiteInPlace(\Imagick $imagick): void
    {
        if (! $this->imagickHasUsefulTransparency($imagick)) {
            try {
                $imagick->setImageBackgroundColor(new \ImagickPixel('white'));
                $imagick->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
            } catch (\Throwable) {
                // ok
            }

            return;
        }

        $canvas = null;
        try {
            $width = max(1, $imagick->getImageWidth());
            $height = max(1, $imagick->getImageHeight());
            $canvas = new \Imagick();
            $canvas->newImage($width, $height, new \ImagickPixel('white'));
            $canvas->setImageColorspace($imagick->getImageColorspace());
            $canvas->compositeImage($imagick, \Imagick::COMPOSITE_OVER, 0, 0);
            $canvas->setImageFormat('png');
            try {
                $canvas->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
            } catch (\Throwable) {
                // ok
            }

            // Thay pixel của object hiện tại — clear() rồi read lại blob (không destroy).
            $blob = $canvas->getImageBlob();
            $imagick->clear();
            $imagick->readImageBlob($blob);
            $imagick->setIteratorIndex(0);
            try {
                $imagick->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
            } catch (\Throwable) {
                // ok
            }
        } catch (\Throwable $exception) {
            logger()->warning('Imagick flatten onto white failed.', [
                'message' => $exception->getMessage(),
            ]);
            throw $exception;
        } finally {
            if ($canvas instanceof \Imagick) {
                $canvas->clear();
                $canvas->destroy();
            }
        }
    }

    private function encodeImage(ImageInterface $image, string $extension, int $quality): string
    {
        $format = match ($extension) {
            'webp' => Format::WEBP,
            'png' => Format::PNG,
            'gif' => Format::GIF,
            default => Format::JPEG,
        };

        if (in_array($extension, ['jpg', 'jpeg'], true)) {
            $image = $this->flattenInterventionOntoWhite($image);
        }

        if ($extension === 'png') {
            return (string) $image->encodeUsingFormat($format);
        }

        return (string) $image->encodeUsingFormat($format, quality: $quality);
    }

    /**
     * JPEG không có alpha — blend transparency lên nền trắng (tránh đen thui).
     */
    private function flattenInterventionOntoWhite(ImageInterface $image): ImageInterface
    {
        try {
            if (method_exists($image, 'blendTransparency')) {
                $blended = $image->blendTransparency('ffffff');
                if ($blended instanceof ImageInterface) {
                    return $blended;
                }
            }
        } catch (\Throwable) {
            // fall through
        }

        try {
            $canvas = $this->interventionManager()->create($image->width(), $image->height())->fill('ffffff');
            $canvas->place($image);

            return $canvas;
        } catch (\Throwable $exception) {
            logger()->warning('Intervention flatten onto white failed.', [
                'message' => $exception->getMessage(),
            ]);

            return $image;
        }
    }

    private function readWithIntervention(string $absolutePath): ImageInterface
    {
        $manager = $this->interventionManager();
        if (! method_exists($manager, 'read')) {
            throw new \RuntimeException(
                'Intervention ImageManager::read() missing — cần intervention/image ^3/^4 (composer install trên host).',
            );
        }

        return $manager->read($absolutePath);
    }

    private function interventionManager(): ImageManager
    {
        $driverClass = ImageDriverResolver::interventionDriverClass();

        return new ImageManager(new $driverClass());
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function readImageDimensions(string $absolutePath): array
    {
        $size = @getimagesize($absolutePath);
        if (! is_array($size)) {
            return [0, 0];
        }

        return [(int) ($size[0] ?? 0), (int) ($size[1] ?? 0)];
    }

    private function normalizeExtension(string $extension): string
    {
        $extension = strtolower($extension);
        if ($extension === 'jpeg') {
            return 'jpg';
        }

        if (! in_array($extension, ['jpg', 'png', 'gif', 'webp'], true)) {
            return 'png';
        }

        return $extension;
    }
}
