<?php

namespace UcpPlugin\Checkout;

class CheckoutSessionRepository
{
    private const TRANSIENT_PREFIX = 'ucp_checkout_session_';
    private const SESSION_TTL = 1800; // 30 minutes in seconds

    /**
     * Save a checkout session.
     */
    public function save(CheckoutSession $session): void
    {
        $key = self::TRANSIENT_PREFIX . $session->getId();
        set_transient($key, $session->toArray(), self::SESSION_TTL);
    }

    /**
     * Find a checkout session by ID.
     */
    public function find(string $id): ?CheckoutSession
    {
        $key = self::TRANSIENT_PREFIX . $id;
        $data = get_transient($key);

        if ($data === false) {
            return null;
        }

        $session = CheckoutSession::fromArray($data);

        // Check if expired and update status
        if ($session->isExpired() && $session->getStatus() === CheckoutSession::STATUS_PENDING) {
            $session->markExpired();
            $this->save($session);
        }

        return $session;
    }

    /**
     * Delete a checkout session.
     */
    public function delete(string $id): bool
    {
        $key = self::TRANSIENT_PREFIX . $id;
        return delete_transient($key);
    }

    /**
     * Check if a session exists.
     */
    public function exists(string $id): bool
    {
        $key = self::TRANSIENT_PREFIX . $id;
        return get_transient($key) !== false;
    }

    /**
     * Clean up expired sessions.
     * Note: WordPress transients auto-expire, but this can be called
     * for explicit cleanup if needed.
     */
    public function cleanupExpired(): int
    {
        global $wpdb;

        $prefix = '_transient_' . self::TRANSIENT_PREFIX;
        $timeoutPrefix = '_transient_timeout_' . self::TRANSIENT_PREFIX;

        // Find expired transients
        $expired = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options}
             WHERE option_name LIKE %s
             AND option_value < %d",
            $timeoutPrefix . '%',
            time()
        ));

        $count = 0;
        foreach ($expired as $timeoutOption) {
            $transientName = str_replace('_transient_timeout_', '', $timeoutOption);
            delete_transient($transientName);
            $count++;
        }

        return $count;
    }
}
