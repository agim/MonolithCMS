# CLAUDE.md — OneCMS Codebase Guide

This document provides AI assistants with the context needed to work effectively on the OneCMS project.

---

## Project Overview

**OneCMS** is a single-file, AI-powered website builder and CMS built in PHP with SQLite. The entire application lives in `index.php` (~15,700 lines) with no external dependencies. It supports multi-provider AI (OpenAI, Anthropic, Google) to generate website content through a human-in-the-loop approval workflow.

**Key Design Philosophy:**
- Zero external dependencies — no Composer packages, no npm
- Portable deployment — copy `index.php` + `site.sqlite` and it runs
- Single-file monolith, but organized into 38+ static utility classes
- Production-grade security built-in (bcrypt, AES-256-CBC, CSRF, MFA, rate limiting)

---

## Repository Structure

```
/home/user/onecms/
├── index.php                  # Complete application (~15,700 lines)
├── site.sqlite                # SQLite database (auto-created on first run)
├── cache/                     # Auto-generated at runtime (gitignored)
│   ├── pages/                 # Full-page HTML cache
│   ├── partials/              # Reusable HTML fragment cache (nav, footer)
│   └── assets/                # Extracted CSS/JS/image files from BLOBs
├── docs/
│   ├── app.md                 # Full feature and architecture specification
│   ├── implementation-plan.md # Phased implementation roadmap
│   └── ui-ux-requirement.md   # React UI/UX patterns reference
├── .github/workflows/
│   └── php.yml                # GitHub Actions CI (Composer validation)
└── .gitignore                 # Excludes cache/, *.sqlite, .env, logs
```

---

## Tech Stack

| Layer | Technology |
|---|---|
| Language | PHP (PDO, OpenSSL, file functions) |
| Database | SQLite with WAL mode, FK constraints |
| Frontend | Server-side rendered HTML, custom template engine |
| Styling | Dynamic CSS generation via `CSSGenerator` class |
| AI Providers | OpenAI (gpt-5.2), Anthropic (claude-sonnet-4-6), Google (gemini-2.0-flash-exp) |
| Security | bcrypt (cost 12), AES-256-CBC, CSRF tokens, MFA |
| Caching | File-based: page, partial, and CSS caching |
| Email | SMTP (configurable) |
| CI/CD | GitHub Actions (Composer validation) |

---

## Running the Application

```bash
# Start development server
php -S 127.0.0.1:8080 index.php

# Access the application
# First run → http://localhost:8080/setup   (setup wizard)
# After setup → http://localhost:8080/admin/login

# Direct database access
sqlite3 site.sqlite
```

No build step, no package manager, no environment file needed.

**Environment detection is automatic:**
- Dev mode: request from `127.0.0.1` or `::1` — enables debug output, disables caching
- Production mode: any other IP — enables caching, hides error details

---

## Architecture

### Request Flow

```
HTTP Request → index.php
  → Input sanitization (GET, POST, COOKIE)
  → Router::dispatch() — pattern matching
  → Middleware chain (auth, CSRF, rate limit)
  → Controller method
  → Cache check (page/partial)
  → Response (HTML/JSON)
```

### Index.php Structure (Sections)

The file is divided into clearly commented sections:

1. Constants and boot (`ONECMS_VERSION`, `ONECMS_ROOT`, `ONECMS_DB`, `ONECMS_CACHE`, `ONECMS_DEV`, `CSP_NONCE`)
2. Error handling and output buffering
3. Database class (`DB`) and 25 schema migrations
4. Security classes (`Session`, `CSRF`, `Password`, `RateLimit`, `Auth`, `MFA`)
5. Request/Response/Router (`Request`, `Response`, `Router`)
6. Settings and encryption (`Settings`)
7. Template engine (`Template`)
8. Caching (`Cache`, `CSSGenerator`)
9. File/asset management (`Asset`, `Sanitize`)
10. Controllers (Setup, Auth, Admin, Page, Blog, AI, Approval, Chat)
11. AI integration (`AI`, `AIOrchestrator`, `AIConversation`)
12. APIs (`BlockAPI`, `ThemeAPI`, `MediaAPI`, `AIPageAPI`, `BlogTagAPI`)
13. Email (`Mailer`)
14. Route registration
15. Dispatch

