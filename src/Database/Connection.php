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

namespace DebugPHP\Server\Database;

use PDO;

/**
 * Manages the database connection.
 *
 * This class has exactly one responsibility: establish a PDO connection
 * to the MySQL/MariaDB database and make it available to the repositories.
 *
 * Configuration is read from environment variables provided via the .env file:
 *   DB_HOST     - Database host     (default: localhost)
 *   DB_PORT     - Database port     (default: 3306)
 *   DB_DATABASE - Database name     (default: debugphp)
 *   DB_USERNAME - Database user     (default: root)
 *   DB_PASSWORD - Database password (default: empty)
 */
final class Connection
{
    /**
     * The PDO connection instance.
     */
    private PDO $pdo;

    /**
     * Establishes a new database connection.
     *
     * Reads the connection details from environment variables
     * and creates a PDO instance with sensible defaults.
     */
    public function __construct()
    {
        $host     = (string) ($_ENV['DB_HOST']     ?? 'localhost');
        $port     = (string) ($_ENV['DB_PORT']     ?? '3306');
        $database = (string) ($_ENV['DB_DATABASE'] ?? 'debugphp');
        $username = (string) ($_ENV['DB_USERNAME'] ?? 'root');
        $password = (string) ($_ENV['DB_PASSWORD'] ?? '');

        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";

        $this->pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    /**
     * Returns the PDO instance.
     *
     * Used by repositories to execute database queries.
     *
     * @return PDO The PDO connection.
     */
    public function pdo(): PDO
    {
        return $this->pdo;
    }
}
