# Contributing to DebugPHP Server

Thanks for your interest in contributing! Please read this before opening an issue or pull request.

---

## Before you start

- **For bugs:** Open a [Bug Report](https://github.com/CallMeLeon167/debugphp-server/issues/new?template=bug_report.yml) first so we can confirm the issue before you start writing code.
- **For features:** Open a [Feature Request](https://github.com/CallMeLeon167/debugphp-server/issues/new?template=feature_request.yml) first. Nothing is worse than a finished PR that doesn't get merged because the feature doesn't fit the project direction.
- **For small fixes** (typos, docs, obvious bugs): Just open a PR directly.

---

## Setup

**Requirements:** PHP 8.1+, Composer, MySQL or MariaDB.

```bash
git clone https://github.com/CallMeLeon167/debugphp-server.git
cd debugphp-server
composer install
```

Copy `.env.example` to `.env` and fill in your local database credentials, or run the setup wizard at `/setup/`.

Run PHPStan before every commit to make sure everything is clean:

```bash
composer analyse
```

---

## Project structure

```
src/
├── Application.php          — Bootstraps and wires all dependencies
├── Config.php               — Reads and exposes .env configuration
├── Request.php              — Encapsulates the incoming HTTP request
├── Router.php               — Matches routes and dispatches to controllers
├── Database/
│   ├── Connection.php       — Establishes the PDO connection
│   ├── EntryRepository.php  — DB access for debug entries
│   ├── MetricRepository.php — DB access for toolbar metrics
│   └── SessionRepository.php — DB access for sessions
└── Http/
    ├── Controller.php       — Handles all REST API routes
    └── StreamController.php — Handles the SSE stream
setup/
├── index.php                — Setup wizard entry point
├── SetupManager.php         — Setup logic (DB connection, table creation, .env writing)
└── template.php             — Setup wizard HTML templates
templates/
└── dashboard.php            — Dashboard HTML template
assets/
├── css/dashboard.css
└── js/dashboard.js
```

---

## Rules

### PHPStan Level 10 is non-negotiable

Every PHP file in `src/` and `setup/` must pass PHPStan at level 10 with zero errors. Run it before every commit:

```bash
composer analyse
```

### PHPDoc on everything

All classes, methods, and non-trivial properties need PHPDoc blocks. Look at the existing source files for the expected style — they serve as the reference.

**Good:**
```php
/**
 * Returns all entries for a session that are newer than the given ID.
 *
 * @param string $sessionId The session to query.
 * @param int    $afterId   Only return entries with an ID greater than this value.
 *
 * @return list<array{id: int, ...}> The matching entries ordered by ID ascending.
 */
public function findNewerThan(string $sessionId, int $afterId): array
```

**Not acceptable:**
```php
// get new entries
public function findNewerThan(string $sessionId, int $afterId): array
```

### Coding style

- `declare(strict_types=1)` at the top of every PHP file
- PSR-4 autoloading, namespace `DebugPHP\Server\`
- `final` classes wherever possible
- No external runtime dependencies — the `require` section in `composer.json` stays as-is (only `vlucas/phpdotenv` and `ext-pdo` / `ext-pdo_mysql`)

### Database schema changes

If your PR changes the database schema, include a migration SQL snippet in the PR description so users with an existing installation know what to run. Use `ALTER TABLE` — never drop and recreate tables with existing data.

### Dashboard changes

If you change `assets/js/dashboard.js` or `assets/css/dashboard.css`, test the dashboard manually in the browser. There are no automated frontend tests — make sure the SSE stream reconnects correctly, entries render as expected, and filters still work.

---

## Pull request checklist

Before opening a PR, make sure:

- [ ] `composer analyse` passes with zero errors
- [ ] All new public methods and classes have complete PHPDoc blocks
- [ ] `declare(strict_types=1)` is present in every new or modified PHP file
- [ ] Schema changes include a migration SQL snippet in the PR description
- [ ] Dashboard changes have been tested manually in the browser
