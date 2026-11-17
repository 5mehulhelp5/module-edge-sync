<?php

/**
 * MageCopilot - EdgeSync Typesense Connector
 *
 * @category  MageCopilot
 * @package   MageCopilot_EdgeSync
 * @author    MageCopilot Team
 * @copyright Copyright (c) 2026 MageCopilot
 * @license   OSL-3.0
 */

declare(strict_types=1);

namespace MageCopilot\EdgeSync\Service;

/**
 * Shared SKU charset validation and catalog audit policy.
 *
 * Rejects dangerous ASCII control characters and null bytes while allowing
 * spaces, symbols, and valid UTF-8 characters up to Magento's 64-char limit.
 */
class SkuCharsetPolicy
{
    public const CONTROL_CHAR_PCRE = '/[\x00-\x1F\x7F]/';
    public const NON_ASCII_PCRE = '/[^\x00-\x7F]/u';
    public const RECOMMENDED_ZOD_REGEX = '/^[^\u0000-\u001F\u007F]+$/u';
    public const MAX_SKU_LENGTH = 64;

    /**
     * Validate a single SKU against EdgeSync charset and length policies.
     *
     * @param string $sku
     * @return array{
     *     valid: bool,
     *     error_code: string|null,
     *     reason: string|null,
     *     has_control_chars: bool,
     *     has_unicode: bool,
     *     special_chars: list<string>
     * }
     */
    public function validate(string $sku): array
    {
        $hasControl = $this->hasControlCharacters($sku);
        $hasUnicode = $this->hasNonAsciiCharacters($sku);
        $specialChars = $this->extractSpecialCharacters($sku);

        if (trim($sku) === '') {
            return [
                'valid' => false,
                'error_code' => 'EMPTY_SKU',
                'reason' => 'Product SKU is empty or whitespace-only.',
                'has_control_chars' => $hasControl,
                'has_unicode' => $hasUnicode,
                'special_chars' => $specialChars,
            ];
        }

        if ($hasControl) {
            return [
                'valid' => false,
                'error_code' => 'SKU_CONTROL_CHAR',
                'reason' => 'Product SKU contains ASCII control characters or null bytes incompatible with Typesense IDs/Zod validation.',
                'has_control_chars' => true,
                'has_unicode' => $hasUnicode,
                'special_chars' => $specialChars,
            ];
        }

        if (mb_strlen($sku, 'UTF-8') > self::MAX_SKU_LENGTH) {
            return [
                'valid' => false,
                'error_code' => 'SKU_TOO_LONG',
                'reason' => sprintf(
                    'Product SKU exceeds %d characters (%d chars).',
                    self::MAX_SKU_LENGTH,
                    mb_strlen($sku, 'UTF-8')
                ),
                'has_control_chars' => false,
                'has_unicode' => $hasUnicode,
                'special_chars' => $specialChars,
            ];
        }

        return [
            'valid' => true,
            'error_code' => null,
            'reason' => null,
            'has_control_chars' => false,
            'has_unicode' => $hasUnicode,
            'special_chars' => $specialChars,
        ];
    }

    /**
     * Analyze a collection of SKU strings and return aggregate charset statistics.
     *
     * @param iterable<string> $skus
     * @return array{
     *     total_skus: int,
     *     min_len: int,
     *     max_len: int,
     *     avg_len: float,
     *     has_unicode: bool,
     *     has_control_chars: bool,
     *     control_char_examples: list<string>,
     *     special_chars_map: array<string, int>,
     *     recommended_regex: string
     * }
     */
    public function analyzeBatch(iterable $skus): array
    {
        $totalSkus = 0;
        $minLen = PHP_INT_MAX;
        $maxLen = 0;
        $totalLen = 0;
        $specialCharsMap = [];
        $hasUnicode = false;
        $hasControlChars = false;
        $controlCharExamples = [];

        foreach ($skus as $rawSku) {
            $sku = (string) $rawSku;
            $totalSkus++;
            $len = mb_strlen($sku, 'UTF-8');

            if ($len < $minLen) {
                $minLen = $len;
            }
            if ($len > $maxLen) {
                $maxLen = $len;
            }
            $totalLen += $len;

            if ($this->hasControlCharacters($sku)) {
                $hasControlChars = true;
                if (count($controlCharExamples) < 5) {
                    $controlCharExamples[] = (string) json_encode($sku);
                }
            }

            if ($this->hasNonAsciiCharacters($sku)) {
                $hasUnicode = true;
            }

            foreach ($this->extractSpecialCharacters($sku) as $char) {
                $specialCharsMap[$char] = ($specialCharsMap[$char] ?? 0) + 1;
            }
        }

        return [
            'total_skus' => $totalSkus,
            'min_len' => $totalSkus > 0 ? $minLen : 0,
            'max_len' => $maxLen,
            'avg_len' => $totalSkus > 0 ? round($totalLen / $totalSkus, 2) : 0.0,
            'has_unicode' => $hasUnicode,
            'has_control_chars' => $hasControlChars,
            'control_char_examples' => $controlCharExamples,
            'special_chars_map' => $specialCharsMap,
            'recommended_regex' => self::RECOMMENDED_ZOD_REGEX,
        ];
    }

    /**
     * Return true if SKU contains ASCII control characters (0x00-0x1F or 0x7F).
     *
     * @param string $sku
     * @return bool
     */
    public function hasControlCharacters(string $sku): bool
    {
        return preg_match(self::CONTROL_CHAR_PCRE, $sku) === 1;
    }

    /**
     * Return true if SKU contains non-ASCII UTF-8 characters.
     *
     * @param string $sku
     * @return bool
     */
    public function hasNonAsciiCharacters(string $sku): bool
    {
        return preg_match(self::NON_ASCII_PCRE, $sku) === 1;
    }

    /**
     * Extract unique non-alphanumeric single-byte characters present in SKU.
     *
     * @param string $sku
     * @return list<string>
     */
    public function extractSpecialCharacters(string $sku): array
    {
        $chars = [];
        $lenRaw = strlen($sku);
        for ($i = 0; $i < $lenRaw; $i++) {
            $char = $sku[$i];
            if (!ctype_alnum($char)) {
                $chars[] = $char;
            }
        }

        return $chars;
    }
}
