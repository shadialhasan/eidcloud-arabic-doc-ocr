<?php

declare(strict_types=1);

spl_autoload_register(function ($class) {
    $prefix = 'EidCloud\\ArabicDocOcr\\';
    $baseDir = __DIR__ . '/../src/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

use EidCloud\ArabicDocOcr\DocOcr;
use EidCloud\ArabicDocOcr\Zatca\TlvDecoder;
use EidCloud\ArabicDocOcr\Normalizer\ArabicNormalizer;
use EidCloud\ArabicDocOcr\Extractors\InvoiceExtractor;
use EidCloud\ArabicDocOcr\Preprocessor\ImageCleaner;
use EidCloud\ArabicDocOcr\Engines\MockEngine;

class DocOcrTest
{
    private int $passes = 0;
    private int $fails = 0;

    public function runAll(): bool
    {
        echo "\033[1;36m=========================================================\033[0m\n";
        echo "\033[1;36m  Running EidCloud Arabic Doc OCR & ZATCA Test Suite     \033[0m\n";
        echo "\033[1;36m=========================================================\033[0m\n\n";

        $this->testArabicNormalizerDigits();
        $this->testArabicNormalizerTashkeel();
        $this->testArabicNormalizerLetters();
        $this->testArabicNormalizerDecimal();
        $this->testZatcaTlvEncodeDecode();
        $this->testZatcaPhase2Tags();
        $this->testZatcaValidationFailures();
        $this->testInvoiceExtractorSimplified();
        $this->testInvoiceExtractorLineItems();
        $this->testImageCleanerOperations();
        $this->testDocOcrFullPipeline();

        echo "\n\033[1;36m=========================================================\033[0m\n";
        if ($this->fails === 0) {
            echo "\033[1;32m  ALL TESTS PASSED! ({$this->passes} passed, 0 failed)\033[0m\n";
            echo "\033[1;36m=========================================================\033[0m\n";
            return true;
        } else {
            echo "\033[1;31m  SOME TESTS FAILED! ({$this->passes} passed, {$this->fails} failed)\033[0m\n";
            echo "\033[1;36m=========================================================\033[0m\n";
            return false;
        }
    }

    private function assert(string $testName, bool $condition, string $detail = ''): void
    {
        if ($condition) {
            $this->passes++;
            echo " \033[1;32m✔ PASS:\033[0m {$testName}\n";
        } else {
            $this->fails++;
            echo " \033[1;31m✘ FAIL:\033[0m {$testName} - {$detail}\n";
        }
    }

    private function testArabicNormalizerDigits(): void
    {
        $input = "رقم الطلب ٠١٢٣٤٥٦٧٨٩ وقيمة الفاتورة ۹۸۷۰";
        $converted = ArabicNormalizer::convertDigits($input);
        $this->assert("Arabic Hindi & Persian Digits to Latin", $converted === "رقم الطلب 0123456789 وقيمة الفاتورة 9870");
    }

    private function testArabicNormalizerTashkeel(): void
    {
        $input = "فَاتُورَةُ ضَرِيبِيَّةٌ مُبَسَّطَةٌ كَاشِيدَةـتَطْوِيل";
        $clean = ArabicNormalizer::stripTashkeel($input);
        $clean = ArabicNormalizer::stripTatweel($clean);
        $this->assert("Strip Tashkeel & Tatweel", $clean === "فاتورة ضريبية مبسطة كاشيدةتطويل");
    }

    private function testArabicNormalizerLetters(): void
    {
        $input = "إرسال إلى أحمد ومؤسسة التقنية";
        $norm = ArabicNormalizer::normalizeLetters($input);
        $this->assert("Normalize Alef, Yaa, Taa Marbuta", $norm === "ارسال الي احمد ومؤسسه التقنيه");
    }

    private function testArabicNormalizerDecimal(): void
    {
        $dec1 = ArabicNormalizer::parseDecimal("المبلغ: ١٢٥٠٫٥٠ ر.س");
        $dec2 = ArabicNormalizer::parseDecimal("Tax: 187,58 SAR");
        $this->assert("Parse Arabic Decimal Separator", $dec1 === 1250.5);
        $this->assert("Parse Comma Separator", $dec2 === 187.58);
    }

    private function testZatcaTlvEncodeDecode(): void
    {
        $seller = "شركة الغيمة الأولى المحدودة";
        $vat = "310123456780003";
        $time = "2026-03-20T14:30:00Z";
        $total = "1150.00";
        $vatTotal = "150.00";

        $encoded = TlvDecoder::encode([
            1 => $seller,
            2 => $vat,
            3 => $time,
            4 => $total,
            5 => $vatTotal
        ]);

        $decoded = TlvDecoder::decode($encoded);

        $this->assert("ZATCA TLV Valid Flag", $decoded['valid'] === true);
        $this->assert("ZATCA TLV Seller Name", $decoded['seller_name'] === $seller);
        $this->assert("ZATCA TLV VAT Number", $decoded['vat_number'] === $vat);
        $this->assert("ZATCA TLV Timestamp", $decoded['timestamp'] === $time);
        $this->assert("ZATCA TLV Invoice Total", $decoded['invoice_total'] === $total);
        $this->assert("ZATCA TLV VAT Total", $decoded['vat_total'] === $vatTotal);
        $this->assert("ZATCA TLV Phase 1 Detection", $decoded['phase'] === 'Phase 1');
    }

    private function testZatcaPhase2Tags(): void
    {
        $encoded = TlvDecoder::encode([
            1 => "Vendor Corp",
            2 => "300000000000003",
            3 => "2026-03-25T10:00:00Z",
            4 => "100.00",
            5 => "15.00",
            6 => "NWZlMDQ3MzRlZGE3MmQyOW...", // SHA-256 hash
            7 => "MEQCIAq5d7..."             // ECDSA Signature
        ]);

        $decoded = TlvDecoder::decode($encoded);
        $this->assert("ZATCA Phase 2 Detection", $decoded['phase'] === 'Phase 2');
        $this->assert("ZATCA Hash Preserved", !empty($decoded['invoice_hash']));
    }

    private function testZatcaValidationFailures(): void
    {
        // Missing tags & invalid VAT
        $encoded = TlvDecoder::encode([
            1 => "Acme",
            2 => "12345", // invalid KSA VAT
        ]);

        $decoded = TlvDecoder::decode($encoded);
        $this->assert("ZATCA Invalid Detection", $decoded['valid'] === false);
        $this->assert("ZATCA Error Count Check", count($decoded['errors']) >= 3);
    }

    private function testInvoiceExtractorSimplified(): void
    {
        $text = <<<INVOICE
فاتورة ضريبية مبسطة
شركة أفق السحاب الرقمية المحدودة
الرقم الضريبي: 310567890123453
رقم الفاتورة: INV-2026-4401
تاريخ الفاتورة: 2026-04-10
المجموع الفرعي: 2000.00 ر.س
ضريبة القيمة المضافة 15%: 300.00 ر.س
الإجمالي الكلي: 2300.00 ر.س
INVOICE;

        $extractor = new InvoiceExtractor();
        $data = $extractor->extract($text);

        $this->assert("Extractor Doc Type", $data['document_type'] === 'Simplified Tax Invoice');
        $this->assert("Extractor Invoice Number", $data['invoice_number'] === 'INV-2026-4401');
        $this->assert("Extractor Date", $data['date'] === '2026-04-10');
        $this->assert("Extractor Vendor", $data['vendor_name'] === 'شركة أفق السحاب الرقمية المحدودة');
        $this->assert("Extractor VAT Number", $data['vat_number'] === '310567890123453');
        $this->assert("Extractor Subtotal", $data['subtotal'] === 2000.0);
        $this->assert("Extractor Tax Amount", $data['tax_amount'] === 300.0);
        $this->assert("Extractor Grand Total", $data['total_amount'] === 2300.0);
        $this->assert("Extractor Currency", $data['currency'] === 'SAR');
    }

    private function testInvoiceExtractorLineItems(): void
    {
        $text = <<<INVOICE
فاتورة ضريبية
الوصف الكمية السعر الإجمالي
تطوير واجهات برمجية 2 750.00 1500.00
خدمات استضافة سحابية 1 500.00 500.00
المجموع الفرعي: 2000.00
الإجمالي: 2300.00
INVOICE;

        $extractor = new InvoiceExtractor();
        $data = $extractor->extract($text);

        $this->assert("Extractor Line Items Count", count($data['line_items']) === 2);
        $this->assert("Line Item 1 Description", $data['line_items'][0]['description'] === 'تطوير واجهات برمجية');
        $this->assert("Line Item 1 Total", $data['line_items'][0]['total'] === 1500.0);
    }

    private function testImageCleanerOperations(): void
    {
        // Generate a 100x100 synthetic test image in memory
        $img = imagecreatetruecolor(100, 100);
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);
        imagefilledrectangle($img, 0, 0, 100, 100, $white);
        // Draw horizontal line representing Arabic baseline
        imageline($img, 10, 50, 90, 50, $black);

        $cleaner = new ImageCleaner($img);
        $cleaner->binarize();
        $angle = $cleaner->estimateSkewAngle(5.0, 1.0);

        $tmpFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_clean.png';
        $saved = $cleaner->save($tmpFile);

        $this->assert("ImageCleaner Binarize & Deskew angle", is_float($angle));
        $this->assert("ImageCleaner Save Output", $saved && file_exists($tmpFile));

        if (file_exists($tmpFile)) {
            @unlink($tmpFile);
        }
    }

    private function testDocOcrFullPipeline(): void
    {
        $mockEngine = new MockEngine();
        $ocr = new DocOcr($mockEngine);
        $ocr->enablePreprocessing(false);

        // Create a temporary mock document file
        $tmpDoc = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_doc.txt';
        file_put_contents($tmpDoc, "فاتورة تجريبية");

        $result = $ocr->process($tmpDoc);

        $this->assert("DocOcr Pipeline Result Structure", isset($result['extracted_data'], $result['full_text']));
        $this->assert("DocOcr Engine Name Registered", $result['engine_used'] === 'Mock OCR Engine');
        $this->assert("DocOcr Extracted Invoice #", $result['extracted_data']['invoice_number'] === 'INV-2026-9812');

        if (file_exists($tmpDoc)) {
            @unlink($tmpDoc);
        }
    }
}
