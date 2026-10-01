<?php

declare(strict_types=1);

namespace EidCloud\ArabicDocOcr\Normalizer;

/**
 * Arabic Unicode Text Normalizer & Number Formatter.
 *
 * Handles:
 * - Conversion of Eastern Arabic (Hindi) digits (٠١٢٣٤٥٦٧٨٩) and Persian digits (۰۱۲۳۴۵۶۷۸۹) to standard Arabic/Latin digits (0-9).
 * - Stripping Arabic diacritics / Tashkeel (َ ً ُ ٌ ِ ٍ ْ ّ).
 * - Letter normalization (أ إ آ -> ا, ة -> ه, ى -> ي).
 * - Tatweel / Kashida removal (ـ).
 * - Numeric price/total sanitization with currency recognition (SAR / ر.س / د.إ / AED, etc.).
 */
class ArabicNormalizer
{
    /**
     * Map of Eastern Arabic digits to Latin digits.
     */
    private const EASTERN_ARABIC_DIGITS = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        // Persian variations
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    /**
     * Convert Eastern Arabic numerals to Western digits.
     */
    public static function convertDigits(string $text): string
    {
        return strtr($text, self::EASTERN_ARABIC_DIGITS);
    }

    /**
     * Strip Tashkeel (diacritics: Fatha, Damma, Kasra, Sukun, Shadda, Tanween).
     */
    public static function stripTashkeel(string $text): string
    {
        return preg_replace('/[\x{064B}-\x{0652}\x{0670}]/u', '', $text) ?? $text;
    }

    /**
     * Strip Tatweel / Kashida (ـ) used in Arabic justification.
     */
    public static function stripTatweel(string $text): string
    {
        return str_replace(['ـ', "\u{0640}"], '', $text);
    }

    /**
     * Normalize common Arabic letter variants for flexible regex matching.
     */
    public static function normalizeLetters(string $text): string
    {
        $text = self::stripTashkeel($text);
        $text = self::stripTatweel($text);

        // Standardize Alef variations
        $text = preg_replace('/[إأآآ]/u', 'ا', $text) ?? $text;
        // Standardize Taa Marbuta
        $text = preg_replace('/ة/u', 'ه', $text) ?? $text;
        // Standardize Yaa / Alef Maqsura
        $text = preg_replace('/ى/u', 'ي', $text) ?? $text;

        return $text;
    }

    /**
     * Extract and parse numerical decimal value from text (supports Arabic and Western formats).
     */
    public static function parseDecimal(string $text): ?float
    {
        $converted = self::convertDigits($text);
        // Replace Arabic decimal separator (٫) and comma (،) with dot
        $converted = str_replace(['٫', '،', ','], '.', $converted);

        // Find numbers with optional decimals
        if (preg_match('/(\d+(?:\.\d+)?)/', $converted, $matches)) {
            return (float)$matches[1];
        }

        return null;
    }

    /**
     * Normalize multi-line document text for consistent OCR token extraction.
     */
    public static function cleanDocumentText(string $text): string
    {
        $converted = self::convertDigits($text);
        $cleanTashkeel = self::stripTashkeel($converted);
        $cleanTatweel = self::stripTatweel($cleanTashkeel);

        // Clean extra horizontal whitespace while preserving newlines
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $cleanTatweel));
        $cleanedLines = array_map(function ($line) {
            return trim(preg_replace('/[ \t]+/u', ' ', $line) ?? '');
        }, $lines);

        return implode("\n", array_filter($cleanedLines, fn($l) => $l !== ''));
    }
}
