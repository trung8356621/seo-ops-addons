<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Support;

/**
 * Sample grid 16×16 — không loop toàn ảnh.
 */
final class ImageContentSignatureSampler
{
    private const GRID = 16;

    public function fromPath(string $path): ?ImageContentSignature
    {
        if (! is_file($path)) {
            return null;
        }

        $bytes = (int) filesize($path);
        if (extension_loaded('imagick') && class_exists(\Imagick::class)) {
            try {
                return $this->fromImagickPath($path, $bytes);
            } catch (\Throwable) {
                // fall through GD
            }
        }

        return $this->fromGdPath($path, $bytes);
    }

    public function fromBytes(string $binary, string $hintExtension = 'bin'): ?ImageContentSignature
    {
        if ($binary === '') {
            return null;
        }

        $ext = strtolower($hintExtension);
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            $ext = 'bin';
        }

        $temp = tempnam(sys_get_temp_dir(), 'seo-sig-');
        if ($temp === false) {
            return null;
        }

        $path = $temp.'.'.$ext;
        @unlink($temp);
        if (@file_put_contents($path, $binary) === false) {
            return null;
        }

        try {
            return $this->fromPath($path);
        } finally {
            @unlink($path);
        }
    }

    private function fromImagickPath(string $path, int $bytes): ImageContentSignature
    {
        $imagick = new \Imagick($path);
        $imagick->setIteratorIndex(0);
        $width = max(1, $imagick->getImageWidth());
        $height = max(1, $imagick->getImageHeight());

        $lumas = [];
        $alphas = [];
        $rs = [];
        $gs = [];
        $bs = [];
        $visible = 0;
        $buckets = [];

        $grid = self::GRID;
        for ($gy = 0; $gy < $grid; $gy++) {
            for ($gx = 0; $gx < $grid; $gx++) {
                $x = (int) round(($gx / max(1, $grid - 1)) * ($width - 1));
                $y = (int) round(($gy / max(1, $grid - 1)) * ($height - 1));
                $pixel = $imagick->getImagePixelColor($x, $y);
                $color = ImagickPixelColor::normalized($pixel);
                $r = (float) ($color['r'] ?? 0);
                $g = (float) ($color['g'] ?? 0);
                $b = (float) ($color['b'] ?? 0);
                $a = array_key_exists('a', $color) ? (float) $color['a'] : 1.0;

                $lumas[] = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
                $alphas[] = $a;
                $rs[] = $r;
                $gs[] = $g;
                $bs[] = $b;
                if ($a > 0.02) {
                    $visible++;
                }

                $bucket = ((int) floor($r * 7)).'-'.((int) floor($g * 7)).'-'.((int) floor($b * 7)).'-'.((int) floor($a * 3));
                $buckets[$bucket] = true;
            }
        }

        $imagick->clear();
        $imagick->destroy();

        return $this->build($width, $height, $bytes, $lumas, $alphas, $rs, $gs, $bs, $visible, count($buckets));
    }

    private function fromGdPath(string $path, int $bytes): ?ImageContentSignature
    {
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $image = match ($extension) {
            'png' => @imagecreatefrompng($path),
            'gif' => @imagecreatefromgif($path),
            'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => @imagecreatefromjpeg($path),
        };

        if ($image === false) {
            return null;
        }

        $width = max(1, imagesx($image));
        $height = max(1, imagesy($image));
        $lumas = [];
        $alphas = [];
        $rs = [];
        $gs = [];
        $bs = [];
        $visible = 0;
        $buckets = [];
        $grid = self::GRID;

        for ($gy = 0; $gy < $grid; $gy++) {
            for ($gx = 0; $gx < $grid; $gx++) {
                $x = (int) round(($gx / max(1, $grid - 1)) * ($width - 1));
                $y = (int) round(($gy / max(1, $grid - 1)) * ($height - 1));
                $rgba = imagecolorat($image, $x, $y);
                $a = 1.0;
                if (imageistruecolor($image)) {
                    $r = (($rgba >> 16) & 0xFF) / 255.0;
                    $g = (($rgba >> 8) & 0xFF) / 255.0;
                    $b = ($rgba & 0xFF) / 255.0;
                    $a = (127 - (($rgba & 0x7F000000) >> 24)) / 127.0;
                } else {
                    $colors = imagecolorsforindex($image, $rgba);
                    $r = ((int) ($colors['red'] ?? 0)) / 255.0;
                    $g = ((int) ($colors['green'] ?? 0)) / 255.0;
                    $b = ((int) ($colors['blue'] ?? 0)) / 255.0;
                    $alpha = (int) ($colors['alpha'] ?? 0);
                    $a = (127 - $alpha) / 127.0;
                }

                $lumas[] = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
                $alphas[] = $a;
                $rs[] = $r;
                $gs[] = $g;
                $bs[] = $b;
                if ($a > 0.02) {
                    $visible++;
                }

                $bucket = ((int) floor($r * 7)).'-'.((int) floor($g * 7)).'-'.((int) floor($b * 7)).'-'.((int) floor($a * 3));
                $buckets[$bucket] = true;
            }
        }

        imagedestroy($image);

        return $this->build($width, $height, $bytes, $lumas, $alphas, $rs, $gs, $bs, $visible, count($buckets));
    }

    /**
     * @param  list<float>  $lumas
     * @param  list<float>  $alphas
     * @param  list<float>  $rs
     * @param  list<float>  $gs
     * @param  list<float>  $bs
     */
    private function build(
        int $width,
        int $height,
        int $bytes,
        array $lumas,
        array $alphas,
        array $rs,
        array $gs,
        array $bs,
        int $visible,
        int $distinctBuckets,
    ): ImageContentSignature {
        $samples = count($lumas);
        $lumaMean = $this->mean($lumas);
        $lumaStd = $this->std($lumas, $lumaMean);
        $alphaMean = $this->mean($alphas);
        $alphaMin = $alphas === [] ? 0.0 : min($alphas);
        $alphaMax = $alphas === [] ? 0.0 : max($alphas);
        $rMean = $this->mean($rs);
        $gMean = $this->mean($gs);
        $bMean = $this->mean($bs);
        $channelStdMax = max(
            $this->std($rs, $rMean),
            $this->std($gs, $gMean),
            $this->std($bs, $bMean),
        );

        $fullyTransparent = $samples > 0 && $visible === 0;
        $nearUniform = $lumaStd < 0.02 && $channelStdMax < 0.025 && $distinctBuckets <= 2;

        return new ImageContentSignature(
            width: $width,
            height: $height,
            bytes: $bytes,
            samples: $samples,
            visibleSamples: $visible,
            visibleRatio: $samples > 0 ? $visible / $samples : 0.0,
            alphaMin: $alphaMin,
            alphaMax: $alphaMax,
            alphaMean: $alphaMean,
            lumaMean: $lumaMean,
            lumaStd: $lumaStd,
            rMean: $rMean,
            gMean: $gMean,
            bMean: $bMean,
            channelStdMax: $channelStdMax,
            distinctBuckets: $distinctBuckets,
            fullyTransparent: $fullyTransparent,
            nearUniform: $nearUniform,
        );
    }

    /**
     * @param  list<float>  $values
     */
    private function mean(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        return array_sum($values) / count($values);
    }

    /**
     * @param  list<float>  $values
     */
    private function std(array $values, float $mean): float
    {
        $n = count($values);
        if ($n < 2) {
            return 0.0;
        }

        $acc = 0.0;
        foreach ($values as $value) {
            $acc += ($value - $mean) ** 2;
        }

        return sqrt($acc / ($n - 1));
    }
}
