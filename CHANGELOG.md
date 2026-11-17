# Changelog

All notable changes to `magecopilot/module-edge-sync` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-11-17

### Upgrade Note
- Run `bin/magento setup:upgrade` after updating to create the new `magecopilot_edgesync_failure` declarative schema table.

### Added
- **DLQ Failure Diagnostics (`magecopilot_edgesync_failure`)**: Added declarative schema (`etc/db_schema.xml`, `etc/db_schema_whitelist.json`) and `Service\FailureRecorder` to persist failed sync payloads idempotently in `open` status, classified by `transient` or `poison` error class.
- **Transient vs. Poison Error Separation**: Added `Exception\TransientSyncException`, `Exception\PoisonMessageException`, `Api\TransientBufferInterface`, and default `Service\NullTransientBuffer` DI preference to harden RabbitMQ consumer processing.
- **Batch Relation Resolution**: Added `Service\RelationResolver` for batch SKU-to-ID mapping and composite parent ID lookups in `Model\Queue\Consumer` and `Model\Queue\Publisher`.
- **SKU Charset Policy**: Extracted `Service\SkuCharsetPolicy` for shared SKU control-character and length validation alongside `Console\Command\SkuCharsetReportCommand`.
- **Typesense Synonym Deletion**: Added `TypesenseClient::deleteSynonym()` to delete synonym rules by ID.
- **Admin Endpoint Mode Indicator**: Added `Block\Adminhtml\System\Config\EndpointMode` (`endpoint_mode.phtml`) to display active endpoint classification (`managed_cloud`, `self_hosted_local`, or `self_hosted_remote`) in system configuration.
- **Unit Tests**: Added unit tests for `EndpointMode`, `Consumer`, `ConsumerDiWiring`, `FailureRecorder`, `RelationResolver`, and `ProductDataMapper`.

### Fixed
- **External Product Image URLs**: Preserved absolute `http://` and `https://` image URLs in `Service\ProductDataMapper` instead of prefixing them as local Magento catalog media paths.

### Changed
- **Sample Catalog Seeder**: Updated sample product imagery in `Service\Seeder\SimpleProductSeeder`.

## [1.0.1] - 2026-11-09

### Added
- Dynamic customer group price mapping (`price_group_*`) in product schema and data mapper.
- High-precision timestamp tracking (`updated_at_ms`) for change-data-capture synchronization.
- Sales channel stock resolution in inventory reservation sync plugin via `StockResolverInterface`.
- Configurable product parent change tracking in `mview.xml` on `catalog_product_super_link`.

### Changed
- Standardized asynchronous queue consumer commands in documentation (`magecopilot_typesense_product_sync` and `magecopilot_typesense_stock_sync`).
- Cleaned default placeholder API key in system configuration.
- Enforced strict Composer semver constraints.

## [1.0.0] - 2026-10-31

### Added
- Initial enterprise release of MageCopilot EdgeSync.
- Asynchronous CDC pipeline with RabbitMQ and MySQL AMQP fallback.
- In-memory Typesense indexer for catalog products and MSI stock allocations.
- Admin surgical sync inspector and live schema verification tools.
- AES-256 encrypted configuration storage for Typesense API keys.
