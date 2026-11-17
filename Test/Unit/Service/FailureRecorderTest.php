<?php

declare(strict_types=1);

namespace MageCopilot\EdgeSync\Test\Unit\Service;

use MageCopilot\EdgeSync\Service\FailureRecorder;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FailureRecorderTest extends TestCase
{
    private ResourceConnection&MockObject $resourceMock;
    private AdapterInterface&MockObject $connectionMock;
    private LoggerInterface&MockObject $loggerMock;
    private FailureRecorder $recorder;

    protected function setUp(): void
    {
        $this->resourceMock = $this->createMock(ResourceConnection::class);
        $this->connectionMock = $this->createMock(AdapterInterface::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);

        $this->resourceMock->method('getConnection')->willReturn($this->connectionMock);
        $this->resourceMock->method('getTableName')->with(FailureRecorder::TABLE_NAME)->willReturn(FailureRecorder::TABLE_NAME);

        $this->recorder = new FailureRecorder($this->resourceMock, $this->loggerMock);
    }

    public function testRecordPersistsUsingInsertOnDuplicateAndNormalizesNullSku(): void
    {
        $rawPayload = '{"skus":["BAD"]}';
        $expectedHash = hash('sha256', $rawPayload);

        $this->connectionMock->expects($this->once())
            ->method('insertOnDuplicate')
            ->with(
                FailureRecorder::TABLE_NAME,
                $this->callback(function (array $row) use ($expectedHash, $rawPayload): bool {
                    return $row['topic'] === 'magecopilot.inventory.stock_update'
                        && $row['error_class'] === FailureRecorder::ERROR_CLASS_POISON
                        && $row['error_code'] === 'MALFORMED_PAYLOAD'
                        && $row['sku'] === ''
                        && $row['entity_id'] === null
                        && $row['payload_hash'] === $expectedHash
                        && $row['payload'] === $rawPayload
                        && $row['status'] === FailureRecorder::STATUS_OPEN;
                }),
                ['error_class', 'error_code', 'entity_id', 'detail', 'status']
            );

        $this->recorder->record(
            'magecopilot.inventory.stock_update',
            FailureRecorder::ERROR_CLASS_POISON,
            'MALFORMED_PAYLOAD',
            null,
            null,
            'Invalid payload',
            $rawPayload
        );
    }

    public function testRecordNeverThrowsWhenDatabaseFails(): void
    {
        $this->connectionMock->method('insertOnDuplicate')
            ->willThrowException(new \RuntimeException('DB gone away'));

        $this->loggerMock->expects($this->once())->method('error');

        $this->recorder->record(
            'magecopilot.inventory.stock_update',
            FailureRecorder::ERROR_CLASS_POISON,
            'TYPESENSE_DOC_REJECTED',
            'SKU-1',
            10,
            'Field price must be float',
            '{"skus":["SKU-1"]}'
        );
    }
}