---

## Key Classes

### Core Infrastructure

| Class | Responsibility |
|---|---|
| `DB` | PDO wrapper with auto-migration; call `DB::pdo()` for raw PDO |
| `Session` | Secure sessions (httponly, samesite=strict, 30-min regeneration) |
| `CSRF` | Token generation (`CSRF::token()`) and validation (`CSRF::validate()`) |
| `Password` | bcrypt hashing and verification |
| `RateLimit` | IP-based limits per action; stored in `rate_limits` table |
| `Auth` | Authentication state and RBAC (`Auth::check()`, `Auth::requireRole()`) |
| `MFA` | TOTP-based multi-factor authentication |

### Request/Response

| Class | Responsibility |
|---|---|
| `Request` | Parses GET, POST, JSON body, uploaded files |
| `Response` | JSON responses, HTML rendering, redirects, HTTP status codes |
| `Router` | Pattern-based routing with named params; `Router::add($method, $pattern, $handler)` |

### Content & Presentation

| Class | Responsibility |
|---|---|
| `Settings` | DB-backed config with AES-256-CBC encryption for sensitive values |
| `Template` | Custom handlebars-like rendering (see Template Syntax below) |
| `Cache` | File-based page/partial/CSS caching with event-driven invalidation |
| `CSSGenerator` | Builds dynamic CSS from `theme_styles` table values |
| `Asset` | Serves binary BLOBs from `assets` table; extracts to `cache/assets/` |
| `Sanitize` | Input sanitization: HTML, URLs, filenames |

### Controllers

| Class | Routes |
|---|---|
| `SetupController` | `/setup` |
| `AuthController` | `/admin/login`, `/admin/mfa`, `/admin/logout` |
| `AdminController` | `/admin`, `/admin/pages`, `/admin/nav`, `/admin/theme`, `/admin/media`, `/admin/users` |
| `PageController` | `/{slug}` (dynamic page rendering) |
| `BlogController` | `/blog`, `/blog/{slug}`, `/blog/category/{slug}` |
| `BlogAdminController` | `/admin/blog/*` |
| `AIController` | `/admin/ai`, `/admin/ai/*` |
| `ApprovalController` | `/admin/approvals`, `/admin/approvals/{id}/*` |
| `AIChatController` | `/admin/ai/chat`, `/api/ai/chat`, `/api/ai/stream` |
| `FormController` | `/contact` |
| `AssetController` | `/assets/{hash}`, `/css`, `/js/*`, `/cdn/*` |

### API Classes

| Class | Base Route |
|---|---|
| `BlockAPI` | `/api/blocks/*` |
| `ThemeAPI` | `/api/theme/*` |
| `MediaAPI` | `/admin/media/json`, `/admin/media/upload` |
| `AIPageAPI` | `/api/ai/generate-page` |
| `BlogTagAPI` | `/api/blog/tags` |

### AI Integration

| Class | Responsibility |
|---|---|
| `AI` | Multi-provider abstraction (OpenAI, Anthropic, Google); uses PHP streams, no curl |
| `AIOrchestrator` | Streaming multi-call generation pipeline |
| `AIConversation` | Conversational design wizard for site planning |
| `BuildQueue` | Job queue storing AI-generated plans awaiting approval |

---

## Database Schema

All schema changes happen via numbered migrations in the `DB` class. To add a new table or column, append a new migration entry — never modify existing ones.

**Tables:**

