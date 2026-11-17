<?php
declare(strict_types=1);

namespace MageCopilot\EdgeSync\Test\Unit\Block\Adminhtml\System\Config;

use MageCopilot\EdgeSync\Block\Adminhtml\System\Config\EndpointMode;
use PHPUnit\Framework\TestCase;

class EndpointModeTest extends TestCase
{
    public function testClassifyEndpointModeDetectsLocalManagedAndCustomRemote(): void
    {
        $this->assertSame(
            EndpointMode::MODE_SELF_HOSTED_LOCAL,
            EndpointMode::classifyEndpointMode('http://typesense:8108')
        );
        $this->assertSame(
            EndpointMode::MODE_MANAGED_CLOUD,
            EndpointMode::classifyEndpointMode('https://demo.memorygrid.cloud')
        );
        $this->assertSame(
            EndpointMode::MODE_SELF_HOSTED_REMOTE,
            EndpointMode::classifyEndpointMode('https://search.storefront-example.org')
        );
    }
}
