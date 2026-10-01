<?php

declare(strict_types=1);

namespace EidCloud\ArabicDocOcr\Extractors;

use EidCloud\ArabicDocOcr\Normalizer\ArabicNormalizer;

/**
 * Extracts structured invoice data (Invoice #, Date, Vendor, VAT, Line items, Totals)
 * from normalized Arabic/English OCR text.
 */
class InvoiceExtractor
{
    /**
     * Parse raw document text into structured invoice fields and line items.
     *
     * @param string $rawText
     * @return array<string, mixed>
     */
    public function extract(string $rawText): array
    {
        $normalizedText = ArabicNormalizer::cleanDocumentText($rawText);
        $lines = explode("\n", $normalizedText);

        $invoiceNumber = $this->extractInvoiceNumber($lines, $normalizedText);
        $invoiceDate = $this->extractInvoiceDate($lines, $normalizedText);
        $vendor = $this->extractVendor($lines);
        $vatNumber = $this->extractVatNumber($lines, $normalizedText);
        $totals = $this->extractTotals($lines, $normalizedText);
        $lineItems = $this->extractLineItems($lines);

        return [
            'document_type' => $this->detectDocumentType($normalizedText),
            'invoice_number' => $invoiceNumber,
            'date' => $invoiceDate,
            'vendor_name' => $vendor,
            'vat_number' => $vatNumber,
            'subtotal' => $totals['subtotal'],
            'tax_amount' => $totals['tax'],
            'tax_rate' => $totals['tax_rate'],
            'total_amount' => $totals['total'],
            'currency' => $totals['currency'],
            'line_items' => $lineItems,
            'raw_text_preview' => mb_substr($normalizedText, 0, 300) . '...'
        ];
    }

    private function detectDocumentType(string $text): string
    {
        if (preg_match('/فاتورة\s*ضريبية\s*مبسطة/ui', $text) || stripos($text, 'Simplified Tax Invoice') !== false) {
            return 'Simplified Tax Invoice';
        }
        if (preg_match('/فاتورة\s*ضريبية/ui', $text) || stripos($text, 'Tax Invoice') !== false) {
            return 'Standard Tax Invoice';
        }
        if (preg_match('/إشعار\s*دائن/ui', $text) || stripos($text, 'Credit Note') !== false) {
            return 'Credit Note';
        }
        if (preg_match('/إشعار\s*مدين/ui', $text) || stripos($text, 'Debit Note') !== false) {
            return 'Debit Note';
        }
        if (preg_match('/إيصال|سند\s*قبض/ui', $text) || stripos($text, 'Receipt') !== false) {
            return 'Receipt';
        }
        return 'Invoice';
    }

