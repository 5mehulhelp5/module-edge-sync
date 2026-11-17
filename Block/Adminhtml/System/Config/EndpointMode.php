<?php
declare(strict_types=1);

namespace MageCopilot\EdgeSync\Block\Adminhtml\System\Config;

use MageCopilot\EdgeSync\Model\Config;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Renders a non-blocking informational badge indicating whether EdgeSync points
 * to a self-hosted Typesense node or a Managed Memory Grid cluster.
 */
class EndpointMode extends Field
{
    public const MODE_MANAGED_CLOUD = 'managed_cloud';
    public const MODE_SELF_HOSTED_LOCAL = 'self_hosted_local';
    public const MODE_SELF_HOSTED_REMOTE = 'self_hosted_remote';

    /**
     * @var string
     */
    protected $_template = 'MageCopilot_EdgeSync::system/config/endpoint_mode.phtml';

    /**
     * @var Config
     */
    private Config $edgeSyncConfig;

    /**
     * @param Context $context
     * @param Config $edgeSyncConfig
     * @param array $data
     * @phpstan-param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        Config $edgeSyncConfig,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->edgeSyncConfig = $edgeSyncConfig;
    }

    /**
     * Render informational endpoint mode badge inside system config.
     *
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element): string
    {
        $host = $this->edgeSyncConfig->getHost();
        $this->setData('endpoint_mode', self::classifyEndpointMode($host));
        $this->setData('endpoint_host', $host);

        return $this->toHtml();
    }

    /**
     * Return resolved endpoint mode identifier.
     *
     * @return string
     */
    public function getEndpointMode(): string
    {
        $mode = $this->getData('endpoint_mode');
        return is_string($mode) && $mode !== '' ? $mode : self::MODE_SELF_HOSTED_LOCAL;
    }

    /**
     * Classify configured Typesense endpoint host.
     *
     * @param string $rawHost
     * @return string
     */
    public static function classifyEndpointMode(string $rawHost): string
    {
        $stripped = trim($rawHost);
        $stripped = (string) preg_replace('#^https?://#i', '', $stripped);
        $authority = (string) strtok($stripped, '/?#');
        $host = self::extractHostname($authority);

        if ($host === '' || self::isLocalOrDockerHost($host)) {
            return self::MODE_SELF_HOSTED_LOCAL;
        }

        if (self::isManagedCloudHost($host)) {
            return self::MODE_MANAGED_CLOUD;
        }

        return self::MODE_SELF_HOSTED_REMOTE;
    }

    /**
     * Strip optional credentials and port from authority string.
     *
     * @param string $authority
     * @return string
     */
    private static function extractHostname(string $authority): string
    {
        if (str_contains($authority, '@')) {
            $parts = explode('@', $authority, 2);
            $authority = $parts[1] ?? '';
        }

        if (str_starts_with($authority, '[')) {
            $endBracket = strpos($authority, ']');
            if ($endBracket !== false) {
                return strtolower(substr($authority, 1, $endBracket - 1));
            }
        }

        $colonPos = strpos($authority, ':');
        if ($colonPos !== false) {
            $authority = substr($authority, 0, $colonPos);
        }

        return strtolower(trim($authority));
    }

    /**
     * Check whether hostname refers to localhost, Docker DNS, or private LAN IP.
     *
     * @param string $host
     * @return bool
     */
    private static function isLocalOrDockerHost(string $host): bool
    {
        if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
            return true;
        }

        if (!str_contains($host, '.')) {
            return true;
        }

        foreach (['.local', '.internal', '.test', '.localhost'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return (bool) preg_match(
            '/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[0-1])\.)/',
            $host
        );
    }

    /**
     * Check whether hostname matches Managed Memory Grid / Typesense Cloud endpoints.
     *
     * @param string $host
     * @return bool
     */
    private static function isManagedCloudHost(string $host): bool
    {
        foreach (['magecopilot.com', 'memorygrid.cloud', 'typesense.net', 'hf.space'] as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }

        return false;
    }
}
