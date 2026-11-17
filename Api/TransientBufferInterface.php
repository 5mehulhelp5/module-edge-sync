<?php

declare(strict_types=1);

namespace MageCopilot\EdgeSync\Api;

/**
 * Buffers transient EdgeSync failures when Typesense is unreachable so they can be drained on recovery.
 */
interface TransientBufferInterface
{
    /**
     * Buffer stock update documents and trip circuit breaker if supported.
     *
     * @param list<array<string, mixed>> $documents
     * @param string $reason
     * @return bool True if buffered and consumer may acknowledge the message; false if unhandled.
     */
    public function bufferStockDocuments(array $documents, string $reason): bool;

    /**
     * Buffer product entity IDs for delta reindex and trip circuit breaker if supported.
     *
     * @param list<int> $productIds
     * @param string $reason
     * @return bool True if buffered and consumer may acknowledge the message; false if unhandled.
     */
    public function bufferProductIds(array $productIds, string $reason): bool;
}
