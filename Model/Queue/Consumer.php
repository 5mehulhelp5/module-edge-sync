<?php

declare(strict_types=1);

namespace MageCopilot\EdgeSync\Model\Queue;

use MageCopilot\EdgeSync\Api\TransientBufferInterface;
use MageCopilot\EdgeSync\Exception\PoisonMessageException;
use MageCopilot\EdgeSync\Exception\TransientSyncException;
use MageCopilot\EdgeSync\Model\Client\TypesenseClient;
use MageCopilot\EdgeSync\Model\Config;
use MageCopilot\EdgeSync\Service\FailureRecorder;
use MageCopilot\EdgeSync\Service\IndexerService;
use MageCopilot\EdgeSync\Service\RelationResolver;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\InventorySalesApi\Api\GetProductSalableQtyInterface;
use Magento\InventorySalesApi\Api\IsProductSalableInterface;
use Magento\InventorySalesApi\Api\StockResolverInterface;
use Psr\Log\LoggerInterface;

/**
 * Message Queue Consumer for Asynchronous Stock and Product Edge Synchronization.
 */
class Consumer
{
    public const TOPIC_STOCK_UPDATE = 'magecopilot.inventory.stock_update';
    public const TOPIC_PRODUCT_SYNC = 'magecopilot.edgesync.product';

    public function __construct(
        private readonly TypesenseClient $typesenseClient,
        private readonly Config $config,
        private readonly RelationResolver $relationResolver,
        private readonly Publisher $publisher,
        private readonly FailureRecorder $failureRecorder,
        private readonly TransientBufferInterface $transientBuffer,
        private readonly ?IsProductSalableInterface $isSalable,
        private readonly ?GetProductSalableQtyInterface $salableQty,
        private readonly LoggerInterface $logger,
        private readonly ?IndexerService $indexerService = null,
        private readonly ?StockResolverInterface $stockResolver = null
    ) {
    }

    /**
     * Process stock update message with batch resolution and parent coalescing.
     *
     * @param string $rawMessage
     * @return void
     * @throws PoisonMessageException
     * @throws TransientSyncException
     */
    public function processMessage(string $rawMessage): void
    {
        $this->logger->debug('[MageCopilot_EdgeSync] Stock consumer received message.', ['payload' => $rawMessage]);

        try {
            $this->handleStockMessage($rawMessage);
        } catch (PoisonMessageException | TransientSyncException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('[MageCopilot_EdgeSync] Unexpected error in stock consumer: ' . $e->getMessage());
            $this->failureRecorder->record(
                self::TOPIC_STOCK_UPDATE,
                FailureRecorder::ERROR_CLASS_POISON,
                PoisonMessageException::CODE_UNHANDLED_ERROR,
                null,
                null,
                $e->getMessage(),
                $rawMessage
            );
            $cause = $e instanceof \Exception ? $e : new \RuntimeException($e->getMessage(), (int) $e->getCode(), $e);
            throw new PoisonMessageException(
                __('Unexpected error while processing stock message: %1', $e->getMessage()),
                PoisonMessageException::CODE_UNHANDLED_ERROR,
                $cause
            );
        }
    }

    /**
     * Process product delta message for reindexing into Typesense.
     *
     * @param string $rawMessage
     * @return void
     * @throws PoisonMessageException
     * @throws TransientSyncException
     */
    public function processProductMessage(string $rawMessage): void
    {
        $this->logger->debug('[MageCopilot_EdgeSync] Product consumer received message.', ['payload' => $rawMessage]);

        try {
            $this->handleProductMessage($rawMessage);
        } catch (PoisonMessageException | TransientSyncException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('[MageCopilot_EdgeSync] Unexpected error in product consumer: ' . $e->getMessage());
            $this->failureRecorder->record(
                self::TOPIC_PRODUCT_SYNC,
                FailureRecorder::ERROR_CLASS_POISON,
                PoisonMessageException::CODE_UNHANDLED_ERROR,
                null,
                null,
                $e->getMessage(),
                $rawMessage
            );
            $cause = $e instanceof \Exception ? $e : new \RuntimeException($e->getMessage(), (int) $e->getCode(), $e);
            throw new PoisonMessageException(
                __('Unexpected error while processing product message: %1', $e->getMessage()),
                PoisonMessageException::CODE_UNHANDLED_ERROR,
                $cause
            );
        }
    }

