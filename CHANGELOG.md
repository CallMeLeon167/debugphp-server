# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-03-24

### Added
- Real-time debugging dashboard with Server-Sent Events (SSE) streaming
- REST API for sessions, entries, metrics, and clear commands
- Session management with configurable lifetime (`SESSION_LIFETIME_HOURS`)
- Debug entries with type filtering (info, sql, error, timer, success, cache, table)
- Custom label filtering in sidebar
- Full-text search across all entries
- PHP var_dump-style type renderer with syntax highlighting
- Entry detail panel with metadata (type, time, file, line, path)
- Single entry deletion with animated feedback
- Bulk clear (all entries in session)
- Live toolbar metrics (key/value chips with upsert and auto-removal)
- Editor integration — open source files directly in VS Code, VS Code Insiders, Cursor, PhpStorm, or Sublime Text
- Auto-clear on new PHP request lifecycle
- Session persistence via localStorage
- Pause/resume stream toggle
- Table rendering with row count badge and horizontal scroll
- Setup wizard with database connection test, `.env` generation, and table creation
- Repository pattern for database access (Session, Entry, Metric repositories)
- Simple regex router with controller dispatch
- PHPStan Level 10 compliance on all PHP source files
- Dependabot for Composer dependency updates

[1.0.0]: https://github.com/CallMeLeon167/debugphp-server/releases/tag/v1.0.0