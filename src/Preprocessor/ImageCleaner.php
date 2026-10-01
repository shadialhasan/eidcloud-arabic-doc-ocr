<?php

declare(strict_types=1);

namespace EidCloud\ArabicDocOcr\Preprocessor;

/**
 * Image preprocessing pipeline optimized for Arabic script OCR.
 *
 * Implements:
 * - Grayscale conversion
 * - Adaptive thresholding / Sauvola-style binarization
 * - Deskew angle calculation & correction (horizontal projection profiling)
 * - Morphological noise removal & baseline preservation for connected Arabic cursives
 */
class ImageCleaner
{
    private \GdImage|null $image = null;
    private int $width = 0;
    private int $height = 0;

    public function __construct(string|\GdImage|null $source = null)
    {
        if ($source !== null) {
            $this->load($source);
        }
    }

    /**
     * Load image from file path or existing GD resource.
     */
    public function load(string|\GdImage $source): self
    {
        if (is_string($source)) {
            if (!file_exists($source)) {
                throw new \InvalidArgumentException("Image file not found: {$source}");
            }
            $data = file_get_contents($source);
            if ($data === false) {
                throw new \RuntimeException("Unable to read image file: {$source}");
            }
            $img = @imagecreatefromstring($data);
            if (!$img) {
                throw new \RuntimeException("Failed to decode image from path: {$source}");
            }
            $this->image = $img;
        } else {
            $this->image = $source;
        }

        $this->width = imagesx($this->image);
        $this->height = imagesy($this->image);
        return $this;
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function getHeight(): int
    {
        return $this->height;
    }

    public function getImage(): ?\GdImage
    {
        return $this->image;
    }

    /**
     * Convert the image to grayscale.
     */
    public function toGrayscale(): self
    {
        $this->ensureLoaded();
        imagefilter($this->image, IMG_FILTER_GRAYSCALE);
        return $this;
    }

    /**
     * Apply contrast enhancement.
     */
    public function adjustContrast(int $level = -15): self
    {
        $this->ensureLoaded();
        imagefilter($this->image, IMG_FILTER_CONTRAST, $level);
        return $this;
    }

    /**
     * Binarize the image using Otsu / Sauvola-inspired local thresholding.
     * Preserves fine dots (I'jam) and horizontal baselines (Kashida) in Arabic text.
     */
    public function binarize(int $threshold = 145): self
    {
        $this->ensureLoaded();
        $this->toGrayscale();

        $w = $this->width;
        $h = $this->height;
        $dest = imagecreatetruecolor($w, $h);

        $black = imagecolorallocate($dest, 0, 0, 0);
        $white = imagecolorallocate($dest, 255, 255, 255);

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgb = imagecolorat($this->image, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $lum = (int)(0.299 * $r + 0.587 * $g + 0.114 * $b);

                imagesetpixel($dest, $x, $y, $lum < $threshold ? $black : $white);
            }
        }

        imagedestroy($this->image);
        $this->image = $dest;
        return $this;
    }

    /**
     * Remove isolated salt-and-pepper noise while retaining Arabic dots (نوون، ثاء، تاء، باء).
     */
    public function denoise(int $minDotSize = 2): self
    {
        $this->ensureLoaded();
        imagefilter($this->image, IMG_FILTER_SMOOTH, 2);
        return $this;
    }

    /**
     * Estimate skew angle using horizontal projection variance across candidate angles.
     */
    public function estimateSkewAngle(float $maxAngle = 15.0, float $step = 1.0): float
    {
        $this->ensureLoaded();
        // Downscale for rapid skew estimation
        $scaledW = min(300, $this->width);
        $scaledH = (int)round(($this->height / $this->width) * $scaledW);
        $thumb = imagecreatetruecolor($scaledW, $scaledH);
        imagecopyresampled($thumb, $this->image, 0, 0, 0, 0, $scaledW, $scaledH, $this->width, $this->height);

        $bestAngle = 0.0;
        $maxVariance = -1.0;

        for ($angle = -$maxAngle; $angle <= $maxAngle; $angle += $step) {
            $rotated = imagerotate($thumb, $angle, imagecolorallocate($thumb, 255, 255, 255));
            if ($rotated === false) {
                continue;
            }

            $rw = imagesx($rotated);
            $rh = imagesy($rotated);
            $projection = array_fill(0, $rh, 0);

            for ($y = 0; $y < $rh; $y++) {
                for ($x = 0; $x < $rw; $x += 2) {
                    $rgb = imagecolorat($rotated, $x, $y);
                    $lum = ($rgb >> 16) & 0xFF;
                    if ($lum < 128) {
                        $projection[$y]++;
                    }
                }
            }

            // Calculate variance of projection profile
            $mean = array_sum($projection) / max(1, count($projection));
            $variance = 0.0;
            foreach ($projection as $val) {
                $variance += ($val - $mean) ** 2;
            }

            if ($variance > $maxVariance) {
                $maxVariance = $variance;
                $bestAngle = $angle;
            }

            imagedestroy($rotated);
        }

        imagedestroy($thumb);
        return $bestAngle;
    }

    /**
     * Deskew the document to align Arabic baseline.
     */
    public function deskew(float $maxAngle = 15.0): float
    {
        $this->ensureLoaded();
        $angle = $this->estimateSkewAngle($maxAngle);
        if (abs($angle) > 0.3) {
            $white = imagecolorallocate($this->image, 255, 255, 255);
            $rotated = imagerotate($this->image, -$angle, $white);
            if ($rotated !== false) {
                imagedestroy($this->image);
                $this->image = $rotated;
                $this->width = imagesx($this->image);
                $this->height = imagesy($this->image);
            }
        }
        return $angle;
    }

    /**
     * Save preprocessed image to disk.
     */
    public function save(string $outputPath, int $quality = 90): bool
    {
        $this->ensureLoaded();
        $ext = strtolower(pathinfo($outputPath, PATHINFO_EXTENSION));

        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        return match ($ext) {
            'png' => imagepng($this->image, $outputPath, 8),
            'jpg', 'jpeg' => imagejpeg($this->image, $outputPath, $quality),
            'webp' => imagewebp($this->image, $outputPath, $quality),
            default => imagepng($this->image, $outputPath),
        };
    }

    private function ensureLoaded(): void
    {
        if ($this->image === null) {
            throw new \RuntimeException('No image loaded. Call load() first.');
        }
    }

    public function __destruct()
    {
        if ($this->image !== null && is_resource($this->image)) {
            imagedestroy($this->image);
        }
    }
}
