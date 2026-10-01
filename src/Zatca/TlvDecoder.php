<?php

declare(strict_types=1);

namespace EidCloud\ArabicDocOcr\Zatca;

/**
 * ZATCA Phase 2 / Phase 1 E-Invoicing TLV QR Code Decoder and Encoder.
 *
 * Implements Saudi ZATCA (Fatoora) specifications:
 * Tag 1: Seller's Name (اسم المورد)
 * Tag 2: VAT Registration Number (الرقم الضريبي للمورد)
 * Tag 3: Time stamp (ISO 8601 / RFC 3339) (تاريخ ووقت الفاتورة)
 * Tag 4: Invoice Total (with VAT) (إجمالي الفاتورة مع الضريبة)
 * Tag 5: VAT Total (مجموع ضريبة القيمة المضافة)
 * Tag 6: Hash of XML Invoice (Phase 2)
 * Tag 7: ECDSA Cryptographic Stamp / Signature (Phase 2)
 * Tag 8: ECDSA Public Key (Phase 2)
 * Tag 9: Cryptographic Stamp's Certificate Signature (Phase 2)
 */
class TlvDecoder
{
    public const TAG_SELLER_NAME = 1;
    public const TAG_VAT_NUMBER = 2;
    public const TAG_TIMESTAMP = 3;
    public const TAG_INVOICE_TOTAL = 4;
    public const TAG_VAT_TOTAL = 5;
    public const TAG_INVOICE_HASH = 6;
    public const TAG_ECDSA_SIGNATURE = 7;
    public const TAG_ECDSA_PUBLIC_KEY = 8;
    public const TAG_CERTIFICATE_SIGNATURE = 9;

    /**
     * Decode a base64 encoded TLV QR payload.
     *
     * @param string $base64Payload
     * @return array<string, mixed>
     */
    public static function decode(string $base64Payload): array
    {
        $cleanPayload = trim($base64Payload);
        $binary = base64_decode($cleanPayload, true);

        if ($binary === false) {
            throw new \InvalidArgumentException('Invalid Base64 payload provided.');
        }

        $length = strlen($binary);
        $offset = 0;
        $tags = [];

        while ($offset < $length) {
            if ($offset + 2 > $length) {
                break;
            }

            $tag = ord($binary[$offset]);
            $tagLength = ord($binary[$offset + 1]);
            $offset += 2;

            if ($offset + $tagLength > $length) {
                throw new \RuntimeException(sprintf(
                    'Corrupted TLV structure: Tag %d specifies length %d but only %d bytes remain.',
                    $tag,
                    $tagLength,
                    $length - $offset
                ));
            }

            $value = substr($binary, $offset, $tagLength);
            $offset += $tagLength;

            $tags[$tag] = [
                'tag' => $tag,
                'tag_name' => self::getTagName($tag),
                'length' => $tagLength,
                'value' => self::formatTagValue($tag, $value),
                'raw_bytes_hex' => bin2hex($value)
            ];
        }

        $isValid = self::validateTags($tags);

        return [
            'valid' => $isValid['valid'],
            'errors' => $isValid['errors'],
            'phase' => isset($tags[self::TAG_INVOICE_HASH]) ? 'Phase 2' : 'Phase 1',
            'seller_name' => $tags[self::TAG_SELLER_NAME]['value'] ?? null,
            'vat_number' => $tags[self::TAG_VAT_NUMBER]['value'] ?? null,
            'timestamp' => $tags[self::TAG_TIMESTAMP]['value'] ?? null,
            'invoice_total' => $tags[self::TAG_INVOICE_TOTAL]['value'] ?? null,
            'vat_total' => $tags[self::TAG_VAT_TOTAL]['value'] ?? null,
            'invoice_hash' => $tags[self::TAG_INVOICE_HASH]['value'] ?? null,
            'tags' => $tags,
        ];
    }

