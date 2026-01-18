<?php

declare(strict_types=1);

namespace UcpCheckout\Admin;

use UcpCheckout\Logging\LogRepository;
use UcpCheckout\Logging\UcpRequestLogger;

/**
 * Renders the UCP Debug Dashboard admin page.
 */
class DebugDashboard
{
    private const int PER_PAGE = 50;
    private const string NONCE_ACTION = 'ucp_debug_dashboard';

    public function __construct(private readonly UcpRequestLogger $logger, private readonly LogRepository $repository)
    {
    }

    /**
     * Get the logger instance.
     */
    public function getLogger(): UcpRequestLogger
    {
        return $this->logger;
    }

    /**
     * Register AJAX handlers.
     */
    public function registerAjaxHandlers(): void
    {
        add_action('wp_ajax_ucp_toggle_debug', $this->handleToggleDebug(...));
        add_action('wp_ajax_ucp_clear_logs', $this->handleClearLogs(...));
        add_action('wp_ajax_ucp_export_logs', $this->handleExportLogs(...));
    }

    /**
     * Handle debug mode toggle.
     */
    public function handleToggleDebug(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!current_user_can(AdminMenu::CAPABILITY)) {
            wp_send_json_error('Permission denied', 403);
        }

        $enabled = isset($_POST['enabled']) && $_POST['enabled'] === 'true';
        $this->logger->setDebugMode($enabled);

        wp_send_json_success(['enabled' => $enabled]);
    }

    /**
     * Handle clear logs action.
     */
    public function handleClearLogs(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!current_user_can(AdminMenu::CAPABILITY)) {
            wp_send_json_error('Permission denied', 403);
        }

        $count = $this->repository->clearAll();

        wp_send_json_success(['cleared' => $count]);
    }

    /**
     * Handle export logs action.
     */
    public function handleExportLogs(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!current_user_can(AdminMenu::CAPABILITY)) {
            wp_send_json_error('Permission denied', 403);
        }

        $filters = $this->getFiltersFromRequest();
        $json = $this->repository->exportToJson($filters);

        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="ucp-logs-' . gmdate('Y-m-d-His') . '.json"');
        echo $json;
        exit;
    }

    /**
     * Render the debug dashboard page.
     */
    public function render(): void
    {
        if (!current_user_can(AdminMenu::CAPABILITY)) {
            wp_die('Permission denied');
        }

        // Ensure the logs table exists (handles upgrades from older versions)
        if (!$this->repository->tableExists()) {
            $this->repository->createTable();
        }

        // Handle form submissions
        $this->handleFormSubmission();

        // Get current state
        $debugMode = $this->logger->isDebugMode();
        $stats = $this->repository->getStats();
        $filters = $this->getFiltersFromRequest();
        $page = max(1, (int) ($_GET['paged'] ?? 1));
        $offset = ($page - 1) * self::PER_PAGE;

        $logs = $this->repository->findRecent(self::PER_PAGE, $offset, $filters);
        $totalLogs = $this->repository->count($filters);
        $totalPages = (int) ceil($totalLogs / self::PER_PAGE);

        // Load the view
        include __DIR__ . '/views/debug-dashboard.php';
    }

    /**
     * Handle form submissions (non-AJAX fallback).
     */
    private function handleFormSubmission(): void
    {
        if (!isset($_POST['ucp_action'])) {
            return;
        }

        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', self::NONCE_ACTION)) {
            return;
        }

        switch ($_POST['ucp_action']) {
            case 'toggle_debug':
                $this->logger->setDebugMode(!$this->logger->isDebugMode());
                break;

            case 'clear_logs':
                $this->repository->clearAll();
                break;

            case 'update_retention':
                $days = (int) ($_POST['retention_days'] ?? 7);
                $this->logger->setRetentionDays($days);
                break;
        }

        // Redirect to prevent form resubmission
        wp_safe_redirect(remove_query_arg(['_wpnonce']));
        exit;
    }

    /**
     * Get filter values from the request.
     */
    private function getFiltersFromRequest(): array
    {
        $filters = [];

        if (!empty($_GET['log_type'])) {
            $filters['type'] = sanitize_text_field($_GET['log_type']);
        }

        if (!empty($_GET['endpoint'])) {
            $filters['endpoint'] = sanitize_text_field($_GET['endpoint']);
        }

        if (!empty($_GET['status_code'])) {
            $filters['status_code'] = (int) $_GET['status_code'];
        }

        if (!empty($_GET['session_id'])) {
            $filters['session_id'] = sanitize_text_field($_GET['session_id']);
        }

        if (!empty($_GET['agent'])) {
            $filters['agent'] = sanitize_text_field($_GET['agent']);
        }

        if (!empty($_GET['date_from'])) {
            $filters['date_from'] = sanitize_text_field($_GET['date_from']) . ' 00:00:00';
        }

        if (!empty($_GET['date_to'])) {
            $filters['date_to'] = sanitize_text_field($_GET['date_to']) . ' 23:59:59';
        }

        return $filters;
    }

    /**
     * Get the nonce action string.
     */
    public static function getNonceAction(): string
    {
        return self::NONCE_ACTION;
    }
}
