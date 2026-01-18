<?php

declare(strict_types=1);

namespace UcpCheckout\Logging;

/**
 * Repository for storing and retrieving UCP log entries.
 */
class LogRepository
{
    public const TABLE_NAME = 'ucp_logs';
    public const DEFAULT_RETENTION_DAYS = 7;

    private \wpdb $wpdb;
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . self::TABLE_NAME;
    }

    /**
     * Get the full table name.
     */
    public function getTableName(): string
    {
        return $this->table;
    }

    /**
     * Create the logs table.
     */
    public function createTable(): void
    {
        $charsetCollate = $this->wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$this->table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            request_id VARCHAR(36) NOT NULL,
            type ENUM('request','response','session','payment','error') NOT NULL,
            endpoint VARCHAR(255),
            method VARCHAR(10),
            session_id VARCHAR(64),
            agent VARCHAR(255),
            ip_address VARCHAR(45),
            status_code SMALLINT UNSIGNED,
            duration_ms INT UNSIGNED,
            data LONGTEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_request_id (request_id),
            INDEX idx_session_id (session_id),
            INDEX idx_created_at (created_at),
            INDEX idx_type (type)
        ) ENGINE=InnoDB {$charsetCollate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Drop the logs table.
     */
    public function dropTable(): void
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $this->wpdb->query("DROP TABLE IF EXISTS {$this->table}");
    }

    /**
     * Check if the logs table exists.
     */
    public function tableExists(): bool
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $this->wpdb->get_var("SHOW TABLES LIKE '{$this->table}'") === $this->table;
    }

    /**
     * Insert a log entry.
     */
    public function insert(LogEntry $entry): int
    {
        $result = $this->wpdb->insert(
            $this->table,
            [
                'request_id' => $entry->requestId,
                'type' => $entry->type,
                'endpoint' => $entry->endpoint,
                'method' => $entry->method,
                'session_id' => $entry->sessionId,
                'agent' => $entry->agent,
                'ip_address' => $entry->ipAddress,
                'status_code' => $entry->statusCode,
                'duration_ms' => $entry->durationMs,
                'data' => wp_json_encode($entry->data),
                'created_at' => $entry->createdAt?->format('Y-m-d H:i:s') ?? current_time('mysql'),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s']
        );

        if ($result === false) {
            throw new \RuntimeException('Failed to insert log entry: ' . $this->wpdb->last_error);
        }

        return (int) $this->wpdb->insert_id;
    }

    /**
     * Find a log entry by ID.
     */
    public function findById(int $id): ?LogEntry
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE id = %d",
                $id
            ),
            ARRAY_A
        );

        return $row ? LogEntry::fromRow($row) : null;
    }

    /**
     * Find all log entries for a request ID.
     */
    public function findByRequestId(string $requestId): array
    {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE request_id = %s ORDER BY created_at ASC",
                $requestId
            ),
            ARRAY_A
        );

        return array_map(fn(array $row) => LogEntry::fromRow($row), $rows);
    }

    /**
     * Find all log entries for a session ID.
     */
    public function findBySessionId(string $sessionId): array
    {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE session_id = %s ORDER BY created_at ASC",
                $sessionId
            ),
            ARRAY_A
        );

        return array_map(fn(array $row) => LogEntry::fromRow($row), $rows);
    }

    /**
     * Find recent log entries with optional filters.
     *
     * @param array{
     *     type?: string,
     *     endpoint?: string,
     *     status_code?: int,
     *     session_id?: string,
     *     agent?: string,
     *     date_from?: string,
     *     date_to?: string,
     * } $filters
     */
    public function findRecent(int $limit = 50, int $offset = 0, array $filters = []): array
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['type'])) {
            $where[] = 'type = %s';
            $params[] = $filters['type'];
        }

        if (!empty($filters['endpoint'])) {
            $where[] = 'endpoint LIKE %s';
            $params[] = '%' . $this->wpdb->esc_like($filters['endpoint']) . '%';
        }

        if (!empty($filters['status_code'])) {
            $where[] = 'status_code = %d';
            $params[] = (int) $filters['status_code'];
        }

        if (!empty($filters['session_id'])) {
            $where[] = 'session_id = %s';
            $params[] = $filters['session_id'];
        }

        if (!empty($filters['agent'])) {
            $where[] = 'agent LIKE %s';
            $params[] = '%' . $this->wpdb->esc_like($filters['agent']) . '%';
        }

        if (!empty($filters['date_from'])) {
            $where[] = 'created_at >= %s';
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[] = 'created_at <= %s';
            $params[] = $filters['date_to'];
        }

        $whereClause = implode(' AND ', $where);
        $params[] = $limit;
        $params[] = $offset;

        $sql = "SELECT * FROM {$this->table} WHERE {$whereClause} ORDER BY created_at DESC LIMIT %d OFFSET %d";

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare($sql, $params),
            ARRAY_A
        );

        return array_map(fn(array $row) => LogEntry::fromRow($row), $rows);
    }

    /**
     * Count log entries with optional filters.
     */
    public function count(array $filters = []): int
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['type'])) {
            $where[] = 'type = %s';
            $params[] = $filters['type'];
        }

        if (!empty($filters['endpoint'])) {
            $where[] = 'endpoint LIKE %s';
            $params[] = '%' . $this->wpdb->esc_like($filters['endpoint']) . '%';
        }

        if (!empty($filters['status_code'])) {
            $where[] = 'status_code = %d';
            $params[] = (int) $filters['status_code'];
        }

        if (!empty($filters['session_id'])) {
            $where[] = 'session_id = %s';
            $params[] = $filters['session_id'];
        }

        if (!empty($filters['agent'])) {
            $where[] = 'agent LIKE %s';
            $params[] = '%' . $this->wpdb->esc_like($filters['agent']) . '%';
        }

        if (!empty($filters['date_from'])) {
            $where[] = 'created_at >= %s';
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[] = 'created_at <= %s';
            $params[] = $filters['date_to'];
        }

        $whereClause = implode(' AND ', $where);

        $sql = "SELECT COUNT(*) FROM {$this->table} WHERE {$whereClause}";

        if (!empty($params)) {
            $sql = $this->wpdb->prepare($sql, $params);
        }

        return (int) $this->wpdb->get_var($sql);
    }

    /**
     * Get summary statistics.
     */
    public function getStats(): array
    {
        $now = current_time('mysql');
        $dayAgo = gmdate('Y-m-d H:i:s', strtotime('-1 day'));
        $weekAgo = gmdate('Y-m-d H:i:s', strtotime('-7 days'));

        return [
            'total' => $this->count(),
            'last_24h' => $this->count(['date_from' => $dayAgo]),
            'last_7d' => $this->count(['date_from' => $weekAgo]),
            'errors_24h' => $this->count(['type' => 'error', 'date_from' => $dayAgo]),
            'avg_duration_ms' => (int) $this->wpdb->get_var(
                "SELECT AVG(duration_ms) FROM {$this->table} WHERE duration_ms IS NOT NULL AND created_at >= '{$dayAgo}'"
            ),
        ];
    }

    /**
     * Purge logs older than the specified number of days.
     */
    public function purgeOld(int $days = self::DEFAULT_RETENTION_DAYS): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

        $deleted = $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM {$this->table} WHERE created_at < %s",
                $cutoff
            )
        );

        return $deleted !== false ? (int) $deleted : 0;
    }

    /**
     * Clear all logs.
     */
    public function clearAll(): int
    {
        $count = $this->count();
        $this->wpdb->query("TRUNCATE TABLE {$this->table}");
        return $count;
    }

    /**
     * Export logs to JSON format.
     */
    public function exportToJson(array $filters = [], ?int $limit = null): string
    {
        $logs = $this->findRecent($limit ?? 1000, 0, $filters);
        $data = array_map(fn(LogEntry $entry) => $entry->toArray(), $logs);

        return wp_json_encode([
            'exported_at' => current_time('c'),
            'count' => count($data),
            'filters' => $filters,
            'logs' => $data,
        ], JSON_PRETTY_PRINT);
    }
}
