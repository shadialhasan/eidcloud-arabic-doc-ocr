<?php

declare(strict_types=1);

namespace EidCloud\ArabicDocOcr\Pdf;

/**
 * Lightweight PDF text extractor and rasterization coordinator.
 * Can extract direct text from digital PDFs or assist page splitting.
 */
class PdfProcessor
{
    /**
     * Check if a given file is a PDF.
     */
    public static function isPdf(string $filePath): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_file($finfo, $filePath) : '';
        if ($finfo) {
            finfo_close($finfo);
        }

        if ($mime === 'application/pdf') {
            return true;
        }

        // Header check
        $fp = @fopen($filePath, 'rb');
        if ($fp) {
            $header = fread($fp, 5);
            fclose($fp);
            return $header === '%PDF-';
        }

        return false;
    }

    /**
     * Extract stream/text content from PDF without external heavy binaries if pure text is embedded.
     */
    public static function extractEmbeddedText(string $pdfPath): string
    {
        $content = file_get_contents($pdfPath);
        if ($content === false) {
            return '';
        }

        $text = '';
        // Extract stream objects
        if (preg_match_all('#stream[\r\n]+(.*?)[\r\n]+endstream#ms', $content, $matches)) {
            foreach ($matches[1] as $stream) {
                $uncompressed = @gzuncompress($stream);
                if ($uncompressed !== false) {
                    $stream = $uncompressed;
                }

                // Match text in parentheses BT ... ET
                if (preg_match_all('#\((.*?)\)\s*Tj#s', $stream, $tMatches)) {
                    $text .= implode(' ', $tMatches[1]) . "\n";
                } elseif (preg_match_all('#\[(.*?)\]\s*TJ#s', $stream, $tjMatches)) {
                    foreach ($tjMatches[1] as $chunk) {
                        if (preg_match_all('#\((.*?)\)#s', $chunk, $sub)) {
                            $text .= implode('', $sub[1]) . ' ';
                        }
                    }
                    $text .= "\n";
                }
            }
        }

        return trim($text);
    }
}
