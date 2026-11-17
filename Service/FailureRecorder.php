<?php

declare(strict_types=1);

namespace MageCopilot\EdgeSync\Service;

use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * Records EdgeSync queue failures idempotently for DLQ diagnosis and replay. Never throws.
 */
class FailureRecorder
{
    public const TABLE_NAME = 'magecopilot_edgesync_failure';
    public const ERROR_CLASS_TRANSIENT = 'transient';
    public const ERROR_CLASS_POISON = 'poison';
    public const STATUS_OPEN = 'open';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Persist or update a failure record using insertOnDuplicate on (payload_hash, sku).
     */
    public function record(
        string $topic,
        string $errorClass,
        string $errorCode,
        ?string $sku,
        ?int $entityId,
        string $detail,
        string $rawPayload
    ): void {
        try {
            $connection = $this->resource->getConnection();
            $tableName = $this->resource->getTableName(self::TABLE_NAME);

            $normalizedSku = ($sku !== null && $sku !== '') ? mb_substr($sku, 0, 64) : '';
            $payloadHash = hash('sha256', $rawPayload);

            $row = [
                'topic' => mb_substr($topic, 0, 128),
                'error_class' => mb_substr($errorClass, 0, 16),
                'error_code' => mb_substr($errorCode, 0, 64),
                'sku' => $normalizedSku,
                'entity_id' => $entityId,
                'detail' => mb_substr($detail, 0, 65000),
                'payload_hash' => $payloadHash,
                'payload' => $rawPayload,
                'status' => self::STATUS_OPEN,
            ];

            $connection->insertOnDuplicate(
                $tableName,
                $row,
                ['error_class', 'error_code', 'entity_id', 'detail', 'status']
            );
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                '[MageCopilot_EdgeSync] Failed to record sync failure (%s/%s): %s',
                $errorClass,
                $errorCode,
                $e->getMessage()
            ));
        }
    }
}
