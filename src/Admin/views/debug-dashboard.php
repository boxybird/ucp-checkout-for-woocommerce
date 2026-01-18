<?php
/**
 * UCP Debug Dashboard view template.
 *
 * @var bool $debugMode
 * @var array $stats
 * @var array $filters
 * @var int $page
 * @var array $logs
 * @var int $totalLogs
 * @var int $totalPages
 */

defined('ABSPATH') || exit;

$nonce = wp_create_nonce(\UcpCheckout\Admin\DebugDashboard::getNonceAction());
$baseUrl = admin_url('admin.php?page=' . \UcpCheckout\Admin\AdminMenu::MENU_SLUG);
?>
<div class="wrap ucp-debug-dashboard">
    <h1>UCP Debug Dashboard</h1>

    <!-- Debug Mode Toggle -->
    <div class="ucp-debug-toggle <?php echo $debugMode ? 'enabled' : ''; ?>">
        <form method="post" style="display: inline;">
            <?php wp_nonce_field(\UcpCheckout\Admin\DebugDashboard::getNonceAction()); ?>
            <input type="hidden" name="ucp_action" value="toggle_debug">
            <button type="submit" class="button">
                <?php echo $debugMode ? 'Disable' : 'Enable'; ?> Debug Mode
            </button>
        </form>
        <span>
            <?php if ($debugMode): ?>
                <strong>Debug mode is ON</strong> - Full request/response bodies are being logged.
            <?php else: ?>
                Debug mode is off - Only metadata is logged (no request/response bodies).
            <?php endif; ?>
        </span>
    </div>

    <!-- Statistics -->
    <div class="ucp-debug-stats">
        <div class="ucp-stat-card">
            <h4>Total Logs</h4>
            <div class="stat-value"><?php echo number_format($stats['total']); ?></div>
        </div>
        <div class="ucp-stat-card">
            <h4>Last 24 Hours</h4>
            <div class="stat-value"><?php echo number_format($stats['last_24h']); ?></div>
        </div>
        <div class="ucp-stat-card">
            <h4>Last 7 Days</h4>
            <div class="stat-value"><?php echo number_format($stats['last_7d']); ?></div>
        </div>
        <div class="ucp-stat-card">
            <h4>Errors (24h)</h4>
            <div class="stat-value <?php echo $stats['errors_24h'] > 0 ? 'error' : ''; ?>">
                <?php echo number_format($stats['errors_24h']); ?>
            </div>
        </div>
        <div class="ucp-stat-card">
            <h4>Avg Response Time</h4>
            <div class="stat-value"><?php echo number_format($stats['avg_duration_ms']); ?>ms</div>
        </div>
    </div>

    <!-- Filters -->
    <div class="ucp-debug-filters">
        <form method="get" action="<?php echo esc_url($baseUrl); ?>">
            <input type="hidden" name="page" value="<?php echo esc_attr(\UcpCheckout\Admin\AdminMenu::MENU_SLUG); ?>">
            <div class="filter-row">
                <div class="filter-group">
                    <label for="log_type">Type</label>
                    <select name="log_type" id="log_type">
                        <option value="">All Types</option>
                        <option value="request" <?php selected($filters['type'] ?? '', 'request'); ?>>Request</option>
                        <option value="response" <?php selected($filters['type'] ?? '', 'response'); ?>>Response</option>
                        <option value="session" <?php selected($filters['type'] ?? '', 'session'); ?>>Session</option>
                        <option value="payment" <?php selected($filters['type'] ?? '', 'payment'); ?>>Payment</option>
                        <option value="error" <?php selected($filters['type'] ?? '', 'error'); ?>>Error</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="endpoint">Endpoint</label>
                    <input type="text" name="endpoint" id="endpoint" value="<?php echo esc_attr($filters['endpoint'] ?? ''); ?>" placeholder="e.g., /checkout-sessions">
                </div>
                <div class="filter-group">
                    <label for="status_code">Status Code</label>
                    <input type="number" name="status_code" id="status_code" value="<?php echo esc_attr($filters['status_code'] ?? ''); ?>" placeholder="e.g., 200">
                </div>
                <div class="filter-group">
                    <label for="session_id">Session ID</label>
                    <input type="text" name="session_id" id="session_id" value="<?php echo esc_attr($filters['session_id'] ?? ''); ?>" placeholder="Session ID">
                </div>
                <div class="filter-group">
                    <label for="agent">Agent</label>
                    <input type="text" name="agent" id="agent" value="<?php echo esc_attr($filters['agent'] ?? ''); ?>" placeholder="e.g., ChatGPT">
                </div>
                <div class="filter-group">
                    <label for="date_from">From</label>
                    <input type="date" name="date_from" id="date_from" value="<?php echo esc_attr(isset($filters['date_from']) ? substr($filters['date_from'], 0, 10) : ''); ?>">
                </div>
                <div class="filter-group">
                    <label for="date_to">To</label>
                    <input type="date" name="date_to" id="date_to" value="<?php echo esc_attr(isset($filters['date_to']) ? substr($filters['date_to'], 0, 10) : ''); ?>">
                </div>
                <div class="filter-group">
                    <button type="submit" class="button button-primary">Filter</button>
                    <a href="<?php echo esc_url($baseUrl); ?>" class="button">Clear</a>
                </div>
            </div>
        </form>
    </div>

    <!-- Logs Table -->
    <div class="ucp-logs-table-wrap">
        <?php if (empty($logs)): ?>
            <div class="ucp-no-logs">
                <p>No logs found<?php echo !empty($filters) ? ' matching your filters' : ''; ?>.</p>
                <?php if (!empty($filters)): ?>
                    <a href="<?php echo esc_url($baseUrl); ?>" class="button">Clear Filters</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <table class="ucp-logs-table">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Type</th>
                        <th>Method</th>
                        <th>Endpoint</th>
                        <th>Status</th>
                        <th>Duration</th>
                        <th>Session</th>
                        <th>Agent</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <?php
                        $statusClass = '';
                        if ($log->statusCode) {
                            if ($log->statusCode >= 200 && $log->statusCode < 300) {
                                $statusClass = 'success';
                            } elseif ($log->statusCode >= 400 && $log->statusCode < 500) {
                                $statusClass = 'client-error';
                            } elseif ($log->statusCode >= 500) {
                                $statusClass = 'server-error';
                            }
                        }
                        ?>
                        <tr>
                            <td>
                                <span title="<?php echo esc_attr($log->createdAt?->format('Y-m-d H:i:s')); ?>">
                                    <?php echo esc_html($log->createdAt?->format('H:i:s')); ?>
                                </span>
                                <br>
                                <small><?php echo esc_html($log->createdAt?->format('M j')); ?></small>
                            </td>
                            <td>
                                <span class="ucp-log-type <?php echo esc_attr($log->type); ?>">
                                    <?php echo esc_html($log->type); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($log->method ?: '-'); ?></td>
                            <td><?php echo esc_html($log->endpoint ?: '-'); ?></td>
                            <td>
                                <?php if ($log->statusCode): ?>
                                    <span class="ucp-status-code <?php echo esc_attr($statusClass); ?>">
                                        <?php echo esc_html($log->statusCode); ?>
                                    </span>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($log->durationMs !== null): ?>
                                    <?php echo number_format($log->durationMs); ?>ms
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($log->sessionId): ?>
                                    <a href="<?php echo esc_url(add_query_arg('session_id', $log->sessionId, $baseUrl)); ?>" title="<?php echo esc_attr($log->sessionId); ?>">
                                        <?php echo esc_html(substr((string) $log->sessionId, 0, 8)); ?>...
                                    </a>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($log->agent): ?>
                                    <span title="<?php echo esc_attr($log->agent); ?>">
                                        <?php echo esc_html(strlen((string) $log->agent) > 20 ? substr((string) $log->agent, 0, 20) . '...' : $log->agent); ?>
                                    </span>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($log->data)): ?>
                                    <a href="#" class="ucp-log-data-toggle" data-log-id="<?php echo esc_attr($log->id); ?>">View</a>
                                    <div class="ucp-log-data" id="log-data-<?php echo esc_attr($log->id); ?>">
                                        <?php echo esc_html(wp_json_encode($log->data, JSON_PRETTY_PRINT)); ?>
                                    </div>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <div class="ucp-pagination">
            <span>
                Showing <?php echo number_format(($page - 1) * 50 + 1); ?>-<?php echo number_format(min($page * 50, $totalLogs)); ?>
                of <?php echo number_format($totalLogs); ?> logs
            </span>
            <div class="tablenav-pages">
                <?php
                echo paginate_links([
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                    'total' => $totalPages,
                    'current' => $page,
                ]);
                ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Actions -->
    <div class="ucp-debug-actions">
        <form method="post" style="display: inline;">
            <?php wp_nonce_field(\UcpCheckout\Admin\DebugDashboard::getNonceAction()); ?>
            <input type="hidden" name="ucp_action" value="clear_logs">
            <button type="submit" class="button" onclick="return confirm('Are you sure you want to clear all logs? This cannot be undone.');">
                Clear All Logs
            </button>
        </form>

        <a href="<?php echo esc_url(admin_url('admin-ajax.php?action=ucp_export_logs&nonce=' . $nonce)); ?>" class="button">
            Export to JSON
        </a>

        <form method="post" style="display: inline-flex; align-items: center; gap: 10px;">
            <?php wp_nonce_field(\UcpCheckout\Admin\DebugDashboard::getNonceAction()); ?>
            <input type="hidden" name="ucp_action" value="update_retention">
            <label for="retention_days">Retention:</label>
            <select name="retention_days" id="retention_days">
                <?php
                $currentRetention = $this->logger->getRetentionDays();
                foreach ([1, 3, 7, 14, 30, 60, 90] as $days):
                ?>
                    <option value="<?php echo $days; ?>" <?php selected($currentRetention, $days); ?>>
                        <?php echo $days; ?> day<?php echo $days > 1 ? 's' : ''; ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="button">Update</button>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Toggle log data visibility
    document.querySelectorAll('.ucp-log-data-toggle').forEach(function(toggle) {
        toggle.addEventListener('click', function(e) {
            e.preventDefault();
            var logId = this.dataset.logId;
            var dataEl = document.getElementById('log-data-' + logId);
            if (dataEl) {
                dataEl.classList.toggle('visible');
                this.textContent = dataEl.classList.contains('visible') ? 'Hide' : 'View';
            }
        });
    });
});
</script>
