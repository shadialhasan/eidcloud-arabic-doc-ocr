<?php

declare(strict_types=1);

namespace EidCloud\ArabicDocOcr;

use EidCloud\ArabicDocOcr\Engines\OcrEngineInterface;
use EidCloud\ArabicDocOcr\Engines\TesseractEngine;
use EidCloud\ArabicDocOcr\Engines\MockEngine;
use EidCloud\ArabicDocOcr\Extractors\InvoiceExtractor;
use EidCloud\ArabicDocOcr\Preprocessor\ImageCleaner;
use EidCloud\ArabicDocOcr\Zatca\TlvDecoder;
use EidCloud\ArabicDocOcr\Pdf\PdfProcessor;

/**
 * Main Entry point for Arabic Document OCR Parser & Structured Data Extractor.
 */
class DocOcr
{
    private OcrEngineInterface $engine;
    private InvoiceExtractor $extractor;
    private bool $preprocessEnabled = true;

    public function __construct(?OcrEngineInterface $engine = null, ?InvoiceExtractor $extractor = null)
    {
        $this->engine = $engine ?? $this->resolveDefaultEngine();
        $this->extractor = $extractor ?? new InvoiceExtractor();
    }

    public function setEngine(OcrEngineInterface $engine): self
    {
        $this->engine = $engine;
        return $this;
    }

    public function getEngine(): OcrEngineInterface
    {
        return $this->engine;
    }

    public function enablePreprocessing(bool $enable = true): self
    {
        $this->preprocessEnabled = $enable;
        return $this;
    }

    /**
     * Process an Arabic document (PDF or Image) and extract full text and structured invoice data.
     *
     * @param string $filePath Path to PDF or image file
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function process(string $filePath, array $options = []): array
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("Document file not found: {$filePath}");
        }

        $ocrText = '';

        if (PdfProcessor::isPdf($filePath)) {
            // Check if digital text can be extracted directly
            $embedded = PdfProcessor::extractEmbeddedText($filePath);
            if (!empty(trim($embedded)) && empty($options['force_ocr'])) {
                $ocrText = $embedded;
            } else {
                // In a full production env with imagick or pdftoppm, render first page.
                // Fallback to OCR engine directly or mock
                $ocrText = $this->engine->recognize($filePath, $options);
            }
        } else {
            $pathToRecognize = $filePath;
            $cleaner = null;

            if ($this->preprocessEnabled) {
                try {
                    $cleaner = new ImageCleaner($filePath);
                    $cleaner->binarize();
                    $cleaner->deskew();

                    $tempClean = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'clean_' . uniqid('', true) . '.png';
                    $cleaner->save($tempClean);
                    $pathToRecognize = $tempClean;
                } catch (\Throwable) {
                    // Fall back to original image if GD preprocessing fails on edge format
                    $pathToRecognize = $filePath;
                }
            }

            $ocrText = $this->engine->recognize($pathToRecognize, $options);

            if ($pathToRecognize !== $filePath && file_exists($pathToRecognize)) {
                @unlink($pathToRecognize);
            }
        }

        // Run structured extraction
        $extracted = $this->extractor->extract($ocrText);

        return [
            'file_name' => basename($filePath),
            'engine_used' => $this->engine->getName(),
            'extracted_data' => $extracted,
            'full_text' => $ocrText,
        ];
    }

    /**
     * Decode ZATCA Base64 TLV string or QR payload directly.
     *
     * @param string $base64Payload
     * @return array<string, mixed>
     */
    public function decodeZatcaQr(string $base64Payload): array
    {
        return TlvDecoder::decode($base64Payload);
    }

    /**
     * Encode ZATCA invoice metadata into standard Base64 TLV payload.
     *
     * @param array<int|string, mixed> $data
     * @return string
     */
    public function encodeZatcaQr(array $data): string
    {
        return TlvDecoder::encode($data);
    }

    private function resolveDefaultEngine(): OcrEngineInterface
    {
        $tesseract = new TesseractEngine();
        if ($tesseract->isAvailable()) {
            return $tesseract;
        }

        return new MockEngine();
    }
}