    /**
     * Extract invoice number using pattern matching across Arabic and English identifiers.
     */
    public function extractInvoiceNumber(array $lines, string $fullText): ?string
    {
        $patterns = [
            '/(?:رقم\s*الفاتورة|رقم\s*المستند|الفاتورة\s*رقم|Invoice\s*No\.?|Invoice\s*#|Bill\s*No)\s*[:#\-]?\s*([A-Za-z0-9\-\_\/]+)/ui',
            '/(?:INV|FACT|FAT)[0-9\-\_\/]{3,20}/ui',
        ];

        foreach ($lines as $line) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $line, $matches)) {
                    return trim($matches[1] ?? $matches[0]);
                }
            }
        }

        // Global search fallback
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $fullText, $matches)) {
                return trim($matches[1] ?? $matches[0]);
            }
        }

        return null;
    }

    /**
     * Extract invoice date (Gregorian or Hijri).
     */
    public function extractInvoiceDate(array $lines, string $fullText): ?string
    {
        $patterns = [
            '/(?:تاريخ\s*الفاتورة|تاريخ\s*الاصدار|التاريخ|Date\s*of\s*issue|Date)\s*[:#\-]?\s*(\d{4}[\/\-\.]\d{1,2}[\/\-\.]\d{1,2}|\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{4})/ui',
            '/\b(\d{4}[\/\-]\d{2}[\/\-]\d{2})\b/u',
            '/\b(\d{2}[\/\-]\d{2}[\/\-]\d{4})\b/u',
        ];

        foreach ($lines as $line) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $line, $matches)) {
                    $rawDate = trim($matches[1]);
                    return str_replace(['/', '.'], '-', $rawDate);
                }
            }
        }

        return null;
    }

    /**
     * Extract vendor / company name from the header lines.
     */
    public function extractVendor(array $lines): ?string
    {
        $vendorKeywords = [
            'شركة', 'مؤسسة', 'متجر', 'مجموعة', 'مركز', 'مكتب',
            'Company', 'Est', 'Trading', 'Co.', 'LLC', 'Corp'
        ];

        foreach (array_slice($lines, 0, 8) as $line) {
            // Check for explicit label
            if (preg_match('/(?:اسم\s*المورد|اسم\s*الشركة|البائع|المورد|Vendor|Seller|Supplier)\s*[:\-]\s*(.+)/ui', $line, $matches)) {
                return trim($matches[1]);
            }

            // Check if line contains company prefix
            foreach ($vendorKeywords as $kw) {
                if (stripos($line, $kw) !== false && mb_strlen($line) > 5 && mb_strlen($line) < 80) {
                    // Exclude lines with numbers like tax or phone
                    if (!preg_match('/\d{6,}/', $line)) {
                        return trim($line);
                    }
                }
            }
        }

        return null;
    }

    /**
     * Extract VAT registration number (15 digits in Saudi Arabia / UAE).
     */
    public function extractVatNumber(array $lines, string $fullText): ?string
    {
        // Check lines with label first
        $labelPatterns = [
            '/(?:الرقم\s*الضريبي|رقم\s*التسجيل\s*الضريبي|معرف\s*الضريبة|VAT\s*Number|TRN|Tax\s*ID)\s*[:#\-]?\s*(\d{10,15})/ui',
        ];

        foreach ($lines as $line) {
            foreach ($labelPatterns as $pattern) {
                if (preg_match($pattern, $line, $matches)) {
                    return trim($matches[1]);
                }
            }
        }

        // Search 15-digit KSA VAT format: starts with 3, ends with 3
        if (preg_match('/\b(3\d{13}3)\b/', $fullText, $matches)) {
            return $matches[1];
        }

        // Generic 15-digit TRN
        if (preg_match('/\b(\d{15})\b/', $fullText, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Extract subtotal, VAT amount, and grand total.
     *
     * @return array{subtotal: ?float, tax: ?float, tax_rate: ?float, total: ?float, currency: string}
     */
    public function extractTotals(array $lines, string $fullText): array
    {
        $currency = 'SAR';
        if (preg_match('/(AED|د\.إ|ريال|ر\.س|SAR|USD|\$|EUR|€|KWD|دينار)/u', $fullText, $currMatch)) {
            $currency = match ($currMatch[1]) {
                'AED', 'د.إ' => 'AED',
                'USD', '$' => 'USD',
                'EUR', '€' => 'EUR',
                'KWD', 'دينار' => 'KWD',
                default => 'SAR'
            };
        }

        $total = null;
        $subtotal = null;
        $tax = null;

        $totalPatterns = [
            '/(?:الإجمالي\s*الكلي|المبلغ\s*الإجمالي|المجموع\s*الكلي|صافي\s*المبلغ|المبلغ\s*المستحق|Total\s*Amount|Grand\s*Total|Total)\s*[:#\-]?\s*([0-9\.,]+)/ui',
        ];

        $subtotalPatterns = [
            '/(?:المجموع\s*الفرعي|الإجمالي\s*قبل\s*الضريبة|المبلغ\s*الخاضع\s*للضريبة|Subtotal|Net\s*Amount)\s*[:#\-]?\s*([0-9\.,]+)/ui',
        ];

        $taxPatterns = [
            '/(?:ضريبة\s*القيمة\s*المضافة|مجموع\s*الضريبة|قيمة\s*الضريبة|مبلغ\s*الضريبة|VAT\s*Amount|VAT|Tax)(?:\s*\d+%)?\s*[:#\-]?\s*([0-9\.,]+)/ui',
        ];

        foreach ($lines as $line) {
            if ($total === null) {
                foreach ($totalPatterns as $p) {
                    if (preg_match($p, $line, $m)) {
                        $total = ArabicNormalizer::parseDecimal($m[1]);
                    }
                }
            }
            if ($subtotal === null) {
                foreach ($subtotalPatterns as $p) {
                    if (preg_match($p, $line, $m)) {
                        $subtotal = ArabicNormalizer::parseDecimal($m[1]);
                    }
                }
            }
            if ($tax === null) {
                foreach ($taxPatterns as $p) {
                    if (preg_match($p, $line, $m)) {
                        $tax = ArabicNormalizer::parseDecimal($m[1]);
                    }
                }
            }
        }

        // Deduce missing values if 2 out of 3 are found
        if ($total !== null && $subtotal !== null && $tax === null) {
            $tax = round($total - $subtotal, 2);
        } elseif ($subtotal !== null && $tax !== null && $total === null) {
            $total = round($subtotal + $tax, 2);
        } elseif ($total !== null && $tax !== null && $subtotal === null) {
            $subtotal = round($total - $tax, 2);
        }

        $taxRate = null;
        if ($subtotal && $tax && $subtotal > 0) {
            $taxRate = round(($tax / $subtotal) * 100, 1);
        }

        return [
            'subtotal' => $subtotal,
            'tax' => $tax,
            'tax_rate' => $taxRate,
            'total' => $total,
            'currency' => $currency
        ];
    }

    /**
     * Extract tabular line items from invoice table rows.
     *
     * @param array<int, string> $lines
     * @return list<array<string, mixed>>
     */
    public function extractLineItems(array $lines): array
    {
        $items = [];
        $isInTable = false;

        foreach ($lines as $line) {
            // Check for table header
            if (preg_match('/(?:الوصف|البند|البيان|الصنف|Description|Item)\s+(?:الكمية|Qty)\s+(?:السعر|Price)/ui', $line)) {
                $isInTable = true;
                continue;
            }

            // If we encounter totals section, table has finished
            if (preg_match('/(?:المجموع|الإجمالي|Subtotal|Total|الضريبة)/ui', $line)) {
                $isInTable = false;
            }

            // Try to match line item: Description + Qty + Unit Price + Total
            // Example: "استضافة سحابية متقدمة 2 500.00 1000.00"
            if (preg_match('/^(.+?)\s+(\d+(?:\.\d+)?)\s+(\d+(?:\.\d+)?)\s+(\d+(?:\.\d+)?)$/u', trim($line), $matches)) {
                $items[] = [
                    'description' => trim($matches[1]),
                    'quantity' => (float)$matches[2],
                    'unit_price' => (float)$matches[3],
                    'total' => (float)$matches[4],
                ];
            } elseif ($isInTable && preg_match('/^([^\d]+)\s+(\d+)\s+([0-9\.]+)/u', trim($line), $matches)) {
                $items[] = [
                    'description' => trim($matches[1]),
                    'quantity' => (float)$matches[2],
                    'unit_price' => (float)$matches[3],
                    'total' => round((float)$matches[2] * (float)$matches[3], 2),
                ];
            }
        }

        return $items;
    }
}
