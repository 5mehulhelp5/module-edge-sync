<?php

declare(strict_types=1);

namespace MageCopilot\EdgeSync\Test\Unit\Service;

use MageCopilot\EdgeSync\Service\RelationResolver;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RelationResolverTest extends TestCase
{
    private ResourceConnection&MockObject $resourceMock;
    private AdapterInterface&MockObject $connectionMock;
    private RelationResolver $resolver;

    protected function setUp(): void
    {
        $this->resourceMock = $this->createMock(ResourceConnection::class);
        $this->connectionMock = $this->createMock(AdapterInterface::class);
        $this->resourceMock->method('getConnection')->willReturn($this->connectionMock);
        $this->resourceMock->method('getTableName')->willReturnArgument(0);

        $this->resolver = new RelationResolver($this->resourceMock);
    }

    public function testResolveIdsReturnsEmptyOnEmptySkusWithoutQuerying(): void
    {
        $this->connectionMock->expects($this->never())->method('select');
        $this->assertSame([], $this->resolver->resolveIds([]));
    }

    public function testResolveIdsQueriesCatalogProductEntityInBatch(): void
    {
        $selectMock = $this->createMock(Select::class);
        $selectMock->expects($this->once())
            ->method('from')
            ->with('catalog_product_entity', ['sku', 'entity_id'])
            ->willReturnSelf();
        $selectMock->expects($this->once())
            ->method('where')
            ->with('sku IN (?)', ['SKU-A', 'SKU-B', '12345'])
            ->willReturnSelf();

        $this->connectionMock->expects($this->once())->method('select')->willReturn($selectMock);
        $this->connectionMock->expects($this->once())
            ->method('fetchPairs')
            ->with($selectMock)
            ->willReturn([
                'SKU-A' => '101',
                'SKU-B' => '102',
                12345 => '103',
            ]);

        $result = $this->resolver->resolveIds(['SKU-A', 'SKU-B', '12345', 'SKU-A']);

        $this->assertSame(101, $result['SKU-A']);
        $this->assertSame(102, $result['SKU-B']);
        $this->assertSame(103, $result['12345']);
    }

    public function testResolveParentIdsReturnsEmptyOnEmptyChildIdsWithoutQuerying(): void
    {
        $this->connectionMock->expects($this->never())->method('select');
        $this->assertSame([], $this->resolver->resolveParentIds([]));
    }

    public function testResolveParentIdsCoversCompositeTypesAndDeduplicates(): void
    {
        $selectMock = $this->createMock(Select::class);
        $selectMock->expects($this->once())->method('distinct')->willReturnSelf();
        $selectMock->expects($this->once())
            ->method('from')
            ->with('catalog_product_relation', ['parent_id'])
            ->willReturnSelf();
        $selectMock->expects($this->once())
            ->method('where')
            ->with('child_id IN (?)', [101, 102, 103])
            ->willReturnSelf();

        $this->connectionMock->expects($this->once())->method('select')->willReturn($selectMock);
        // Simulate configurable parent (500), bundle parent (600), grouped parent (700) with duplicates
        $this->connectionMock->expects($this->once())
            ->method('fetchCol')
            ->with($selectMock)
            ->willReturn(['500', '600', '500', '700', '0']);

        $parents = $this->resolver->resolveParentIds([101, 102, 103, 101]);

        $this->assertSame([500, 600, 700], $parents);
    }
}
