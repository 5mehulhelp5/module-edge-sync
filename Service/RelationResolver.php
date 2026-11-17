<?php

declare(strict_types=1);

namespace MageCopilot\EdgeSync\Service;

use Magento\Framework\App\ResourceConnection;

/**
 * Resolves SKU to entity IDs and composite parents (configurable, bundle, grouped) in two queries.
 */
class RelationResolver
{
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * Resolve SKUs to product entity IDs in a single query.
     *
     * @param list<string> $skus
     * @return array<int|string, int> sku => entity_id
     */
    public function resolveIds(array $skus): array
    {
        $cleanSkus = array_values(array_unique(array_filter(
            $skus,
            static fn (string $sku): bool => $sku !== ''
        )));
        if ($cleanSkus === []) {
            return [];
        }

        $conn = $this->resource->getConnection();
        $select = $conn->select()
            ->from($this->resource->getTableName('catalog_product_entity'), ['sku', 'entity_id'])
            ->where('sku IN (?)', $cleanSkus);

        $pairs = $conn->fetchPairs($select);
        $resolved = [];
        foreach ($pairs as $sku => $entityId) {
            $resolved[(string) $sku] = (int) $entityId;
        }

        return $resolved;
    }

    /**
     * Resolve unique composite parent entity IDs for child IDs in a single query.
     * catalog_product_relation covers configurable, bundle, and grouped links.
     *
     * @param list<int> $childIds
     * @return list<int> unique parent entity IDs
     */
    public function resolveParentIds(array $childIds): array
    {
        $cleanIds = array_values(array_unique(array_filter(
            array_map('intval', $childIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($cleanIds === []) {
            return [];
        }

        $conn = $this->resource->getConnection();
        $select = $conn->select()
            ->distinct()
            ->from($this->resource->getTableName('catalog_product_relation'), ['parent_id'])
            ->where('child_id IN (?)', $cleanIds);

        $parentIds = array_values(array_unique(array_filter(
            array_map('intval', $conn->fetchCol($select)),
            static fn (int $id): bool => $id > 0
        )));

        return $parentIds;
    }
}