| Table | Purpose |
|---|---|
| `users` | Admin/editor accounts (roles: admin, editor, viewer) |
| `sessions` | Login sessions with token_hash |
| `settings` | Key-value config (some values AES-encrypted) |
| `nav` | Hierarchical navigation items |
| `pages` | Website pages (slug, status: draft/published/archived) |
| `content_blocks` | Modular page sections (block_json holds structured data) |
| `theme_header` | Header configuration |
| `theme_footer` | Footer configuration |
| `theme_styles` | CSS variables (colors, fonts) |
| `assets` | Binary file storage as BLOBs |
| `build_queue` | AI-generated plans (status: pending/approved/rejected/applied) |
| `revisions` | Full audit history of changes |
| `rate_limits` | IP-based brute-force protection |
| `blog_categories` | Blog categories |
| `blog_tags` | Blog tags |
| `blog_posts` | Blog articles |
| `blog_post_tags` | Many-to-many: posts ↔ tags |
| `blog_settings` | Blog module configuration |

**Important patterns:**
- Flexible data stored as JSON in `*_json` columns (`block_json`, `plan_json`, `meta_json`, etc.)
- All queries use PDO prepared statements — never interpolate user input into SQL
- `DB::pdo()` returns the singleton PDO instance

---

## Template Syntax

OneCMS uses a custom handlebars-like template engine:

```handlebars
{{variable}}              {{!-- HTML-escaped output --}}
{{{variable}}}            {{!-- Raw HTML output --}}
{{> partial_name}}        {{!-- Include a partial --}}
{{#if condition}}
  ...
{{/if}}
{{#each items}}
  {{@index}}              {{!-- Loop index (0-based) --}}
  {{this.property}}
{{/each}}
```

---

## Security Conventions

Follow these patterns consistently when adding new features:

1. **CSRF** — All POST routes that mutate state must validate: `CSRF::validate()`
2. **Auth** — All admin routes must call `Auth::requireRole('admin')` or appropriate role
3. **Rate limiting** — Login, contact forms, and AI generation are rate-limited via `RateLimit::check()`
4. **SQL** — Always use PDO prepared statements; never string-concatenate user input into queries
5. **Output** — Always escape user data with `htmlspecialchars()` or the `{{variable}}` template syntax
6. **Sensitive settings** — Store API keys and passwords via `Settings::set($key, $value, true)` (third arg = encrypt)
7. **Passwords** — Use `Password::hash()` and `Password::verify()` (bcrypt cost 12)
8. **File uploads** — Validate MIME type via `finfo`, sanitize filenames via `Sanitize::filename()`
9. **Sessions** — Use `Session::regenerate()` after privilege changes; sessions auto-expire
10. **CSP** — Use `CSP_NONCE` constant when injecting inline scripts in templates

---

## Caching

The caching system has three layers:

| Cache Type | Location | TTL | Invalidated by |
|---|---|---|---|
| Full-page | `cache/pages/` | 1 hour | Content changes (event-driven) |
| Partials | `cache/partials/` | 1 hour | Nav/header/footer updates |
| CSS | `cache/assets/` | Hash-based | Theme style changes |

**Admin route to regenerate all caches:**
```
POST /admin/cache/regenerate
```

In development mode (`ONECMS_DEV === true`), caching is disabled automatically.

---

## AI Integration

### Providers and Models

| Provider | Default Model | API Style |
|---|---|---|
| OpenAI | `gpt-5.2` | Chat completions |
| Anthropic | `claude-sonnet-4-6` | Messages API |
| Google | `gemini-2.0-flash-exp` | GenerateContent |

Provider and API key are stored in the `settings` table (key encrypted). Configure via `/admin/ai`.

### Content Generation Workflow

1. Admin triggers AI generation at `/admin/ai/generate` or via chat at `/admin/ai/chat`
2. AI produces a `plan_json` stored in `build_queue` with status `pending`
3. Admin reviews at `/admin/approvals/{id}/preview`
4. On approval (`/admin/approvals/{id}/approve`) → status becomes `approved`
5. On apply (`/admin/approvals/{id}/apply`) → content written to DB, status becomes `applied`
6. On reject → status becomes `rejected` with an optional reason

### Streaming

Use `/api/ai/stream` for server-sent events (SSE) streaming responses. The `AIOrchestrator` class handles multi-call pipelines.

---

## Adding New Features

