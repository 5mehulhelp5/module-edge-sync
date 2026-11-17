<?php

declare(strict_types=1);

namespace MageCopilot\EdgeSync\Test\Unit\Model\Queue;

use MageCopilot\EdgeSync\Api\TransientBufferInterface;
use MageCopilot\EdgeSync\Model\Queue\Consumer;
use MageCopilot\EdgeSync\Service\IndexerService;
use Magento\InventorySalesApi\Api\StockResolverInterface;
use PHPUnit\Framework\TestCase;

class ConsumerDiWiringTest extends TestCase
{
    public function testConsumerArgumentsAreExplicitlyWiredInDiXml(): void
    {
        $diPath = dirname(__DIR__, 4) . '/etc/di.xml';
        $this->assertFileExists($diPath);

        $xml = simplexml_load_file($diPath);
        $this->assertNotFalse($xml, 'di.xml must be valid XML');

        $consumerTypes = $xml->xpath(
            sprintf('/config/type[@name="%s"]', Consumer::class)
        );
        $this->assertNotEmpty($consumerTypes, 'Consumer <type> declaration missing in di.xml');

        $arguments = [];
        foreach ($consumerTypes[0]->arguments->argument ?? [] as $arg) {
            $arguments[(string) $arg['name']] = trim((string) $arg);
        }

        $this->assertSame(IndexerService::class, $arguments['indexerService'] ?? null);
        $this->assertSame(StockResolverInterface::class, $arguments['stockResolver'] ?? null);
        $this->assertSame(TransientBufferInterface::class, $arguments['transientBuffer'] ?? null);
    }

    public function testTransientBufferPreferenceExistsInEdgeSyncAndCore(): void
    {
        $edgeSyncDi = dirname(__DIR__, 4) . '/etc/di.xml';
        $xml = simplexml_load_file($edgeSyncDi);
        $this->assertNotFalse($xml);

        $prefs = $xml->xpath(
            sprintf('/config/preference[@for="%s"]', TransientBufferInterface::class)
        );
        $this->assertNotEmpty($prefs, 'EdgeSync di.xml must declare default TransientBufferInterface preference');

        $coreDi = dirname(__DIR__, 5) . '/Core/etc/di.xml';
        if (file_exists($coreDi)) {
            $coreXml = simplexml_load_file($coreDi);
            $this->assertNotFalse($coreXml);
            $corePrefs = $coreXml->xpath(
                sprintf('/config/preference[@for="%s"]', TransientBufferInterface::class)
            );
            $this->assertNotEmpty($corePrefs, 'Core di.xml must override TransientBufferInterface preference');
        }
    }
}
