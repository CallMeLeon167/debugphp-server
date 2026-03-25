<?php

/**
 * This file is part of the DebugPHP Server.
 *
 * (c) Leon Schmidt <kontakt@callmeleon.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @see https://github.com/CallMeLeon167/debugphp-server
 */

declare(strict_types=1);

/**
 * Handles all setup logic for the DebugPHP server.
 *
 * Responsible for:
 * - Checking whether the server is already configured
 * - Testing the database connection
 * - Writing the .env file
 * - Creating the database tables
 * - Loading existing .env values for pre-filling the form
 */
final class SetupManager
{
    /**
     * Absolute path to the project root directory.
     */
    private string $rootDir;

    /**
     * Absolute path to the .env file.
     */
    private string $envPath;

    public function __construct()
    {
        $this->rootDir = dirname(__DIR__);
        $this->envPath = $this->rootDir . '/.env';
    }

    /**
     * Returns true if the .env file exists.
     */
    public function envExists(): bool
    {
        return file_exists($this->envPath);
    }

    /**
     * Checks whether the server is fully configured.
     *
     * Considers the server configured when the .env file exists,
     * the database connection works, and all required tables are present.
     *
     * @param array<string, string> $env The loaded environment variables.
     */
    public function isConfigured(array $env): bool
    {
        if (!$this->envExists()) {
            return false;
        }

        try {
            $pdo = $this->buildPdo(
                $env['DB_HOST']     ?? 'localhost',
                $env['DB_PORT']     ?? '3306',
                $env['DB_DATABASE'] ?? 'debugphp',
                $env['DB_USERNAME'] ?? 'root',
                $env['DB_PASSWORD'] ?? '',
                3,
            );

            $stmt = $pdo->query('SHOW TABLES');

            if ($stmt === false) {
                return false;
            }

            /** @var list<string> $tables */
            $tables = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            return in_array('sessions', $tables, true)
                && in_array('entries', $tables, true)
                && in_array('metrics', $tables, true);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Loads existing .env values for pre-filling the setup form.
     *
     * Returns defaults for any missing key.
     *
     * @param  array<string, string> $env The loaded environment variables.
     * @return array<string, string>
     */
    public function loadFormValues(array $env): array
    {
        return [
            'app_name'         => $env['APP_NAME']               ?? 'DebugPHP',
            'db_host'          => $env['DB_HOST']                ?? 'localhost',
            'db_port'          => $env['DB_PORT']                ?? '3306',
            'db_database'      => $env['DB_DATABASE']            ?? 'debugphp',
            'db_username'      => $env['DB_USERNAME']            ?? 'root',
            'db_password'      => $env['DB_PASSWORD']            ?? '',
            'session_lifetime' => $env['SESSION_LIFETIME_HOURS'] ?? '24',
        ];
    }

    /**
     * Tests whether the given credentials can connect to the database.
     *
     * @param  array<string, mixed> $body The decoded request body.
     * @return array{success: bool, message: string}
     */
    public function testConnection(array $body): array
    {
        try {
            $pdo = $this->buildPdo(
                is_string($body['db_host'] ?? null) ? $body['db_host'] : 'localhost',
                is_string($body['db_port'] ?? null) ? $body['db_port'] : '3306',
                is_string($body['db_database'] ?? null) ? $body['db_database'] : '',
                is_string($body['db_username'] ?? null) ? $body['db_username'] : 'root',
                is_string($body['db_password'] ?? null) ? $body['db_password'] : '',
                5,
            );

            $stmt = $pdo->query("SHOW VARIABLES LIKE 'version'");

            if ($stmt === false) {
                return ['success' => false, 'message' => 'Could not query server version.'];
            }

            /** @var array{Value: string}|false $row */
            $row     = $stmt->fetch(\PDO::FETCH_ASSOC);
            $version = is_array($row) ? $row['Value'] : 'unknown';

            return [
                'success' => true,
                'message' => "Connection successful! Server: MySQL/MariaDB {$version}",
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Connection failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Writes the .env file from the submitted form data.
     *
     * @param  array<string, mixed> $body The decoded request body.
     * @return array{success: bool, message: string}
     */
    public function saveEnv(array $body): array
    {
        $content  = "# DebugPHP Server Configuration\n";
        $content .= "# Generated by setup wizard on " . date('Y-m-d H:i:s') . "\n\n";
        $content .= "# Application\n";
        $content .= "# Database Connection\n";
        $content .= 'DB_HOST='              . (is_string($body['db_host'] ?? null) ? $body['db_host'] : 'localhost') . "\n";
        $content .= 'DB_PORT='              . (is_string($body['db_port'] ?? null) ? $body['db_port'] : '3306')      . "\n";
        $content .= 'DB_DATABASE='          . (is_string($body['db_database'] ?? null) ? $body['db_database'] : 'debugphp')  . "\n";
        $content .= 'DB_USERNAME='          . (is_string($body['db_username'] ?? null) ? $body['db_username'] : 'root')      . "\n";
        $content .= 'DB_PASSWORD='          . (is_string($body['db_password'] ?? null) ? $body['db_password'] : '')          . "\n\n";
        $content .= "# Session Settings\n";
        $content .= 'SESSION_LIFETIME_HOURS=' . (is_string($body['session_lifetime'] ?? null) ? $body['session_lifetime'] : '24')   . "\n";

        if (file_put_contents($this->envPath, $content) === false) {
            return [
                'success' => false,
                'message' => 'Failed to write .env file. Check permissions for: ' . $this->rootDir,
            ];
        }

        return ['success' => true, 'message' => '.env file saved successfully.'];
    }

    /**
     * Creates the required database tables.
     *
     * Uses CREATE TABLE IF NOT EXISTS, so running this multiple times
     * is safe and will not destroy existing data.
     *
     * Tables:
     * - sessions: Active debug sessions
     * - entries:  Sent debug entries
     * - metrics:  Toolbar metrics with request lifecycle tracking
     *               request_id — ties each metric to a specific PHP request
     *               removed_at — soft-delete timestamp for stale metric detection
     *
     * @param  array<string, mixed> $body The decoded request body.
     * @return array{success: bool, message: string}
     */
    public function createTables(array $body): array
    {
        try {
            $pdo = $this->buildPdo(
                is_string($body['db_host'] ?? null) ? $body['db_host'] : 'localhost',
                is_string($body['db_port'] ?? null) ? $body['db_port'] : '3306',
                is_string($body['db_database'] ?? null) ? $body['db_database'] : '',
                is_string($body['db_username'] ?? null) ? $body['db_username'] : 'root',
                is_string($body['db_password'] ?? null) ? $body['db_password'] : '',
            );

            $pdo->exec('
                CREATE TABLE IF NOT EXISTS sessions (
                    id VARCHAR(32) NOT NULL PRIMARY KEY,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    expires_at DATETIME NOT NULL,
                    INDEX idx_expires (expires_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ');

            $pdo->exec('
                CREATE TABLE IF NOT EXISTS entries (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    session_id VARCHAR(32) NOT NULL,
                    request_id VARCHAR(16) NOT NULL,
                    data JSON NOT NULL,
                    meta JSON NOT NULL,
                    timestamp DOUBLE NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_session_id (session_id),
                    INDEX idx_session_created (session_id, id),
                    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ');

            $pdo->exec('
                CREATE TABLE IF NOT EXISTS metrics (
                    session_id VARCHAR(32) NOT NULL,
                    `key` VARCHAR(100) NOT NULL,
                    value TEXT NULL,
                    request_id VARCHAR(16) NOT NULL DEFAULT \'\',
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    removed_at DATETIME NULL DEFAULT NULL,
                    PRIMARY KEY (session_id, `key`),
                    INDEX idx_metrics_updated (session_id, updated_at),
                    INDEX idx_metrics_removed (session_id, removed_at),
                    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ');

            return [
                'success' => true,
                'message' => 'Tables created successfully! Your DebugPHP server is ready.',
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Setup failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Builds a PDO connection with the given credentials.
     *
     * @param string   $host     Database host.
     * @param string   $port     Database port.
     * @param string   $database Database name.
     * @param string   $username Database username.
     * @param string   $password Database password.
     * @param int|null $timeout  Optional connection timeout in seconds.
     */
    private function buildPdo(
        string $host,
        string $port,
        string $database,
        string $username,
        string $password,
        ?int $timeout = null,
    ): \PDO {
        $dsn     = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ];

        if ($timeout !== null) {
            $options[\PDO::ATTR_TIMEOUT] = $timeout;
        }

        return new \PDO($dsn, $username, $password, $options);
    }
}