### Add a Route

```php
// In the route registration section near the bottom of index.php:
Router::add('GET', '/admin/my-feature', [MyController::class, 'index']);
Router::add('POST', '/admin/my-feature/save', [MyController::class, 'save']);
```

### Add a Database Migration

```php
// In the DB class migrations array, append a new numbered entry:
[
    'version' => 26,
    'sql' => "CREATE TABLE IF NOT EXISTS my_table (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )"
],
```

Never modify existing migration entries — always append new ones.

### Add a Settings Value

```php
// Read:
$value = Settings::get('my_setting', 'default_value');

// Write (plain):
Settings::set('my_setting', 'value');

// Write (encrypted):
Settings::set('ai_api_key', $apiKey, true);
```

### Add a Content Block Type

Content blocks store data in `block_json`. To add a new block type:
1. Add its schema to `/api/blocks/schema` in `BlockAPI`
2. Add rendering logic in the template rendering section of `PageController`
3. Add admin editing UI in `AdminController`

---

## Naming Conventions

| Element | Convention | Example |
|---|---|---|
| PHP Classes | PascalCase | `BlogAdminController` |
| PHP Methods | camelCase | `saveTheme()` |
| PHP Constants | UPPER_SNAKE_CASE | `ONECMS_VERSION` |
| DB Tables | lowercase_snake_case | `content_blocks` |
| DB Columns | lowercase_snake_case | `user_id`, `block_json` |
| Route patterns | kebab-case | `/admin/blog-posts` |
| Cache keys | dot.separated | `page.home`, `partial.nav` |

---

## Error Handling

- In development (`ONECMS_DEV`), errors are displayed with full stack traces
- In production, errors return generic messages; details logged to output buffer
- Output buffering (`ob_start()`) is used to prevent partial output corrupting JSON responses
- AI failures degrade gracefully — errors returned as JSON, not exceptions

---

## Testing

No automated test suite exists yet. The GitHub Actions workflow validates `composer.json` and `composer.lock` only (placeholder for future tests).

**Manual testing checklist for changes:**
- [ ] Start dev server: `php -S 127.0.0.1:8080 index.php`
- [ ] Verify setup wizard at `/setup` (fresh database)
- [ ] Test affected admin routes with a logged-in session
- [ ] Confirm CSRF validation still passes on POST routes
- [ ] Check cache regeneration at `/admin/cache/regenerate`
- [ ] Verify JSON API responses return correct `Content-Type: application/json`

---

## Git Workflow

Development happens on feature branches following the pattern `claude/<description>-<session-id>`.

```bash
# Push to origin
git push -u origin claude/add-claude-documentation-J35tR

# Standard commit
git add index.php
git commit -m "Short description of change"
```

The CI/CD pipeline (`.github/workflows/php.yml`) runs on push/PR to `main` and validates Composer configuration.

---

## Configuration Reference

All configuration is stored in the `settings` table. Key setting keys:

| Key | Description | Encrypted |
|---|---|---|
| `site_name` | Website title | No |
| `site_description` | Meta description | No |
| `ai_provider` | `openai`, `anthropic`, or `google` | No |
| `ai_api_key` | Provider API key | **Yes** |
| `ai_model` | Model identifier | No |
| `smtp_host` | SMTP server hostname | No |
| `smtp_port` | SMTP port | No |
| `smtp_user` | SMTP username | No |
| `smtp_password` | SMTP password | **Yes** |
| `smtp_from_email` | Sender email address | No |
| `smtp_from_name` | Sender display name | No |
| `mfa_enabled` | `1` or `0` | No |
| `blog_enabled` | `1` or `0` | No |
| `blog_posts_per_page` | Integer | No |

---

## Docs Reference

| File | Purpose |
|---|---|
| `docs/app.md` | Authoritative feature and architecture specification — read this for intended behaviour |
| `docs/implementation-plan.md` | Phased roadmap; useful for understanding why certain patterns were chosen |
| `docs/ui-ux-requirement.md` | React component/UX reference for future frontend work |