    /**
     * Core handler for stock update messages.
     *
     * @throws PoisonMessageException
     * @throws TransientSyncException
     */
    private function handleStockMessage(string $rawMessage): void
    {
        $payload = json_decode($rawMessage, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload)) {
            $this->recordAndThrowMalformed(
                self::TOPIC_STOCK_UPDATE,
                'Malformed stock update payload: invalid JSON.',
                $rawMessage
            );
        }

        $skus = $this->extractStringList($payload['skus'] ?? null);
        if ($skus === []) {
            $this->recordAndThrowMalformed(
                self::TOPIC_STOCK_UPDATE,
                'Malformed stock update payload: missing or empty skus array.',
                $rawMessage
            );
        }

        $stockId = $this->resolveStockId($payload);
        $skuMap = $this->relationResolver->resolveIds($skus);
        $lowerSkuMap = $this->buildLowercaseSkuMap($skuMap);

        $updatedAtMs = (int) round(microtime(true) * 1000);
        $collectionName = $this->config->getCollectionName();
        $documentsToUpdate = [];
        $resolvedIds = [];

        foreach ($skus as $sku) {
            $productId = $skuMap[$sku] ?? $lowerSkuMap[strtolower($sku)] ?? null;
            if ($productId === null) {
                $this->logger->warning(sprintf('[MageCopilot_EdgeSync] Product ID not found for SKU: %s', $sku));
                $this->failureRecorder->record(
                    self::TOPIC_STOCK_UPDATE,
                    FailureRecorder::ERROR_CLASS_POISON,
                    PoisonMessageException::CODE_SKU_NOT_FOUND,
                    $sku,
                    null,
                    'SKU not found in catalog_product_entity.',
                    $rawMessage
                );
                continue;
            }

            $isAvailable = true;
            $salableQuantity = 0.0;
            if ($this->isSalable !== null && $this->salableQty !== null) {
                $isAvailable = (bool) $this->isSalable->execute($sku, $stockId);
                $salableQuantity = $isAvailable ? (float) $this->salableQty->execute($sku, $stockId) : 0.0;
            }

            $documentsToUpdate[] = [
                'id' => (string) $productId,
                'sku' => $sku,
                'is_in_stock' => $isAvailable,
                'salable_qty' => $salableQuantity,
                'updated_at_ms' => $updatedAtMs,
            ];
            $resolvedIds[] = $productId;
        }

        if ($documentsToUpdate === []) {
            throw new PoisonMessageException(
                __('None of the SKUs in the stock update message exist in catalog.'),
                PoisonMessageException::CODE_SKU_NOT_FOUND
            );
        }

        $res = $this->pushStockDocumentsWithTransientHandling($collectionName, $documentsToUpdate);
        if ($res === null) {
            return;
        }

        if ($res['failed_count'] > 0) {
            $this->recordFailedImportItems(
                self::TOPIC_STOCK_UPDATE,
                $res['items'],
                $documentsToUpdate,
                $rawMessage
            );
            if ($res['success_count'] === 0) {
                throw new PoisonMessageException(
                    __('All documents in stock update batch were rejected by Typesense.'),
                    PoisonMessageException::CODE_TYPESENSE_DOC_REJECTED
                );
            }
        }

        $parentIds = $this->relationResolver->resolveParentIds($resolvedIds);
        if ($parentIds !== []) {
            if ($this->indexerService === null) {
                $this->failureRecorder->record(
                    self::TOPIC_STOCK_UPDATE,
                    FailureRecorder::ERROR_CLASS_POISON,
                    PoisonMessageException::CODE_INDEXER_UNAVAILABLE,
                    null,
                    null,
                    'IndexerService is unavailable in Consumer DI configuration.',
                    $rawMessage
                );
                throw new PoisonMessageException(
                    __('IndexerService unavailable for parent coalescing.'),
                    PoisonMessageException::CODE_INDEXER_UNAVAILABLE
                );
            }

            try {
                $this->publisher->publishProductSync([
                    'parent_ids' => $parentIds,
                    'reason' => 'child_stock_change',
                ]);
            } catch (\Throwable $e) {
                $cause = $e instanceof \Exception ? $e : new \RuntimeException($e->getMessage(), 0, $e);
                throw new TransientSyncException(
                    __('Failed to publish parent coalescing message: %1', $e->getMessage()),
                    $cause
                );
            }
        }

