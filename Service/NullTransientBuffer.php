<?php

declare(strict_types=1);

namespace MageCopilot\EdgeSync\Service;

use MageCopilot\EdgeSync\Api\TransientBufferInterface;

/**
 * Default no-op transient buffer used when MageCopilot_Core is not installed.
 */
class NullTransientBuffer implements TransientBufferInterface
{
    /**
     * @inheritdoc
     */
    public function bufferStockDocuments(array $documents, string $reason): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public function bufferProductIds(array $productIds, string $reason): bool
    {
        return false;
    }
}
