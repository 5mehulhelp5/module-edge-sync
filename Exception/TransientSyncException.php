<?php

declare(strict_types=1);

namespace MageCopilot\EdgeSync\Exception;

use Magento\Framework\Exception\LocalizedException;

/**
 * Thrown when Edge synchronization fails due to a transient network, timeout, or upstream 5xx condition.
 */
class TransientSyncException extends LocalizedException
{
}
