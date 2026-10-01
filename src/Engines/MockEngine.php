<?php

declare(strict_types=1);

namespace EidCloud\ArabicDocOcr\Engines;

/**
 * Mock / Testing OCR Engine with preset responses or text passthrough.
 */
class MockEngine implements OcrEngineInterface
{
    private string $mockOutput;

    public function __construct(string $mockOutput = '')
    {
        $this->mockOutput = $mockOutput;
    }

    public function setMockOutput(string $output): void
    {
        $this->mockOutput = $output;
    }

    public function getName(): string
    {
        return 'Mock OCR Engine';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function recognize(string $imagePath, array $options = []): string
    {
        if (!empty($options['mock_text'])) {
            return (string)$options['mock_text'];
        }

        if (!empty($this->mockOutput)) {
            return $this->mockOutput;
        }

        return "فاتورة ضريبية مبسطة\nرقم الفاتورة: INV-2026-9812\nالتاريخ: 2026-03-15\nالمورد: شركة سحاب السحاب لتقنية المعلومات\nالرقم الضريبي: 310123456780003\nالمجموع الفرعي: 1500.00 ر.س\nضريبة القيمة المضافة 15%: 225.00 ر.س\nالإجمالي الكلي: 1725.00 ر.س";
    }
}
