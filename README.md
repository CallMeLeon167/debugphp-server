# DebugPHP Server

**Self-hosted real-time debugging dashboard for PHP.**

This is the server and dashboard component of [DebugPHP](https://github.com/CallMeLeon167/debugphp). It receives debug data from your PHP application and displays it in a browser-based dashboard in real-time via Server-Sent Events (SSE).

[![License: MIT](https://img.shields.io/badge/License-MIT-00e89d.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/PHP-^8.1-777BB4.svg)](https://php.net)

---

## Requirements

- PHP 8.1+
- MySQL 5.7+ or MariaDB 10.3+
- Apache with `mod_rewrite` enabled
- `ext-pdo` and `ext-pdo_mysql`
- Composer

## Installation

```bash
# 1. Clone the repository
git clone https://github.com/CallMeLeon167/debugphp-server.git
cd debugphp-server

# 2. Install dependencies
composer install

# 3. Create the database
mysql -u root -p -e "CREATE DATABASE debugphp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 4. Open the setup wizard in your browser
# http://your-domain.com/setup/
```

The **Setup Wizard** guides you through the entire configuration:

1. **Enter your database credentials** — host, port, database name, username, password
2. **Test the connection** — verifies the credentials before saving
3. **Save .env** — the wizard creates the `.env` file for you
4. **Create tables** — sets up the `sessions` and `entries` tables automatically

After setup, the dashboard is available at `/`.

If you revisit `/setup/` later, it will show you that DebugPHP is already configured and ready. If you need to reconfigure the server (e.g. changed database credentials), open `setup/index.php` and set the flag at the top of the file:

```php
const ALLOW_SETUP = true;
```

This unlocks the setup wizard again. After reconfiguring, set it back to `false`.

## Configuration

All configuration is managed via the `.env` file (created by the setup wizard):

```env
# Database Connection
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=debugphp
DB_USERNAME=root
DB_PASSWORD=secret

# Session lifetime (hours)
SESSION_LIFETIME_HOURS=24
```

| Variable | Default | Description |
|---|---|---|
| `DB_HOST` | `localhost` | MySQL/MariaDB host |
| `DB_PORT` | `3306` | Database port |
| `DB_DATABASE` | `debugphp` | Database name |
| `DB_USERNAME` | `root` | Database username |
| `DB_PASSWORD` | *(empty)* | Database password |
| `SESSION_LIFETIME_HOURS` | `24` | How long a debug session stays active |

## Connecting Your App

Install the [DebugPHP Composer package](https://github.com/CallMeLeon167/debugphp) in your PHP project:

```bash
composer require callmeleon167/debugphp --dev
```

Point it to your self-hosted server:

```php
use DebugPHP\Debug;

Debug::init('your-session-token', [
    'host' => 'http://your-server.com',
]);

Debug::send('Hello from my app!');
```

The session token is displayed in the dashboard when you click **"+ New Session"**.

## API Endpoints

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/` | Dashboard |
| `POST` | `/api/session` | Create a new session |
| `DELETE` | `/api/session/{id}` | Delete a session |
| `POST` | `/api/debug` | Send a debug entry |
| `POST` | `/api/clear` | Clear all entries for a session |
| `GET` | `/api/stream/{id}` | SSE stream for live updates |

## Apache Configuration

The included `.htaccess` handles everything automatically:

- URL rewriting to `index.php`
- Output buffering disabled for SSE streams
- CORS headers for cross-origin requests
- Security headers (no directory listing, no access to sensitive files)

Make sure `mod_rewrite` is enabled:

```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

## How It Works

```
┌──────────────┐     POST /api/debug     ┌──────────────┐     SSE Stream     ┌──────────────┐
│   Your App   │ ──────────────────────→ │   DebugPHP   │ ────────────────→  │  Dashboard   │
│  Debug::send │                         │    Server    │                    │   (Browser)  │
└──────────────┘                         └──────────────┘                    └──────────────┘
```

1. Your PHP app sends debug data via HTTP POST to `/api/debug`.
2. The server stores the entry in MySQL.
3. The dashboard connects via SSE (`/api/stream/{id}`) and receives new entries in real-time.

## License

MIT — see [LICENSE](LICENSE) for details.

## Links

- **DebugPHP Package:** [github.com/CallMeLeon167/debugphp](https://github.com/CallMeLeon167/debugphp)
- **Website:** [debugphp.dev](https://debugphp.dev)
- **Documentation:** [debugphp.dev/docs](https://debugphp.dev/docs)