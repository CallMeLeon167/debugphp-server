<div align="center">

<img src="https://raw.githubusercontent.com/CallMeLeon167/debugphp-art/refs/heads/main/DebugPHP_logo_server.png" alt="DebugPHP Server" width="400">

**Self-hosted real-time debugging dashboard for PHP.**

This is the server and dashboard component of [DebugPHP](https://github.com/CallMeLeon167/debugphp).

[![License: MIT](https://img.shields.io/badge/License-MIT-00e89d.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/PHP-^8.1-777BB4.svg)](https://php.net)
[![PHPStan](https://img.shields.io/badge/PHPStan-Level%2010-brightgreen.svg)](https://phpstan.org)

</div>

---

## Requirements

- PHP 8.1+
- MySQL 5.7+ or MariaDB 10.3+
- Composer

---

## Installation

```bash
git clone https://github.com/CallMeLeon167/debugphp-server.git
cd debugphp-server
composer install
```

Then open `http://localhost:8080/setup/` in your browser and follow the setup wizard.

The wizard will:
1. Test your database connection
2. Write the `.env` file
3. Create the required tables

---

## Configuration

All configuration lives in `.env` after setup. The available options are:

| Variable | Default | Description |
|---|---|---|
| `SITE_URL` | `http://localhost` | Base URL of the server |
| `APP_NAME` | `DebugPHP` | Application name |
| `DB_HOST` | `localhost` | Database host |
| `DB_PORT` | `3306` | Database port |
| `DB_DATABASE` | `debugphp` | Database name |
| `DB_USERNAME` | `root` | Database user |
| `DB_PASSWORD` | — | Database password |
| `SESSION_LIFETIME_HOURS` | `24` | How long a debug session stays active |

---

## Connecting your PHP app

Install the client package in your PHP application:

```bash
composer require callmeleon167/debugphp --dev
```

Then initialize it with your session token from the dashboard:

```php
use DebugPHP\Debug;

Debug::init('your-session-token', [
    'host' => 'http://localhost:8080',
]);

Debug::send('Hello DebugPHP!');
Debug::send($user, 'User')->color('blue');
```

See the [DebugPHP client repository](https://github.com/CallMeLeon167/debugphp) for the full API documentation.

---

## API

The server exposes a REST API consumed by the client package and the dashboard.

| Method | Endpoint | Description |
|---|---|---|
| `POST` | `/api/session` | Create a new debug session |
| `DELETE` | `/api/session/{id}` | Delete a session |
| `GET` | `/api/stream/{id}` | SSE stream for a session |
| `POST` | `/api/debug` | Store a debug entry |
| `DELETE` | `/api/entry/{id}` | Delete a single entry |
| `POST` | `/api/clear` | Clear all entries for a session |
| `POST` | `/api/metric` | Store or update a toolbar metric |

---

## Re-running the setup

If you need to reconfigure the server after initial setup, set `ALLOW_SETUP` to `true` in `setup/index.php`, run the wizard, then set it back to `false`.

---

## Contributing

Please read [CONTRIBUTING.md](CONTRIBUTING.md) before opening a pull request.

---

## License

MIT — see [LICENSE](LICENSE) for details.

---

## Links

- **DebugPHP Package:** [github.com/CallMeLeon167/debugphp](https://github.com/CallMeLeon167/debugphp)
- **Website:** [debugphp.dev](https://debugphp.dev)
- **Documentation:** [debugphp.dev/docs](https://debugphp.dev/docs)