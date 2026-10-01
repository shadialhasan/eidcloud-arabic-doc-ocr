<?php

declare(strict_types=1);

namespace EidCloud\ArabicDocOcr\Engines;

/**
 * Tesseract OCR Engine wrapper with Arabic language model support (ara + eng).
 */
class TesseractEngine implements OcrEngineInterface
{
    private string $binaryPath;
    private string $defaultLanguage;

    public function __construct(?string $binaryPath = null, string $defaultLanguage = 'ara+eng')
    {
        $this->binaryPath = $binaryPath ?? $this->detectBinary();
        $this->defaultLanguage = $defaultLanguage;
    }

    public function getName(): string
    {
        return 'Tesseract OCR';
    }

    public function isAvailable(): bool
    {
        $output = [];
        $returnVar = 0;
        @exec(escapeshellcmd($this->binaryPath) . ' --version 2>&1', $output, $returnVar);
        return $returnVar === 0;
    }

    public function recognize(string $imagePath, array $options = []): string
    {
        if (!file_exists($imagePath)) {
            throw new \InvalidArgumentException("Image not found: {$imagePath}");
        }

        if (!$this->isAvailable()) {
            throw new \RuntimeException("Tesseract binary not found or not executable at '{$this->binaryPath}'");
        }

        $lang = $options['lang'] ?? $this->defaultLanguage;
        $psm = $options['psm'] ?? '3'; // Fully automatic page segmentation

        $tempOut = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tess_' . uniqid('', true);

        $cmd = sprintf(
            '%s %s %s -l %s --psm %s 2>&1',
            escapeshellcmd($this->binaryPath),
            escapeshellarg($imagePath),
            escapeshellarg($tempOut),
            escapeshellarg($lang),
            escapeshellarg((string)$psm)
        );

        $output = [];
        $returnVar = 0;
        exec($cmd, $output, $returnVar);

        $resultFile = $tempOut . '.txt';
        if (file_exists($resultFile)) {
            $content = (string)file_get_contents($resultFile);
            @unlink($resultFile);
            return $content;
        }

        throw new \RuntimeException("Tesseract execution failed: " . implode("\n", $output));
    }

    private function detectBinary(): string
    {
        // Common paths on Windows and Unix
        $candidates = [
            'tesseract',
            'C:\\Program Files\\Tesseract-OCR\\tesseract.exe',
            'C:\\Program Files (x86)\\Tesseract-OCR\\tesseract.exe',
            '/usr/bin/tesseract',
            '/usr/local/bin/tesseract',
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === 'tesseract') {
                $checkCmd = (stripos(PHP_OS, 'WIN') === 0) ? 'where tesseract 2>nul' : 'which tesseract 2>/dev/null';
                $out = @shell_exec($checkCmd);
                if (!empty(trim((string)$out))) {
                    return 'tesseract';
                }
            } elseif (file_exists($candidate)) {
                return $candidate;
            }
        }

        return 'tesseract';
    }
}