    /**
     * Encode an associative array or tag list into base64 TLV format.
     *
     * @param array<int|string, mixed> $data
     * @return string Base64 encoded string
     */
    public static function encode(array $data): string
    {
        $binary = '';

        $tagMap = [
            1 => $data[1] ?? $data['seller_name'] ?? null,
            2 => $data[2] ?? $data['vat_number'] ?? null,
            3 => $data[3] ?? $data['timestamp'] ?? null,
            4 => $data[4] ?? $data['invoice_total'] ?? null,
            5 => $data[5] ?? $data['vat_total'] ?? null,
            6 => $data[6] ?? $data['invoice_hash'] ?? null,
            7 => $data[7] ?? $data['signature'] ?? null,
            8 => $data[8] ?? $data['public_key'] ?? null,
            9 => $data[9] ?? $data['certificate_signature'] ?? null,
        ];

        foreach ($tagMap as $tagNumber => $val) {
            if ($val !== null && $val !== '') {
                $strVal = (string)$val;
                $len = strlen($strVal);
                $binary .= chr($tagNumber) . chr($len) . $strVal;
            }
        }

        return base64_encode($binary);
    }

    /**
     * Human-friendly tag names according to ZATCA.
     */
    public static function getTagName(int $tag): string
    {
        return match ($tag) {
            self::TAG_SELLER_NAME => 'seller_name',
            self::TAG_VAT_NUMBER => 'vat_registration_number',
            self::TAG_TIMESTAMP => 'invoice_timestamp',
            self::TAG_INVOICE_TOTAL => 'invoice_total_with_vat',
            self::TAG_VAT_TOTAL => 'vat_total',
            self::TAG_INVOICE_HASH => 'xml_invoice_hash',
            self::TAG_ECDSA_SIGNATURE => 'ecdsa_signature',
            self::TAG_ECDSA_PUBLIC_KEY => 'ecdsa_public_key',
            self::TAG_CERTIFICATE_SIGNATURE => 'certificate_signature',
            default => 'custom_tag_' . $tag,
        };
    }

    private static function formatTagValue(int $tag, string $rawBytes): string
    {
        // For binary cryptographic tags, return Base64 or Hex representation
        if (in_array($tag, [self::TAG_INVOICE_HASH, self::TAG_ECDSA_SIGNATURE, self::TAG_ECDSA_PUBLIC_KEY, self::TAG_CERTIFICATE_SIGNATURE], true)) {
            // Check if it's already printable ascii
            if (ctype_print($rawBytes)) {
                return $rawBytes;
            }
            return base64_encode($rawBytes);
        }

        return $rawBytes;
    }

    /**
     * Validate presence of mandatory tags and basic VAT rules.
     *
     * @param array<int, mixed> $tags
     * @return array{valid: bool, errors: list<string>}
     */
    public static function validateTags(array $tags): array
    {
        $errors = [];

        // Check required tags 1..5 for any standard ZATCA invoice
        for ($i = 1; $i <= 5; $i++) {
            if (!isset($tags[$i])) {
                $errors[] = sprintf('Missing mandatory ZATCA Tag %d (%s).', $i, self::getTagName($i));
            }
        }

        // Validate VAT Number (15 digits, starting and ending with 3 in KSA)
        if (isset($tags[self::TAG_VAT_NUMBER])) {
            $vat = trim((string)$tags[self::TAG_VAT_NUMBER]['value']);
            if (!preg_match('/^3\d{13}3$/', $vat) && !preg_match('/^\d{15}$/', $vat)) {
                $errors[] = "VAT number '{$vat}' should be a 15-digit number (standard Saudi format begins and ends with 3).";
            }
        }

        // Validate total and VAT amounts
        if (isset($tags[self::TAG_INVOICE_TOTAL], $tags[self::TAG_VAT_TOTAL])) {
            $total = (float)$tags[self::TAG_INVOICE_TOTAL]['value'];
            $vat = (float)$tags[self::TAG_VAT_TOTAL]['value'];
            if ($vat > $total && $total > 0) {
                $errors[] = "VAT amount ({$vat}) cannot exceed total invoice amount ({$total}).";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }
}
