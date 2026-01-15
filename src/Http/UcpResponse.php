<?php

namespace UcpCheckout\Http;

use UcpCheckout\Config\PluginConfig;
use WP_REST_Response;

class UcpResponse
{
    /**
     * Create a successful UCP-compliant response.
     * Per UCP spec, data fields are at root level alongside the `ucp` envelope.
     *
     * @param array $data The response data (merged at root level)
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
     * Wrap data with the required UCP envelope.
     * Per UCP spec, data fields are at root level alongside the `ucp` field.
     *
     * @param array $data The response data
     * @param array $activeCapabilities Override capabilities if provided
     */
    private static function wrapWithUcpEnvelope(array $data, array $activeCapabilities): array
    {
        $capabilities = !empty($activeCapabilities)
            ? $activeCapabilities
            : self::getDefaultCapabilities();

        return array_merge(
            [
                'ucp' => [
                    'version' => PluginConfig::UCP_VERSION,
                    'capabilities' => $capabilities,
                ],
            ],
            $data
        );
    }

    /**
     * Get default capabilities for responses.
     */
    private static function getDefaultCapabilities(): array
    {
        return [
            [
                'name' => 'dev.ucp.shopping.checkout',
                'version' => PluginConfig::UCP_VERSION,
            ],
        ];
    }
}