        $this->logger->info(sprintf(
            '[MageCopilot_EdgeSync] Stock batch processed (skus=%d, updated=%d, failed=%d, parent_ids=%d)',
            count($skus),
            $res['success_count'],
            $res['failed_count'],
            count($parentIds)
        ));
    }

    /**
     * Core handler for product delta sync messages.
     *
     * @throws PoisonMessageException
     * @throws TransientSyncException
     */
    private function handleProductMessage(string $rawMessage): void
    {
        $payload = json_decode($rawMessage, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload)) {
            $this->recordAndThrowMalformed(
                self::TOPIC_PRODUCT_SYNC,
                'Malformed product sync payload: invalid JSON.',
                $rawMessage
            );
        }

        $ids = [];
        foreach (['ids', 'parent_ids'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                foreach ($payload[$key] as $val) {
                    if (is_numeric($val) && (int) $val > 0) {
                        $ids[] = (int) $val;
                    }
                }
            }
        }

        $skus = $this->extractStringList($payload['skus'] ?? null);
        if ($ids === [] && $skus !== []) {
            $skuMap = $this->relationResolver->resolveIds($skus);
            $lowerSkuMap = $this->buildLowercaseSkuMap($skuMap);
            foreach ($skus as $sku) {
                $resolved = $skuMap[$sku] ?? $lowerSkuMap[strtolower($sku)] ?? null;
                if ($resolved !== null) {
                    $ids[] = $resolved;
                } else {
                    $this->failureRecorder->record(
                        self::TOPIC_PRODUCT_SYNC,
                        FailureRecorder::ERROR_CLASS_POISON,
                        PoisonMessageException::CODE_SKU_NOT_FOUND,
                        $sku,
                        null,
                        'SKU not found in catalog_product_entity.',
                        $rawMessage
                    );
                }
            }
            if ($ids === []) {
                throw new PoisonMessageException(
                    __('No valid product IDs resolved from SKUs for reindexing.'),
                    PoisonMessageException::CODE_SKU_NOT_FOUND
                );
            }
        }

        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            $this->recordAndThrowMalformed(
                self::TOPIC_PRODUCT_SYNC,
                'Malformed product sync payload: no product IDs or SKUs provided.',
                $rawMessage
            );
        }

        if ($this->indexerService === null) {
            $this->failureRecorder->record(
                self::TOPIC_PRODUCT_SYNC,
                FailureRecorder::ERROR_CLASS_POISON,
                PoisonMessageException::CODE_INDEXER_UNAVAILABLE,
                null,
                null,
                'IndexerService is unavailable in Consumer DI configuration.',
                $rawMessage
            );
            throw new PoisonMessageException(
                __('IndexerService unavailable for product delta sync.'),
                PoisonMessageException::CODE_INDEXER_UNAVAILABLE
            );
        }

        try {
            $res = $this->indexerService->reindexList($ids);
        } catch (TransientSyncException | \RuntimeException $e) {
            if ($this->transientBuffer->bufferProductIds($ids, $e->getMessage())) {
                $this->logger->warning(sprintf(
                    '[MageCopilot_EdgeSync] Transient failure during product delta sync; buffered %d IDs for recovery.',
                    count($ids)
                ));
                return;
            }
            if ($e instanceof TransientSyncException) {
                throw $e;
            }
            throw new TransientSyncException(
                __('Transient failure during product delta sync: %1', $e->getMessage()),
                $e
            );
        }

        if ($res['failed'] > 0) {
            $this->failureRecorder->record(
                self::TOPIC_PRODUCT_SYNC,
                FailureRecorder::ERROR_CLASS_POISON,
                PoisonMessageException::CODE_TYPESENSE_DOC_REJECTED,
                null,
                count($ids) === 1 ? $ids[0] : null,
                sprintf('Product reindex reported %d failed document(s).', $res['failed']),
                $rawMessage
            );
            if ($res['indexed'] === 0 && $res['deleted'] === 0) {
                throw new PoisonMessageException(
                    __('All products in delta sync batch failed indexing.'),
                    PoisonMessageException::CODE_TYPESENSE_DOC_REJECTED
                );
            }
        }

        $this->logger->info(sprintf(
            '[MageCopilot_EdgeSync] Product delta batch processed (ids=%d, indexed=%d, deleted=%d, failed=%d)',
            count($ids),
            $res['indexed'],
            $res['deleted'],
            $res['failed']
        ));
    }

    /**
     * Send stock documents to Typesense, buffering in TransientBuffer on network or 5xx errors.
     *
     * @param string $collectionName
     * @param list<array<string, mixed>> $documentsToUpdate
     * @return array{success: bool, success_count: int, failed_count: int, items: list<mixed>}|null
     * @throws TransientSyncException
     */
    private function pushStockDocumentsWithTransientHandling(string $collectionName, array $documentsToUpdate): ?array
    {
        try {
            return $this->typesenseClient->updateDocuments($collectionName, $documentsToUpdate);
        } catch (TransientSyncException | \RuntimeException $e) {
            if ($this->transientBuffer->bufferStockDocuments($documentsToUpdate, $e->getMessage())) {
                $this->logger->warning(sprintf(
                    '[MageCopilot_EdgeSync] Transient Typesense failure; buffered %d stock document(s) for recovery.',
                    count($documentsToUpdate)
                ));
                return null;
            }
            if ($e instanceof TransientSyncException) {
                throw $e;
            }
            throw new TransientSyncException(
                __('Transient Typesense failure during stock sync: %1', $e->getMessage()),
                $e
            );
        }
    }

    /**
     * Record each failed Typesense import document individually.
     *
     * @param string $topic
     * @param list<mixed> $items
     * @param list<array<string, mixed>> $documentsToUpdate
     * @param string $rawMessage
     */
    private function recordFailedImportItems(
        string $topic,
        array $items,
        array $documentsToUpdate,
        string $rawMessage
    ): void {
        foreach ($items as $idx => $item) {
            if (!is_array($item) || ($item['success'] ?? false) === true) {
                continue;
            }

            $sku = null;
            $entityId = null;

            $doc = $documentsToUpdate[$idx] ?? null;
            if (is_array($doc)) {
                $sku = isset($doc['sku']) ? (string) $doc['sku'] : null;
                $entityId = isset($doc['id']) && is_numeric($doc['id']) ? (int) $doc['id'] : null;
            }

            if ($sku === null && isset($item['document'])) {
                $parsedDoc = is_string($item['document']) ? json_decode($item['document'], true) : $item['document'];
                if (is_array($parsedDoc)) {
                    $sku = isset($parsedDoc['sku']) ? (string) $parsedDoc['sku'] : null;
                    $entityId = isset($parsedDoc['id']) && is_numeric($parsedDoc['id']) ? (int) $parsedDoc['id'] : null;
                }
            }

            $detail = (string) ($item['error'] ?? 'Typesense rejected document during import.');
            $this->failureRecorder->record(
                $topic,
                FailureRecorder::ERROR_CLASS_POISON,
                PoisonMessageException::CODE_TYPESENSE_DOC_REJECTED,
                $sku,
                $entityId,
                $detail,
                $rawMessage
            );
        }
    }

    /**
     * Resolve MSI stock ID from payload sales channel or fall back to default stock 1.
     *
     * @param array<string, mixed> $payload
     */
    private function resolveStockId(array $payload): int
    {
        if ($this->stockResolver === null) {
            return 1;
        }

        try {
            $channelType = (string) ($payload['sales_channel_type'] ?? SalesChannelInterface::TYPE_WEBSITE);
            $channelCode = (string) ($payload['sales_channel_code'] ?? 'base');
            $stock = $this->stockResolver->execute($channelType, $channelCode);
            return (int) ($stock->getStockId() ?? 1);
        } catch (\Throwable) {
            return 1;
        }
    }

    /**
     * @param mixed $rawSkus
     * @return list<string>
     */
    private function extractStringList(mixed $rawSkus): array
    {
        if (!is_array($rawSkus)) {
            return [];
        }

        $skus = [];
        foreach ($rawSkus as $rawSku) {
            if (is_string($rawSku)) {
                $trimmed = trim($rawSku);
                if ($trimmed !== '') {
                    $skus[] = $trimmed;
                }
            }
        }

        return array_values(array_unique($skus));
    }

    /**
     * @param array<int|string, int> $skuMap
     * @return array<string, int>
     */
    private function buildLowercaseSkuMap(array $skuMap): array
    {
        $lowerMap = [];
        foreach ($skuMap as $sku => $id) {
            $lowerMap[strtolower((string) $sku)] = $id;
        }

        return $lowerMap;
    }

    /**
     * @throws PoisonMessageException
     */
    private function recordAndThrowMalformed(string $topic, string $detail, string $rawMessage): never
    {
        $this->failureRecorder->record(
            $topic,
            FailureRecorder::ERROR_CLASS_POISON,
            PoisonMessageException::CODE_MALFORMED_PAYLOAD,
            null,
            null,
            $detail,
            $rawMessage
        );

        throw new PoisonMessageException(
            __($detail),
            PoisonMessageException::CODE_MALFORMED_PAYLOAD
        );
    }
}
