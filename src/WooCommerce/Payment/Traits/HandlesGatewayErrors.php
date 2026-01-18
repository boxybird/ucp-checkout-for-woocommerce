<?php

namespace UcpCheckout\WooCommerce\Payment\Traits;

/**
 * Provides common error extraction from WooCommerce notices.
 */
trait HandlesGatewayErrors
{
    /**
     * Extract error message from WooCommerce notices.
     *
     * @param string $defaultMessage Default message if no notices found
     * @return string The error message
     */
    protected function extractErrorFromNotices(string $defaultMessage = 'Payment processing failed'): string
    {
        if (!function_exists('wc_get_notices')) {
            return $defaultMessage;
        }

        $notices = wc_get_notices('error');
        if (empty($notices)) {
            return $defaultMessage;
        }

        $firstNotice = $notices[0];
        $message = $firstNotice['notice'] ?? $defaultMessage;

        // Clear notices to prevent them from showing elsewhere
        wc_clear_notices();

        return $message;
    }

    /**
     * Clear all WooCommerce notices.
     */
    protected function clearNotices(): void
    {
        if (function_exists('wc_clear_notices')) {
            wc_clear_notices();
        }
    }

    /**
     * Add an error notice to WooCommerce.
     *
     * @param string $message Error message
     */
    protected function addErrorNotice(string $message): void
    {
        if (function_exists('wc_add_notice')) {
            wc_add_notice($message, 'error');
        }
    }

    /**
     * Check if the gateway returned a successful result.
     *
     * @param array|null $result The gateway's process_payment result
     * @return bool
     */
    protected function isSuccessfulResult(?array $result): bool
    {
        return $result !== null && ($result['result'] ?? '') === 'success';
    }

    /**
     * Get redirect URL from gateway result.
     *
     * @param array|null $result The gateway's process_payment result
     * @return string|null
     */
    protected function getRedirectFromResult(?array $result): ?string
    {
        return $result['redirect'] ?? null;
    }
}
