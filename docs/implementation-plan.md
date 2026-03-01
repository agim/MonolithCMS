# OneCMS Implementation Plan

This document provides a comprehensive, step-by-step implementation plan for building the OneCMS single-file PHP application. Every section maps directly to the requirements defined in [app.md](app.md).

---

## Table of Contents

1. [Project Structure](#1-project-structure)
2. [Phase 1: Core Foundation](#phase-1-core-foundation)
3. [Phase 2: Database & Schema](#phase-2-database--schema)
4. [Phase 3: Security Layer](#phase-3-security-layer)
5. [Phase 4: Routing & Request Handling](#phase-4-routing--request-handling)
6. [Phase 5: Templating Engine](#phase-5-templating-engine)
7. [Phase 6: Caching System](#phase-6-caching-system)
8. [Phase 7: File & Asset Management](#phase-7-file--asset-management)
9. [Phase 8: Admin Interface](#phase-8-admin-interface)
10. [Phase 9: AI Integration](#phase-9-ai-integration)
11. [Phase 10: Setup Wizard](#phase-10-setup-wizard)
12. [Phase 11: SEO & Metadata](#phase-11-seo--metadata)
13. [Phase 12: Email & Communication](#phase-12-email--communication)
14. [Phase 13: Content Approval Workflow](#phase-13-content-approval-workflow)
15. [Phase 14: CSS Optimization](#phase-14-css-optimization)
16. [Phase 15: Production Hardening](#phase-15-production-hardening)
17. [Testing & QA Checklist](#testing--qa-checklist)

---

## 1. Project Structure

### 1.1 File Layout
```
/onecms/
├── index.php           # The single-file application (all logic)
├── site.sqlite         # SQLite database (auto-created)
├── cache/              # Auto-generated cache directory
│   ├── pages/          # Full-page HTML cache
│   ├── partials/       # Fragment cache (nav, footer, blocks)
│   └── assets/         # Extracted CSS/JS/images from BLOBs
├── .htaccess           # Apache rewrite rules (optional)
└── docs/               # Documentation
    ├── app.md
    └── implementation-plan.md
```

### 1.2 Single-File Architecture
The `index.php` file will be organized into clearly separated sections using comments and internal classes/functions:

```php
<?php
/*==============================================================================
 * OneCMS - Single-File AI-Driven Website Builder
 * Version: 1.0.0
 *=============================================================================*/

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 1: CONFIGURATION & BOOT
// ─────────────────────────────────────────────────────────────────────────────

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 2: DATABASE LAYER
// ─────────────────────────────────────────────────────────────────────────────

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 3: SECURITY & AUTH
// ─────────────────────────────────────────────────────────────────────────────

// ... and so on
```

---

## Phase 1: Core Foundation

**Maps to:** app.md §1 (Production Environment), §14 (Self-Contained App)

### 1.1 Boot & Configuration Block

```php
// Constants
define('ONECMS_VERSION', '1.0.0');
define('ONECMS_ROOT', __DIR__);
define('ONECMS_DB', ONECMS_ROOT . '/site.sqlite');
define('ONECMS_CACHE', ONECMS_ROOT . '/cache');

// Environment Detection
$isProduction = !in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1']);
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

// Error Handling
if ($isProduction) {
    error_reporting(0);
    ini_set('display_errors', '0');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

// Secure Headers (CSP, HSTS, X-Frame-Options)
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
if ($isHttps) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
```

### 1.2 Directory Bootstrap

```php
// Ensure cache directories exist
$cacheDirs = [ONECMS_CACHE, ONECMS_CACHE.'/pages', ONECMS_CACHE.'/partials', ONECMS_CACHE.'/assets'];
foreach ($cacheDirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}
```

### Deliverables:
- [ ] Constants defined
- [ ] Environment detection working
- [ ] Error handling configured per environment
- [ ] Security headers sent
- [ ] Cache directories auto-created

---

## Phase 2: Database & Schema

**Maps to:** app.md §5 (Database Structure), Technical Architecture §1.B

### 2.1 PDO Connection Class

```php
class DB {
    private static ?PDO $pdo = null;
    
    public static function connect(): PDO {
        if (self::$pdo === null) {
            self::$pdo = new PDO('sqlite:' . ONECMS_DB, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]);
            self::$pdo->exec('PRAGMA journal_mode=WAL');
            self::$pdo->exec('PRAGMA foreign_keys=ON');
        }
        return self::$pdo;
    }
    
    public static function migrate(): void {
        // Run schema migrations
    }
}
```

### 2.2 Schema Definition

| Table | Purpose | Key Columns |
|-------|---------|-------------|
| `users` | Admin/Editor accounts | id, email, password_hash, role, mfa_secret, created_at |
| `sessions` | Session management | id, user_id, token_hash, expires_at, ip_address |
| `settings` | Site-wide configuration | key, value, encrypted (bool) |
| `nav` | Navigation menu items | id, label, url, parent_id, order, visible |
| `pages` | Page definitions | id, slug, title, status (draft/published), meta_json |
| `content_blocks` | Page content sections | id, page_id, type, block_json, order |
| `theme_header` | Header configuration | id, logo_blob_id, tagline, bg_color, nav_style |
| `theme_footer` | Footer configuration | id, text, links_json, social_json |
| `theme_styles` | CSS variables & tokens | id, key, value |
| `assets` | Binary file storage | id, filename, mime_type, blob_data, hash, created_at |
| `build_queue` | AI generation queue | id, plan_json, status, created_at, approved_at |
| `revisions` | Content version history | id, table_name, record_id, old_json, new_json, user_id, created_at |
| `rate_limits` | Brute-force protection | ip, action, attempts, last_attempt |

### 2.3 Migration System

```php
private static function migrate(): void {
    $db = self::connect();
    
    // Check current schema version
    $db->exec("CREATE TABLE IF NOT EXISTS migrations (version INT PRIMARY KEY)");
    $current = $db->query("SELECT MAX(version) FROM migrations")->fetchColumn() ?: 0;
    
    $migrations = [
        1 => "CREATE TABLE users (...)",
        2 => "CREATE TABLE settings (...)",
        3 => "CREATE TABLE nav (...)",
        // ... all tables
    ];
    
    foreach ($migrations as $version => $sql) {
        if ($version > $current) {
            $db->exec($sql);
            $db->exec("INSERT INTO migrations (version) VALUES ($version)");
        }
    }
}
```

### Deliverables:
- [ ] PDO singleton with WAL mode
- [ ] All 12 tables created
- [ ] Migration system with versioning
- [ ] Foreign key constraints active
- [ ] Indexes on frequently queried columns

---

## Phase 3: Security Layer

**Maps to:** app.md §10 (RBAC), §20 (Maximum Security), Technical Architecture §2

### 3.1 Session Management

```php
class Session {
    public static function start(): void {
        if (session_status() === PHP_SESSION_NONE) {
            ini_set('session.cookie_httponly', '1');
            ini_set('session.cookie_secure', $GLOBALS['isHttps'] ? '1' : '0');
            ini_set('session.cookie_samesite', 'Strict');
            ini_set('session.use_strict_mode', '1');
            session_start();
        }
    }
    
    public static function regenerate(): void {
        session_regenerate_id(true);
    }
}
```

### 3.2 CSRF Protection

```php
class CSRF {
    public static function token(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
    
    public static function verify(string $token): bool {
        return hash_equals($_SESSION['csrf_token'] ?? '', $token);
    }
    
    public static function field(): string {
        return '<input type="hidden" name="_csrf" value="' . self::token() . '">';
    }
}
```

### 3.3 Password Hashing

```php
class Password {
    public static function hash(string $password): string {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }
    
    public static function verify(string $password, string $hash): bool {
        return password_verify($password, $hash);
    }
}
```

### 3.4 Rate Limiting

```php
class RateLimit {
    public static function check(string $action, int $maxAttempts = 5, int $windowSeconds = 900): bool {
        $db = DB::connect();
        $ip = $_SERVER['REMOTE_ADDR'];
        
        // Clean old entries
        $db->exec("DELETE FROM rate_limits WHERE last_attempt < datetime('now', '-1 hour')");
        
        $stmt = $db->prepare("SELECT attempts, last_attempt FROM rate_limits WHERE ip = ? AND action = ?");
        $stmt->execute([$ip, $action]);
        $row = $stmt->fetch();
        
        if (!$row) {
            $db->prepare("INSERT INTO rate_limits (ip, action, attempts, last_attempt) VALUES (?, ?, 1, datetime('now'))")
               ->execute([$ip, $action]);
            return true;
        }
        
        $lastAttempt = strtotime($row['last_attempt']);
        if (time() - $lastAttempt > $windowSeconds) {
            // Reset after window
            $db->prepare("UPDATE rate_limits SET attempts = 1, last_attempt = datetime('now') WHERE ip = ? AND action = ?")
               ->execute([$ip, $action]);
            return true;
        }
        
        if ($row['attempts'] >= $maxAttempts) {
            return false; // Blocked
        }
        
        $db->prepare("UPDATE rate_limits SET attempts = attempts + 1, last_attempt = datetime('now') WHERE ip = ? AND action = ?")
           ->execute([$ip, $action]);
        return true;
    }
}
```

### 3.5 Role-Based Access Control

```php
class Auth {
    public static function user(): ?array {
        if (!isset($_SESSION['user_id'])) return null;
        $stmt = DB::connect()->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        return $stmt->fetch() ?: null;
    }
    
    public static function check(): bool {
        return self::user() !== null;
    }
    
    public static function role(): ?string {
        return self::user()['role'] ?? null;
    }
    
    public static function can(string $permission): bool {
        $permissions = [
            'admin' => ['*'],
            'editor' => ['content.edit', 'content.view', 'media.upload'],
            'viewer' => ['content.view']
        ];
        $role = self::role();
        if (!$role) return false;
        $allowed = $permissions[$role] ?? [];
        return in_array('*', $allowed) || in_array($permission, $allowed);
    }
    
    public static function require(string $permission): void {
        if (!self::can($permission)) {
            http_response_code(403);
            exit('Access Denied');
        }
    }
}
```

### 3.6 MFA (Email OTP)

```php
class MFA {
    public static function generateOTP(int $userId): string {
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $hash = password_hash($code, PASSWORD_DEFAULT);
        $expires = date('Y-m-d H:i:s', time() + 600); // 10 minutes
        
        $db = DB::connect();
        $db->prepare("UPDATE users SET mfa_code_hash = ?, mfa_expires = ? WHERE id = ?")
           ->execute([$hash, $expires, $userId]);
        
        return $code;
    }
    
    public static function verifyOTP(int $userId, string $code): bool {
        $db = DB::connect();
        $stmt = $db->prepare("SELECT mfa_code_hash, mfa_expires FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        
        if (!$row || strtotime($row['mfa_expires']) < time()) {
            return false;
        }
        
        if (password_verify($code, $row['mfa_code_hash'])) {
            $db->prepare("UPDATE users SET mfa_code_hash = NULL, mfa_expires = NULL WHERE id = ?")
               ->execute([$userId]);
            return true;
        }
        return false;
    }
}
```

### Deliverables:
- [ ] Secure session configuration
- [ ] CSRF token generation & validation
- [ ] bcrypt password hashing (cost 12)
- [ ] IP-based rate limiting
- [ ] RBAC with 3 roles (admin, editor, viewer)
- [ ] Email-based MFA with expiring OTP

---

## Phase 4: Routing & Request Handling

**Maps to:** Technical Architecture §1.A, §2

### 4.1 Router Class

```php
class Router {
    private static array $routes = [];
    
    public static function get(string $pattern, callable $handler): void {
        self::$routes['GET'][$pattern] = $handler;
    }
    
    public static function post(string $pattern, callable $handler): void {
        self::$routes['POST'][$pattern] = $handler;
    }
    
    public static function dispatch(): void {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $uri = rtrim($uri, '/') ?: '/';
        
        foreach (self::$routes[$method] ?? [] as $pattern => $handler) {
            $regex = self::patternToRegex($pattern);
            if (preg_match($regex, $uri, $matches)) {
                array_shift($matches); // Remove full match
                echo $handler(...$matches);
                return;
            }
        }
        
        // 404 fallback
        http_response_code(404);
        echo self::render404();
    }
    
    private static function patternToRegex(string $pattern): string {
        $pattern = preg_replace('/\{(\w+)\}/', '([^/]+)', $pattern);
        return '#^' . $pattern . '$#';
    }
}
```

### 4.2 Route Definitions

```php
// ─── PUBLIC ROUTES ───────────────────────────────────────────────────────────
Router::get('/', 'PageController::home');
Router::get('/{slug}', 'PageController::show');
Router::post('/contact', 'FormController::contact');

// ─── ADMIN ROUTES ────────────────────────────────────────────────────────────
Router::get('/admin', 'AdminController::dashboard');
Router::get('/admin/login', 'AuthController::loginForm');
Router::post('/admin/login', 'AuthController::login');
Router::post('/admin/logout', 'AuthController::logout');
Router::get('/admin/mfa', 'AuthController::mfaForm');
Router::post('/admin/mfa', 'AuthController::mfaVerify');

Router::get('/admin/pages', 'AdminController::pages');
Router::get('/admin/pages/{id}/edit', 'AdminController::editPage');
Router::post('/admin/pages/{id}', 'AdminController::updatePage');

Router::get('/admin/nav', 'AdminController::nav');
Router::post('/admin/nav', 'AdminController::updateNav');

Router::get('/admin/theme', 'AdminController::theme');
Router::post('/admin/theme', 'AdminController::updateTheme');

Router::get('/admin/media', 'MediaController::index');
Router::post('/admin/media/upload', 'MediaController::upload');
Router::get('/admin/media/{id}', 'MediaController::serve');

Router::get('/admin/approvals', 'ApprovalController::queue');
Router::post('/admin/approvals/{id}/approve', 'ApprovalController::approve');
Router::post('/admin/approvals/{id}/reject', 'ApprovalController::reject');

Router::get('/admin/settings', 'SettingsController::index');
Router::post('/admin/settings', 'SettingsController::update');

Router::get('/admin/ai/generate', 'AIController::form');
Router::post('/admin/ai/generate', 'AIController::generate');

// ─── ASSET ROUTES ────────────────────────────────────────────────────────────
Router::get('/assets/{hash}', 'AssetController::serve');

// ─── SETUP WIZARD ────────────────────────────────────────────────────────────
Router::get('/setup', 'SetupController::index');
Router::post('/setup', 'SetupController::process');
```

### 4.3 Request Helpers

```php
class Request {
    public static function input(string $key, $default = null) {
        return $_POST[$key] ?? $_GET[$key] ?? $default;
    }
    
    public static function all(): array {
        return array_merge($_GET, $_POST);
    }
    
    public static function isPost(): bool {
        return $_SERVER['REQUEST_METHOD'] === 'POST';
    }
    
    public static function json(): array {
        return json_decode(file_get_contents('php://input'), true) ?? [];
    }
}

class Response {
    public static function json(array $data, int $code = 200): string {
        http_response_code($code);
        header('Content-Type: application/json');
        return json_encode($data);
    }
    
    public static function redirect(string $url): void {
        header('Location: ' . $url);
        exit;
    }
}
```

### Deliverables:
- [ ] Pattern-based router with parameter extraction
- [ ] Public page routes
- [ ] Admin CRUD routes
- [ ] Asset serving route
- [ ] Setup wizard route
- [ ] Request/Response helper classes

---

## Phase 5: Templating Engine

**Maps to:** app.md §15 (Templating with Partials), Technical Architecture §3

### 5.1 Template Class

```php
class Template {
    private static array $blocks = [];
    private static array $data = [];
    
    public static function render(string $template, array $data = []): string {
        self::$data = $data;
        
        // Load template string from embedded templates
        $html = self::getTemplate($template);
        
        // Process includes: {{> partial_name}}
        $html = preg_replace_callback('/\{\{>\s*(\w+)\s*\}\}/', function($m) {
            return self::partial($m[1]);
        }, $html);
        
        // Process variables: {{variable}}
        $html = preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/', function($m) use ($data) {
            return htmlspecialchars($data[$m[1]] ?? '', ENT_QUOTES, 'UTF-8');
        }, $html);
        
        // Process raw output: {{{variable}}}
        $html = preg_replace_callback('/\{\{\{\s*(\w+)\s*\}\}\}/', function($m) use ($data) {
            return $data[$m[1]] ?? '';
        }, $html);
        
        // Process conditionals: {{#if var}}...{{/if}}
        $html = preg_replace_callback('/\{\{#if\s+(\w+)\}\}(.*?)\{\{\/if\}\}/s', function($m) use ($data) {
            return !empty($data[$m[1]]) ? $m[2] : '';
        }, $html);
        
        // Process loops: {{#each items}}...{{/each}}
        $html = preg_replace_callback('/\{\{#each\s+(\w+)\}\}(.*?)\{\{\/each\}\}/s', function($m) use ($data) {
            $output = '';
            foreach ($data[$m[1]] ?? [] as $item) {
                $inner = $m[2];
                foreach ($item as $k => $v) {
                    $inner = str_replace('{{' . $k . '}}', htmlspecialchars($v, ENT_QUOTES, 'UTF-8'), $inner);
                }
                $output .= $inner;
            }
            return $output;
        }, $html);
        
        return $html;
    }
    
    public static function partial(string $name): string {
        // Check partial cache first
        $cacheFile = ONECMS_CACHE . '/partials/' . $name . '.html';
        if (file_exists($cacheFile) && filemtime($cacheFile) > time() - 3600) {
            return file_get_contents($cacheFile);
        }
        
        // Render partial
        $html = match($name) {
            'header' => self::renderHeader(),
            'footer' => self::renderFooter(),
            'nav' => self::renderNav(),
            default => ''
        };
        
        // Cache it
        file_put_contents($cacheFile, $html);
        return $html;
    }
    
    private static function renderHeader(): string {
        $db = DB::connect();
        $header = $db->query("SELECT * FROM theme_header LIMIT 1")->fetch();
        return self::render('_header', $header ?: []);
    }
    
    private static function renderNav(): string {
        $db = DB::connect();
        $items = $db->query("SELECT * FROM nav WHERE visible = 1 ORDER BY `order`")->fetchAll();
        return self::render('_nav', ['items' => $items]);
    }
    
    private static function renderFooter(): string {
        $db = DB::connect();
        $footer = $db->query("SELECT * FROM theme_footer LIMIT 1")->fetch();
        return self::render('_footer', $footer ?: []);
    }
}
```

### 5.2 Embedded Templates

```php
// Templates stored as heredoc strings within index.php
class Templates {
    public static function get(string $name): string {
        $templates = [
            'layout' => <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{title}} | {{site_name}}</title>
    <meta name="description" content="{{meta_description}}">
    <meta property="og:title" content="{{title}}">
    <meta property="og:description" content="{{meta_description}}">
    <meta property="og:image" content="{{og_image}}">
    <link rel="stylesheet" href="/assets/{{css_hash}}">
    {{{head_scripts}}}
</head>
<body>
    {{> header}}
    {{> nav}}
    <main>
        {{{content}}}
    </main>
    {{> footer}}
    {{{footer_scripts}}}
</body>
</html>
HTML,
            '_header' => <<<'HTML'
<header class="site-header" style="background-color: {{bg_color}}">
    {{#if logo_url}}<img src="{{logo_url}}" alt="Logo" class="logo">{{/if}}
    <h1>{{tagline}}</h1>
</header>
HTML,
            '_nav' => <<<'HTML'
<nav class="main-nav">
    <ul>
        {{#each items}}
        <li><a href="{{url}}">{{label}}</a></li>
        {{/each}}
    </ul>
</nav>
HTML,
            '_footer' => <<<'HTML'
<footer class="site-footer">
    <p>{{text}}</p>
</footer>
HTML,
            // ... more templates
        ];
        
        return $templates[$name] ?? '';
    }
}
```

### Deliverables:
- [ ] Handlebars-like syntax ({{var}}, {{{raw}}})
- [ ] Partial includes ({{> name}})
- [ ] Conditionals ({{#if}})
- [ ] Loops ({{#each}})
- [ ] Partial caching
- [ ] All templates embedded as heredocs

---

## Phase 6: Caching System

**Maps to:** app.md §12 (Smart Caching with Partials), Technical Architecture §1.C

### 6.1 Cache Manager

```php
class Cache {
    // ─── PAGE CACHE ──────────────────────────────────────────────────────────
    public static function getPage(string $slug): ?string {
        $file = ONECMS_CACHE . '/pages/' . md5($slug) . '.html';
        if (file_exists($file) && filemtime($file) > time() - 3600) {
            return file_get_contents($file);
        }
        return null;
    }
    
    public static function setPage(string $slug, string $html): void {
        $file = ONECMS_CACHE . '/pages/' . md5($slug) . '.html';
        file_put_contents($file, $html);
    }
    
    public static function invalidatePage(string $slug): void {
        $file = ONECMS_CACHE . '/pages/' . md5($slug) . '.html';
        if (file_exists($file)) unlink($file);
    }
    
    // ─── PARTIAL CACHE ───────────────────────────────────────────────────────
    public static function getPartial(string $name): ?string {
        $file = ONECMS_CACHE . '/partials/' . $name . '.html';
        if (file_exists($file)) {
            return file_get_contents($file);
        }
        return null;
    }
    
    public static function setPartial(string $name, string $html): void {
        file_put_contents(ONECMS_CACHE . '/partials/' . $name . '.html', $html);
    }
    
    public static function invalidatePartial(string $name): void {
        $file = ONECMS_CACHE . '/partials/' . $name . '.html';
        if (file_exists($file)) unlink($file);
    }
    
    // ─── FULL INVALIDATION ───────────────────────────────────────────────────
    public static function invalidateAll(): void {
        $dirs = ['pages', 'partials'];
        foreach ($dirs as $dir) {
            $path = ONECMS_CACHE . '/' . $dir;
            foreach (glob($path . '/*') as $file) {
                unlink($file);
            }
        }
    }
    
    // ─── EVENT-DRIVEN INVALIDATION ───────────────────────────────────────────
    public static function onContentChange(string $table, int $recordId): void {
        match($table) {
            'nav' => self::invalidatePartial('nav'),
            'theme_header' => self::invalidatePartial('header'),
            'theme_footer' => self::invalidatePartial('footer'),
            'pages', 'content_blocks' => self::invalidateAllPages(),
            default => null
        };
    }
    
    private static function invalidateAllPages(): void {
        foreach (glob(ONECMS_CACHE . '/pages/*') as $file) {
            unlink($file);
        }
    }
}
```

### 6.2 Request Flow with Caching

```php
class PageController {
    public static function show(string $slug): string {
        // 1. Check page cache
        $cached = Cache::getPage($slug);
        if ($cached !== null) {
            return $cached;
        }
        
        // 2. Load page from DB
        $db = DB::connect();
        $stmt = $db->prepare("SELECT * FROM pages WHERE slug = ? AND status = 'published'");
        $stmt->execute([$slug]);
        $page = $stmt->fetch();
        
        if (!$page) {
            http_response_code(404);
            return Template::render('404');
        }
        
        // 3. Load content blocks
        $stmt = $db->prepare("SELECT * FROM content_blocks WHERE page_id = ? ORDER BY `order`");
        $stmt->execute([$page['id']]);
        $blocks = $stmt->fetchAll();
        
        // 4. Render content blocks
        $content = '';
        foreach ($blocks as $block) {
            $content .= self::renderBlock($block);
        }
        
        // 5. Render full page
        $html = Template::render('layout', [
            'title' => $page['title'],
            'meta_description' => $page['meta_description'] ?? '',
            'og_image' => $page['og_image'] ?? '',
            'content' => $content,
            'site_name' => Settings::get('site_name', 'OneCMS'),
            'css_hash' => Cache::getCSSHash(),
            'head_scripts' => Settings::get('head_scripts', ''),
            'footer_scripts' => Settings::get('footer_scripts', '')
        ]);
        
        // 6. Cache and return
        Cache::setPage($slug, $html);
        return $html;
    }
}
```

### Deliverables:
- [ ] Page-level caching (full HTML)
- [ ] Partial caching (nav, header, footer)
- [ ] Event-driven invalidation hooks
- [ ] Cache TTL management
- [ ] Request flow: check cache → render → store

---

## Phase 7: File & Asset Management

**Maps to:** app.md §4 (File Upload Management), §14 (External Assets as BLOBs)

### 7.1 Asset Storage (SQLite BLOBs)

```php
class Asset {
    public static function store(array $file): int {
        // Validate upload
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Upload failed');
        }
        
        // Validate MIME type
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/svg+xml', 'image/webp',
                         'application/pdf', 'application/msword'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mimeType, $allowedTypes)) {
            throw new Exception('File type not allowed');
        }
        
        // Read file content
        $content = file_get_contents($file['tmp_name']);
        $hash = hash('sha256', $content);
        
        // Check for duplicate
        $db = DB::connect();
        $stmt = $db->prepare("SELECT id FROM assets WHERE hash = ?");
        $stmt->execute([$hash]);
        $existing = $stmt->fetch();
        if ($existing) {
            return $existing['id'];
        }
        
        // Strip metadata from images (security)
        if (str_starts_with($mimeType, 'image/') && $mimeType !== 'image/svg+xml') {
            $content = self::stripMetadata($content, $mimeType);
        }
        
        // Store in database
        $stmt = $db->prepare("INSERT INTO assets (filename, mime_type, blob_data, hash, created_at) VALUES (?, ?, ?, ?, datetime('now'))");
        $stmt->execute([$file['name'], $mimeType, $content, $hash]);
        
        return (int)$db->lastInsertId();
    }
    
    public static function get(int $id): ?array {
        $stmt = DB::connect()->prepare("SELECT * FROM assets WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
    
    public static function serve(int $id): void {
        $asset = self::get($id);
        if (!$asset) {
            http_response_code(404);
            exit;
        }
        
        // Set cache headers
        header('Content-Type: ' . $asset['mime_type']);
        header('Content-Length: ' . strlen($asset['blob_data']));
        header('Cache-Control: public, max-age=31536000');
        header('ETag: "' . $asset['hash'] . '"');
        
        echo $asset['blob_data'];
        exit;
    }
    
    public static function url(int $id): string {
        $asset = self::get($id);
        if (!$asset) return '';
        return '/assets/' . $asset['hash'];
    }
    
    private static function stripMetadata(string $content, string $mimeType): string {
        // Use GD to recreate image without metadata
        $img = imagecreatefromstring($content);
        if (!$img) return $content;
        
        ob_start();
        match($mimeType) {
            'image/jpeg' => imagejpeg($img, null, 90),
            'image/png' => imagepng($img),
            'image/gif' => imagegif($img),
            'image/webp' => imagewebp($img, null, 90),
            default => null
        };
        $clean = ob_get_clean();
        imagedestroy($img);
        
        return $clean ?: $content;
    }
}
```

### 7.2 Asset Extraction to Cache

```php
class AssetExtractor {
    public static function extractAll(): void {
        $db = DB::connect();
        $assets = $db->query("SELECT id, hash, blob_data, mime_type FROM assets")->fetchAll();
        
        foreach ($assets as $asset) {
            $ext = self::mimeToExt($asset['mime_type']);
            $file = ONECMS_CACHE . '/assets/' . $asset['hash'] . '.' . $ext;
            
            if (!file_exists($file)) {
                file_put_contents($file, $asset['blob_data']);
            }
        }
    }
    
    private static function mimeToExt(string $mime): string {
        return match($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/svg+xml' => 'svg',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            default => 'bin'
        };
    }
}
```

### 7.3 Media Controller

```php
class MediaController {
    public static function index(): string {
        Auth::require('media.upload');
        
        $db = DB::connect();
        $assets = $db->query("SELECT id, filename, mime_type, created_at FROM assets ORDER BY created_at DESC")->fetchAll();
        
        return Template::render('admin/media', ['assets' => $assets]);
    }
    
    public static function upload(): string {
        Auth::require('media.upload');
        CSRF::verify($_POST['_csrf'] ?? '');
        
        try {
            $id = Asset::store($_FILES['file']);
            return Response::json(['success' => true, 'id' => $id, 'url' => Asset::url($id)]);
        } catch (Exception $e) {
            return Response::json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }
    
    public static function serve(string $hash): void {
        $stmt = DB::connect()->prepare("SELECT * FROM assets WHERE hash = ?");
        $stmt->execute([$hash]);
        $asset = $stmt->fetch();
        
        if (!$asset) {
            http_response_code(404);
            exit;
        }
        
        header('Content-Type: ' . $asset['mime_type']);
        header('Cache-Control: public, max-age=31536000, immutable');
        header('ETag: "' . $hash . '"');
        echo $asset['blob_data'];
        exit;
    }
}
```

### Deliverables:
- [ ] BLOB storage in SQLite `assets` table
- [ ] MIME validation with finfo
- [ ] Image metadata stripping
- [ ] Hash-based deduplication
- [ ] Extraction to cache/assets for performance
- [ ] Long-lived cache headers for assets

---

## Phase 8: Admin Interface

**Maps to:** app.md §6 (Manual Editing), Technical Architecture §1.D

### 8.1 Admin Layout Template

```php
'admin_layout' => <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{title}} | OneCMS Admin</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
    <style>
        .admin-layout { display: grid; grid-template-columns: 220px 1fr; min-height: 100vh; }
        .admin-sidebar { background: #1a1a2e; color: #fff; padding: 1rem; }
        .admin-sidebar a { color: #eee; display: block; padding: 0.5rem; }
        .admin-main { padding: 2rem; }
        .flash { padding: 1rem; margin-bottom: 1rem; border-radius: 4px; }
        .flash-success { background: #d4edda; color: #155724; }
        .flash-error { background: #f8d7da; color: #721c24; }
    </style>
</head>
<body>
    <div class="admin-layout">
        <aside class="admin-sidebar">
            <h2>OneCMS</h2>
            <nav>
                <a href="/admin">Dashboard</a>
                <a href="/admin/pages">Pages</a>
                <a href="/admin/nav">Navigation</a>
                <a href="/admin/theme">Theme</a>
                <a href="/admin/media">Media</a>
                <a href="/admin/approvals">Approvals</a>
                <a href="/admin/ai/generate">AI Generate</a>
                <a href="/admin/settings">Settings</a>
            </nav>
            <hr>
            <form method="post" action="/admin/logout">
                {{csrf}}
                <button type="submit">Logout</button>
            </form>
        </aside>
        <main class="admin-main">
            {{#if flash}}<div class="flash flash-{{flash_type}}">{{flash}}</div>{{/if}}
            <h1>{{title}}</h1>
            {{{content}}}
        </main>
    </div>
</body>
</html>
HTML
```

### 8.2 WYSIWYG Block Editor

```php
'admin/page_edit' => <<<'HTML'
<form method="post" action="/admin/pages/{{id}}">
    {{csrf}}
    <label>Title
        <input type="text" name="title" value="{{title}}" required>
    </label>
    <label>Slug
        <input type="text" name="slug" value="{{slug}}" required>
    </label>
    <label>Meta Description
        <textarea name="meta_description">{{meta_description}}</textarea>
    </label>
    
    <h2>Content Blocks</h2>
    <div id="blocks-editor">
        {{#each blocks}}
        <div class="block" data-id="{{id}}">
            <select name="blocks[{{id}}][type]">
                <option value="text" {{#if type_text}}selected{{/if}}>Text</option>
                <option value="heading" {{#if type_heading}}selected{{/if}}>Heading</option>
                <option value="image" {{#if type_image}}selected{{/if}}>Image</option>
                <option value="gallery" {{#if type_gallery}}selected{{/if}}>Gallery</option>
                <option value="form" {{#if type_form}}selected{{/if}}>Contact Form</option>
            </select>
            <div class="block-content">
                <textarea name="blocks[{{id}}][content]" class="wysiwyg">{{content}}</textarea>
            </div>
        </div>
        {{/each}}
    </div>
    <button type="button" onclick="addBlock()">+ Add Block</button>
    
    <hr>
    <button type="submit">Save Draft</button>
    <button type="submit" name="publish" value="1">Save & Publish</button>
</form>

<!-- WYSIWYG via CDN -->
<link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
<script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>
<script>
document.querySelectorAll('.wysiwyg').forEach(el => {
    const quill = new Quill(el.parentElement, {
        theme: 'snow',
        modules: { toolbar: [['bold', 'italic'], ['link', 'image'], [{ 'list': 'ordered'}, { 'list': 'bullet' }]] }
    });
    // Sync to hidden textarea on form submit
    el.closest('form').addEventListener('submit', () => {
        el.value = JSON.stringify(quill.getContents());
    });
});
</script>
HTML
```

### 8.3 Admin Controllers

```php
class AdminController {
    public static function dashboard(): string {
        Auth::require('content.view');
        
        $db = DB::connect();
        $stats = [
            'pages' => $db->query("SELECT COUNT(*) FROM pages")->fetchColumn(),
            'pending' => $db->query("SELECT COUNT(*) FROM build_queue WHERE status = 'pending'")->fetchColumn(),
            'assets' => $db->query("SELECT COUNT(*) FROM assets")->fetchColumn()
        ];
        
        return Template::render('admin/dashboard', $stats);
    }
    
    public static function pages(): string {
        Auth::require('content.view');
        
        $pages = DB::connect()->query("SELECT * FROM pages ORDER BY created_at DESC")->fetchAll();
        return Template::render('admin/pages', ['pages' => $pages]);
    }
    
    public static function editPage(int $id): string {
        Auth::require('content.edit');
        
        $db = DB::connect();
        $page = $db->prepare("SELECT * FROM pages WHERE id = ?")->execute([$id])->fetch();
        $blocks = $db->prepare("SELECT * FROM content_blocks WHERE page_id = ? ORDER BY `order`")->execute([$id])->fetchAll();
        
        return Template::render('admin/page_edit', array_merge($page, ['blocks' => $blocks]));
    }
    
    public static function updatePage(int $id): void {
        Auth::require('content.edit');
        CSRF::verify($_POST['_csrf'] ?? '');
        
        $db = DB::connect();
        
        // Update page
        $stmt = $db->prepare("UPDATE pages SET title = ?, slug = ?, meta_description = ?, status = ? WHERE id = ?");
        $status = isset($_POST['publish']) ? 'published' : 'draft';
        $stmt->execute([$_POST['title'], $_POST['slug'], $_POST['meta_description'], $status, $id]);
        
        // Update blocks
        foreach ($_POST['blocks'] ?? [] as $blockId => $block) {
            if (str_starts_with($blockId, 'new_')) {
                $db->prepare("INSERT INTO content_blocks (page_id, type, block_json, `order`) VALUES (?, ?, ?, ?)")
                   ->execute([$id, $block['type'], $block['content'], 0]);
            } else {
                $db->prepare("UPDATE content_blocks SET type = ?, block_json = ? WHERE id = ?")
                   ->execute([$block['type'], $block['content'], $blockId]);
            }
        }
        
        // Create revision
        Revision::create('pages', $id);
        
        // Invalidate cache
        $page = $db->prepare("SELECT slug FROM pages WHERE id = ?")->execute([$id])->fetch();
        Cache::invalidatePage($page['slug']);
        
        Response::redirect('/admin/pages/' . $id . '/edit?saved=1');
    }
}
```

### Deliverables:
- [ ] Admin layout with sidebar navigation
- [ ] Dashboard with stats
- [ ] Page list view
- [ ] Page editor with WYSIWYG (Quill via CDN)
- [ ] Block-based content editing
- [ ] Navigation manager
- [ ] Theme settings (header/footer)
- [ ] Media library with upload
- [ ] Revision tracking

---

## Phase 9: AI Integration

**Maps to:** app.md §2 (Full AI Integration), §17 (Advanced AI Models), §18 (User Input), §19 (User-Provided Content)

### 9.1 AI Provider Configuration

```php
class AI {
    private static function getProvider(): array {
        return [
            'provider' => Settings::get('ai_provider', 'openai'),
            'api_key' => Settings::getEncrypted('ai_api_key'),
            'model' => Settings::get('ai_model', 'gpt-5.2')
        ];
    }
    
    public static function generate(string $prompt): string {
        $config = self::getProvider();
        
        $response = match($config['provider']) {
            'openai' => self::openaiRequest($prompt, $config),
            'anthropic' => self::anthropicRequest($prompt, $config),
            default => throw new Exception('Unknown AI provider')
        };
        
        return $response;
    }
    
    // HTTP requests use PHP streams (file_get_contents + stream_context_create),
    // never curl. Providers: openai, anthropic, google.
    // See AI::generate() / AI::stream() in index.php for full implementation.

    private static function getSystemPrompt(): string {
        // Lists block types as primitives — the AI picks whichever blocks suit each page.
        // No mandatory page structures, no locked colour palettes.
        return <<<PROMPT
You are a creative website designer and copywriter AI.

Available block types (use whichever suit each page's purpose):
hero, features, text, testimonials, gallery, contact, faq, cta, team, pricing, stats

Design rules:
- Choose blocks freely based on what each page needs — not based on industry templates.
- Invent a colour palette that matches the brand personality described in the brief.
- Every site should feel genuinely unique, not like a filled-in template.
- Output valid JSON only (no markdown fences).
PROMPT;
    }
}
```

### 9.2 AI Creative Brief Form

The generation form at `/admin/ai` collects an open-ended creative brief. There are no industry dropdowns,
tone selectors, UI-theme presets, or feature checkboxes — the AI interprets the brief freely and makes
every design decision itself.

```php
class AIController {
    public static function form(): string {
        Auth::requireRole('admin');
        // No preset lists — template renders plain free-text inputs
        return Template::render('admin_ai', []);
    }

    public static function generate(): void {
        Auth::requireRole('admin');
        CSRF::validate();

        // Collect open-ended brief fields
        $input = [
            'business_name'      => Request::input('business_name'),      // required
            'description'        => Request::input('description'),         // required
            'target_audience'    => Request::input('target_audience'),
            'visual_style'       => Request::input('visual_style'),
            'color_preference'   => Request::input('color_preference'),
            'design_inspiration' => Request::input('design_inspiration'),
            'pages_needed'       => Request::input('pages_needed'),
            'features'           => Request::input('features'),            // free-text
            'user_content'       => Request::input('user_content'),
        ];

        $brief  = self::buildBrief($input);
        $struct = AI::generate(self::buildStructurePrompt($brief));
        $plan   = json_decode($struct, true);
        // … per-page content generation loop …

        DB::pdo()->prepare(
            "INSERT INTO build_queue (plan_json, status, created_at) VALUES (?, 'pending', datetime('now'))"
        )->execute([json_encode($plan)]);

        Session::flash('success', 'Site plan generated! Review it in the Approvals queue.');
        Response::redirect('/admin/approvals');
    }

    private static function buildBrief(array $input): string {
        $parts = ["Business / Project: {$input['business_name']}"];
        if ($input['description'])        $parts[] = "Description: {$input['description']}";
        if ($input['target_audience'])    $parts[] = "Target Audience: {$input['target_audience']}";
        if ($input['visual_style'])       $parts[] = "Visual Style: {$input['visual_style']}";
        if ($input['color_preference'])   $parts[] = "Colour Direction: {$input['color_preference']}";
        if ($input['design_inspiration']) $parts[] = "Design Inspiration: {$input['design_inspiration']}";
        if ($input['pages_needed'])       $parts[] = "Pages: {$input['pages_needed']}";
        if ($input['features'])           $parts[] = "Features: {$input['features']}";
        if ($input['user_content'])       $parts[] = "Existing content to use:\n{$input['user_content']}";
        return implode("\n", $parts);
    }

    private static function buildStructurePrompt(string $brief): string {
        return <<<PROMPT
You are a creative website designer. Based on the brief below, design a complete site architecture.

Choose everything freely: site name, tagline, page list, navigation, footer, and a full colour palette
that suits the brand personality. Do not follow rigid industry templates.

Output valid JSON only (no markdown fences):
{
  "site_name": "...", "tagline": "...",
  "colors": { "primary": "#hex", "secondary": "#hex", "accent": "#hex",
              "background": "#hex", "text": "#hex" },
  "pages": [{ "slug": "home", "title": "Home", "meta_description": "...",
              "purpose": "Landing page …" }],
  "nav":    [{ "label": "Home", "url": "/" }],
  "footer": { "text": "© 2026 …", "links": [], "social": [] }
}

Brief:
{$brief}
PROMPT;
    }
}
```

**System prompt philosophy (`AI::getSystemPrompt()`):**
The system prompt lists every available block type as a primitive (hero, features, text, testimonials,
gallery, contact, faq, cta, team, pricing, stats) and instructs the AI to choose blocks by page purpose —
not by industry template. It forbids mandatory page structures and locked colour palettes, ensuring every
generated site is genuinely unique.

### 9.3 Plan Application (After Approval)

```php
class BuildQueue {
    public static function apply(int $queueId): void {
        $db = DB::connect();
        
        $stmt = $db->prepare("SELECT * FROM build_queue WHERE id = ? AND status = 'approved'");
        $stmt->execute([$queueId]);
        $queue = $stmt->fetch();
        
        if (!$queue) throw new Exception('Build not found or not approved');
        
        $plan = json_decode($queue['plan_json'], true);
        
        $db->beginTransaction();
        try {
            // Apply theme colors
            foreach ($plan['colors'] as $key => $value) {
                $db->prepare("INSERT OR REPLACE INTO theme_styles (key, value) VALUES (?, ?)")
                   ->execute(['color_' . $key, $value]);
            }
            
            // Apply header
            $db->exec("DELETE FROM theme_header");
            $db->prepare("INSERT INTO theme_header (tagline, bg_color) VALUES (?, ?)")
               ->execute([$plan['tagline'], $plan['colors']['primary']]);
            
            // Apply nav
            $db->exec("DELETE FROM nav");
            $order = 0;
            foreach ($plan['nav'] as $item) {
                $db->prepare("INSERT INTO nav (label, url, `order`, visible) VALUES (?, ?, ?, 1)")
                   ->execute([$item['label'], $item['url'], $order++]);
            }
            
            // Apply pages
            foreach ($plan['pages'] as $page) {
                $db->prepare("INSERT INTO pages (slug, title, meta_description, status, created_at) VALUES (?, ?, ?, 'published', datetime('now'))")
                   ->execute([$page['slug'], $page['title'], $page['meta_description']]);
                $pageId = $db->lastInsertId();
                
                $blockOrder = 0;
                foreach ($page['blocks'] as $block) {
                    $db->prepare("INSERT INTO content_blocks (page_id, type, block_json, `order`) VALUES (?, ?, ?, ?)")
                       ->execute([$pageId, $block['type'], json_encode($block['content']), $blockOrder++]);
                }
            }
            
            // Apply footer
            $db->exec("DELETE FROM theme_footer");
            $db->prepare("INSERT INTO theme_footer (text, links_json, social_json) VALUES (?, ?, ?)")
               ->execute([$plan['footer']['text'], json_encode($plan['footer']['links']), json_encode($plan['footer']['social'])]);
            
            // Mark as applied
            $db->prepare("UPDATE build_queue SET status = 'applied', applied_at = datetime('now') WHERE id = ?")
               ->execute([$queueId]);
            
            $db->commit();
            
            // Invalidate all caches
            Cache::invalidateAll();
            
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }
}
```

### Deliverables:
- [ ] AI provider abstraction (OpenAI, Anthropic)
- [ ] System prompt for structured JSON output
- [ ] Discovery interview form
- [ ] User-provided content option
- [ ] Build queue storage
- [ ] Plan application with transaction safety
- [ ] Full cache invalidation after apply

---

## Phase 10: Setup Wizard

**Maps to:** app.md §9 (Optional Configuration at Setup), Technical Architecture §5

### 10.1 Setup Detection & Flow

```php
class SetupController {
    public static function index(): string {
        // Already set up?
        $db = DB::connect();
        $hasAdmin = $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() > 0;
        
        if ($hasAdmin) {
            Response::redirect('/admin/login');
        }
        
        $step = Request::input('step', 1);
        
        return match((int)$step) {
            1 => self::stepAdmin(),
            2 => self::stepAIProvider(),
            3 => self::stepSMTP(),
            4 => self::stepComplete(),
            default => self::stepAdmin()
        };
    }
    
    private static function stepAdmin(): string {
        return Template::render('setup/admin', [
            'step' => 1,
            'title' => 'Create Admin Account'
        ]);
    }
    
    private static function stepAIProvider(): string {
        return Template::render('setup/ai_provider', [
            'step' => 2,
            'title' => 'Configure AI Provider (Optional)',
            'providers' => ['openai' => 'OpenAI (GPT-4)', 'anthropic' => 'Anthropic (Claude)']
        ]);
    }
    
    private static function stepSMTP(): string {
        return Template::render('setup/smtp', [
            'step' => 3,
            'title' => 'Configure Email (Optional)'
        ]);
    }
    
    private static function stepComplete(): string {
        return Template::render('setup/complete', [
            'step' => 4,
            'title' => 'Setup Complete!'
        ]);
    }
    
    public static function process(): void {
        $step = (int)Request::input('step', 1);
        
        match($step) {
            1 => self::processAdmin(),
            2 => self::processAIProvider(),
            3 => self::processSMTP(),
            default => null
        };
    }
    
    private static function processAdmin(): void {
        $email = Request::input('email');
        $password = Request::input('password');
        $confirm = Request::input('password_confirm');
        
        if ($password !== $confirm) {
            Session::flash('error', 'Passwords do not match');
            Response::redirect('/setup?step=1');
        }
        
        if (strlen($password) < 12) {
            Session::flash('error', 'Password must be at least 12 characters');
            Response::redirect('/setup?step=1');
        }
        
        $db = DB::connect();
        $db->prepare("INSERT INTO users (email, password_hash, role, created_at) VALUES (?, ?, 'admin', datetime('now'))")
           ->execute([$email, Password::hash($password)]);
        
        Response::redirect('/setup?step=2');
    }
    
    private static function processAIProvider(): void {
        $provider = Request::input('ai_provider');
        $apiKey = Request::input('ai_api_key');
        
        if ($provider && $apiKey) {
            Settings::set('ai_provider', $provider);
            Settings::setEncrypted('ai_api_key', $apiKey);
        }
        
        Response::redirect('/setup?step=3');
    }
    
    private static function processSMTP(): void {
        $host = Request::input('smtp_host');
        $port = Request::input('smtp_port');
        $user = Request::input('smtp_user');
        $pass = Request::input('smtp_pass');
        
        if ($host) {
            Settings::set('smtp_host', $host);
            Settings::set('smtp_port', $port ?: '587');
            Settings::set('smtp_user', $user);
            Settings::setEncrypted('smtp_pass', $pass);
        }
        
        Response::redirect('/setup?step=4');
    }
}
```

### Deliverables:
- [ ] Uninitialized detection (no admin user)
- [ ] Multi-step wizard UI
- [ ] Admin account creation with strong password requirement
- [ ] Optional AI provider configuration
- [ ] Optional SMTP configuration
- [ ] Encrypted storage for API keys and passwords

---

## Phase 11: SEO & Metadata

**Maps to:** app.md §7 (SEO and Metadata)

### 11.1 SEO Helper Class

```php
class SEO {
    public static function meta(array $page): string {
        $siteName = Settings::get('site_name', 'OneCMS');
        $title = $page['title'] . ' | ' . $siteName;
        $description = $page['meta_description'] ?? '';
        $ogImage = $page['og_image'] ?? Settings::get('default_og_image', '');
        $url = 'https://' . $_SERVER['HTTP_HOST'] . '/' . $page['slug'];
        
        $meta = <<<HTML
<title>{$title}</title>
<meta name="description" content="{$description}">
<meta name="robots" content="index, follow">
<link rel="canonical" href="{$url}">

<!-- Open Graph -->
<meta property="og:type" content="website">
<meta property="og:title" content="{$page['title']}">
<meta property="og:description" content="{$description}">
<meta property="og:image" content="{$ogImage}">
<meta property="og:url" content="{$url}">
<meta property="og:site_name" content="{$siteName}">

<!-- Twitter Card -->
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{$page['title']}">
<meta name="twitter:description" content="{$description}">
<meta name="twitter:image" content="{$ogImage}">
HTML;
        
        // Add head scripts from settings
        $headScripts = Settings::get('head_scripts', '');
        if ($headScripts) {
            $meta .= "\n" . $headScripts;
        }
        
        return $meta;
    }
    
    public static function structuredData(array $page): string {
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
            'name' => $page['title'],
            'description' => $page['meta_description'] ?? '',
            'url' => 'https://' . $_SERVER['HTTP_HOST'] . '/' . $page['slug']
        ];
        
        return '<script type="application/ld+json">' . json_encode($data) . '</script>';
    }
}
```

### Deliverables:
- [ ] Dynamic `<title>` and meta description
- [ ] Open Graph tags
- [ ] Twitter Card tags
- [ ] Canonical URL
- [ ] Custom head/footer scripts support
- [ ] JSON-LD structured data

---

## Phase 12: Email & Communication

**Maps to:** app.md §8 (Communication)

### 12.1 SMTP Mailer

```php
class Mailer {
    public static function send(string $to, string $subject, string $body): bool {
        $host = Settings::get('smtp_host');
        $port = (int)Settings::get('smtp_port', 587);
        $user = Settings::get('smtp_user');
        $pass = Settings::getEncrypted('smtp_pass');
        $from = Settings::get('smtp_from', $user);
        
        if (!$host) {
            // Fallback to mail() function
            return mail($to, $subject, $body, "From: $from\r\nContent-Type: text/html; charset=UTF-8");
        }
        
        // SMTP via socket
        $socket = fsockopen($host, $port, $errno, $errstr, 30);
        if (!$socket) return false;
        
        // ... SMTP handshake implementation ...
        // (AUTH LOGIN, MAIL FROM, RCPT TO, DATA, etc.)
        
        fclose($socket);
        return true;
    }
    
    public static function sendContactForm(array $data): bool {
        $to = Settings::get('contact_email');
        $subject = 'New Contact Form Submission';
        
        $body = "<h2>New Contact Form Submission</h2>";
        $body .= "<p><strong>Name:</strong> " . htmlspecialchars($data['name']) . "</p>";
        $body .= "<p><strong>Email:</strong> " . htmlspecialchars($data['email']) . "</p>";
        $body .= "<p><strong>Message:</strong><br>" . nl2br(htmlspecialchars($data['message'])) . "</p>";
        
        return self::send($to, $subject, $body);
    }
    
    public static function sendMFACode(string $email, string $code): bool {
        $subject = 'Your Login Code';
        $body = "<p>Your verification code is: <strong>$code</strong></p>";
        $body .= "<p>This code expires in 10 minutes.</p>";
        
        return self::send($email, $subject, $body);
    }
}
```

### 12.2 Contact Form Handler

```php
class FormController {
    public static function contact(): string {
        if (!RateLimit::check('contact_form', 5, 3600)) {
            return Response::json(['error' => 'Too many submissions. Please wait.'], 429);
        }
        
        // Validate
        $name = trim(Request::input('name', ''));
        $email = filter_var(Request::input('email', ''), FILTER_VALIDATE_EMAIL);
        $message = trim(Request::input('message', ''));
        
        if (!$name || !$email || !$message) {
            return Response::json(['error' => 'All fields are required'], 400);
        }
        
        // Send email
        $sent = Mailer::sendContactForm([
            'name' => $name,
            'email' => $email,
            'message' => $message
        ]);
        
        if ($sent) {
            return Response::json(['success' => true, 'message' => 'Thank you! We\'ll be in touch.']);
        }
        
        return Response::json(['error' => 'Failed to send message'], 500);
    }
}
```

### Deliverables:
- [ ] SMTP mailer with socket implementation
- [ ] Fallback to PHP mail()
- [ ] Contact form handling with rate limiting
- [ ] MFA code emails
- [ ] HTML email templates

---

## Phase 13: Content Approval Workflow

**Maps to:** app.md §11 (Content Approval Workflow)

### 13.1 Approval Controller

```php
class ApprovalController {
    public static function queue(): string {
        Auth::require('*');
        
        $db = DB::connect();
        $pending = $db->query("SELECT * FROM build_queue WHERE status = 'pending' ORDER BY created_at DESC")->fetchAll();
        
        // Decode plan JSON for display
        foreach ($pending as &$item) {
            $item['plan'] = json_decode($item['plan_json'], true);
        }
        
        return Template::render('admin/approvals', ['pending' => $pending]);
    }
    
    public static function approve(int $id): void {
        Auth::require('*');
        CSRF::verify($_POST['_csrf'] ?? '');
        
        $db = DB::connect();
        $db->prepare("UPDATE build_queue SET status = 'approved', approved_at = datetime('now'), approved_by = ? WHERE id = ?")
           ->execute([Auth::user()['id'], $id]);
        
        // Apply the build
        BuildQueue::apply($id);
        
        Session::flash('success', 'Site plan approved and applied!');
        Response::redirect('/admin/approvals');
    }
    
    public static function reject(int $id): void {
        Auth::require('*');
        CSRF::verify($_POST['_csrf'] ?? '');
        
        $db = DB::connect();
        $db->prepare("UPDATE build_queue SET status = 'rejected', rejected_at = datetime('now') WHERE id = ?")
           ->execute([$id]);
        
        Session::flash('info', 'Site plan rejected.');
        Response::redirect('/admin/approvals');
    }
}
```

### 13.2 Approval UI Template

```php
'admin/approvals' => <<<'HTML'
<h2>Pending Approvals</h2>

{{#if pending}}
{{#each pending}}
<article class="approval-item">
    <header>
        <strong>{{plan.site_name}}</strong> - Generated {{created_at}}
    </header>
    
    <details>
        <summary>View Plan Details</summary>
        <pre>{{plan_json}}</pre>
    </details>
    
    <h4>Pages to be created:</h4>
    <ul>
        {{#each plan.pages}}
        <li>{{title}} ({{slug}})</li>
        {{/each}}
    </ul>
    
    <h4>Navigation:</h4>
    <ul>
        {{#each plan.nav}}
        <li>{{label}} → {{url}}</li>
        {{/each}}
    </ul>
    
    <footer>
        <form method="post" action="/admin/approvals/{{id}}/approve" style="display:inline">
            {{csrf}}
            <button type="submit" class="success">✓ Approve & Apply</button>
        </form>
        <form method="post" action="/admin/approvals/{{id}}/reject" style="display:inline">
            {{csrf}}
            <button type="submit" class="danger">✗ Reject</button>
        </form>
    </footer>
</article>
{{/each}}
{{else}}
<p>No pending approvals.</p>
{{/if}}
HTML
```

### Deliverables:
- [ ] Approval queue view
- [ ] Plan preview (pages, nav, colors)
- [ ] Approve action (triggers BuildQueue::apply)
- [ ] Reject action
- [ ] Audit trail (approved_by, timestamps)

---

## Phase 14: CSS Optimization

**Maps to:** app.md §16 (Optimized CSS)

### 14.1 CSS Generator

```php
class CSSGenerator {
    public static function generate(): string {
        $db = DB::connect();
        
        // Get theme tokens
        $styles = $db->query("SELECT key, value FROM theme_styles")->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Base CSS with CSS variables (Light Mode)
        $css = <<<CSS
:root {
    --color-primary: {$styles['color_primary'] ?? '#3b82f6'};
    --color-secondary: {$styles['color_secondary'] ?? '#1e40af'};
    --color-accent: {$styles['color_accent'] ?? '#f59e0b'};
    --color-background: {$styles['color_background'] ?? '#ffffff'};
    --color-background-secondary: {$styles['color_background_secondary'] ?? '#f8fafc'};
    --color-text: {$styles['color_text'] ?? '#1f2937'};
    --color-text-muted: {$styles['color_text_muted'] ?? '#6b7280'};
    --color-border: {$styles['color_border'] ?? '#e5e7eb'};
    --font-family: {$styles['font_family'] ?? 'system-ui, sans-serif'};
}

/* Dark Mode */
@media (prefers-color-scheme: dark) {
    :root {
        --color-primary: {$styles['color_primary_dark'] ?? '#60a5fa'};
        --color-secondary: {$styles['color_secondary_dark'] ?? '#3b82f6'};
        --color-accent: {$styles['color_accent_dark'] ?? '#fbbf24'};
        --color-background: {$styles['color_background_dark'] ?? '#0f172a'};
        --color-background-secondary: {$styles['color_background_secondary_dark'] ?? '#1e293b'};
        --color-text: {$styles['color_text_dark'] ?? '#f1f5f9'};
        --color-text-muted: {$styles['color_text_muted_dark'] ?? '#94a3b8'};
        --color-border: {$styles['color_border_dark'] ?? '#334155'};
    }
}

/* Reset */
*, *::before, *::after { box-sizing: border-box; }
body { margin: 0; font-family: var(--font-family); color: var(--color-text); background: var(--color-background); line-height: 1.6; }

/* Typography */
h1, h2, h3, h4, h5, h6 { margin-top: 0; }
a { color: var(--color-primary); }

/* Layout */
.container { max-width: 1200px; margin: 0 auto; padding: 0 1rem; }

/* Header */
.site-header { background: var(--color-primary); color: #fff; padding: 1rem; }
.site-header .logo { max-height: 60px; }

/* Navigation */
.main-nav ul { list-style: none; padding: 0; margin: 0; display: flex; gap: 1rem; background: var(--color-secondary); padding: 1rem; }
.main-nav a { color: #fff; text-decoration: none; }

/* Footer */
.site-footer { background: var(--color-text); color: #fff; padding: 2rem; text-align: center; margin-top: 2rem; }
CSS;
        
        // Add block-specific styles based on what blocks are used
        $usedTypes = $db->query("SELECT DISTINCT type FROM content_blocks")->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($usedTypes as $type) {
            $css .= self::getBlockCSS($type);
        }
        
        // Minify
        $css = preg_replace('/\s+/', ' ', $css);
        $css = preg_replace('/\s*([{}:;,])\s*/', '$1', $css);
        
        return $css;
    }
    
    private static function getBlockCSS(string $type): string {
        return match($type) {
            'hero' => '.block-hero { padding: 4rem 2rem; text-align: center; background: linear-gradient(135deg, var(--color-primary), var(--color-secondary)); color: #fff; }',
            'features' => '.block-features { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 2rem; padding: 2rem; }',
            'gallery' => '.block-gallery { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; } .block-gallery img { width: 100%; height: auto; }',
            'form' => '.block-form { max-width: 600px; margin: 2rem auto; } .block-form input, .block-form textarea { width: 100%; padding: 0.5rem; margin-bottom: 1rem; }',
            'text' => '.block-text { padding: 2rem; max-width: 800px; margin: 0 auto; }',
            default => ''
        };
    }
    
    public static function cacheAndGetHash(): string {
        $css = self::generate();
        $hash = substr(md5($css), 0, 12);
        
        $file = ONECMS_CACHE . '/assets/app.' . $hash . '.css';
        if (!file_exists($file)) {
            // Clear old CSS files
            foreach (glob(ONECMS_CACHE . '/assets/app.*.css') as $old) {
                unlink($old);
            }
            file_put_contents($file, $css);
        }
        
        return $hash;
    }
}
```

### Deliverables:
- [ ] CSS variables from theme_styles table
- [ ] Block-specific CSS only for used block types
- [ ] Minification
- [ ] Hash-based cache busting
- [ ] Automatic regeneration on theme change

---

## Phase 15: Production Hardening

**Maps to:** app.md §1 (Production Environment), §20 (Maximum Security)

### 15.1 Security Checklist Implementation

```php
// At the top of index.php after boot

// 1. Content Security Policy
$cspNonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$cspNonce}' https://cdn.quilljs.com https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.quilljs.com https://cdn.jsdelivr.net; img-src 'self' data: https:; font-src 'self' https:; connect-src 'self' https://api.openai.com");

// 2. Additional security headers
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
header('Referrer-Policy: strict-origin-when-cross-origin');

// 3. Prevent access to sensitive files
$uri = $_SERVER['REQUEST_URI'];
if (preg_match('/\.(sqlite|db|sql|log|env|htaccess)$/i', $uri)) {
    http_response_code(403);
    exit('Access Denied');
}

// 4. Block direct access to cache directory contents
if (str_starts_with($uri, '/cache/') && !str_starts_with($uri, '/cache/assets/')) {
    http_response_code(403);
    exit('Access Denied');
}
```

### 15.2 .htaccess for Apache

```apache
# /onecms/.htaccess
RewriteEngine On

# Force HTTPS (uncomment in production)
# RewriteCond %{HTTPS} off
# RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

# Front controller
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [L]

# Protect sensitive files
<FilesMatch "\.(sqlite|db|sql|log|env)$">
    Order allow,deny
    Deny from all
</FilesMatch>

# Protect cache directory (except assets)
<Directory "cache">
    Order allow,deny
    Deny from all
</Directory>
<Directory "cache/assets">
    Order deny,allow
    Allow from all
</Directory>

# Security headers (if mod_headers enabled)
<IfModule mod_headers.c>
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-XSS-Protection "1; mode=block"
</IfModule>
```

### 15.3 Input Sanitization Helpers

```php
class Sanitize {
    public static function html(string $input): string {
        return htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    
    public static function sql(PDO $pdo, string $input): string {
        // Note: Always use prepared statements instead
        return $pdo->quote($input);
    }
    
    public static function filename(string $input): string {
        return preg_replace('/[^a-zA-Z0-9._-]/', '', $input);
    }
    
    public static function slug(string $input): string {
        $slug = strtolower(trim($input));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        return trim($slug, '-');
    }
    
    public static function richText(string $html): string {
        // Allow only safe tags
        $allowed = '<p><br><strong><em><u><a><ul><ol><li><h1><h2><h3><h4><blockquote>';
        $clean = strip_tags($html, $allowed);
        
        // Sanitize URLs in links
        $clean = preg_replace_callback('/<a\s+href=["\']([^"\']+)["\']/', function($m) {
            $url = filter_var($m[1], FILTER_SANITIZE_URL);
            if (!preg_match('/^https?:\/\//', $url) && !str_starts_with($url, '/')) {
                $url = '#';
            }
            return '<a href="' . self::html($url) . '"';
        }, $clean);
        
        return $clean;
    }
}
```

### Deliverables:
- [ ] CSP header with nonce for inline scripts
- [ ] .htaccess protection for SQLite and cache
- [ ] Input sanitization helpers
- [ ] XSS prevention via Sanitize::html()
- [ ] SQL injection prevention via prepared statements
- [ ] File upload validation
- [ ] Rate limiting on all sensitive endpoints

---

## Testing & QA Checklist

### Functional Tests

| Test | Expected Result |
|------|-----------------|
| Setup wizard completes | Admin user created, settings saved |
| Admin login works | Session established, redirect to dashboard |
| MFA code sent and verified | OTP email received, login completes |
| AI generation works | JSON plan stored in build_queue |
| Approval applies plan | Pages, nav, theme populated |
| Page renders from cache | HTML served from cache/pages/ |
| Cache invalidates on edit | Fresh content after save |
| Media upload works | Asset stored as BLOB, servable via /assets/ |
| Contact form submits | Email sent, rate limit enforced |
| CSS regenerates on theme change | New hash, old file deleted |

### Security Tests

| Test | Expected Result |
|------|-----------------|
| SQL injection attempt | Query fails safely, no data leak |
| XSS in content | Tags escaped in output |
| CSRF without token | 403 Forbidden |
| Direct SQLite access | 403 Forbidden |
| Brute force login | Rate limited after 5 attempts |
| Invalid file upload | Rejected, error message |
| Unauthorized admin access | Redirect to login |

### Performance Tests

| Metric | Target |
|--------|--------|
| Cached page load | < 50ms |
| Uncached page render | < 200ms |
| CSS file size | < 20KB minified |
| Asset serve time | < 30ms |
| Database queries per page | < 10 |

---

## Deployment Checklist

1. [ ] Upload `index.php` to web root
2. [ ] Ensure PHP 8.1+ with extensions: pdo_sqlite, curl, gd, mbstring
3. [ ] Create `.htaccess` (Apache) or configure nginx
4. [ ] Set file permissions: `index.php` (644), cache dir (755)
5. [ ] Run setup wizard at `/setup`
6. [ ] Configure AI provider and SMTP
7. [ ] Generate first site via AI interview
8. [ ] Approve and publish
9. [ ] Test public pages
10. [ ] Enable HTTPS and update .htaccess

---

## Estimated Development Timeline

| Phase | Duration | Dependencies |
|-------|----------|--------------|
| Phase 1-2: Foundation & DB | 2 days | None |
| Phase 3: Security | 2 days | Phase 2 |
| Phase 4-5: Routing & Templates | 2 days | Phase 2 |
| Phase 6: Caching | 1 day | Phase 5 |
| Phase 7: Asset Management | 1 day | Phase 2 |
| Phase 8: Admin UI | 3 days | Phase 3-6 |
| Phase 9: AI Integration | 2 days | Phase 8 |
| Phase 10: Setup Wizard | 1 day | Phase 3, 9 |
| Phase 11-12: SEO & Email | 1 day | Phase 5 |
| Phase 13: Approval Workflow | 1 day | Phase 9 |
| Phase 14-15: CSS & Hardening | 1 day | All |
| Testing & QA | 2 days | All |

**Total: ~19 days**

---

## Conclusion

This implementation plan provides a complete roadmap for building OneCMS as a single-file PHP application. Every requirement from [app.md](app.md) has been addressed with specific code patterns, database schemas, and implementation strategies.

Key architectural decisions:
- **Single-file architecture** with clearly separated sections
- **SQLite with BLOBs** for true portability (2 files to deploy)
- **Partial caching** for performance with granular invalidation
- **CDN-based WYSIWYG** (Quill) to keep the single file lean
- **Structured AI output** (JSON) for reliable plan application
- **Human-in-the-loop approval** before any AI content goes live

Next steps:
1. Begin with Phase 1-2 (Foundation & Database)
2. Build incrementally, testing each phase
3. Integrate AI last, once the foundation is solid

