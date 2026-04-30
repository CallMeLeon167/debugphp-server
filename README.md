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
- Composer

---

## Installation

### Option A — PHP built-in server

```bash
git clone https://github.com/CallMeLeon167/debugphp-server.git
cd debugphp-server
composer install
php -S localhost:8787
```

### Option B — Docker

```bash
git clone https://github.com/CallMeLeon167/debugphp-server.git
cd debugphp-server
docker compose -f docker-compose.yml -f docker-compose.local.yml up --build
```

The dashboard will be available at [http://localhost:8787](http://localhost:8787).

### Deploying with Coolify

Use `docker-compose.yml` as the Compose file in Coolify and assign your domain to
the `app` service on container port `8787`. The base Compose file intentionally
does not publish host ports or force a custom Docker network, so traffic goes
through Coolify's proxy instead of a direct host port mapping.

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

## Running inside Docker?

Enable auto-detection so the client finds the server automatically:

```php
Debug::init('your-session-token', [
    'dockerized' => true,
]);
```

See the [DebugPHP client repository](https://github.com/CallMeLeon167/debugphp) for the full API documentation.

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
