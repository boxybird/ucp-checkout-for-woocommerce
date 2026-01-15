<?php

namespace UcpPlugin\Http;

use UcpPlugin\Config\PluginConfig;
use WP_REST_Response;

class UcpResponse
{
    /**
     * Create a successful UCP-compliant response.
     *
     * @param array $data The response data
     * @param array $activeCapabilities Optional override for capabilities
     * @param int $statusCode HTTP status code
     */
    public static function success(
        array $data,
        array $activeCapabilities = [],
        int $statusCode = 200
    ): WP_REST_Response {
        $response = new WP_REST_Response(
            self::wrapWithUcpEnvelope($data, $activeCapabilities),
            $statusCode
        );

        return $response;
    }

    /**
     * Create an array response (for search results, etc.) with UCP envelope.
     *
     * @param array $items The array of items
     * @param string $key The key to use for the items in the response
     * @param array $activeCapabilities Optional override for capabilities
     */
    public static function collection(
        array $items,
        string $key = 'results',
        array $activeCapabilities = []
    ): WP_REST_Response {
        return self::success([$key => $items], $activeCapabilities);
    }

    /**
     * Wrap data with the required UCP envelope.
     *
     * @param array $data The response data
     * @param array $activeCapabilities Override capabilities if provided
     */
    private static function wrapWithUcpEnvelope(array $data, array $activeCapabilities): array
    {
        $capabilities = !empty($activeCapabilities)
            ? $activeCapabilities
            : self::getDefaultCapabilities();

        return [
            'ucp' => [
                'version' => PluginConfig::UCP_VERSION,
                'capabilities' => $capabilities,
            ],
            'data' => $data,
        ];
    }

    /**
     * Get default capabilities for responses.
     */
    private static function getDefaultCapabilities(): array
    {
        return [
            [
                'name' => 'dev.ucp.product-search',
                'version' => PluginConfig::UCP_VERSION,
            ],
            [
                'name' => 'dev.ucp.availability',
                'version' => PluginConfig::UCP_VERSION,
            ],
            [
                'name' => 'dev.ucp.shipping-estimate',
                'version' => PluginConfig::UCP_VERSION,
            ],
            [
                'name' => 'dev.ucp.checkout',
                'version' => PluginConfig::UCP_VERSION,
            ],
        ];
    }
}
