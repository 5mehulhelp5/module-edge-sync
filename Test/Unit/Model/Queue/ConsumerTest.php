<?php

declare(strict_types=1);

namespace MageCopilot\EdgeSync\Test\Unit\Model\Queue;

use MageCopilot\EdgeSync\Api\TransientBufferInterface;
use MageCopilot\EdgeSync\Exception\PoisonMessageException;
use MageCopilot\EdgeSync\Exception\TransientSyncException;
use MageCopilot\EdgeSync\Model\Client\TypesenseClient;
use MageCopilot\EdgeSync\Model\Config;
use MageCopilot\EdgeSync\Model\Queue\Consumer;
use MageCopilot\EdgeSync\Model\Queue\Publisher;
use MageCopilot\EdgeSync\Service\FailureRecorder;
use MageCopilot\EdgeSync\Service\IndexerService;
use MageCopilot\EdgeSync\Service\RelationResolver;
use Magento\InventoryApi\Api\Data\StockInterface;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\InventorySalesApi\Api\GetProductSalableQtyInterface;
use Magento\InventorySalesApi\Api\IsProductSalableInterface;
use Magento\InventorySalesApi\Api\StockResolverInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ConsumerTest extends TestCase
{
    private TypesenseClient&MockObject $typesenseClientMock;
    private Config&MockObject $configMock;
    private RelationResolver&MockObject $relationResolverMock;
    private Publisher&MockObject $publisherMock;
    private FailureRecorder&MockObject $failureRecorderMock;
    private TransientBufferInterface&MockObject $transientBufferMock;
    private IsProductSalableInterface&MockObject $isSalableMock;
    private GetProductSalableQtyInterface&MockObject $salableQtyMock;
    private LoggerInterface&MockObject $loggerMock;
    private IndexerService&MockObject $indexerServiceMock;
    private StockResolverInterface&MockObject $stockResolverMock;
    private Consumer $consumer;

    protected function setUp(): void
    {
        $this->typesenseClientMock = $this->createMock(TypesenseClient::class);
        $this->configMock = $this->createMock(Config::class);
        $this->configMock->method('getCollectionName')->willReturn('products');

        $this->relationResolverMock = $this->createMock(RelationResolver::class);
        $this->publisherMock = $this->createMock(Publisher::class);
        $this->failureRecorderMock = $this->createMock(FailureRecorder::class);
        $this->transientBufferMock = $this->createMock(TransientBufferInterface::class);
        $this->isSalableMock = $this->createMock(IsProductSalableInterface::class);
        $this->salableQtyMock = $this->createMock(GetProductSalableQtyInterface::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->indexerServiceMock = $this->createMock(IndexerService::class);
        $this->stockResolverMock = $this->createMock(StockResolverInterface::class);

        $this->consumer = $this->createConsumer($this->indexerServiceMock, $this->stockResolverMock);
    }

    private function createConsumer(
        ?IndexerService $indexerService,
        ?StockResolverInterface $stockResolver
    ): Consumer {
        return new Consumer(
            $this->typesenseClientMock,
            $this->configMock,
            $this->relationResolverMock,
            $this->publisherMock,
            $this->failureRecorderMock,
            $this->transientBufferMock,
            $this->isSalableMock,
            $this->salableQtyMock,
            $this->loggerMock,
            $indexerService,
            $stockResolver
        );
    }

    public function testBatchOf500SkusExecutesTwoResolutionQueriesAndOneUpdateAndCoalescesParents(): void
    {
        $skus = [];
        $skuMap = [];
        $childIds = [];
        for ($i = 1; $i <= 500; $i++) {
            $sku = 'SKU-' . $i;
            $skus[] = $sku;
            $skuMap[$sku] = $i;
            $childIds[] = $i;
        }

        $stockMock = $this->createStub(StockInterface::class);
        $stockMock->method('getStockId')->willReturn(3);
        $this->stockResolverMock->expects($this->once())
            ->method('execute')
            ->with(SalesChannelInterface::TYPE_WEBSITE, 'eu_store')
            ->willReturn($stockMock);

        $this->relationResolverMock->expects($this->once())
            ->method('resolveIds')
            ->with($skus)
            ->willReturn($skuMap);

        $this->relationResolverMock->expects($this->once())
            ->method('resolveParentIds')
            ->with($childIds)
            ->willReturn([9001, 9002]);

        $this->isSalableMock->expects($this->exactly(500))
            ->method('execute')
            ->with($this->isString(), 3)
            ->willReturn(true);
        $this->salableQtyMock->expects($this->exactly(500))
            ->method('execute')
            ->with($this->isString(), 3)
            ->willReturn(12.0);

        $beforeMs = (int) round(microtime(true) * 1000) - 5;
        $this->typesenseClientMock->expects($this->once())
            ->method('updateDocuments')
            ->with(
                'products',
                $this->callback(function (array $docs) use ($beforeMs): bool {
                    return count($docs) === 500
                        && $docs[0]['id'] === '1'
                        && $docs[0]['sku'] === 'SKU-1'
                        && $docs[0]['updated_at_ms'] >= $beforeMs;
                })
            )
            ->willReturn([
                'success' => true,
                'success_count' => 500,
                'failed_count' => 0,
                'items' => [],
            ]);

        $this->publisherMock->expects($this->once())
            ->method('publishProductSync')
            ->with([
                'parent_ids' => [9001, 9002],
                'reason' => 'child_stock_change',
            ]);

        $this->indexerServiceMock->expects($this->never())->method('reindexList');
        $this->loggerMock->expects($this->once())->method('debug');

        $payload = json_encode([
            'skus' => $skus,
            'sales_channel_type' => SalesChannelInterface::TYPE_WEBSITE,
            'sales_channel_code' => 'eu_store',
            'updated_at_ms' => 1000, // stale payload timestamp must be ignored (H8)
        ], JSON_THROW_ON_ERROR);

        $this->consumer->processMessage($payload);
    }

    public function testMalformedPayloadRecordsFailureAndThrowsPoisonMessageException(): void
    {
        $raw = '{"bad": true}';

        $this->failureRecorderMock->expects($this->once())
            ->method('record')
            ->with(
                Consumer::TOPIC_STOCK_UPDATE,
                FailureRecorder::ERROR_CLASS_POISON,
                PoisonMessageException::CODE_MALFORMED_PAYLOAD,
                null,
                null,
                $this->isString(),
                $raw
            );

        $this->expectException(PoisonMessageException::class);
        $this->consumer->processMessage($raw);
    }

    public function testPartialTypesenseImportFailureRecordsNFailuresWithoutThrowing(): void
    {
        $raw = '{"skus":["SKU-1","SKU-2","SKU-3"]}';

        $this->relationResolverMock->method('resolveIds')->willReturn([
            'SKU-1' => 10,
            'SKU-2' => 20,
            'SKU-3' => 30,
        ]);
        $this->relationResolverMock->method('resolveParentIds')->willReturn([]);
        $this->isSalableMock->method('execute')->willReturn(true);
        $this->salableQtyMock->method('execute')->willReturn(5.0);

        $this->typesenseClientMock->expects($this->once())
            ->method('updateDocuments')
            ->willReturn([
                'success' => false,
                'success_count' => 1,
                'failed_count' => 2,
                'items' => [
                    ['success' => true],
                    ['success' => false, 'error' => 'Field salable_qty must be float'],
                    ['success' => false, 'error' => 'Document id 30 not found'],
                ],
            ]);

        $recordedSkus = [];
        $this->failureRecorderMock->expects($this->exactly(2))
            ->method('record')
            ->willReturnCallback(function (
                string $topic,
                string $class,
                string $code,
                ?string $sku,
                ?int $entityId,
                string $detail,
                string $payload
            ) use (&$recordedSkus, $raw): void {
                $this->assertSame(Consumer::TOPIC_STOCK_UPDATE, $topic);
                $this->assertSame(FailureRecorder::ERROR_CLASS_POISON, $class);
                $this->assertSame(PoisonMessageException::CODE_TYPESENSE_DOC_REJECTED, $code);
                $this->assertSame($raw, $payload);
                $recordedSkus[$sku ?? ''] = $entityId;
            });

        $this->consumer->processMessage($raw);
        $this->assertSame(['SKU-2' => 20, 'SKU-3' => 30], $recordedSkus);
    }

    public function testTotalTypesenseImportFailureRecordsFailuresAndThrowsPoisonMessageException(): void
    {
        $raw = '{"skus":["SKU-1"]}';

        $this->relationResolverMock->method('resolveIds')->willReturn(['SKU-1' => 10]);
        $this->isSalableMock->method('execute')->willReturn(true);
        $this->salableQtyMock->method('execute')->willReturn(5.0);

        $this->typesenseClientMock->method('updateDocuments')->willReturn([
            'success' => false,
            'success_count' => 0,
            'failed_count' => 1,
            'items' => [
                ['success' => false, 'error' => 'Schema mismatch'],
            ],
        ]);

        $this->failureRecorderMock->expects($this->once())
            ->method('record')
            ->with(
                Consumer::TOPIC_STOCK_UPDATE,
                FailureRecorder::ERROR_CLASS_POISON,
                PoisonMessageException::CODE_TYPESENSE_DOC_REJECTED,
                'SKU-1',
                10,
                'Schema mismatch',
                $raw
            );

        $this->expectException(PoisonMessageException::class);
        $this->consumer->processMessage($raw);
    }

    public function testNetworkFailureBuffersMutationAndAcknowledgesWhenCoreIsInstalled(): void
    {
        $raw = '{"skus":["SKU-1"]}';

        $this->relationResolverMock->method('resolveIds')->willReturn(['SKU-1' => 10]);
        $this->isSalableMock->method('execute')->willReturn(true);
        $this->salableQtyMock->method('execute')->willReturn(5.0);

        $this->typesenseClientMock->method('updateDocuments')
            ->willThrowException(new TransientSyncException(__('Connection refused')));

        $this->transientBufferMock->expects($this->once())
            ->method('bufferStockDocuments')
            ->with(
                $this->callback(fn (array $docs): bool => count($docs) === 1 && $docs[0]['sku'] === 'SKU-1'),
                'Connection refused'
            )
            ->willReturn(true);

        $this->publisherMock->expects($this->never())->method('publishProductSync');

        $this->consumer->processMessage($raw);
    }

    public function testNetworkFailureThrowsTransientSyncExceptionWhenBufferReturnsFalse(): void
    {
        $raw = '{"skus":["SKU-1"]}';

        $this->relationResolverMock->method('resolveIds')->willReturn(['SKU-1' => 10]);
        $this->isSalableMock->method('execute')->willReturn(true);
        $this->salableQtyMock->method('execute')->willReturn(5.0);

        $this->typesenseClientMock->method('updateDocuments')
            ->willThrowException(new TransientSyncException(__('Typesense timeout')));

        $this->transientBufferMock->expects($this->once())
            ->method('bufferStockDocuments')
            ->willReturn(false);

        $this->expectException(TransientSyncException::class);
        $this->consumer->processMessage($raw);
    }

    public function testNullIndexerServiceWithParentsThrowsPoisonIndexerUnavailable(): void
    {
        $consumer = $this->createConsumer(null, $this->stockResolverMock);

        $this->relationResolverMock->method('resolveIds')->willReturn(['CHILD-1' => 10]);
        $this->relationResolverMock->method('resolveParentIds')->willReturn([99]);
        $this->isSalableMock->method('execute')->willReturn(true);
        $this->salableQtyMock->method('execute')->willReturn(5.0);
        $this->typesenseClientMock->method('updateDocuments')->willReturn([
            'success' => true,
            'success_count' => 1,
            'failed_count' => 0,
            'items' => [],
        ]);

        try {
            $consumer->processMessage('{"skus":["CHILD-1"]}');
            $this->fail('Expected PoisonMessageException');
        } catch (PoisonMessageException $e) {
            $this->assertSame(PoisonMessageException::CODE_INDEXER_UNAVAILABLE, $e->getErrorCode());
        }
    }

    public function testPhpErrorIsWrappedInPoisonMessageException(): void
    {
        $this->relationResolverMock->method('resolveIds')
            ->willThrowException(new \TypeError('Unexpected type mismatch'));

        $this->failureRecorderMock->expects($this->once())
            ->method('record')
            ->with(
                Consumer::TOPIC_STOCK_UPDATE,
                FailureRecorder::ERROR_CLASS_POISON,
                PoisonMessageException::CODE_UNHANDLED_ERROR,
                null,
                null,
                'Unexpected type mismatch',
                '{"skus":["SKU-1"]}'
            );

        $this->expectException(PoisonMessageException::class);
        $this->consumer->processMessage('{"skus":["SKU-1"]}');
    }

    public function testProcessProductMessageResolvesSkusInBatchAndReindexes(): void
    {
        $this->relationResolverMock->expects($this->once())
            ->method('resolveIds')
            ->with(['SKU-A', 'SKU-B'])
            ->willReturn(['SKU-A' => 101, 'SKU-B' => 102]);

        $this->indexerServiceMock->expects($this->once())
            ->method('reindexList')
            ->with([101, 102])
            ->willReturn(['indexed' => 2, 'deleted' => 0, 'failed' => 0]);

        $this->consumer->processProductMessage('{"skus":["SKU-A","SKU-B"]}');
    }
}
