<?php

declare(strict_types=1);

namespace EidCloud\ArabicDocOcr\Engines;

/**
 * Interface for OCR and Vision recognition engines.
 */
interface OcrEngineInterface
{
    /**
     * Recognize text from an image file.
     *
     * @param string $imagePath Path to image or PDF page
     * @param array<string, mixed> $options Additional engine parameters (lang, psm, timeout)
     * @return string Extracted text
     */
    public function recognize(string $imagePath, array $options = []): string;

    /**
     * Check if this OCR engine is available on the host machine.
     */
    public function isAvailable(): bool;

    /**
     * Return engine name/identifier.
     */
    public function getName(): string;
}
