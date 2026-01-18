<?php

declare(strict_types=1);

namespace UcpCheckout\Admin;

/**
 * Registers the UCP admin menu items in WordPress.
 */
class AdminMenu
{
    public const CAPABILITY = 'manage_woocommerce';
    public const MENU_SLUG = 'ucp-debug';

    public function __construct(private readonly DebugDashboard $dashboard)
    {
    }

    /**
     * Register the admin menu hooks.
     */
    public function register(): void
    {
        add_action('admin_menu', $this->addMenuPages(...));
        add_action('admin_enqueue_scripts', $this->enqueueAssets(...));
    }

    /**
     * Add the menu pages to WordPress admin.
     */
    public function addMenuPages(): void
    {
        add_submenu_page(
            'woocommerce',
            'UCP Debug',
            'UCP Debug',
            self::CAPABILITY,
            self::MENU_SLUG,
            $this->dashboard->render(...)
        );
    }

    /**
     * Enqueue admin assets.
     */
    public function enqueueAssets(string $hook): void
    {
        // Only load on our page
        if ($hook !== 'woocommerce_page_' . self::MENU_SLUG) {
            return;
        }

        // Inline styles for the dashboard
        wp_add_inline_style('wp-admin', $this->getDashboardStyles());
    }

    /**
     * Get the dashboard CSS styles.
     */
    private function getDashboardStyles(): string
    {
        return <<<CSS
.ucp-debug-dashboard {
    max-width: 1400px;
}
.ucp-debug-stats {
    display: flex;
    gap: 20px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}
.ucp-stat-card {
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    padding: 15px 20px;
    min-width: 120px;
}
.ucp-stat-card h4 {
    margin: 0 0 5px 0;
    color: #1d2327;
    font-size: 13px;
}
.ucp-stat-card .stat-value {
    font-size: 24px;
    font-weight: 600;
    color: #2271b1;
}
.ucp-stat-card .stat-value.error {
    color: #d63638;
}
.ucp-debug-filters {
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    padding: 15px;
    margin-bottom: 20px;
}
.ucp-debug-filters .filter-row {
    display: flex;
    gap: 15px;
    flex-wrap: wrap;
    align-items: flex-end;
}
.ucp-debug-filters .filter-group {
    display: flex;
    flex-direction: column;
    gap: 5px;
}
.ucp-debug-filters label {
    font-weight: 600;
    font-size: 12px;
    color: #50575e;
}
.ucp-debug-filters input,
.ucp-debug-filters select {
    min-width: 120px;
}
.ucp-logs-table-wrap {
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    overflow: hidden;
}
.ucp-logs-table {
    width: 100%;
    border-collapse: collapse;
}
.ucp-logs-table th,
.ucp-logs-table td {
    padding: 10px 12px;
    text-align: left;
    border-bottom: 1px solid #c3c4c7;
}
.ucp-logs-table th {
    background: #f0f0f1;
    font-weight: 600;
}
.ucp-logs-table tr:last-child td {
    border-bottom: none;
}
.ucp-logs-table tr:hover {
    background: #f6f7f7;
}
.ucp-log-type {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}
.ucp-log-type.request { background: #dff0d8; color: #3c763d; }
.ucp-log-type.response { background: #d9edf7; color: #31708f; }
.ucp-log-type.session { background: #fcf8e3; color: #8a6d3b; }
.ucp-log-type.payment { background: #e7d4f5; color: #6b3f9c; }
.ucp-log-type.error { background: #f2dede; color: #a94442; }
.ucp-status-code {
    font-weight: 600;
}
.ucp-status-code.success { color: #3c763d; }
.ucp-status-code.client-error { color: #f0ad4e; }
.ucp-status-code.server-error { color: #d63638; }
.ucp-log-data-toggle {
    cursor: pointer;
    color: #2271b1;
    text-decoration: none;
}
.ucp-log-data-toggle:hover {
    text-decoration: underline;
}
.ucp-log-data {
    display: none;
    background: #f6f7f7;
    padding: 10px;
    margin-top: 10px;
    border-radius: 4px;
    font-family: monospace;
    font-size: 12px;
    white-space: pre-wrap;
    word-break: break-all;
    max-height: 300px;
    overflow: auto;
}
.ucp-log-data.visible {
    display: block;
}
.ucp-debug-actions {
    margin-top: 20px;
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}
.ucp-pagination {
    margin-top: 20px;
    display: flex;
    gap: 10px;
    justify-content: space-between;
    align-items: center;
}
.ucp-no-logs {
    padding: 40px;
    text-align: center;
    color: #50575e;
}
.ucp-debug-toggle {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 15px;
    background: #fff3cd;
    border: 1px solid #ffc107;
    border-radius: 4px;
    margin-bottom: 20px;
}
.ucp-debug-toggle.enabled {
    background: #d4edda;
    border-color: #28a745;
}
CSS;
    }
}
