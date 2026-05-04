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

namespace DebugPHP\Server;

/**
 * Reads configuration from dotenv and server-provided environment variables.
 */
final class Environment
{
    /**
     * @param string $key
     *
     * @return string|null
     */
    public static function get(string $key): ?string
    {
        if (isset($_ENV[$key]) && is_string($_ENV[$key])) {
            return $_ENV[$key];
        }

        if (isset($_SERVER[$key]) && is_string($_SERVER[$key])) {
            return $_SERVER[$key];
        }

        $value = getenv($key);

        return is_string($value) ? $value : null;
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, string>
     */
    public static function only(array $keys): array
    {
        $values = [];

        foreach ($keys as $key) {
            $value = self::get($key);

            if ($value !== null) {
                $values[$key] = $value;
            }
        }

        return $values;
    }
}
