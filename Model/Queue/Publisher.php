<?php
declare(strict_types=1);

namespace MageCopilot\EdgeSync\Model\Queue;

use Magento\Framework\MessageQueue\PublisherInterface;
use Psr\Log\LoggerInterface;

class Publisher
{
    public const TOPIC_NAME = 'magecopilot.inventory.stock_update';
    public const TOPIC_PRODUCT_SYNC = 'magecopilot.edgesync.product';

    public function __construct(
        private PublisherInterface $publisher,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Publish stock update payload to RabbitMQ.
     *
     * @param array<string, mixed> $payload
     */
    public function publish(array $payload): void
    {
        try {
            $message = json_encode($payload, JSON_THROW_ON_ERROR);
            $this->publisher->publish(self::TOPIC_NAME, $message);
            $this->logger->info(sprintf(
                '[MageCopilot_EdgeSync] Published stock update message (sku_count=%d)',
                is_array($payload['skus'] ?? null) ? count($payload['skus']) : 0
            ));
        } catch (\Throwable $e) {
            $this->logger->error('[MageCopilot_EdgeSync] Failed to publish message: ' . $e->getMessage());
        }
    }

    /**
     * Publish coalesced product or parent delta reindex message.
     *
     * @param array<string, mixed> $payload
     * @throws \Throwable
     */
    public function publishProductSync(array $payload): void
    {
        $message = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->publisher->publish(self::TOPIC_PRODUCT_SYNC, $message);
        $count = 0;
        if (is_array($payload['parent_ids'] ?? null)) {
            $count += count($payload['parent_ids']);
        }
        if (is_array($payload['ids'] ?? null)) {
            $count += count($payload['ids']);
        }
        $this->logger->info(sprintf(
            '[MageCopilot_EdgeSync] Published product delta sync message (id_count=%d, reason=%s)',
            $count,
            (string) ($payload['reason'] ?? 'unspecified')
        ));
    }
}
