<?php

declare(strict_types=1);

namespace MageCopilot\EdgeSync\Exception;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * Thrown when a queue message cannot be processed due to invalid payload, missing SKU, or broken wiring.
 */
class PoisonMessageException extends LocalizedException
{
    public const CODE_MALFORMED_PAYLOAD = 'MALFORMED_PAYLOAD';
    public const CODE_TYPESENSE_DOC_REJECTED = 'TYPESENSE_DOC_REJECTED';
    public const CODE_SKU_NOT_FOUND = 'SKU_NOT_FOUND';
    public const CODE_INDEXER_UNAVAILABLE = 'INDEXER_UNAVAILABLE';
    public const CODE_UNHANDLED_ERROR = 'UNHANDLED_ERROR';

    /**
     * @param Phrase $phrase
     * @param string $errorCode
     * @param \Exception|null $cause
     * @param int $code
     */
    public function __construct(
        Phrase $phrase,
        private readonly string $errorCode = self::CODE_MALFORMED_PAYLOAD,
        ?\Exception $cause = null,
        int $code = 0
    ) {
        parent::__construct($phrase, $cause, $code);
    }

    /**
     * Return machine-readable poison error classification code.
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
