<?php
/*==============================================================================
 * MonolithCMS - Single-File AI-Driven Website Builder
 * Version: 1.1.5
 *
 * A portable, AI-powered CMS in a single PHP file with SQLite backend.
 * Upload index.php to any PHP host — site.sqlite is created automatically on first run.
 *
 * Dev server (no separate router file needed):
 *   php -S 127.0.0.1:8080 index.php
 *
 * ── Editing workflow ─────────────────────────────────────────────────────────
 * index.php is READ-ONLY (chmod 444). Never edit it directly.
 * Use the split/merge scripts to make changes:
 *
 *   php scripts/split.php   → splits into tmp/sections/s01…s30.php
 *   # edit the relevant section file(s)
 *   php scripts/merge.php   → validates, lints, merges back, re-locks
 *
 * Section map and convention rules: see CLAUDE.md §Section Convention
 *
 * Copyright (c) 2026 MonolithCMS Contributors.
 * Licensed under the GNU General Public License v3 with Attribution Requirement.
 * Attribution: "Powered by MonolithCMS — https://monolithcms.com/" must be
 * included in the UI or documentation of any distributed or hosted version.
 * See LICENSE file for full terms.
 *=============================================================================*/

// ── PHP built-in server: pass real static files through, route everything else
if (PHP_SAPI === 'cli-server') {
    $static = __DIR__ . parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if ($static !== __FILE__ && is_file($static)) {
        return false; // serve file as-is
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 1: CONFIGURATION & BOOT
// ─────────────────────────────────────────────────────────────────────────────

// Constants
define('MONOLITHCMS_VERSION', '1.1.5');
define('MONOLITHCMS_ROOT', __DIR__);
define('MONOLITHCMS_DB', MONOLITHCMS_ROOT . '/site.sqlite');
define('MONOLITHCMS_CACHE', MONOLITHCMS_ROOT . '/cache');

// Environment Detection
$isProduction = !in_array($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', ['127.0.0.1', '::1']);
$isDev        = !$isProduction;
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
           || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')  === 'https'
           || ($_SERVER['HTTP_X_FORWARDED_SSL']   ?? '')  === 'on'
           || ($_SERVER['HTTP_CF_VISITOR']         ?? '')  === '{"scheme":"https"}';

define('MONOLITHCMS_DEV', $isDev);

// Error Handling
if ($isProduction) {
    error_reporting(0);
    ini_set('display_errors', '0');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

// Buffer all output so PHP warnings/notices never corrupt JSON API responses
ob_start();

// Safety net: ensure session is always written/closed on every exit path. This covers asset serves, JSON responses, and any other path that calls exit without explicitly calling session_write_close(), preventing lock contention under HTTP/2 concurrent requests.
register_shutdown_function(function () {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
});

// Dev mode: disable all caching at the HTTP level
if (MONOLITHCMS_DEV) {
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('X-MonolithCMS-Dev: 1');
}

// Timezone
date_default_timezone_set('UTC');

// Security Headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

if ($isHttps) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// CSP Nonce for inline scripts
$cspNonce = base64_encode(random_bytes(16));
define('CSP_NONCE', $cspNonce);

// Content Security Policy - all resources served locally from cache
header("Content-Security-Policy: " .
    "default-src 'self'; " .
    "script-src 'self' 'nonce-{$cspNonce}' https://www.googletagmanager.com https://www.google-analytics.com; " .
    "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://fonts.googleapis.com; " .
    "font-src 'self' https://cdnjs.cloudflare.com https://fonts.gstatic.com; " .
    "img-src 'self' data: https:; " .
    "frame-src 'self' https://www.youtube.com https://youtu.be https://www.youtube-nocookie.com https://player.vimeo.com https://www.google.com; " .
    "worker-src blob: 'self'; " .
    "connect-src 'self' https://api.openai.com https://api.anthropic.com https://generativelanguage.googleapis.com " .
        "https://www.google-analytics.com https://analytics.google.com https://stats.g.doubleclick.net https://www.googletagmanager.com"
);

// Block access to sensitive files
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
if (preg_match('/\.(sqlite|db|sql|log|env|htaccess)$/i', $requestUri)) {
    http_response_code(403);
    exit('Access Denied');
}

// Block direct access to cache directory (except assets)
if (str_starts_with($requestUri, '/cache/') && !str_starts_with($requestUri, '/cache/assets/')) {
    http_response_code(403);
    exit('Access Denied');
}

// Ensure cache directories exist
$cacheDirs = [
    MONOLITHCMS_CACHE,
    MONOLITHCMS_CACHE . '/pages',
    MONOLITHCMS_CACHE . '/partials',
    MONOLITHCMS_CACHE . '/assets',
    MONOLITHCMS_CACHE . '/assets/files',  // static copies of uploaded asset BLOBs
    MONOLITHCMS_CACHE . '/assets/cdn',    // locally-cached CDN libraries
];
foreach ($cacheDirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// Version-based cache invalidation
$versionFile = MONOLITHCMS_CACHE . '/.version';
$cachedVersion = @file_get_contents($versionFile);
if ($cachedVersion !== MONOLITHCMS_VERSION) {
    // Clear page/partial HTML caches
    foreach (['/pages', '/partials'] as $subdir) {
        $dir = MONOLITHCMS_CACHE . $subdir;
        if (is_dir($dir)) {
            array_map('unlink', glob($dir . '/*.*') ?: []);
        }
    }
    // Clear CDN-cached library files (e.g. GrapesJS) so updated URLs are re-downloaded
    $cdnDir = MONOLITHCMS_CACHE . '/assets/cdn';
    if (is_dir($cdnDir)) {
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($cdnDir, FilesystemIterator::SKIP_DOTS)
        ) as $f) {
            if ($f->isFile()) @unlink($f->getPathname());
        }
    }
    // Clear generated CSS asset files (theme CSS, regenerated on next request)
    array_map('unlink', glob(MONOLITHCMS_CACHE . '/assets/*.css') ?: []);
    // Invalidate cached sitemap so it is rebuilt with fresh URLs on next request
    @unlink(MONOLITHCMS_CACHE . '/sitemap.xml');
    // Update version marker
    @file_put_contents($versionFile, MONOLITHCMS_VERSION);
}

// Auto-write .htaccess if missing (Apache front-controller routing)
$htaccess = MONOLITHCMS_ROOT . '/.htaccess';
if (!file_exists($htaccess)) {
    $htaccessContent = <<<'HTACCESS'
RewriteEngine On
RewriteBase /

# Redirect www to non-www
RewriteCond %{HTTP_HOST} ^www\.(.+)$ [NC]
RewriteRule ^ https://%1%{REQUEST_URI} [R=301,L]

# Block direct access to sensitive files
<FilesMatch "\.(sqlite|sqlite3|db|env|log|sh|bak|sql)$">
    Require all denied
</FilesMatch>
<FilesMatch "^(composer\.json|composer\.lock|CLAUDE\.md|\.gitignore)$">
    Require all denied
</FilesMatch>

RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [QSA,L]
HTACCESS;
    @file_put_contents($htaccess, $htaccessContent);
    if (file_exists($htaccess)) {
        @chmod($htaccess, 0644);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 2: DATABASE LAYER
// ─────────────────────────────────────────────────────────────────────────────

class DB {
    private static ?PDO $pdo = null;

    public static function connect(): PDO {
        if (self::$pdo === null) {
            self::$pdo = new PDO('sqlite:' . MONOLITHCMS_DB, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]);
            self::$pdo->exec('PRAGMA journal_mode=WAL');
            self::$pdo->exec('PRAGMA foreign_keys=ON');
            self::migrate();
        }
        return self::$pdo;
    }

    public static function query(string $sql, array $params = []): \PDOStatement {
        $stmt = self::connect()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function fetch(string $sql, array $params = []): ?array {
        $result = self::query($sql, $params)->fetch();
        return $result ?: null;
    }

    public static function fetchAll(string $sql, array $params = []): array {
        return self::query($sql, $params)->fetchAll();
    }

    public static function execute(string $sql, array $params = []): int {
        return self::query($sql, $params)->rowCount();
    }

    public static function lastInsertId(): int {
        return (int) self::connect()->lastInsertId();
    }

    public static function beginTransaction(): void {
        self::connect()->beginTransaction();
    }

    public static function commit(): void {
        self::connect()->commit();
    }

    public static function rollBack(): void {
        self::connect()->rollBack();
    }

    private static function migrate(): void {
        // Create migrations table
        self::$pdo->exec("CREATE TABLE IF NOT EXISTS migrations (
            version INTEGER PRIMARY KEY,
            applied_at TEXT DEFAULT (datetime('now'))
        )");

        $current = (int) self::$pdo->query("SELECT MAX(version) FROM migrations")->fetchColumn();

        $migrations = [
            // Migration 1: Users table
            1 => "CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT UNIQUE NOT NULL,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT 'viewer' CHECK(role IN ('admin', 'editor', 'viewer')),
                mfa_code_hash TEXT,
                mfa_expires TEXT,
                created_at TEXT DEFAULT (datetime('now')),
                updated_at TEXT DEFAULT (datetime('now'))
            )",

            // Migration 2: Sessions table
            2 => "CREATE TABLE IF NOT EXISTS sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                token_hash TEXT UNIQUE NOT NULL,
                ip_address TEXT,
                user_agent TEXT,
                expires_at TEXT NOT NULL,
                created_at TEXT DEFAULT (datetime('now')),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )",

            // Migration 3: Settings table
            3 => "CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value TEXT,
                encrypted INTEGER DEFAULT 0
            )",

            // Migration 4: Navigation table
            4 => "CREATE TABLE IF NOT EXISTS nav (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                label TEXT NOT NULL,
                url TEXT NOT NULL,
                parent_id INTEGER,
                sort_order INTEGER DEFAULT 0,
                visible INTEGER DEFAULT 1,
                created_at TEXT DEFAULT (datetime('now')),
                FOREIGN KEY (parent_id) REFERENCES nav(id) ON DELETE SET NULL
            )",

            // Migration 5: Pages table
            5 => "CREATE TABLE IF NOT EXISTS pages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                slug TEXT UNIQUE NOT NULL,
                title TEXT NOT NULL,
                meta_description TEXT,
                og_image TEXT,
                status TEXT DEFAULT 'draft' CHECK(status IN ('draft', 'published', 'archived')),
                created_at TEXT DEFAULT (datetime('now')),
                updated_at TEXT DEFAULT (datetime('now'))
            )",

            // Migration 6: Content blocks table
            6 => "CREATE TABLE IF NOT EXISTS content_blocks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                page_id INTEGER NOT NULL,
                type TEXT NOT NULL,
                block_json TEXT NOT NULL DEFAULT '{}',
                sort_order INTEGER DEFAULT 0,
                created_at TEXT DEFAULT (datetime('now')),
                updated_at TEXT DEFAULT (datetime('now')),
                FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE
            )",

            // Migration 7: Theme header table
            7 => "CREATE TABLE IF NOT EXISTS theme_header (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                logo_asset_id INTEGER,
                tagline TEXT,
                bg_color TEXT DEFAULT '#3b82f6',
                text_color TEXT DEFAULT '#ffffff',
                nav_style TEXT DEFAULT 'horizontal'
            )",

            // Migration 8: Theme footer table
            8 => "CREATE TABLE IF NOT EXISTS theme_footer (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                text TEXT,
                links_json TEXT DEFAULT '[]',
                social_json TEXT DEFAULT '[]',
                bg_color TEXT DEFAULT '#1f2937',
                text_color TEXT DEFAULT '#ffffff'
            )",

            // Migration 9: Theme styles table
            9 => "CREATE TABLE IF NOT EXISTS theme_styles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                key TEXT UNIQUE NOT NULL,
                value TEXT NOT NULL
            )",

            // Migration 10: Assets table (BLOB storage)
            10 => "CREATE TABLE IF NOT EXISTS assets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                filename TEXT NOT NULL,
                mime_type TEXT NOT NULL,
                blob_data BLOB NOT NULL,
                hash TEXT UNIQUE NOT NULL,
                file_size INTEGER,
                created_at TEXT DEFAULT (datetime('now'))
            )",

            // Migration 11: Build queue table (AI generation)
            11 => "CREATE TABLE IF NOT EXISTS build_queue (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                plan_json TEXT NOT NULL,
                status TEXT DEFAULT 'pending' CHECK(status IN ('pending', 'approved', 'rejected', 'applied')),
                created_at TEXT DEFAULT (datetime('now')),
                approved_at TEXT,
                approved_by INTEGER,
                rejected_at TEXT,
                FOREIGN KEY (approved_by) REFERENCES users(id)
            )",

            // Migration 12: Revisions table (version history)
            12 => "CREATE TABLE IF NOT EXISTS revisions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                table_name TEXT NOT NULL,
                record_id INTEGER NOT NULL,
                old_json TEXT,
                new_json TEXT,
                user_id INTEGER,
                created_at TEXT DEFAULT (datetime('now')),
                FOREIGN KEY (user_id) REFERENCES users(id)
            )",

            // Migration 13: Rate limits table
            13 => "CREATE TABLE IF NOT EXISTS rate_limits (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ip TEXT NOT NULL,
                action TEXT NOT NULL,
                attempts INTEGER DEFAULT 1,
                last_attempt TEXT DEFAULT (datetime('now')),
                UNIQUE(ip, action)
            )",

            // Migration 14: Create indexes
            14 => "CREATE INDEX IF NOT EXISTS idx_pages_slug ON pages(slug);
                   CREATE INDEX IF NOT EXISTS idx_pages_status ON pages(status);
                   CREATE INDEX IF NOT EXISTS idx_content_blocks_page ON content_blocks(page_id);
                   CREATE INDEX IF NOT EXISTS idx_assets_hash ON assets(hash);
                   CREATE INDEX IF NOT EXISTS idx_sessions_token ON sessions(token_hash);
                   CREATE INDEX IF NOT EXISTS idx_rate_limits_ip_action ON rate_limits(ip, action)",

            // Migration 15: Insert default theme styles (light mode only; migration 19 removed dark variants)
            15 => "INSERT OR IGNORE INTO theme_styles (key, value) VALUES
                   ('color_primary', '#3b82f6'),
                   ('color_secondary', '#1e40af'),
                   ('color_accent', '#f59e0b'),
                   ('color_background', '#ffffff'),
                   ('color_background_secondary', '#f8fafc'),
                   ('color_text', '#1f2937'),
                   ('color_text_muted', '#6b7280'),
                   ('color_border', '#e5e7eb'),
                   ('font_family', 'system-ui, -apple-system, sans-serif')",

            // Migration 16: Insert default header and footer
            16 => "INSERT OR IGNORE INTO theme_header (id, tagline, bg_color) VALUES (1, 'Welcome to MonolithCMS', '#3b82f6');
                   INSERT OR IGNORE INTO theme_footer (id, text) VALUES (1, '© ' || strftime('%Y', 'now') || ' MonolithCMS. All rights reserved.')",

            // Migration 17: Add applied_at and rejection_reason to build_queue
            17 => "ALTER TABLE build_queue ADD COLUMN applied_at TEXT;
                   ALTER TABLE build_queue ADD COLUMN rejection_reason TEXT",

            // Migration 18: Add last_login to users and meta_json to pages
            18 => "ALTER TABLE users ADD COLUMN last_login TEXT;
                   ALTER TABLE pages ADD COLUMN meta_json TEXT DEFAULT '{}'",

            // Migration 19: Remove dark-mode color variants (dark mode removed)
            19 => "DELETE FROM theme_styles WHERE key LIKE '%_dark'",

            // Migration 20: Blog categories
            20 => "CREATE TABLE IF NOT EXISTS blog_categories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                slug TEXT UNIQUE NOT NULL,
                description TEXT,
                created_at TEXT DEFAULT (datetime('now'))
            )",

            // Migration 21: Blog tags
            21 => "CREATE TABLE IF NOT EXISTS blog_tags (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                slug TEXT UNIQUE NOT NULL,
                created_at TEXT DEFAULT (datetime('now'))
            )",

            // Migration 22: Blog posts
            22 => "CREATE TABLE IF NOT EXISTS blog_posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                slug TEXT UNIQUE NOT NULL,
                excerpt TEXT,
                body_html TEXT NOT NULL DEFAULT '',
                cover_asset_id INTEGER,
                author_id INTEGER NOT NULL,
                category_id INTEGER,
                status TEXT DEFAULT 'draft' CHECK(status IN ('draft','published','archived')),
                published_at TEXT,
                meta_description TEXT,
                og_image_asset_id INTEGER,
                created_at TEXT DEFAULT (datetime('now')),
                updated_at TEXT DEFAULT (datetime('now')),
                FOREIGN KEY (author_id) REFERENCES users(id),
                FOREIGN KEY (category_id) REFERENCES blog_categories(id) ON DELETE SET NULL,
                FOREIGN KEY (cover_asset_id) REFERENCES assets(id) ON DELETE SET NULL
            )",

            // Migration 23: Blog post-tag many-to-many
            23 => "CREATE TABLE IF NOT EXISTS blog_post_tags (
                post_id INTEGER NOT NULL,
                tag_id INTEGER NOT NULL,
                PRIMARY KEY (post_id, tag_id),
                FOREIGN KEY (post_id) REFERENCES blog_posts(id) ON DELETE CASCADE,
                FOREIGN KEY (tag_id) REFERENCES blog_tags(id) ON DELETE CASCADE
            )",

            // Migration 24: Blog indexes
            24 => "CREATE INDEX IF NOT EXISTS idx_blog_posts_slug ON blog_posts(slug);
                   CREATE INDEX IF NOT EXISTS idx_blog_posts_status ON blog_posts(status);
                   CREATE INDEX IF NOT EXISTS idx_blog_posts_category ON blog_posts(category_id);
                   CREATE INDEX IF NOT EXISTS idx_blog_posts_author ON blog_posts(author_id)",

            // Migration 25: Blog settings defaults
            25 => "INSERT OR IGNORE INTO settings (key, value) VALUES
                   ('blog_enabled', '0'),
                   ('blog_posts_per_page', '10'),
                   ('blog_title', 'Blog'),
                   ('blog_description', '')",

            // Migration 26: Store AI brief alongside plan in build_queue
            26 => "ALTER TABLE build_queue ADD COLUMN brief_json TEXT",

            // Migration 27: DB-backed PHP sessions (portable across all hosting environments)
            27 => "CREATE TABLE IF NOT EXISTS php_sessions (
                session_id TEXT PRIMARY KEY,
                data TEXT NOT NULL DEFAULT '',
                expires_at INTEGER NOT NULL
            );
            CREATE INDEX IF NOT EXISTS idx_php_sessions_expires ON php_sessions(expires_at)",

            // Migration 28: Markdown body storage for blog posts
            28 => "ALTER TABLE blog_posts ADD COLUMN body_markdown TEXT DEFAULT NULL",

            // Migration 29: AI request/response logs for debugging generation issues
            29 => "CREATE TABLE IF NOT EXISTS ai_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                stage INTEGER,
                page_slug TEXT,
                model TEXT,
                provider TEXT,
                prompt_length INTEGER,
                raw_response TEXT,
                parsed_ok INTEGER DEFAULT 0,
                duration_ms INTEGER,
                created_at TEXT DEFAULT (datetime('now'))
            )",

            // Migration 30: Add display name for users (shown as blog post author)
            30 => "ALTER TABLE users ADD COLUMN name TEXT DEFAULT NULL"
        ];

        foreach ($migrations as $version => $sql) {
            if ($version > $current) {
                // Handle multi-statement migrations
                $statements = array_filter(array_map('trim', explode(';', $sql)));
                foreach ($statements as $statement) {
                    if (!empty($statement)) {
                        self::$pdo->exec($statement);
                    }
                }
                self::$pdo->exec("INSERT INTO migrations (version) VALUES ($version)");
            }
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 3: SECURITY & AUTHENTICATION
// ─────────────────────────────────────────────────────────────────────────────

class DbSessionHandler implements SessionHandlerInterface {
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }

    public function read(string $id): string|false {
        $row = DB::fetch(
            "SELECT data FROM php_sessions WHERE session_id = ? AND expires_at > ?",
            [$id, time()]
        );
        return $row ? $row['data'] : '';
    }

    public function write(string $id, string $data): bool {
        $lifetime = (int) ini_get('session.gc_maxlifetime') ?: 7200;
        // INSERT OR REPLACE works on all SQLite versions (no 3.24+ UPSERT syntax needed)
        DB::execute(
            "INSERT OR REPLACE INTO php_sessions (session_id, data, expires_at) VALUES (?, ?, ?)",
            [$id, $data, time() + $lifetime]
        );
        return true;
    }

    public function destroy(string $id): bool {
        DB::execute("DELETE FROM php_sessions WHERE session_id = ?", [$id]);
        return true;
    }

    public function gc(int $max_lifetime): int|false {
        return DB::execute("DELETE FROM php_sessions WHERE expires_at <= ?", [time()]);
    }
}

class Session {
    private static bool $started = false;

    private static function registerDbHandler(): void {
        static $registered = false;
        if ($registered) return;
        $handler = new DbSessionHandler();
        session_set_save_handler($handler, true);
        $registered = true;
    }

    public static function start(): void {
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        self::registerDbHandler();

        global $isHttps;

        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_maxlifetime', '7200'); // 2 hours
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        self::$started = true;

        // Regenerate session ID periodically
        if (!isset($_SESSION['_created'])) {
            $_SESSION['_created'] = time();
        } elseif (time() - $_SESSION['_created'] > 1800) { // 30 minutes
            self::regenerate();
            $_SESSION['_created'] = time();
        }
    }

    public static function regenerate(): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(false); // false = keep old file briefly to avoid race conditions
        }
    }

    public static function get(string $key, $default = null) {
        self::start();
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, $value): void {
        self::start();
        $_SESSION[$key] = $value;
    }

    public static function delete(string $key): void {
        self::start();
        unset($_SESSION[$key]);
    }

    public static function destroy(): void {
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }
        session_destroy();
        self::$started = false;
    }

    public static function flash(string $type, string $message): void {
        self::start();
        $_SESSION['_flash'] = ['type' => $type, 'message' => $message];
    }

    public static function getFlash(): ?array {
        self::start();
        $flash = $_SESSION['_flash'] ?? null;
        unset($_SESSION['_flash']);
        return $flash;
    }
}

class CSRF {
    // Emit the full suite of cache-prevention headers. Why so many? - Cache-Control: no-store, private   → RFC 7234; all caches must not store - Pragma: no-cache                   → HTTP/1.0 proxies - Expires: 0                         → ancient proxy compat - Surrogate-Control: no-store        → Varnish / ESI-aware caches check this FIRST, before Cache-Control - Vary: Cookie                       → makes any remaining cache layer store a separate entry per Cookie header, so cookie-less users always get PHP
    private static function nocache(): void {
        if (headers_sent()) return;
        header('Cache-Control: no-store, no-cache, must-revalidate, private');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Surrogate-Control: no-store');
        header('Vary: Cookie');
    }

    public static function token(): string {
        self::nocache();

        // For authenticated requests, derive CSRF from the HttpOnly auth cookie.
        $authToken = $_COOKIE['cms_auth'] ?? null;
        if ($authToken) {
            return hash_hmac('sha256', 'csrf', $authToken);
        }

        // Unauthenticated pages (setup, login, MFA): use a dedicated first-party CSRF cookie (_c) that is independent of the PHP session cookie. This survives any caching layer that strips Set-Cookie on the session cookie, because: 1. _c is set via an explicit setcookie() call on every GET response. 2. The token stored in HTML = HMAC(_c value). 3. On POST: HMAC($_COOKIE['_c']) must equal $_POST['_csrf']. 4. Varnish cannot forge _c because SameSite=Strict prevents cross-site submission, and each user's browser holds a different random seed.
        global $isHttps;
        if (empty($_COOKIE['_c'])) {
            $seed = bin2hex(random_bytes(24));
            setcookie('_c', $seed, [
                'expires'  => 0,
                'path'     => '/',
                'secure'   => $isHttps,
                'httponly' => false,   // must be readable by the CSRF check on POST
                'samesite' => 'Strict',
            ]);
            $_COOKIE['_c'] = $seed;   // make it available in the same request
        }
        return hash_hmac('sha256', 'csrf_v1', $_COOKIE['_c']);
    }

    public static function verify(?string $token): bool {
        if (empty($token)) {
            return false;
        }
        return hash_equals(self::token(), $token);
    }

    public static function field(): string {
        return '<input type="hidden" name="_csrf" value="' . self::token() . '">';
    }

    public static function require(): void {
        $token = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (!self::verify($token)) {
            http_response_code(403);
            exit('CSRF token validation failed');
        }
    }
}

class Password {
    private const COST = 12;

    public static function hash(string $password): string {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => self::COST]);
    }

    public static function verify(string $password, string $hash): bool {
        return password_verify($password, $hash);
    }

    public static function needsRehash(string $hash): bool {
        return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => self::COST]);
    }

    public static function isStrong(string $password): array {
        $errors = [];

        if (strlen($password) < 12) {
            $errors[] = 'Password must be at least 12 characters';
        }
        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain at least one uppercase letter';
        }
        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must contain at least one lowercase letter';
        }
        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must contain at least one number';
        }

        return $errors;
    }
}

class RateLimit {
    public static function check(string $action, int $maxAttempts = 5, int $windowSeconds = 900): bool {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        // Clean old entries
        DB::execute("DELETE FROM rate_limits WHERE last_attempt < datetime('now', '-1 hour')");

        $row = DB::fetch(
            "SELECT attempts, last_attempt FROM rate_limits WHERE ip = ? AND action = ?",
            [$ip, $action]
        );

        if (!$row) {
            DB::execute(
                "INSERT INTO rate_limits (ip, action, attempts, last_attempt) VALUES (?, ?, 1, datetime('now'))",
                [$ip, $action]
            );
            return true;
        }

        $lastAttempt = strtotime($row['last_attempt']);
        if (time() - $lastAttempt > $windowSeconds) {
            // Reset after window
            DB::execute(
                "UPDATE rate_limits SET attempts = 1, last_attempt = datetime('now') WHERE ip = ? AND action = ?",
                [$ip, $action]
            );
            return true;
        }

        if ($row['attempts'] >= $maxAttempts) {
            return false; // Blocked
        }

        DB::execute(
            "UPDATE rate_limits SET attempts = attempts + 1, last_attempt = datetime('now') WHERE ip = ? AND action = ?",
            [$ip, $action]
        );
        return true;
    }

    public static function reset(string $action): void {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        DB::execute("DELETE FROM rate_limits WHERE ip = ? AND action = ?", [$ip, $action]);
    }
}

class Auth {
    private static function createSession(int $userId): string {
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $expires = date('Y-m-d H:i:s', time() + 7200);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

        // Single active session per user
        DB::execute("DELETE FROM sessions WHERE user_id = ?", [$userId]);
        DB::execute(
            "INSERT INTO sessions (user_id, token_hash, ip_address, user_agent, expires_at) VALUES (?, ?, ?, ?, ?)",
            [$userId, $hash, $ip, $ua, $expires]
        );

        return $token;
    }

    public static function user(): ?array {
        $token = $_COOKIE['cms_auth'] ?? null;
        if (!$token) {
            return null;
        }
        $hash = hash('sha256', $token);
        $row = DB::fetch(
            "SELECT * FROM sessions WHERE token_hash = ? AND expires_at > datetime('now')",
            [$hash]
        );
        if (!$row) {
            return null;
        }
        return DB::fetch("SELECT * FROM users WHERE id = ?", [$row['user_id']]);
    }

    public static function check(): bool {
        return self::user() !== null;
    }

    public static function id(): ?int {
        return self::user()['id'] ?? null;
    }

    public static function role(): ?string {
        $user = self::user();
        return $user['role'] ?? null;
    }

    public static function can(string $permission): bool {
        $permissions = [
            'admin' => ['*'],
            'editor' => ['content.view', 'content.edit', 'media.view', 'media.upload'],
            'viewer' => ['content.view', 'media.view']
        ];

        $role = self::role();
        if (!$role) {
            return false;
        }

        $allowed = $permissions[$role] ?? [];
        return in_array('*', $allowed, true) || in_array($permission, $allowed, true);
    }

    public static function require(string $permission = '*'): void {
        if (!self::check()) {
            Session::flash('error', 'Please log in to continue.');
            Response::redirect('/admin/login');
        }

        if (!self::can($permission)) {
            http_response_code(403);
            exit('Access Denied: Insufficient permissions');
        }
    }

    public static function attempt(string $email, string $password): bool|int {
        if (!RateLimit::check('login', 5, 900)) {
            return false;
        }

        $user = DB::fetch("SELECT * FROM users WHERE email = ?", [$email]);

        if (!$user || !Password::verify($password, $user['password_hash'])) {
            return false;
        }

        // Check if password needs rehash
        if (Password::needsRehash($user['password_hash'])) {
            DB::execute(
                "UPDATE users SET password_hash = ? WHERE id = ?",
                [Password::hash($password), $user['id']]
            );
        }

        return (int) $user['id'];
    }

    public static function login(int $userId): void {
        $token = self::createSession($userId);
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['SERVER_PORT'] ?? 80) == 443;
        setcookie('cms_auth', $token, [
            'expires'  => 0,
            'path'     => '/',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        RateLimit::reset('login');

        // Update last_login timestamp
        DB::execute("UPDATE users SET last_login = datetime('now') WHERE id = ?", [$userId]);
    }

    public static function logout(): void {
        $token = $_COOKIE['cms_auth'] ?? null;
        if ($token) {
            $hash = hash('sha256', $token);
            DB::execute("DELETE FROM sessions WHERE token_hash = ?", [$hash]);
        }
        setcookie('cms_auth', '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        Session::destroy();
    }
}

class MFA {
    public static function generateOTP(int $userId): string {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $hash = password_hash($code, PASSWORD_DEFAULT);
        $expires = date('Y-m-d H:i:s', time() + 600); // 10 minutes

        DB::execute(
            "UPDATE users SET mfa_code_hash = ?, mfa_expires = ? WHERE id = ?",
            [$hash, $expires, $userId]
        );

        return $code;
    }

    public static function verifyOTP(int $userId, string $code): bool {
        $user = DB::fetch(
            "SELECT mfa_code_hash, mfa_expires FROM users WHERE id = ?",
            [$userId]
        );

        if (!$user || !$user['mfa_code_hash'] || !$user['mfa_expires']) {
            return false;
        }

        if (strtotime($user['mfa_expires']) < time()) {
            return false; // Expired
        }

        if (password_verify($code, $user['mfa_code_hash'])) {
            // Clear MFA code after successful verification
            DB::execute(
                "UPDATE users SET mfa_code_hash = NULL, mfa_expires = NULL WHERE id = ?",
                [$userId]
            );
            return true;
        }

        return false;
    }

    public static function isRequired(): bool {
        return (bool) Settings::get('mfa_enabled', false);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 4: SETTINGS MANAGEMENT
// ─────────────────────────────────────────────────────────────────────────────

class Settings {
    private static array $cache = [];
    private static string $encryptionKey = '';

    public static function get(string $key, $default = null) {
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $row = DB::fetch("SELECT value, encrypted FROM settings WHERE key = ?", [$key]);

        if (!$row) {
            return $default;
        }

        $value = $row['encrypted'] ? self::decrypt($row['value']) : $row['value'];
        self::$cache[$key] = $value;

        return $value;
    }

    public static function set(string $key, string $value): void {
        DB::execute(
            "INSERT INTO settings (key, value, encrypted) VALUES (?, ?, 0)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, encrypted = 0",
            [$key, $value]
        );
        self::$cache[$key] = $value;
    }

    public static function setEncrypted(string $key, string $value): void {
        $encrypted = self::encrypt($value);
        DB::execute(
            "INSERT INTO settings (key, value, encrypted) VALUES (?, ?, 1)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, encrypted = 1",
            [$key, $encrypted]
        );
        self::$cache[$key] = $value;
    }

    public static function delete(string $key): void {
        DB::execute("DELETE FROM settings WHERE key = ?", [$key]);
        unset(self::$cache[$key]);
    }

    private static function getEncryptionKey(): string {
        if (empty(self::$encryptionKey)) {
            $storedKey = DB::fetch("SELECT value FROM settings WHERE key = '_encryption_key'");
            if (!$storedKey) {
                $rawKey = bin2hex(random_bytes(32));
                DB::execute(
                    "INSERT INTO settings (key, value, encrypted) VALUES ('_encryption_key', ?, 0)",
                    [$rawKey]
                );
            } else {
                $rawKey = $storedKey['value'];
            }
            // Derive the actual AES key by HMACing the stored random key with the server deployment path. Stealing the database alone is not sufficient to decrypt sensitive settings — the attacker also needs the server filesystem location.
            self::$encryptionKey = hash_hmac('sha256', $rawKey, MONOLITHCMS_ROOT);
        }
        return self::$encryptionKey;
    }

    private static function encrypt(string $value): string {
        $key = hex2bin(self::getEncryptionKey());
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($value, 'AES-256-CBC', $key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }

    private static function decrypt(string $value): string {
        $key = hex2bin(self::getEncryptionKey());
        $data = base64_decode($value);
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        return openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 5: INPUT SANITIZATION
// ─────────────────────────────────────────────────────────────────────────────

class Sanitize {
    public static function html(string $input): string {
        return htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
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
        $allowed = '<p><br><strong><em><u><a><ul><ol><li><h1><h2><h3><h4><h5><h6><blockquote><pre><code><img>';
        $clean = strip_tags($html, $allowed);

        // Sanitize URLs in links and images
        $clean = preg_replace_callback(
            '/<(a|img)\s+([^>]*)(href|src)=["\']([^"\']+)["\']/',
            function ($m) {
                $url = filter_var($m[4], FILTER_SANITIZE_URL);
                if (!preg_match('/^(https?:\/\/|\/|#)/', $url)) {
                    $url = '#'; // Block potentially dangerous URLs
                }
                return '<' . $m[1] . ' ' . $m[2] . $m[3] . '="' . self::html($url) . '"';
            },
            $clean
        );

        return $clean;
    }

    public static function email(string $input): string {
        return filter_var($input, FILTER_SANITIZE_EMAIL);
    }

    public static function int($input): int {
        return (int) filter_var($input, FILTER_SANITIZE_NUMBER_INT);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 6: REQUEST & RESPONSE HELPERS
// ─────────────────────────────────────────────────────────────────────────────

class Request {
    public static function method(): string {
        return $_SERVER['REQUEST_METHOD'] ?? 'GET';
    }

    public static function isPost(): bool {
        return self::method() === 'POST';
    }

    public static function isAjax(): bool {
        return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    }

    public static function uri(): string {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        return rtrim($uri, '/') ?: '/';
    }

    public static function input(string $key, $default = null) {
        return $_POST[$key] ?? $_GET[$key] ?? $default;
    }

    public static function query(string $key, $default = null) {
        return $_GET[$key] ?? $default;
    }

    public static function all(): array {
        return array_merge($_GET, $_POST);
    }

    public static function json(): array {
        $input = file_get_contents('php://input');
        return json_decode($input, true) ?? [];
    }

    public static function file(string $key): ?array {
        return $_FILES[$key] ?? null;
    }

    public static function ip(): string {
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
}

class Response {
    public static function json(array $data, int $code = 200): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        // Discard any PHP notices/warnings buffered before this call
        while (ob_get_level() > 0) { ob_end_clean(); }
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    public static function redirect(string $url, int $code = 302): void {
        // Ensure session is written before we exit, especially after login/auth state changes
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        http_response_code($code);
        header('Location: ' . $url);
        exit;
    }

    public static function html(string $content, int $code = 200): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        http_response_code($code);
        header('Content-Type: text/html; charset=UTF-8');
        echo $content;
        exit;
    }

    public static function notFound(string $message = 'Not Found'): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        http_response_code(404);
        header('Content-Type: text/html; charset=UTF-8');
        echo Template::render('error', ['code' => 404, 'message' => $message]);
        exit;
    }

    public static function error(string $message = 'Server Error', int $code = 500): void {
        http_response_code($code);
        header('Content-Type: text/html; charset=UTF-8');
        echo Template::render('error', ['code' => $code, 'message' => $message]);
        exit;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 7: ROUTING
// ─────────────────────────────────────────────────────────────────────────────

class Router {
    private static array $routes = [];
    private static array $middlewares = [];

    public static function get(string $pattern, callable|string $handler): void {
        self::$routes['GET'][$pattern] = $handler;
    }

    public static function post(string $pattern, callable|string $handler): void {
        self::$routes['POST'][$pattern] = $handler;
    }

    public static function any(string $pattern, callable|string $handler): void {
        self::$routes['GET'][$pattern] = $handler;
        self::$routes['POST'][$pattern] = $handler;
    }

    public static function middleware(string $pattern, callable $middleware): void {
        self::$middlewares[$pattern] = $middleware;
    }

    public static function dispatch(): void {
        $method = Request::method();
        $uri = Request::uri();

        // Treat HEAD requests as GET
        if ($method === 'HEAD') {
            $method = 'GET';
        }

        // Run middlewares
        foreach (self::$middlewares as $pattern => $middleware) {
            if (self::matchPattern($pattern, $uri)) {
                $middleware();
            }
        }

        // Find matching route
        foreach (self::$routes[$method] ?? [] as $pattern => $handler) {
            $params = self::matchPattern($pattern, $uri);
            if ($params !== false) {
                self::executeHandler($handler, $params);
                return;
            }
        }

        // No route found - try dynamic page lookup
        $slug = ltrim($uri, '/') ?: 'home';
        $page = DB::fetch(
            "SELECT * FROM pages WHERE slug = ? AND status = 'published'",
            [$slug]
        );

        if ($page) {
            PageController::show($page);
            return;
        }

        Response::notFound();
    }

    private static function matchPattern(string $pattern, string $uri): array|false {
        // Exact match
        if ($pattern === $uri) {
            return [];
        }

        // Pattern with parameters
        $regex = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $pattern);
        $regex = '#^' . $regex . '$#';

        if (preg_match($regex, $uri, $matches)) {
            return array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
        }

        return false;
    }

    private static function executeHandler(callable|string $handler, array $params): void {
        if (is_string($handler) && str_contains($handler, '::')) {
            [$class, $method] = explode('::', $handler);
            $handler = [$class, $method];
        }

        call_user_func_array($handler, $params);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 8: TEMPLATING ENGINE
// ─────────────────────────────────────────────────────────────────────────────

class Template {
    private static array $data = [];

    public static function render(string $template, array $data = []): string {
        $data['csrf_field'] = CSRF::field();
        $data['csrf_token'] = CSRF::token();
        $data['csp_nonce'] = defined('CSP_NONCE') ? CSP_NONCE : '';
        $data['app_version'] = MONOLITHCMS_VERSION;
        $data['site_name'] = Settings::get('site_name', 'MonolithCMS');
        $data['flash'] = Session::getFlash();
        $data['css_hash'] = Cache::getCSSHash();
        if (!isset($data['media_picker_html'])) {
            $data['media_picker_html'] = EditorAssets::mediaPickerHtml();
        }

        // Check if user can edit (for visual editor)
        $data['edit_mode'] = Auth::can('content.edit') && isset($_GET['edit']);

        self::$data = $data;

        $html = self::getTemplate($template);

        // Process includes: {{> partial_name}}
        $html = preg_replace_callback('/\{\{>\s*(\w+)\s*\}\}/', function ($m) {
            return self::partial($m[1]);
        }, $html);

        // Process #each loops FIRST (before #if) to handle nested conditionals properly
        $html = self::processEachBlocks($html);

        // Process conditionals: {{#if var}}...{{else}}...{{/if}}
        $html = self::processIfBlocks($html);

        // Process raw output: {{{variable}}} (unescaped)
        $html = preg_replace_callback('/\{\{\{\s*([\w.]+)\s*\}\}\}/', function ($m) {
            $v = self::getValue($m[1]);
            if (!is_scalar($v) && $v !== null && MONOLITHCMS_DEV) {
                error_log('Template: {{{' . $m[1] . '}}} resolved to non-scalar (' . gettype($v) . ')');
            }
            return is_scalar($v) ? (string) $v : '';
        }, $html);

        // Process variables: {{variable}} (escaped) - supports dot notation like {{flash.message}}
        $html = preg_replace_callback('/\{\{\s*([\w.]+)\s*\}\}/', function ($m) {
            $value = self::getValue($m[1]);
            if (!is_scalar($value) && $value !== null && MONOLITHCMS_DEV) {
                error_log('Template: {{' . $m[1] . '}} resolved to non-scalar (' . gettype($value) . ')');
            }
            return is_scalar($value) ? Sanitize::html((string) $value) : '';
        }, $html);

        return $html;
    }

    private static function getValue(string $key) {
        // Support dot notation for nested values (e.g., flash.message)
        if (str_contains($key, '.')) {
            $parts = explode('.', $key);
            $value = self::$data;
            foreach ($parts as $part) {
                if (!is_array($value) || !isset($value[$part])) {
                    return null;
                }
                $value = $value[$part];
            }
            return $value;
        }
        return self::$data[$key] ?? null;
    }

    // Resolve a dot-notation key from a context array
    private static function resolveFromContext(array $context, string $key) {
        if (str_contains($key, '.')) {
            $parts = explode('.', $key);
            $value = $context;
            foreach ($parts as $part) {
                if (!is_array($value) || !isset($value[$part])) {
                    // Try from main data if not in context
                    return null;
                }
                $value = $value[$part];
            }
            return $value;
        }
        return $context[$key] ?? null;
    }

    // Process {{#each items}}...{{/each}} blocks with proper nesting support
    private static function processEachBlocks(string $html, ?array $context = null): string {
        // Find each blocks with balanced tag matching (supports dot notation like plan.nav)
        $pattern = '/\{\{#each\s+([\w.]+)\}\}/';

        while (preg_match($pattern, $html, $match, PREG_OFFSET_CAPTURE)) {
            $startTag = $match[0][0];
            $varName = $match[1][0];
            $startPos = $match[0][1];
            $contentStart = $startPos + strlen($startTag);

            // Find matching {{/each}} accounting for nesting
            $depth = 1;
            $pos = $contentStart;
            $len = strlen($html);

            while ($depth > 0 && $pos < $len) {
                $nextOpen = strpos($html, '{{#each', $pos);
                $nextClose = strpos($html, '{{/each}}', $pos);

                if ($nextClose === false) break;

                if ($nextOpen !== false && $nextOpen < $nextClose) {
                    $depth++;
                    $pos = $nextOpen + 7;
                } else {
                    $depth--;
                    if ($depth === 0) {
                        $contentEnd = $nextClose;
                    }
                    $pos = $nextClose + 9;
                }
            }

            if (!isset($contentEnd)) break;

            $content = substr($html, $contentStart, $contentEnd - $contentStart);
            $fullBlock = substr($html, $startPos, ($contentEnd + 9) - $startPos);

            // Get items from context or main data (supports dot notation)
            if ($context !== null) {
                $items = self::resolveFromContext($context, $varName) ?? self::getValue($varName);
            } else {
                $items = self::getValue($varName);
            }

            if (!is_array($items) || empty($items)) {
                $html = str_replace($fullBlock, '', $html);
                continue;
            }

            // Process each item
            $output = '';
            foreach ($items as $index => $item) {
                $inner = $content;

                if (is_array($item)) {
                    // Process nested #each blocks first
                    $inner = self::processEachBlocks($inner, $item);

                    // Process nested conditionals with item values
                    $inner = self::processIfBlocksWithContext($inner, $item);

                    // Substitute variables — triple braces FIRST (raw), then double (escaped) because '{{key}}' is a substring of '{{{key}}}' and would fire early otherwise
                    foreach ($item as $key => $val) {
                        if (is_scalar($val)) {
                            $inner = str_replace('{{{' . $key . '}}}', (string) $val, $inner);
                            $inner = str_replace('{{' . $key . '}}', Sanitize::html((string) $val), $inner);
                        }
                    }
                } else {
                    $inner = str_replace('{{{this}}}', (string) $item, $inner);
                    $inner = str_replace('{{this}}', Sanitize::html((string) $item), $inner);
                }

                $inner = str_replace('{{@index}}', (string) $index, $inner);
                $inner = str_replace('{{@key}}', (string) $index, $inner);
                $output .= $inner;
            }

            $html = substr($html, 0, $startPos) . $output . substr($html, $contentEnd + 9);
        }

        return $html;
    }

    // Process {{#if var}}...{{else}}...{{/if}} blocks with proper nesting support
    private static function processIfBlocks(string $html): string {
        return self::processIfBlocksWithContext($html, null);
    }

    private static function processIfBlocksWithContext(string $html, ?array $context): string {
        // Supports dot notation like plan.colors
        $pattern = '/\{\{#if\s+([\w.]+)\}\}/';

        while (preg_match($pattern, $html, $match, PREG_OFFSET_CAPTURE)) {
            $startTag = $match[0][0];
            $varName = $match[1][0];
            $startPos = $match[0][1];
            $contentStart = $startPos + strlen($startTag);

            // Find matching {{/if}} accounting for nesting
            $depth = 1;
            $pos = $contentStart;
            $len = strlen($html);
            $elsePos = null;
            $contentEnd = null;

            while ($depth > 0 && $pos < $len) {
                $nextOpen = strpos($html, '{{#if', $pos);
                $nextElse = ($depth === 1) ? strpos($html, '{{else}}', $pos) : false;
                $nextClose = strpos($html, '{{/if}}', $pos);

                if ($nextClose === false) break;

                // Find the earliest tag
                $positions = array_filter([
                    'open' => $nextOpen,
                    'else' => $nextElse,
                    'close' => $nextClose
                ], fn($p) => $p !== false);

                if (empty($positions)) break;

                $earliest = min($positions);
                $type = array_search($earliest, $positions);

                if ($type === 'open') {
                    $depth++;
                    $pos = $nextOpen + 5;
                } elseif ($type === 'else' && $depth === 1) {
                    $elsePos = $nextElse;
                    $pos = $nextElse + 8;
                } else {
                    $depth--;
                    if ($depth === 0) {
                        $contentEnd = $nextClose;
                    }
                    $pos = $nextClose + 7;
                }
            }

            if ($contentEnd === null) break;

            // Extract if and else content
            if ($elsePos !== null) {
                $ifContent = substr($html, $contentStart, $elsePos - $contentStart);
                $elseContent = substr($html, $elsePos + 8, $contentEnd - ($elsePos + 8));
            } else {
                $ifContent = substr($html, $contentStart, $contentEnd - $contentStart);
                $elseContent = '';
            }

            $fullBlock = substr($html, $startPos, ($contentEnd + 7) - $startPos);

            // Evaluate condition (supports dot notation like plan.colors)
            if ($context !== null) {
                $value = self::resolveFromContext($context, $varName) ?? self::getValue($varName);
            } else {
                $value = self::getValue($varName);
            }
            $result = !empty($value) ? $ifContent : $elseContent;

            $html = substr($html, 0, $startPos) . $result . substr($html, $contentEnd + 7);
        }

        return $html;
    }

    public static function partial(string $name): string {
        // Check partial cache first
        $cacheFile = MONOLITHCMS_CACHE . '/partials/' . $name . '.html';

        // Use cached version if fresh (1 hour TTL) — skip in dev mode
        if (!MONOLITHCMS_DEV && file_exists($cacheFile) && filemtime($cacheFile) > time() - 3600) {
            $html = file_get_contents($cacheFile);
            // Replace CSP nonce placeholder with current request's nonce
            return str_replace('{{CSP_NONCE_PLACEHOLDER}}', CSP_NONCE, $html);
        }

        // Render partial
        $html = match ($name) {
            'header' => self::renderHeader(),
            'footer' => self::renderFooter(),
            'nav' => self::renderNav(),
            default => self::getTemplate('_' . $name)
        };

        // Cache it with placeholder nonce (skip write in dev mode)
        if (!MONOLITHCMS_DEV) {
            $cacheHtml = str_replace(CSP_NONCE, '{{CSP_NONCE_PLACEHOLDER}}', $html);
            file_put_contents($cacheFile, $cacheHtml);
        }
        return $html;
    }

    private static function renderHeader(): string {
        $header = DB::fetch("SELECT * FROM theme_header LIMIT 1") ?? [];

        // Get logo URL - from asset or settings
        if (!empty($header['logo_asset_id'])) {
            $asset = DB::fetch("SELECT hash FROM assets WHERE id = ?", [$header['logo_asset_id']]);
            $header['logo_url'] = $asset ? '/assets/' . $asset['hash'] : '';
        } else {
            $header['logo_url'] = Settings::get('logo_url', '');
        }

        // Include site name for display
        $header['site_name'] = Settings::get('site_name', '');

        return self::renderPartialTemplate('_header', $header);
    }

    private static function renderNav(): string {
        $items = DB::fetchAll(
            "SELECT * FROM nav WHERE visible = 1 ORDER BY sort_order ASC"
        );
        $siteName = Settings::get('site_name', 'MonolithCMS');
        return self::renderPartialTemplate('_nav', [
            'items'    => $items,
            'site_name' => $siteName,
        ]);
    }

    private static function renderFooter(): string {
        $footer = DB::fetch("SELECT * FROM theme_footer LIMIT 1") ?? [];
        $footer['site_name'] = Settings::get('site_name', 'MonolithCMS');
        $footer['year'] = date('Y');
        $footer['text'] = str_replace('{{year}}', date('Y'), $footer['text'] ?? '');
        $footer['links'] = json_decode($footer['links_json'] ?? '[]', true) ?: [];
        $footer['social'] = json_decode($footer['social_json'] ?? '[]', true) ?: [];
        return self::renderPartialTemplate('_footer', $footer);
    }

    // Render a partial template without overwriting parent data
    private static function renderPartialTemplate(string $template, array $data): string {
        $savedData = self::$data;
        $result = self::render($template, $data);
        self::$data = $savedData;
        return $result;
    }

    private static function getTemplate(string $name): string {
        return Templates::get($name);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 9: TEMPLATE DEFINITIONS
// ─────────────────────────────────────────────────────────────────────────────

class Templates {
    public static function get(string $name): string {
        static $cache = null;
        if ($cache === null) {
            $cache = array_merge(
                _tpl_public(),
                _tpl_auth_setup(),
                _tpl_pages(),
                _tpl_content(),
                _tpl_ai(),
                _tpl_blog()
            );
        }
        return $cache[$name] ?? '';
    }
}

function _tpl_public(): array {
    return [
        // ─── HEADER PARTIAL ──────────────────────────────────────────
        '_header' => <<<'HTML'
HTML,

        // ─── NAV PARTIAL ─────────────────────────────────────────────
        '_nav' => <<<'HTML'
<style nonce="{{csp_nonce}}">
#site-nav{background:var(--color-header-bg);border-bottom:1px solid var(--color-border);position:sticky;top:0;z-index:100;}
#site-nav .nav-inner{max-width:1200px;margin:0 auto;padding:0 1.5rem;display:flex;align-items:center;justify-content:space-between;height:64px;}
#site-nav .nav-brand{color:var(--color-header-text);font-weight:700;font-size:1.1rem;text-decoration:none;letter-spacing:-.01em;}
#site-nav .nav-links{display:flex;align-items:center;gap:0.25rem;}
#site-nav .nav-links a{color:var(--color-header-text);text-decoration:none;padding:0.45rem 0.85rem;border-radius:6px;font-size:0.9rem;font-weight:500;opacity:.85;transition:opacity .15s,background .15s;}
#site-nav .nav-links a:hover{opacity:1;background:rgba(128,128,128,0.1);}
#site-nav .nav-toggle{display:none;background:none;border:none;cursor:pointer;padding:0.5rem;flex-direction:column;gap:5px;}
#site-nav .nav-toggle span{display:block;width:22px;height:2px;background:var(--color-header-text);border-radius:2px;}
@media(max-width:767px){
  #site-nav .nav-toggle{display:flex;}
  #site-nav .nav-links{display:none;flex-direction:column;align-items:flex-start;position:absolute;top:64px;left:0;right:0;background:var(--color-header-bg);border-bottom:1px solid var(--color-border);padding:0.75rem 1.5rem 1rem;}
  #site-nav .nav-links.open{display:flex;}
}
</style>
<nav id="site-nav" role="navigation" aria-label="main navigation">
  <div class="nav-inner">
    <a class="nav-brand" href="/">{{site_name}}</a>
    <button type="button" class="nav-toggle" aria-label="Toggle menu" aria-expanded="false" aria-controls="nav-links">
      <span></span><span></span><span></span>
    </button>
    <div class="nav-links" id="nav-links">
      {{#each items}}
      <a href="{{url}}">{{label}}</a>
      {{/each}}
    </div>
  </div>
</nav>
<script nonce="{{csp_nonce}}">(function(){var t=document.querySelector('#site-nav .nav-toggle'),m=document.getElementById('nav-links');if(!t||!m)return;t.addEventListener('click',function(){var open=m.classList.toggle('open');t.setAttribute('aria-expanded',String(open));});})();</script>
HTML,

        // ─── FOOTER PARTIAL ──────────────────────────────────────────
        '_footer' => <<<'HTML'
<footer style="background:var(--color-footer-bg);border-top:1px solid var(--color-border);padding:3rem 1.5rem 2rem;">
  <div style="max-width:1200px;margin:0 auto;display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:1.5rem 3rem;">
    <div>
      <strong style="color:var(--color-footer-text);font-size:0.95rem;display:block;margin-bottom:0.35rem;">{{site_name}}</strong>
      <p style="color:var(--color-footer-text);opacity:0.55;font-size:0.82rem;margin:0;">{{text}}</p>
    </div>
    {{#if links}}
    <nav style="display:flex;flex-wrap:wrap;gap:0.25rem 1.5rem;align-items:center;">
      {{#each links}}
      <a href="{{url}}" style="color:var(--color-footer-text);opacity:0.6;font-size:0.85rem;text-decoration:none;">{{label}}</a>
      {{/each}}
    </nav>
    {{/if}}
  </div>
  <div style="max-width:1200px;margin:1.5rem auto 0;border-top:1px solid var(--color-border);padding-top:1rem;text-align:center;">
    <a href="https://monolithcms.com/" style="color:var(--color-footer-text);opacity:0.3;font-size:0.75rem;text-decoration:none;" target="_blank" rel="noopener">Powered by MonolithCMS</a>
  </div>
</footer>
HTML,

        // ─── ERROR PAGE ──────────────────────────────────────────────
        'error' => <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error {{code}}</title>
    <style>
        body { font-family: system-ui, sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; background: #f3f4f6; }
        .error-box { text-align: center; padding: 2rem; }
        h1 { font-size: 4rem; color: #ef4444; margin: 0; }
        p { color: #6b7280; }
        a { color: #3b82f6; }
    </style>
</head>
<body>
    <div class="error-box">
        <h1>{{code}}</h1>
        <p>{{message}}</p>
        <p><a href="/">← Go Home</a></p>
    </div>
</body>
</html>
HTML,

        // ─── PAGE TEMPLATE ───────────────────────────────────────────
        'page' => <<<'HTML'
<!DOCTYPE html>
<html lang="en" data-theme="{{ui_theme}}" data-light-theme="{{ui_theme}}"{{#if edit_mode}} data-edit-mode="true"{{/if}}>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{doc_title}}</title>
    <meta name="description" content="{{meta_description}}">
    <meta property="og:title" content="{{title}}">
    <meta property="og:description" content="{{meta_description}}">
    {{#if og_image}}<meta property="og:image" content="{{og_image}}">{{/if}}
    <link rel="canonical" href="{{canonical_url}}">
    {{{google_fonts_link}}}
    <link rel="stylesheet" href="/cdn/bulma.min.css">
    <link rel="stylesheet" href="/cdn/material-icons.css">
    <link rel="stylesheet" href="/css?v={{css_hash}}">{{#if google_fonts_css}}
    <style>{{{google_fonts_css}}}</style>{{/if}}
    {{#if edit_mode}}<link rel="stylesheet" href="/css/editor">{{/if}}
    {{{page_custom_css}}}
    {{{head_scripts}}}
    <style>
        /* Transparent nav floats over the page (position:absolute). Push the
           first section's content down so it clears the nav height (~3.25rem). */
        body:has(#cms-nav-wrapper[style*="position:absolute"]) main > *:first-child {
            padding-top: 4.5rem !important;
        }
    </style>
</head>
<body>
    <!--MONOLITHCMS_ADMIN_BAR-->
    {{#if edit_mode}}<input type="hidden" id="monolithcms-csrf" value="{{csrf_token}}">{{/if}}
    {{#if edit_mode}}<div class="monolithcms-section-wrapper" data-section="header"><button class="section-edit-btn" data-action="edit-header">✎ Edit Header</button>{{/if}}
    {{> header}}
    {{#if edit_mode}}</div>{{/if}}
    {{#if show_nav}}
    {{#if edit_mode}}<div class="monolithcms-section-wrapper" data-section="nav"><button class="section-edit-btn" data-action="edit-nav">✎ Edit Navigation</button>{{/if}}
    <div id="cms-nav-wrapper" style="{{nav_wrapper_style}}">{{> nav}}</div>
    {{#if edit_mode}}</div>{{/if}}
    {{/if}}
    <main data-page-id="{{id}}">
        {{#if flash}}<div class="container"><div class="notification is-info">{{flash.message}}</div></div>{{/if}}
        {{#if show_page_title}}<div class="container" style="padding:2rem 1.5rem 0.5rem"><h1 class="title is-2">{{title}}</h1></div>{{/if}}
        {{{blocks_html}}}
    </main>
    {{#if show_footer}}
    {{#if edit_mode}}<div class="monolithcms-section-wrapper" data-section="footer"><button class="section-edit-btn" data-action="edit-footer">✎ Edit Footer</button>{{/if}}
    {{> footer}}
    {{#if edit_mode}}</div>{{/if}}
    {{/if}}
    {{#if edit_mode}}<script src="/js/editor" nonce="{{csp_nonce}}"></script>{{/if}}
    {{#if edit_mode}}<script src="/js/admin-global" nonce="{{csp_nonce}}"></script>{{/if}}
    {{#if edit_mode}}{{{media_picker_html}}}{{/if}}
    <script src="/js/reveal" nonce="{{csp_nonce}}"></script>
    {{{footer_scripts}}}
</body>
</html>
HTML,

    ];
}
// ─────────────────────────────────────────────────────────────────────────────
// SECTION 10: AUTH AND SETUP TEMPLATES
// ─────────────────────────────────────────────────────────────────────────────

function _tpl_auth_setup(): array {
    return [

        // ─── ADMIN LAYOUT ────────────────────────────────────────────
        'admin_layout' => <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{title}} | MonolithCMS Admin</title>
    <meta name="csrf-token" content="{{csrf_token}}">
    <script nonce="{{csp_nonce}}">
        // Check local storage or system preference
        if (localStorage.getItem('color-theme') === 'dark' || (!('color-theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        document.documentElement.classList.add('dark');
        } else {
        document.documentElement.classList.remove('dark');
        }
    </script>
    <script src="/cdn/tailwind-forms.min.js"></script>
    <link href="/cdn/inter-font.css" rel="stylesheet">
    <link href="/cdn/material-icons.css" rel="stylesheet">
    <script nonce="{{csp_nonce}}">
        tailwind.config = {
        darkMode: "class",
        theme: {
            extend: {
                colors: {
                    "primary": "#135bec",
                    "background-light": "#f6f6f8",
                    "background-dark": "#101622",
                },
                fontFamily: {
                    "display": ["Inter", "system-ui", "sans-serif"]
                },
                borderRadius: {"DEFAULT": "0.25rem", "lg": "0.5rem", "xl": "0.75rem", "full": "9999px"},
            },
        },
        }
    </script>
    <link href="/css/admin" rel="stylesheet">
</head>
<body class="bg-background-light dark:bg-[#0f172a] font-display text-slate-900 dark:text-slate-100 transition-colors duration-200">
<!-- Page transition overlay -->
<div id="page-overlay" style="position:fixed;inset:0;z-index:9999;background:var(--overlay-bg,#f6f6f8);display:flex;align-items:center;justify-content:center;transition:opacity .25s ease">
  <div style="display:flex;flex-direction:column;align-items:center;gap:1rem">
    <div style="width:2.25rem;height:2.25rem;border:3px solid #e2e8f0;border-top-color:#135bec;border-radius:50%;animation:spin .7s linear infinite"></div>
    <span style="font-size:.8125rem;font-weight:600;color:#64748b;letter-spacing:.05em">Loading…</span>
  </div>
</div>
<style>@keyframes spin{to{transform:rotate(360deg)}}html.dark #page-overlay{--overlay-bg:#0f172a}</style>
<script nonce="{{csp_nonce}}">
(function(){
  // Hide overlay once Material Symbols font is ready
  var overlay = document.getElementById('page-overlay');
  function hide(){overlay.style.opacity='0';setTimeout(function(){overlay.style.display='none';},260);}
  document.fonts.ready.then(hide);
  // Safety: always hide after 1.5s even if fonts.ready never resolves
  setTimeout(hide, 1500);
  // Re-show overlay on navigation
  document.addEventListener('click', function(e){
    var a = e.target.closest('a[href]');
    if (!a) return;
    var href = a.getAttribute('href');
    if (!href || href.startsWith('#') || href.startsWith('javascript') || a.target === '_blank') return;
    if (a.closest('form')) return;
    overlay.style.display='flex';
    overlay.style.opacity='1';
  });
  document.addEventListener('submit', function(){
    overlay.style.display='flex';
    overlay.style.opacity='1';
  });
})();
</script>
<div class="flex h-screen overflow-hidden">
    <!-- Sidebar -->
    <aside class="w-64 bg-white dark:bg-[#1e293b] border-r border-slate-200 dark:border-slate-700 flex flex-col transition-colors duration-200">
        <div class="p-6 flex items-center gap-3">
        <div class="bg-primary size-10 rounded-lg flex items-center justify-center text-white">
            <span class="material-symbols-outlined">mms</span>
        </div>
        <div class="flex flex-col">
            <h1 class="text-slate-900 text-base font-bold leading-none">MonolithCMS</h1>
            <p class="text-slate-500 text-xs mt-1">Admin Portal &middot; v{{app_version}}</p>
        </div>
        </div>
        <nav class="flex-1 px-4 space-y-1">
        <a class="flex items-center gap-3 px-3 py-2.5 rounded-lg {{#if is_dashboard}}bg-primary/10 text-primary font-medium{{else}}text-slate-600 hover:bg-slate-50{{/if}} transition-colors" href="/admin">
            <span class="material-symbols-outlined text-[22px]">dashboard</span>
            <span class="text-sm">Dashboard</span>
        </a>
        <a class="flex items-center gap-3 px-3 py-2.5 rounded-lg {{#if is_pages}}bg-primary/10 text-primary font-medium{{else}}text-slate-600 hover:bg-slate-50{{/if}} transition-colors" href="/admin/pages">
            <span class="material-symbols-outlined text-[22px]">description</span>
            <span class="text-sm">Pages</span>
        </a>
        <a class="flex items-center gap-3 px-3 py-2.5 rounded-lg {{#if is_nav}}bg-primary/10 text-primary font-medium{{else}}text-slate-600 hover:bg-slate-50{{/if}} transition-colors" href="/admin/nav">
            <span class="material-symbols-outlined text-[22px]">menu</span>
            <span class="text-sm">Navigation</span>
        </a>
        <a class="flex items-center gap-3 px-3 py-2.5 rounded-lg {{#if is_media}}bg-primary/10 text-primary font-medium{{else}}text-slate-600 hover:bg-slate-50{{/if}} transition-colors" href="/admin/media">
            <span class="material-symbols-outlined text-[22px]">image</span>
            <span class="text-sm">Media</span>
        </a>
        <a class="flex items-center gap-3 px-3 py-2.5 rounded-lg {{#if is_theme}}bg-primary/10 text-primary font-medium{{else}}text-slate-600 hover:bg-slate-50{{/if}} transition-colors" href="/admin/theme">
            <span class="material-symbols-outlined text-[22px]">palette</span>
            <span class="text-sm">Theme</span>
        </a>
        <a class="flex items-center gap-3 px-3 py-2.5 rounded-lg {{#if is_users}}bg-primary/10 text-primary font-medium{{else}}text-slate-600 hover:bg-slate-50{{/if}} transition-colors" href="/admin/users">
            <span class="material-symbols-outlined text-[22px]">group</span>
            <span class="text-sm">Users</span>
        </a>
        <a class="flex items-center gap-3 px-3 py-2.5 rounded-lg {{#if is_settings}}bg-primary/10 text-primary font-medium{{else}}text-slate-600 hover:bg-slate-50{{/if}} transition-colors" href="/admin/settings">
            <span class="material-symbols-outlined text-[22px]">tune</span>
            <span class="text-sm">Settings</span>
        </a>
        <div class="pt-4 pb-2">
            <p class="px-3 text-[10px] font-bold text-slate-400 uppercase tracking-widest">AI Tools</p>
        </div>
        <a class="flex items-center gap-3 px-3 py-2.5 rounded-lg {{#if is_ai}}bg-primary/10 text-primary font-medium{{else}}text-slate-600 hover:bg-slate-50{{/if}} transition-colors" href="/admin/ai">
            <span class="material-symbols-outlined text-[22px]">auto_awesome</span>
            <span class="text-sm">AI Generate</span>
        </a>
        <a class="flex items-center gap-3 px-3 py-2.5 rounded-lg {{#if is_ai_chat}}bg-primary/10 text-primary font-medium{{else}}text-slate-600 hover:bg-slate-50{{/if}} transition-colors" href="/admin/ai/chat">
            <span class="material-symbols-outlined text-[22px]">forum</span>
            <span class="text-sm">AI Chat</span>
        </a>
        <a class="flex items-center gap-3 px-3 py-2.5 rounded-lg {{#if is_approvals}}bg-primary/10 text-primary font-medium{{else}}text-slate-600 hover:bg-slate-50{{/if}} transition-colors" href="/admin/approvals">
            <span class="material-symbols-outlined text-[22px]">pending_actions</span>
            <span class="text-sm">Approvals</span>
        </a>
        {{#if blog_enabled}}
        <div class="pt-4 pb-2">
            <p class="px-3 text-[10px] font-bold text-slate-400 uppercase tracking-widest">Blog</p>
        </div>
        <a class="flex items-center gap-3 px-3 py-2.5 rounded-lg {{#if is_blog}}bg-primary/10 text-primary font-medium{{else}}text-slate-600 hover:bg-slate-50{{/if}} transition-colors" href="/admin/blog/posts">
            <span class="material-symbols-outlined text-[22px]">article</span>
            <span class="text-sm">Posts</span>
        </a>
        <a class="flex items-center gap-3 px-3 py-2.5 rounded-lg {{#if is_blog_cats}}bg-primary/10 text-primary font-medium{{else}}text-slate-600 hover:bg-slate-50{{/if}} transition-colors" href="/admin/blog/categories">
            <span class="material-symbols-outlined text-[22px]">folder</span>
            <span class="text-sm">Categories</span>
        </a>
        {{/if}}
        </nav>
        <div class="p-4 border-t border-slate-200 dark:border-slate-700">
        <div class="flex items-center gap-3 p-2">
            <div class="size-8 rounded-full bg-primary/20 flex items-center justify-center">
                <span class="material-symbols-outlined text-primary text-[18px]">person</span>
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-semibold text-slate-900 dark:text-white truncate">{{user_email}}</p>
                <p class="text-xs text-slate-500 truncate">{{user_role}}</p>
            </div>
        </div>
        <form method="post" action="/admin/logout">
            {{{csrf_field}}}
            <button type="submit" class="mt-2 w-full flex items-center justify-center gap-2 border border-red-200 text-red-600 px-4 py-2 rounded-lg text-sm font-semibold whitespace-nowrap hover:bg-red-50 transition-colors">
                <span class="material-symbols-outlined text-[18px]">logout</span>
                <span>Logout</span>
            </button>
        </form>
        </div>
    </aside>
    <!-- Main Content -->
    <main class="flex-1 flex flex-col overflow-y-auto">
        <!-- Top Header -->
        <header class="h-16 bg-white dark:bg-[#1e293b] border-b border-slate-200 dark:border-slate-700 flex items-center justify-between px-8 sticky top-0 z-10">
        <div class="flex items-center gap-4 flex-1 max-w-xl">
            <div class="relative w-full">
                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xl">search</span>
                <input class="w-full bg-slate-50 dark:bg-slate-800 dark:text-slate-200 dark:placeholder-slate-500 border-none rounded-lg pl-10 pr-4 py-2 text-sm focus:ring-2 focus:ring-primary" placeholder="Search..." type="text">
            </div>
        </div>
        <div class="flex items-center gap-3">
            <a href="/" target="_blank" class="p-2 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-700 rounded-lg" title="View Site">
                <span class="material-symbols-outlined">open_in_new</span>
            </a>
<div class="relative">
            <button id="notif-btn" class="p-2 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-700 rounded-lg relative" aria-label="Notifications" aria-haspopup="true" aria-expanded="false">
                <span class="material-symbols-outlined">notifications</span>
                {{#if pending_count}}<span class="absolute top-2 right-2 size-2 bg-red-500 rounded-full border-2 border-white dark:border-[#1e293b]"></span>{{/if}}
            </button>
            <!-- Notifications dropdown -->
            <div id="notif-dropdown"
                 class="hidden absolute right-0 top-full mt-1 w-80 bg-white dark:bg-[#1e293b] rounded-xl shadow-xl border border-slate-200 dark:border-slate-700 z-50">
                <div class="p-3 border-b border-slate-100 dark:border-slate-700 flex items-center justify-between">
                    <span class="text-sm font-semibold text-slate-700 dark:text-slate-200">Pending Approvals</span>
                    {{#if pending_count}}<span class="text-xs font-semibold bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300 px-2 py-0.5 rounded-full">{{pending_count}}</span>{{/if}}
                </div>
                {{#if has_pending}}
                <ul class="divide-y divide-slate-100 dark:divide-slate-700 max-h-64 overflow-y-auto">
                {{#each pending_items}}
                <li>
                    <a href="/admin/approvals/{{id}}" class="flex items-center gap-3 px-4 py-3 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                        <span class="material-symbols-outlined text-amber-500 text-xl">pending</span>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-slate-800 dark:text-slate-200">Site Plan #{{id}}</p>
                            <p class="text-xs text-slate-400 truncate">Created {{created_at}}</p>
                        </div>
                        <span class="material-symbols-outlined text-slate-300 text-base">chevron_right</span>
                    </a>
                </li>
                {{/each}}
                </ul>
                <div class="p-3 border-t border-slate-100 dark:border-slate-700">
                    <a href="/admin/approvals" class="block w-full text-center text-sm font-semibold text-[#135bec] hover:underline">View all approvals</a>
                </div>
                {{else}}
                <div class="px-4 py-8 text-center text-slate-400 text-sm">
                    <span class="material-symbols-outlined text-3xl block mb-2">done_all</span>
                    Nothing pending review
                </div>
                {{/if}}
            </div>
            </div><!-- end notif wrapper -->
            <div class="h-8 w-px bg-slate-200 dark:bg-slate-700 mx-2"></div>
            <a href="/admin/pages/new" class="flex items-center gap-2 bg-primary text-white px-4 py-2 rounded-lg text-sm font-semibold whitespace-nowrap hover:bg-primary/90 transition-colors">
                <span class="material-symbols-outlined text-sm">add</span>
                Create New
            </a>
        </div>
        </header>
        <div class="p-8">
        {{#if flash_error}}<div class="cms-alert cms-alert-error mb-6"><span class="material-symbols-outlined">error</span>{{flash_error}}</div>{{/if}}
        {{#if flash_success}}<div class="cms-alert cms-alert-success mb-6"><span class="material-symbols-outlined">check_circle</span>{{flash_success}}</div>{{/if}}
        {{{content}}}
        </div>
    </main>
</div>
<script src="/js/admin-global" nonce="{{csp_nonce}}"></script>
{{{media_picker_html}}}
</body>
</html>
HTML,

        // ─── LOGIN PAGE ──────────────────────────────────────────────
        'admin/login' => <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | MonolithCMS</title>
    <script src="/cdn/tailwind-forms.min.js"></script>
    <link href="/cdn/inter-font.css" rel="stylesheet">
    <link href="/cdn/material-icons.css" rel="stylesheet">
    <script nonce="{{csp_nonce}}">
        tailwind.config = {
        theme: {
            extend: {
                colors: { "primary": "#135bec" },
                fontFamily: { "display": ["Inter", "system-ui", "sans-serif"] },
            },
        },
        }
    </script>
    <link href="/css/admin" rel="stylesheet">
</head>
<body class="bg-slate-50 font-display min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md">
        <div class="bg-white rounded-2xl shadow-xl p-8">
        <div class="flex justify-center mb-6">
            <div class="bg-primary size-14 rounded-xl flex items-center justify-center text-white">
                <span class="material-symbols-outlined text-3xl">mms</span>
            </div>
        </div>
        <h1 class="text-2xl font-bold text-slate-900 text-center mb-2">Welcome back</h1>
        <p class="text-slate-500 text-center mb-8">Sign in to your MonolithCMS admin account</p>

        {{#if flash}}<div class="cms-alert {{#if flash.is_error}}cms-alert-error{{else}}cms-alert-success{{/if}} mb-6">
            <span class="material-symbols-outlined">{{#if flash.is_error}}error{{else}}check_circle{{/if}}</span>
            {{flash.message}}
        </div>{{/if}}

        <form method="post" action="/admin/login" class="space-y-5">
            {{{csrf_field}}}
            <div>
                <label class="cms-label">Email</label>
                <input type="email" name="email" required autofocus
                    class="cms-input"
                    placeholder="you@example.com">
            </div>
            <div>
                <label class="cms-label">Password</label>
                <input type="password" name="password" required
                    class="cms-input"
                    placeholder="••••••••••••">
            </div>
            <button type="submit"
                class="cms-btn w-full py-3">
                <span class="material-symbols-outlined text-xl">login</span>
                Sign In
            </button>
        </form>
        </div>
        <p class="text-center text-slate-500 text-sm mt-6">Powered by MonolithCMS</p>
    </div>
</body>
</html>
HTML,

        // ─── MFA PAGE ────────────────────────────────────────────────
        'admin/mfa' => <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Code | MonolithCMS</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; background: #f1f5f9; margin: 0; }
        .mfa-box { background: #fff; padding: 2rem; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); width: 100%; max-width: 400px; text-align: center; }
        h1 { margin: 0 0 0.5rem; color: #1e293b; font-size: 1.25rem; }
        p { color: #6b7280; margin: 0 0 1.5rem; }
        .flash { padding: 0.75rem; margin-bottom: 1rem; border-radius: 4px; font-size: 0.875rem; }
        .flash-error { background: #fee2e2; color: #991b1b; }
        .flash-success { background: #d1fae5; color: #065f46; }
        input { display: block; width: 100%; padding: 0.625rem 0.875rem; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; color: #1e293b; font-size: 0.875rem; outline: none; margin-bottom: 1rem; font-family: inherit; }
        input:focus { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,0.1); }
        input::placeholder { color: #94a3b8; }
        input[name="code"] { text-align: center; font-size: 1.5rem; letter-spacing: 0.5rem; }
        button[type="submit"] { display: block; width: 100%; padding: 0.75rem; background: #3b82f6; color: #fff; border: none; border-radius: 8px; font-size: 0.875rem; font-weight: 600; cursor: pointer; font-family: inherit; }
        button[type="submit"]:hover { background: #2563eb; }
    </style>
</head>
<body>
    <div class="mfa-box">
        <h1>Enter Verification Code</h1>
        <p>We sent a 6-digit code to your email.</p>
        {{#if flash}}<div class="flash flash-{{flash.type}}">{{flash.message}}</div>{{/if}}
        <form method="post" action="/admin/mfa">
        {{{csrf_field}}}
        <input type="text" name="code" pattern="[0-9]{6}" maxlength="6" required autofocus placeholder="000000">
        <button type="submit">Verify</button>
        </form>
    </div>
</body>
</html>
HTML,

        // ─── ADMIN DASHBOARD ─────────────────────────────────────────
        'admin/dashboard' => <<<'HTML'
<!-- Page Heading -->
<div class="mb-8">
    <h2 class="text-2xl font-bold text-slate-900">Dashboard Overview</h2>
    <p class="text-slate-500">Welcome back, here's what's happening with your site today.</p>
</div>

<!-- Security Warnings -->
{{#if warn_sqlite_live_exposed}}
<div class="mb-6 flex items-start gap-3 p-4 bg-red-50 border border-red-200 rounded-xl">
    <span class="material-symbols-outlined text-red-600 mt-0.5">gpp_bad</span>
    <div>
        <p class="font-semibold text-red-800">Confirmed: database is publicly accessible</p>
        <p class="text-sm text-red-700 mt-1">A live HTTP probe confirmed that <code class="bg-red-100 px-1 rounded">site.sqlite</code> is downloadable from <code class="bg-red-100 px-1 rounded">{{sqlite_probe_url}}</code>. Anyone on the internet can download your entire database, including all content, user accounts, and encrypted credentials. Block this path in your web server configuration immediately.</p>
        <p class="text-sm text-red-700 mt-2"><strong>Apache:</strong> add a <code class="bg-red-100 px-1 rounded">FilesMatch</code> rule to <code class="bg-red-100 px-1 rounded">.htaccess</code>. <strong>nginx:</strong> add a <code class="bg-red-100 px-1 rounded">location ~* \.sqlite$</code> block returning 403.</p>
    </div>
</div>
{{/if}}
{{#if warn_no_htaccess}}
<div class="mb-6 flex items-start gap-3 p-4 bg-red-50 border border-red-200 rounded-xl">
    <span class="material-symbols-outlined text-red-600 mt-0.5">dangerous</span>
    <div>
        <p class="font-semibold text-red-800">No .htaccess file detected</p>
        <p class="text-sm text-red-700 mt-1">Your <strong>SQLite database</strong> and other sensitive files may be publicly downloadable. MonolithCMS will try to auto-create the file on next request, but if your host ignores .htaccess (nginx) you must block these paths manually.</p>
    </div>
</div>
{{/if}}
{{#if warn_sqlite_exposed}}
<div class="mb-6 flex items-start gap-3 p-4 bg-red-50 border border-red-200 rounded-xl">
    <span class="material-symbols-outlined text-red-600 mt-0.5">lock_open</span>
    <div>
        <p class="font-semibold text-red-800">Database not protected in .htaccess</p>
        <p class="text-sm text-red-700 mt-1">Your .htaccess exists but is missing the <code class="bg-red-100 px-1 rounded">FilesMatch</code> rule that blocks direct access to <code class="bg-red-100 px-1 rounded">site.sqlite</code>. Anyone who knows the URL could download your entire database including encrypted credentials.</p>
    </div>
</div>
{{/if}}
{{#if warn_no_rewrite}}
<div class="mb-4 flex items-start gap-3 p-4 bg-amber-50 border border-amber-200 rounded-xl">
    <span class="material-symbols-outlined text-amber-600 mt-0.5">warning</span>
    <div>
        <p class="font-semibold text-amber-800">Front-controller routing not configured</p>
        <p class="text-sm text-amber-700 mt-1">Your .htaccess is missing the <code class="bg-amber-100 px-1 rounded">RewriteRule</code> that routes all requests through <code class="bg-amber-100 px-1 rounded">index.php</code>. Pretty URLs and page routing will not work correctly.</p>
    </div>
</div>
{{/if}}

<!-- Stats Row -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <div class="bg-white p-6 rounded-xl border border-slate-200">
        <div class="flex items-center justify-between mb-4">
        <div class="p-2 bg-blue-50 text-blue-600 rounded-lg">
            <span class="material-symbols-outlined">description</span>
        </div>
        </div>
        <p class="text-slate-500 text-sm font-medium">Total Pages</p>
        <h3 class="text-2xl font-bold text-slate-900 mt-1">{{pages_count}}</h3>
    </div>
    <div class="bg-white p-6 rounded-xl border border-slate-200">
        <div class="flex items-center justify-between mb-4">
        <div class="p-2 bg-emerald-50 text-emerald-600 rounded-lg">
            <span class="material-symbols-outlined">check_circle</span>
        </div>
        </div>
        <p class="text-slate-500 text-sm font-medium">Published</p>
        <h3 class="text-2xl font-bold text-slate-900 mt-1">{{published_count}}</h3>
    </div>
    <div class="bg-white p-6 rounded-xl border border-slate-200">
        <div class="flex items-center justify-between mb-4">
        <div class="p-2 bg-blue-50 text-blue-600 rounded-lg">
            <span class="material-symbols-outlined">image</span>
        </div>
        </div>
        <p class="text-slate-500 text-sm font-medium">Media Files</p>
        <h3 class="text-2xl font-bold text-slate-900 mt-1">{{assets_count}}</h3>
    </div>
    <div class="bg-white p-6 rounded-xl border border-slate-200">
        <div class="flex items-center justify-between mb-4">
        <div class="p-2 bg-orange-50 text-orange-600 rounded-lg">
            <span class="material-symbols-outlined">pending_actions</span>
        </div>
        </div>
        <p class="text-slate-500 text-sm font-medium">Pending Approvals</p>
        <h3 class="text-2xl font-bold text-slate-900 mt-1">{{pending_count}}</h3>
    </div>
</div>

<!-- Quick Actions -->
<div class="bg-white p-6 rounded-xl border border-slate-200 mb-8">
    <div class="flex items-center justify-between mb-4">
        <h3 class="text-lg font-bold text-slate-900">Quick Actions</h3>
    </div>
    <div class="flex flex-wrap gap-3">
        <a href="/admin/pages/new" class="flex items-center gap-2 bg-primary text-white px-4 py-2 rounded-lg text-sm font-semibold whitespace-nowrap hover:bg-primary/90 transition-colors">
        <span class="material-symbols-outlined text-sm">add</span>
        New Page
        </a>
        <a href="/admin/ai" class="flex items-center gap-2 bg-slate-100 text-slate-700 px-4 py-2 rounded-lg text-sm font-semibold whitespace-nowrap hover:bg-slate-200 transition-colors">
        <span class="material-symbols-outlined text-sm">auto_awesome</span>
        AI Generate
        </a>
        <a href="/admin/media" class="flex items-center gap-2 bg-slate-100 text-slate-700 px-4 py-2 rounded-lg text-sm font-semibold whitespace-nowrap hover:bg-slate-200 transition-colors">
        <span class="material-symbols-outlined text-sm">upload</span>
        Upload Media
        </a>
        <form method="post" action="/admin/cache/regenerate" class="inline">
        {{{csrf_field}}}
        <button type="submit" class="flex items-center gap-2 bg-amber-100 text-amber-700 px-4 py-2 rounded-lg text-sm font-semibold whitespace-nowrap hover:bg-amber-200 transition-colors">
            <span class="material-symbols-outlined text-sm">cached</span>
            Regenerate Cache
        </button>
        </form>
    </div>
</div>

<!-- Recent Pages Table -->
<div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
    <div class="px-6 py-5 border-b border-slate-200 flex items-center justify-between">
        <h3 class="text-lg font-bold text-slate-900">Recent Pages</h3>
        <a href="/admin/pages" class="text-primary text-sm font-semibold hover:underline">View All</a>
    </div>
    {{#if has_pages}}
    <div class="overflow-x-auto">
        <table class="w-full text-left">
        <thead class="bg-slate-50">
            <tr>
                <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Page Title</th>
                <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Status</th>
                <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Last Modified</th>
                <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider text-right">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-200">
            {{#each recent_pages}}
            <tr>
                <td class="px-6 py-4 whitespace-nowrap">
                    <div class="flex items-center gap-3">
                        <div class="size-10 rounded bg-slate-100 flex items-center justify-center">
                            <span class="material-symbols-outlined text-slate-400">article</span>
                        </div>
                        <div>
                            <p class="text-sm font-semibold text-slate-900">{{title}}</p>
                            <p class="text-xs text-slate-500">/{{slug}}</p>
                        </div>
                    </div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    {{#if is_published}}
                    <span class="cms-badge cms-badge-published">Published</span>
                    {{else}}
                    <span class="cms-badge cms-badge-draft">Draft</span>
                    {{/if}}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-600">{{updated_at}}</td>
                <td class="px-6 py-4 whitespace-nowrap text-right">
                    <a href="/admin/pages/{{id}}" class="text-primary hover:underline text-sm font-medium mr-3">Edit</a>
                    <a href="/{{slug}}" target="_blank" class="text-slate-500 hover:underline text-sm">View</a>
                </td>
            </tr>
            {{/each}}
        </tbody>
        </table>
    </div>
    {{else}}
    <div class="p-8 text-center">
        <div class="size-16 rounded-full bg-slate-100 flex items-center justify-center mx-auto mb-4">
        <span class="material-symbols-outlined text-slate-400 text-3xl">description</span>
        </div>
        <h4 class="text-lg font-semibold text-slate-900 mb-2">No pages yet</h4>
        <p class="text-slate-500 mb-4">Get started by creating your first page or use AI to generate a complete site.</p>
        <div class="flex justify-center gap-3">
        <a href="/admin/pages/new" class="flex items-center gap-2 bg-primary text-white px-4 py-2 rounded-lg text-sm font-semibold whitespace-nowrap hover:bg-primary/90 transition-colors">
            <span class="material-symbols-outlined text-sm">add</span>
            Create Page
        </a>
        <a href="/admin/ai" class="flex items-center gap-2 bg-slate-100 text-slate-700 px-4 py-2 rounded-lg text-sm font-semibold whitespace-nowrap hover:bg-slate-200 transition-colors">
            <span class="material-symbols-outlined text-sm">auto_awesome</span>
            AI Generate
        </a>
        </div>
    </div>
    {{/if}}
</div>
HTML,

        // ─── SETUP WIZARD ────────────────────────────────────────────
        'setup/welcome' => <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup | MonolithCMS</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; background: #f1f5f9; margin: 0; padding: 1rem; }
        .setup-box { background: #fff; padding: 2.5rem; border-radius: 12px; box-shadow: 0 25px 50px rgba(0,0,0,0.15); width: 100%; max-width: 500px; }
        h1 { text-align: center; margin: 0 0 0.5rem; color: #1e293b; font-size: 1.5rem; }
        h2 { color: #1e293b; margin: 0 0 1rem; font-size: 1.1rem; }
        .subtitle { text-align: center; color: #64748b; margin: 0 0 2rem; }
        .steps { display: flex; justify-content: center; gap: 0.5rem; margin-bottom: 2rem; }
        .step { width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 0.875rem; }
        .step.active { background: #3b82f6; color: #fff; }
        .step.done { background: #10b981; color: #fff; }
        .step.pending { background: #e2e8f0; color: #94a3b8; }
        .flash { padding: 0.75rem; margin-bottom: 1rem; border-radius: 4px; font-size: 0.875rem; }
        .flash-error { background: #fee2e2; color: #991b1b; }
        label { display: block; margin-bottom: 0.25rem; color: #475569; font-size: 0.875rem; font-weight: 500; }
        input, select, textarea { display: block; width: 100%; padding: 0.625rem 0.875rem; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; color: #1e293b; font-size: 0.875rem; outline: none; margin-bottom: 1rem; font-family: inherit; appearance: none; -webkit-appearance: none; }
        input:focus, select:focus, textarea:focus { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,0.1); }
        input::placeholder, textarea::placeholder { color: #94a3b8; }
        p { color: #64748b; margin: 0 0 1rem; }
        button[type="submit"] { display: block; width: 100%; padding: 0.75rem; background: #3b82f6; color: #fff; border: none; border-radius: 8px; font-size: 0.875rem; font-weight: 600; cursor: pointer; font-family: inherit; margin-top: 0.5rem; }
        button[type="submit"]:hover { background: #2563eb; }
        code { background: #f1f5f9; padding: 0.125rem 0.375rem; border-radius: 4px; font-size: 0.8125rem; }
    </style>
</head>
<body>
    <div class="setup-box">
        <h1>Welcome to MonolithCMS</h1>
        <p class="subtitle">Let's set up your site in a few steps.</p>

        <div class="steps">
        <div class="step {{step1_class}}">1</div>
        <div class="step {{step2_class}}">2</div>
        <div class="step {{step3_class}}">3</div>
        <div class="step {{step4_class}}">4</div>
        </div>

        {{#if flash}}<div class="flash flash-{{flash.type}}">{{flash.message}}</div>{{/if}}

        {{{step_content}}}
    </div>
</body>
</html>
HTML,

        'setup/step1' => <<<'HTML'
<h2>Create Admin Account</h2>
<form method="post" action="/setup">
    {{{csrf_field}}}
    <input type="hidden" name="step" value="1">
    <label>
        Email Address
        <input type="email" name="email" required autofocus>
    </label>
    <label>
        Password (min 12 characters)
        <input type="password" name="password" minlength="12" required>
    </label>
    <label>
        Confirm Password
        <input type="password" name="password_confirm" minlength="12" required>
    </label>
    <button type="submit">Continue →</button>
</form>
HTML,

        'setup/step2' => <<<'HTML'
<h2>Site Information</h2>
<form method="post" action="/setup">
    {{{csrf_field}}}
    <input type="hidden" name="step" value="2">
    <label>
        Site Name
        <input type="text" name="site_name" required autofocus placeholder="My Awesome Site">
    </label>
    <label>
        Tagline
        <input type="text" name="tagline" placeholder="A brief description of your site">
    </label>
    <button type="submit">Continue →</button>
</form>
HTML,

        'setup/step3' => <<<'HTML'
<h2>AI Provider <span style="font-weight:400;color:#94a3b8;">(Optional)</span></h2>
<p style="color: #64748b; font-size: 0.875rem; margin-bottom: 1.5rem;">Connect an AI provider to auto-generate your full website from a description. You can skip this and configure it later in Settings.</p>
<form method="post" action="/setup">
    {{{csrf_field}}}
    <input type="hidden" name="step" value="3">
    <label>
        Provider
        <select name="ai_provider">
        <option value="">— Skip for now —</option>
        <option value="openai">OpenAI (GPT-5.4)</option>
        <option value="anthropic">Anthropic (Claude 4.6)</option>
        <option value="google">Google (Gemini 3)</option>
        </select>
    </label>
    <label>
        API Key
        <input type="password" name="ai_api_key" placeholder="Paste your API key here">
    </label>
    <label>
        Model <span style="font-weight:400;color:#94a3b8;">(optional — leave blank for default)</span>
        <input type="text" name="ai_model" placeholder="e.g. gpt-5.4-pro, claude-sonnet-4-6, gemini-3-flash">
    </label>
    <button type="submit">Continue →</button>
</form>
HTML,

        'setup/step4' => <<<'HTML'
<h2>Email <span style="font-weight:400;color:#94a3b8;">(Optional)</span></h2>
<p style="color: #64748b; font-size: 0.875rem; margin-bottom: 1.5rem;">Configure how transactional emails are sent — for contact form submissions and MFA login codes. Skip to fall back to PHP's built-in <code>mail()</code>.</p>
<form method="post" action="/setup">
    {{{csrf_field}}}
    <input type="hidden" name="step" value="4">
    <label>
        Driver
        <select name="email_driver" id="setup-email-driver">
        <option value="smtp">SMTP</option>
        <option value="mailgun">Mailgun</option>
        <option value="sendgrid">SendGrid</option>
        <option value="postmark">Postmark</option>
        </select>
    </label>
    <label>
        From Email
        <input type="email" name="smtp_from" placeholder="noreply@example.com">
    </label>
    <fieldset id="setup-smtp-fields" style="border:none;padding:0;margin:0;">
        <label>SMTP Host<input type="text" name="smtp_host" placeholder="smtp.example.com"></label>
        <label>SMTP Port<input type="number" name="smtp_port" value="587"></label>
        <label>SMTP Username<input type="text" name="smtp_user"></label>
        <label>SMTP Password<input type="password" name="smtp_pass"></label>
    </fieldset>
    <fieldset id="setup-mailgun-fields" style="border:none;padding:0;margin:0;display:none;">
        <label>Mailgun API Key<input type="password" name="mailgun_api_key" placeholder="key-..."></label>
        <label>Mailgun Domain<input type="text" name="mailgun_domain" placeholder="mg.example.com"></label>
    </fieldset>
    <fieldset id="setup-sendgrid-fields" style="border:none;padding:0;margin:0;display:none;">
        <label>SendGrid API Key<input type="password" name="sendgrid_api_key" placeholder="SG...."></label>
    </fieldset>
    <fieldset id="setup-postmark-fields" style="border:none;padding:0;margin:0;display:none;">
        <label>Postmark Server Token<input type="password" name="postmark_api_key"></label>
    </fieldset>
    <div style="display:flex;gap:0.75rem;margin-top:1rem;">
        <button type="submit">Complete Setup →</button>
        <a href="/setup?step=5" style="padding:0.625rem 1.25rem;border:1px solid #e2e8f0;border-radius:6px;color:#64748b;text-decoration:none;font-size:0.875rem;">Skip</a>
    </div>
</form>
<script>
(function(){
    var sel = document.getElementById('setup-email-driver');
    var fieldsets = {
        smtp: document.getElementById('setup-smtp-fields'),
        mailgun: document.getElementById('setup-mailgun-fields'),
        sendgrid: document.getElementById('setup-sendgrid-fields'),
        postmark: document.getElementById('setup-postmark-fields'),
    };
    function update() {
        Object.keys(fieldsets).forEach(function(k) {
        fieldsets[k].style.display = k === sel.value ? '' : 'none';
        });
    }
    sel.addEventListener('change', update);
    update();
})();
</script>
HTML,

        'setup/complete' => <<<'HTML'
<div style="text-align: center;">
    <div style="font-size: 4rem; margin-bottom: 1rem;">🎉</div>
    <h2>Setup Complete!</h2>
    <p style="color: #64748b;">Your MonolithCMS installation is ready to use.</p>
    <a href="/admin/login" style="display: inline-block; background: #3b82f6; color: #fff; padding: 0.75rem 2rem; border-radius: 6px; text-decoration: none; margin-top: 1rem;">Go to Admin Panel →</a>
</div>
HTML,

        // ─── ADMIN: PAGES LIST ───────────────────────────────────────
    ];
}
// ─────────────────────────────────────────────────────────────────────────────
// SECTION 11: PAGES AND NAVIGATION TEMPLATES
// ─────────────────────────────────────────────────────────────────────────────

function _tpl_pages(): array {
    return [

        'admin/pages' => <<<'HTML'
<!-- Page Header -->
<div class="cms-page-header">
    <div>
        <h1 class="cms-page-title">Pages</h1>
        <p class="cms-page-subtitle">Manage your website pages and content.</p>
    </div>
    <a href="/admin/pages/new" class="cms-btn">
        <span class="material-symbols-outlined text-xl">add</span>
        New Page
    </a>
</div>

{{#if pages}}
<div class="cms-table-wrap">
    <div class="overflow-x-auto">
        <table class="w-full">
        <thead>
            <tr class="bg-gray-50 border-b border-gray-200">
                <th class="text-left px-6 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Page</th>
                <th class="text-left px-6 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">URL</th>
                <th class="text-left px-6 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                <th class="text-left px-6 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Last Updated</th>
                <th class="text-right px-6 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            {{#each pages}}
            <tr class="hover:bg-gray-50 transition-colors">
                <td class="px-6 py-4">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg bg-[#135bec]/10 flex items-center justify-center">
                            <span class="material-symbols-outlined text-[#135bec]">description</span>
                        </div>
                        <span class="font-medium text-gray-900">{{title}}</span>
                    </div>
                </td>
                <td class="px-6 py-4">
                    <code class="px-2 py-1 bg-gray-100 rounded text-sm text-gray-700">/{{slug}}</code>
                </td>
                <td class="px-6 py-4">
                    {{#if is_published}}
                    <span class="cms-badge cms-badge-published">Published</span>
                    {{else}}
                    <span class="cms-badge cms-badge-draft">Draft</span>
                    {{/if}}
                </td>
                <td class="px-6 py-4 text-sm text-gray-500">{{updated_at}}</td>
                <td class="px-6 py-4">
                    <div class="flex items-center justify-end gap-2">
                        <a href="/admin/pages/{{id}}" class="cms-btn-action">
                            <span class="material-symbols-outlined text-lg">edit</span>
                            Edit
                        </a>
                        <a href="/{{slug}}" target="_blank" class="cms-btn-action">
                            <span class="material-symbols-outlined text-lg">open_in_new</span>
                            View
                        </a>
                        <form method="post" action="/admin/pages/{{id}}/delete" class="inline" data-confirm="Delete this page?">
                            {{{csrf_field}}}
                            <button type="submit" class="cms-btn-danger">
                                <span class="material-symbols-outlined text-lg">delete</span>
                                Delete
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
            {{/each}}
        </tbody>
        </table>
    </div>
</div>
{{else}}
<div class="bg-white rounded-xl border border-gray-200 p-12 text-center">
    <div class="w-16 h-16 rounded-full bg-[#135bec]/10 flex items-center justify-center mx-auto mb-4">
        <span class="material-symbols-outlined text-3xl text-[#135bec]">description</span>
    </div>
    <h3 class="text-lg font-semibold text-gray-900 mb-2">No pages yet</h3>
    <p class="text-gray-600 mb-6">Create your first page or use AI to generate a complete website.</p>
    <div class="flex items-center justify-center gap-3">
        <a href="/admin/pages/new" class="cms-btn">
        <span class="material-symbols-outlined text-xl">add</span>
        Create Page
        </a>
        <a href="/admin/ai" class="cms-btn-ghost">
        <span class="material-symbols-outlined text-xl">auto_awesome</span>
        AI Generate
        </a>
    </div>
</div>
{{/if}}
HTML,

        // ─── ADMIN: PAGE EDIT ────────────────────────────────────────
        'admin/page_edit' => <<<'HTML'
<!-- Page Header -->
<div class="mb-4 flex items-center justify-between">
    <div class="flex items-center gap-3">
        <a href="/admin/pages" class="cms-back">
            <span class="material-symbols-outlined">arrow_back</span>
        </a>
        <div>
            <h1 class="text-2xl font-bold text-gray-900">{{#if is_new}}Create Page{{else}}Edit Page{{/if}}</h1>
            <p class="text-gray-600 mt-0.5 text-sm">{{#if is_new}}Add a new page to your site.{{else}}Edit content visually and update page settings.{{/if}}</p>
        </div>
    </div>
    {{#if is_new}}{{else}}
    <a href="/admin/pages/{{id}}/revisions" class="flex items-center gap-2 border border-gray-200 text-gray-600 px-4 py-2 rounded-lg text-sm font-semibold whitespace-nowrap hover:bg-gray-50 transition-colors">
        <span class="material-symbols-outlined text-lg">history</span>
        Revisions
    </a>
    {{/if}}
</div>

{{#if is_new}}
<!-- ── NEW PAGE: simple creation form ─────────────────────────────────────── -->
<div class="max-w-xl">
    <form method="post" action="/admin/pages/new" id="page-form">
        {{{csrf_field}}}
        <div class="cms-card space-y-4">
            <div>
                <label class="cms-label">Page Title *</label>
                <input type="text" name="title" value="{{title}}" required autofocus
                       class="cms-input text-lg font-semibold py-3" placeholder="e.g. About Us">
            </div>
            <div>
                <label class="cms-label">URL Slug *</label>
                <div class="flex items-center">
                    <span class="px-3 py-2 bg-gray-100 border border-r-0 border-gray-300 rounded-l-lg text-gray-500 text-sm">/</span>
                    <input type="text" name="slug" id="page-slug" value="{{slug}}" required pattern="[a-z0-9\-]+"
                           class="cms-input rounded-l-none border-l-0 flex-1" placeholder="about-us">
                </div>
            </div>
            <div>
                <label class="cms-label">Status</label>
                <select name="status" class="cms-input">
                    <option value="draft">Draft</option>
                    <option value="published">Published</option>
                </select>
            </div>
            <p class="text-sm text-gray-500 flex items-start gap-2">
                <span class="material-symbols-outlined text-base text-[#135bec] mt-0.5 flex-shrink-0">info</span>
                After saving, you can add and edit content using the visual editor directly on the page.
            </p>
            <button type="submit" class="cms-btn w-full py-3">
                <span class="material-symbols-outlined">add</span>
                Create Page
            </button>
        </div>
    </form>
</div>

{{else}}
<!-- ── EXISTING PAGE: visual editor iframe + settings sidebar ─────────────── -->
<div class="flex gap-5" style="height:calc(100vh - 110px)">

    <!-- Visual editor iframe -->
    <div class="flex-1 rounded-xl overflow-hidden border border-gray-200 shadow-sm min-w-0">
        <iframe id="visual-editor-frame"
                src="/{{slug}}?edit&embedded=1"
                class="w-full h-full border-0"
                allow="same-origin"></iframe>
    </div>

    <!-- Settings sidebar -->
    <div class="w-72 flex-shrink-0 flex flex-col gap-4 overflow-y-auto pb-4">
        <form method="post" action="/admin/pages/{{id}}" id="page-form">
            {{{csrf_field}}}

            <!-- Title -->
            <div class="cms-card">
                <label class="cms-label">Page Title *</label>
                <input type="text" name="title" value="{{title}}" required
                       class="cms-input font-semibold">
            </div>

            <!-- Page Settings -->
            <div class="cms-card">
                <h3 class="text-sm font-semibold text-gray-900 mb-3 flex items-center gap-2 uppercase tracking-wide">
                    <span class="material-symbols-outlined text-[#135bec] text-lg">settings</span>
                    Page Settings
                </h3>
                <div class="space-y-3">
                    <div>
                        <label class="cms-label">URL Slug *</label>
                        <div class="flex items-center">
                            <span class="px-3 py-2 bg-gray-100 border border-r-0 border-gray-300 rounded-l-lg text-gray-500 text-sm">/</span>
                            <input type="text" name="slug" id="page-slug" value="{{slug}}" required pattern="[a-z0-9\-]+"
                                   class="cms-input rounded-l-none border-l-0 flex-1">
                        </div>
                    </div>
                    <div>
                        <label class="cms-label">Status</label>
                        <select name="status" class="cms-input">
                            <option value="draft" {{#if is_draft}}selected{{/if}}>Draft</option>
                            <option value="published" {{#if is_published}}selected{{/if}}>Published</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- SEO -->
            <div class="cms-card">
                <h3 class="text-sm font-semibold text-gray-900 mb-3 flex items-center gap-2 uppercase tracking-wide">
                    <span class="material-symbols-outlined text-[#135bec] text-lg">search</span>
                    SEO
                </h3>
                <div class="space-y-3">
                    <div>
                        <label class="cms-label">Meta Description</label>
                        <textarea name="meta_description" rows="3" class="cms-input">{{meta_description}}</textarea>
                        <p class="cms-hint">150–160 characters recommended</p>
                    </div>
                    <div>
                        <label class="cms-label">OG Image</label>
                        <input type="hidden" name="og_image" id="og-image-input" value="{{og_image}}">
                        <div id="og-image-preview" class="min-h-[72px] border border-gray-300 rounded-lg bg-gray-50 flex items-center justify-center overflow-hidden mb-2">
                            {{#if og_image}}
                            <img src="{{og_image}}" alt="OG Image" class="max-h-[72px] object-contain">
                            {{else}}
                            <span class="text-gray-400 text-xs">No image selected</span>
                            {{/if}}
                        </div>
                        <div class="flex gap-2">
                            <button type="button" id="select-og-image" class="flex-1 flex items-center justify-center gap-1 border border-gray-200 text-gray-600 px-3 py-1.5 rounded-lg text-sm font-medium hover:bg-gray-50 transition-colors">
                                <span class="material-symbols-outlined text-base">photo_library</span>
                                Select
                            </button>
                            <button type="button" id="clear-og-image" class="flex items-center justify-center gap-1 border border-red-200 text-red-600 px-3 py-1.5 rounded-lg text-sm font-medium hover:bg-red-50 transition-colors">
                                <span class="material-symbols-outlined text-base">close</span>
                                Clear
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Actions -->
            <div class="cms-card">
                <button type="submit" class="cms-btn w-full py-3">
                    <span class="material-symbols-outlined">save</span>
                    Save Settings
                </button>
                <a href="/{{slug}}" target="_blank"
                   class="mt-3 w-full flex items-center justify-center gap-2 border border-gray-200 text-gray-600 px-4 py-2 rounded-lg text-sm font-semibold hover:bg-gray-50 transition-colors">
                    <span class="material-symbols-outlined">open_in_new</span>
                    View Live Page
                </a>
            </div>

        </form>
    </div><!-- end sidebar -->

</div><!-- end flex layout -->

{{/if}}
HTML,

        // ─── ADMIN: PAGE REVISIONS ───────────────────────────────────
        'admin/page_revisions' => <<<'HTML'
<div class="mb-6 flex items-center justify-between">
    <div class="flex items-center gap-3">
        <a href="/admin/pages/{{page.id}}" class="cms-back">
        <span class="material-symbols-outlined">arrow_back</span>
        </a>
        <div>
        <h1 class="text-2xl font-bold text-gray-900">Revision History</h1>
        <p class="text-gray-600 mt-1">{{page.title}}</p>
        </div>
    </div>
</div>

<div class="cms-card">
    {{#if revisions}}
    <p class="text-sm text-gray-500 mb-4">Showing the {{revisions_count}} most recent snapshots. Each snapshot captures the full page and all its blocks at the moment of saving.</p>
    <div class="divide-y divide-gray-100">
        {{#each revisions}}
        <div class="flex items-center justify-between py-3 gap-4">
        <div class="flex items-center gap-3 min-w-0">
            <span class="material-symbols-outlined text-gray-400">history</span>
            <div class="min-w-0">
                <p class="text-sm font-medium text-gray-900">{{created_at}}</p>
                <p class="text-xs text-gray-500">{{#if user_email}}Saved by {{user_email}}{{else}}System{{/if}}</p>
            </div>
        </div>
        <form method="post" action="/admin/pages/{{page_id}}/revisions/{{id}}/restore" class="shrink-0" data-confirm="Restore this revision? The current page state will be saved as a new revision first.">
            {{{csrf_field}}}
            <button type="submit" class="flex items-center gap-2 px-4 py-2 text-sm font-semibold text-[#135bec] bg-[#135bec]/5 border border-[#135bec]/20 rounded-lg hover:bg-[#135bec]/10 transition-colors">
                <span class="material-symbols-outlined text-base">restore</span>
                Restore
            </button>
        </form>
        </div>
        {{/each}}
    </div>
    {{else}}
    <div class="text-center py-12 text-gray-500">
        <span class="material-symbols-outlined text-4xl block mb-3 text-gray-300">history</span>
        <p class="font-medium">No revisions yet</p>
        <p class="text-sm mt-1">Revisions are saved automatically every time you edit this page.</p>
    </div>
    {{/if}}
</div>
HTML,

        // ─── ADMIN: NAVIGATION ───────────────────────────────────────
        'admin/nav' => <<<'HTML'
<!-- Page Header -->
<div class="cms-page-header">
    <div>
        <h1 class="cms-page-title">Navigation</h1>
        <p class="cms-page-subtitle">Manage your site's menu items. Drag to reorder.</p>
    </div>
</div>

<div class="cms-card">
    <form method="post" action="/admin/nav" id="nav-form">
        {{{csrf_field}}}

        <div id="nav-items" class="space-y-3" data-nav-count="{{nav_count}}">
        {{#each items}}
        <div class="nav-item flex items-center gap-4 p-4 bg-gray-50 rounded-lg border border-gray-200" data-id="{{id}}" draggable="true">
            <span class="handle cursor-move text-gray-400 hover:text-gray-600">
                <span class="material-symbols-outlined">drag_indicator</span>
            </span>
            <div class="flex-1 grid grid-cols-1 md:grid-cols-3 gap-3 items-center">
                <input type="text" name="nav[{{id}}][label]" value="{{label}}" placeholder="Label" required
                       class="px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none">
                <input type="text" name="nav[{{id}}][url]" value="{{url}}" placeholder="/page-slug" required
                       class="px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none">
                <label class="flex items-center gap-2 text-sm text-gray-600">
                    <input type="checkbox" name="nav[{{id}}][visible]" value="1" {{#if visible}}checked{{/if}}
                           class="size-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                    Visible
                </label>
                <input type="hidden" name="nav[{{id}}][id]" value="{{id}}">
                <input type="hidden" name="nav[{{id}}][order]" class="nav-order" value="{{sort_order}}">
            </div>
            <button type="button" data-action="remove-nav-item" class="p-2 text-red-500 hover:text-red-700 hover:bg-red-50 rounded-lg transition-colors" title="Delete">
                <span class="material-symbols-outlined">delete</span>
            </button>
        </div>
        {{/each}}
        </div>

        <div class="mt-6 flex items-center gap-3 pt-6 border-t border-gray-200">
        <button type="button" data-action="add-nav-item" class="cms-btn-ghost">
            <span class="material-symbols-outlined text-xl">add</span>
            Add Menu Item
        </button>
        <button type="submit" class="cms-btn">
            <span class="material-symbols-outlined text-xl">save</span>
            Save Navigation
        </button>
        </div>
    </form>
</div>

<script src="/js/admin-nav" nonce="{{csp_nonce}}"></script>
HTML,

        // ─── ADMIN: MEDIA LIBRARY ────────────────────────────────────
    ];
}
// ─────────────────────────────────────────────────────────────────────────────
// SECTION 12: CONTENT MANAGEMENT TEMPLATES
// ─────────────────────────────────────────────────────────────────────────────

function _tpl_content(): array {
    return [

        'admin/media' => <<<'HTML'
<!-- Page Header -->
<div class="cms-page-header">
    <div>
        <h1 class="cms-page-title">Media Library</h1>
        <p class="cms-page-subtitle">Upload and manage images and documents for your site.</p>
    </div>
</div>

<!-- Upload Zone -->
<div id="upload-zone" class="border-2 border-dashed border-gray-300 rounded-xl p-8 text-center bg-gray-50 mb-6 transition-all hover:border-[#135bec] hover:bg-blue-50/30 cursor-pointer">
    <div class="w-16 h-16 rounded-full bg-[#135bec]/10 flex items-center justify-center mx-auto mb-4">
        <span class="material-symbols-outlined text-3xl text-[#135bec]">cloud_upload</span>
    </div>
    <p class="text-gray-900 font-medium mb-1">Drag & drop files here</p>
    <p class="text-gray-500 text-sm mb-3">or <label class="text-[#135bec] hover:underline cursor-pointer">browse<input type="file" id="file-input" multiple accept="image/*,.pdf,.doc,.docx" class="hidden"></label></p>
    <p class="text-xs text-gray-400">Max 10MB per file. Images, PDFs, and documents allowed.</p>
</div>

<!-- Upload Progress -->
<div id="upload-progress" class="hidden mb-6">
    <div class="bg-gray-200 rounded-full h-2 overflow-hidden">
        <div id="progress-bar" class="bg-[#135bec] h-full rounded-full transition-all duration-300" style="width: 0%"></div>
    </div>
    <p id="upload-status" class="text-sm text-gray-600 mt-2">Uploading...</p>
</div>

{{#if assets}}
<div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-4">
    {{#each assets}}
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden group hover:shadow-md transition-shadow">
        {{#if is_image}}
        <div class="aspect-square bg-gray-100">
        <img src="/assets/{{hash}}" alt="{{filename}}" class="w-full h-full object-cover" loading="lazy">
        </div>
        {{else}}
        <div class="aspect-square bg-gray-100 flex items-center justify-center">
        <span class="material-symbols-outlined text-4xl text-gray-400">description</span>
        </div>
        {{/if}}
        <div class="p-3">
        <p class="text-sm font-medium text-gray-900 truncate" title="{{filename}}">{{filename}}</p>
        <p class="text-xs text-gray-500">{{size}}</p>
        </div>
        <div class="px-3 pb-3 flex gap-2">
        <button type="button" data-action="copy-url" data-url="/assets/{{hash}}"
                class="flex-1 inline-flex items-center justify-center gap-1 px-2 py-1.5 text-xs font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors">
            <span class="material-symbols-outlined text-sm">content_copy</span>
            Copy
        </button>
        <form method="post" action="/admin/media/{{id}}/delete" class="flex-1" data-confirm="Delete this file?">
            {{{csrf_field}}}
            <button type="submit" class="w-full inline-flex items-center justify-center gap-1 px-2 py-1.5 text-xs font-medium text-red-600 bg-red-50 rounded-lg hover:bg-red-100 transition-colors">
                <span class="material-symbols-outlined text-sm">delete</span>
                Delete
            </button>
        </form>
        </div>
    </div>
    {{/each}}
</div>
{{else}}
<div class="bg-white rounded-xl border border-gray-200 p-12 text-center">
    <div class="w-16 h-16 rounded-full bg-[#135bec]/10 flex items-center justify-center mx-auto mb-4">
        <span class="material-symbols-outlined text-3xl text-[#135bec]">photo_library</span>
    </div>
    <h3 class="text-lg font-semibold text-gray-900 mb-2">No media files yet</h3>
    <p class="text-gray-600">Upload images and documents to use in your pages.</p>
</div>
{{/if}}

<script src="/js/admin-media" nonce="{{csp_nonce}}"></script>
HTML,

        // ─── ADMIN: THEME SETTINGS ───────────────────────────────────
        'admin/theme' => <<<'HTML'
<!-- Page Header -->
<div class="cms-page-header">
    <div>
        <h1 class="cms-page-title">Theme Settings</h1>
        <p class="cms-page-subtitle">Customize your site's appearance, colors, and branding.</p>
    </div>
</div>

<form method="post" action="/admin/theme">
    {{{csrf_field}}}

    <!-- Site Identity -->
    <div class="cms-card">
        <h2 class="cms-card-header">
        <span class="material-symbols-outlined text-[#135bec]">badge</span>
        Site Identity
        </h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
            <label class="cms-label">Site Name *</label>
            <input type="text" name="site_name" value="{{site_name}}" required
                   class="cms-input">
        </div>
        <div>
            <label class="cms-label">Tagline</label>
            <input type="text" name="tagline" value="{{tagline}}"
                   class="cms-input">
        </div>
        <div class="md:col-span-2">
            <label class="cms-label">Logo URL</label>
            <input type="url" name="logo_url" value="{{logo_url}}" placeholder="/assets/..."
                   class="cms-input">
        </div>
        </div>
    </div>

    <!-- Color Palette -->
    <div class="cms-card">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-slate-100 mb-2 flex items-center gap-2">
        <span class="material-symbols-outlined text-[#135bec]">palette</span>
        Color Palette
        </h2>
        <p class="text-sm text-gray-600 dark:text-slate-400 mb-6">These colors are used throughout your site. Dark mode variants are automatically used when visitors prefer dark mode.</p>

        <!-- Light Mode -->
        <h3 class="text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wider mb-3">Light Mode</h3>
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4 mb-6">
        <div>
            <label class="cms-label">Primary</label>
            <div class="flex items-center gap-2">
                <input type="color" name="color_primary" value="{{color_primary}}"
                       class="w-10 h-10 rounded-lg border-2 border-gray-200 dark:border-slate-600 cursor-pointer p-0">
                <input type="text" value="{{color_primary}}" disabled
                       class="flex-1 px-2 py-1.5 text-sm font-mono bg-gray-50 dark:bg-slate-700 border border-gray-200 dark:border-slate-600 rounded dark:text-slate-300">
            </div>
        </div>
        <div>
            <label class="cms-label">Secondary</label>
            <div class="flex items-center gap-2">
                <input type="color" name="color_secondary" value="{{color_secondary}}"
                       class="w-10 h-10 rounded-lg border-2 border-gray-200 dark:border-slate-600 cursor-pointer p-0">
                <input type="text" value="{{color_secondary}}" disabled
                       class="flex-1 px-2 py-1.5 text-sm font-mono bg-gray-50 dark:bg-slate-700 border border-gray-200 dark:border-slate-600 rounded dark:text-slate-300">
            </div>
        </div>
        <div>
            <label class="cms-label">Accent</label>
            <div class="flex items-center gap-2">
                <input type="color" name="color_accent" value="{{color_accent}}"
                       class="w-10 h-10 rounded-lg border-2 border-gray-200 dark:border-slate-600 cursor-pointer p-0">
                <input type="text" value="{{color_accent}}" disabled
                       class="flex-1 px-2 py-1.5 text-sm font-mono bg-gray-50 dark:bg-slate-700 border border-gray-200 dark:border-slate-600 rounded dark:text-slate-300">
            </div>
        </div>
        <div>
            <label class="cms-label">Background</label>
            <div class="flex items-center gap-2">
                <input type="color" name="color_background" value="{{color_background}}"
                       class="w-10 h-10 rounded-lg border-2 border-gray-200 dark:border-slate-600 cursor-pointer p-0">
                <input type="text" value="{{color_background}}" disabled
                       class="flex-1 px-2 py-1.5 text-sm font-mono bg-gray-50 dark:bg-slate-700 border border-gray-200 dark:border-slate-600 rounded dark:text-slate-300">
            </div>
        </div>
        <div>
            <label class="cms-label">Text</label>
            <div class="flex items-center gap-2">
                <input type="color" name="color_text" value="{{color_text}}"
                       class="w-10 h-10 rounded-lg border-2 border-gray-200 dark:border-slate-600 cursor-pointer p-0">
                <input type="text" value="{{color_text}}" disabled
                       class="flex-1 px-2 py-1.5 text-sm font-mono bg-gray-50 dark:bg-slate-700 border border-gray-200 dark:border-slate-600 rounded dark:text-slate-300">
            </div>
        </div>
        </div>

        <!-- Dark Mode -->
        <h3 class="text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wider mb-3">Dark Mode</h3>
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
        <div>
            <label class="cms-label">Primary</label>
            <div class="flex items-center gap-2">
                <input type="color" name="color_primary_dark" value="{{color_primary_dark}}"
                       class="w-10 h-10 rounded-lg border-2 border-gray-200 dark:border-slate-600 cursor-pointer p-0">
                <input type="text" value="{{color_primary_dark}}" disabled
                       class="flex-1 px-2 py-1.5 text-sm font-mono bg-gray-50 dark:bg-slate-700 border border-gray-200 dark:border-slate-600 rounded dark:text-slate-300">
            </div>
        </div>
        <div>
            <label class="cms-label">Secondary</label>
            <div class="flex items-center gap-2">
                <input type="color" name="color_secondary_dark" value="{{color_secondary_dark}}"
                       class="w-10 h-10 rounded-lg border-2 border-gray-200 dark:border-slate-600 cursor-pointer p-0">
                <input type="text" value="{{color_secondary_dark}}" disabled
                       class="flex-1 px-2 py-1.5 text-sm font-mono bg-gray-50 dark:bg-slate-700 border border-gray-200 dark:border-slate-600 rounded dark:text-slate-300">
            </div>
        </div>
        <div>
            <label class="cms-label">Accent</label>
            <div class="flex items-center gap-2">
                <input type="color" name="color_accent_dark" value="{{color_accent_dark}}"
                       class="w-10 h-10 rounded-lg border-2 border-gray-200 dark:border-slate-600 cursor-pointer p-0">
                <input type="text" value="{{color_accent_dark}}" disabled
                       class="flex-1 px-2 py-1.5 text-sm font-mono bg-gray-50 dark:bg-slate-700 border border-gray-200 dark:border-slate-600 rounded dark:text-slate-300">
            </div>
        </div>
        <div>
            <label class="cms-label">Background</label>
            <div class="flex items-center gap-2">
                <input type="color" name="color_background_dark" value="{{color_background_dark}}"
                       class="w-10 h-10 rounded-lg border-2 border-gray-200 dark:border-slate-600 cursor-pointer p-0">
                <input type="text" value="{{color_background_dark}}" disabled
                       class="flex-1 px-2 py-1.5 text-sm font-mono bg-gray-50 dark:bg-slate-700 border border-gray-200 dark:border-slate-600 rounded dark:text-slate-300">
            </div>
        </div>
        <div>
            <label class="cms-label">Text</label>
            <div class="flex items-center gap-2">
                <input type="color" name="color_text_dark" value="{{color_text_dark}}"
                       class="w-10 h-10 rounded-lg border-2 border-gray-200 dark:border-slate-600 cursor-pointer p-0">
                <input type="text" value="{{color_text_dark}}" disabled
                       class="flex-1 px-2 py-1.5 text-sm font-mono bg-gray-50 dark:bg-slate-700 border border-gray-200 dark:border-slate-600 rounded dark:text-slate-300">
            </div>
        </div>
        </div>
    </div>

    <!-- Typography -->
    <div class="cms-card">
        <h2 class="cms-card-header">
        <span class="material-symbols-outlined text-[#135bec]">text_fields</span>
        Typography
        </h2>
        <div class="max-w-md">
        <label class="cms-label">Font Family</label>
        <select name="font_family"
                class="cms-input">
            <option value="system-ui, -apple-system, sans-serif" {{#if font_system}}selected{{/if}}>System Default</option>
            <option value="'Inter', sans-serif" {{#if font_inter}}selected{{/if}}>Inter</option>
            <option value="'Roboto', sans-serif" {{#if font_roboto}}selected{{/if}}>Roboto</option>
            <option value="'Open Sans', sans-serif" {{#if font_opensans}}selected{{/if}}>Open Sans</option>
            <option value="Georgia, serif" {{#if font_georgia}}selected{{/if}}>Georgia (Serif)</option>
        </select>
        </div>
    </div>

    <!-- Header & Footer -->
    <div class="cms-card">
        <h2 class="cms-card-header">
        <span class="material-symbols-outlined text-[#135bec]">view_quilt</span>
        Header & Footer
        </h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
            <label class="cms-label">Header Background</label>
            <div class="flex items-center gap-2">
                <input type="color" name="header_bg" value="{{header_bg}}"
                       class="w-10 h-10 rounded-lg border-2 border-gray-200 dark:border-slate-600 cursor-pointer p-0">
                <input type="text" value="{{header_bg}}" disabled
                       class="flex-1 px-2 py-1.5 text-sm font-mono bg-gray-50 dark:bg-slate-700 border border-gray-200 dark:border-slate-600 rounded dark:text-slate-300">
            </div>
        </div>
        <div>
            <label class="cms-label">Footer Text</label>
            <input type="text" name="footer_text" value="{{footer_text}}" placeholder="© 2026 Your Company"
                   class="cms-input">
        </div>
        </div>
    </div>

    <!-- Custom Code -->
    <div class="cms-card">
        <h2 class="cms-card-header">
        <span class="material-symbols-outlined text-[#135bec]">code</span>
        Custom Code
        </h2>
        <div class="space-y-4">
        <div>
            <label class="cms-label">Custom Head Scripts</label>
            <p class="text-xs text-gray-500 mb-2">Add analytics, fonts, or other scripts to the &lt;head&gt; section.</p>
            <textarea name="head_scripts" rows="4"
                      class="cms-input font-mono">{{head_scripts}}</textarea>
        </div>
        <div>
            <label class="cms-label">Custom Footer Scripts</label>
            <p class="text-xs text-gray-500 mb-2">Add scripts before the closing &lt;/body&gt; tag.</p>
            <textarea name="footer_scripts" rows="4"
                      class="cms-input font-mono">{{footer_scripts}}</textarea>
        </div>
        </div>
    </div>

    <button type="submit" class="cms-btn">
        <span class="material-symbols-outlined text-xl">save</span>
        Save Theme Settings
    </button>
</form>
HTML,

        // ─── ADMIN: SETTINGS ─────────────────────────────────────────
        'admin/settings' => <<<'HTML'
<!-- Page Header -->
<div class="cms-page-header">
    <div>
        <h1 class="cms-page-title">Settings</h1>
        <p class="cms-page-subtitle">Manage API credentials and integrations.</p>
    </div>
</div>

<form method="post" action="/admin/settings" class="space-y-6">
    {{{csrf_field}}}

    <!-- AI Provider -->
    <div class="cms-card">
        <h2 class="cms-card-header">
        <span class="material-symbols-outlined text-[#135bec]">auto_awesome</span>
        AI Provider
        </h2>
        {{#if ai_has_key}}
        <div class="flex items-center gap-3 mb-4 p-3 bg-green-50 border border-green-200 rounded-lg">
        <span class="material-symbols-outlined text-green-600 text-lg">check_circle</span>
        <p class="text-sm text-green-800 font-medium">Configured — {{ai_provider_name}}, model: {{ai_model}}</p>
        </div>
        {{/if}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div>
            <label class="cms-label">Provider</label>
            <select name="ai_provider" class="cms-input">
                <option value="openai" {{#if ai_provider_openai}}selected{{/if}}>OpenAI (GPT-5.4)</option>
                <option value="anthropic" {{#if ai_provider_anthropic}}selected{{/if}}>Anthropic (Claude 4.6)</option>
                <option value="google" {{#if ai_provider_google}}selected{{/if}}>Google (Gemini 3)</option>
            </select>
        </div>
        <div>
            <label class="cms-label">API Key</label>
            <input type="password" name="ai_api_key"
                   placeholder="{{#if ai_has_key}}Leave blank to keep current{{else}}Enter API key{{/if}}"
                   class="cms-input" autocomplete="new-password">
        </div>
        <div>
            <label class="cms-label">Model <span class="text-gray-400 font-normal">(optional)</span></label>
            <input type="text" name="ai_model" value="{{ai_model}}"
                   placeholder="Leave blank for provider default"
                   class="cms-input">
        </div>
        </div>
    </div>

    <!-- Email -->
    <div class="cms-card">
        <h2 class="cms-card-header">
        <span class="material-symbols-outlined text-[#135bec]">mail</span>
        Email
        </h2>
        <p class="text-sm text-gray-500 dark:text-slate-400 mb-4">Used for contact form submissions and MFA codes. Leave all fields empty to fall back to PHP's <code class="bg-gray-100 dark:bg-slate-700 px-1 rounded text-xs">mail()</code>.</p>
        <div class="space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="cms-label">Driver</label>
                <select name="email_driver" class="cms-input">
                    <option value="smtp" {{#if email_driver_smtp}}selected{{/if}}>SMTP</option>
                    <option value="mailgun" {{#if email_driver_mailgun}}selected{{/if}}>Mailgun</option>
                    <option value="sendgrid" {{#if email_driver_sendgrid}}selected{{/if}}>SendGrid</option>
                    <option value="postmark" {{#if email_driver_postmark}}selected{{/if}}>Postmark</option>
                </select>
            </div>
            <div>
                <label class="cms-label">From Email</label>
                <input type="email" name="smtp_from" value="{{smtp_from}}"
                       placeholder="noreply@example.com" class="cms-input">
            </div>
        </div>

        <!-- SMTP -->
        <div class="p-4 bg-gray-50 dark:bg-slate-800/60 rounded-lg border border-gray-200 dark:border-slate-700">
            <p class="text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wider mb-3">SMTP</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="cms-label">Host</label>
                    <input type="text" name="smtp_host" value="{{smtp_host}}"
                           placeholder="smtp.example.com" class="cms-input">
                </div>
                <div>
                    <label class="cms-label">Port</label>
                    <input type="number" name="smtp_port" value="{{smtp_port}}"
                           placeholder="587" class="cms-input">
                </div>
                <div>
                    <label class="cms-label">Username</label>
                    <input type="text" name="smtp_user" value="{{smtp_user}}" class="cms-input">
                </div>
                <div>
                    <label class="cms-label">Password</label>
                    <input type="password" name="smtp_pass"
                           placeholder="Leave blank to keep current"
                           class="cms-input" autocomplete="new-password">
                </div>
            </div>
        </div>

        <!-- HTTP API Keys -->
        <div class="p-4 bg-gray-50 dark:bg-slate-800/60 rounded-lg border border-gray-200 dark:border-slate-700">
            <p class="text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wider mb-3">HTTP API Keys</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="cms-label">Mailgun API Key</label>
                    <input type="password" name="mailgun_api_key"
                           placeholder="Leave blank to keep current"
                           class="cms-input" autocomplete="new-password">
                </div>
                <div>
                    <label class="cms-label">Mailgun Domain</label>
                    <input type="text" name="mailgun_domain" value="{{mailgun_domain}}"
                           placeholder="mg.example.com" class="cms-input">
                </div>
                <div>
                    <label class="cms-label">SendGrid API Key</label>
                    <input type="password" name="sendgrid_api_key"
                           placeholder="Leave blank to keep current"
                           class="cms-input" autocomplete="new-password">
                </div>
                <div>
                    <label class="cms-label">Postmark Server Token</label>
                    <input type="password" name="postmark_api_key"
                           placeholder="Leave blank to keep current"
                           class="cms-input" autocomplete="new-password">
                </div>
            </div>
        </div>
        </div>
    </div>

    <!-- General -->
    <div class="cms-card">
        <h2 class="cms-card-header">
        <span class="material-symbols-outlined text-[#135bec]">settings</span>
        General
        </h2>
        <div class="max-w-sm">
        <label class="cms-label">Contact Email</label>
        <p class="text-xs text-gray-500 mb-2">Where contact form submissions are sent. Defaults to From Email if empty.</p>
        <input type="email" name="contact_email" value="{{contact_email}}"
               placeholder="hello@example.com" class="cms-input">
        </div>
    </div>

    <!-- Security -->
    <div class="cms-card">
        <h2 class="cms-card-header">
        <span class="material-symbols-outlined text-[#135bec]">security</span>
        Security
        </h2>
        <label class="flex items-start gap-3 cursor-pointer select-none">
            <input type="checkbox" name="mfa_enabled" value="1" class="mt-0.5 h-4 w-4 rounded border-gray-300 text-[#135bec]" {{#if mfa_enabled}}checked{{/if}}>
            <div>
                <span class="cms-label mb-0 cursor-pointer">Enable Email MFA</span>
                <p class="text-xs text-gray-500 mt-0.5">Require a one-time code sent by email on every login. Email must be configured above for this to work.</p>
            </div>
        </label>
    </div>

    <!-- Blog -->
    <div class="cms-card">
        <h2 class="cms-card-header">
        <span class="material-symbols-outlined text-[#135bec]">rss_feed</span>
        Blog
        </h2>
        <div class="space-y-4">
        <label class="flex items-center gap-3 cursor-pointer">
            <input type="hidden" name="blog_enabled" value="0">
            <input type="checkbox" name="blog_enabled" value="1" {{#if blog_enabled}}checked{{/if}}
                   class="w-4 h-4 accent-[#135bec] cursor-pointer">
            <span class="text-sm font-medium text-slate-700">Enable Blog</span>
            <span class="text-xs text-slate-500">— publishes <code>/blog</code> routes and sidebar navigation</span>
        </label>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="cms-label">Blog Title</label>
                <input type="text" name="blog_title" value="{{blog_title}}" placeholder="Blog"
                       class="cms-input">
            </div>
            <div>
                <label class="cms-label">Posts Per Page</label>
                <input type="number" name="blog_posts_per_page" value="{{blog_posts_per_page}}" min="1" max="100"
                       class="cms-input">
            </div>
            <div class="md:col-span-2">
                <label class="cms-label">Blog Description</label>
                <input type="text" name="blog_description" value="{{blog_description}}" placeholder="Latest articles and updates"
                       class="cms-input">
            </div>
        </div>
        </div>
    </div>

    <button type="submit" class="cms-btn">
        <span class="material-symbols-outlined text-xl">save</span>
        Save Settings
    </button>
</form>
HTML,

        // ─── ADMIN: USERS LIST ───────────────────────────────────────
        'admin/users' => <<<'HTML'
<!-- Page Header -->
<div class="cms-page-header">
    <div>
        <h1 class="cms-page-title">Users</h1>
        <p class="cms-page-subtitle">Manage user accounts and permissions.</p>
    </div>
    <a href="/admin/users/new" class="cms-btn">
        <span class="material-symbols-outlined text-xl">person_add</span>
        Add User
    </a>
</div>

<div class="cms-table-wrap">
    <div class="overflow-x-auto">
        <table class="w-full">
        <thead>
            <tr class="bg-gray-50 border-b border-gray-200">
                <th class="text-left px-6 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">User</th>
                <th class="text-left px-6 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Role</th>
                <th class="text-left px-6 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Created</th>
                <th class="text-left px-6 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Last Login</th>
                <th class="text-right px-6 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            {{#each users}}
            <tr class="hover:bg-gray-50 transition-colors">
                <td class="px-6 py-4">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full bg-[#135bec]/10 flex items-center justify-center">
                            <span class="material-symbols-outlined text-[#135bec]">person</span>
                        </div>
                        <span class="font-medium text-gray-900">{{email}}</span>
                    </div>
                </td>
                <td class="px-6 py-4">
                    {{#if is_admin}}
                    <span class="cms-badge cms-badge-admin">Admin</span>
                    {{/if}}
                    {{#if is_editor}}
                    <span class="cms-badge cms-badge-editor">Editor</span>
                    {{/if}}
                    {{#if is_viewer}}
                    <span class="cms-badge cms-badge-viewer">Viewer</span>
                    {{/if}}
                </td>
                <td class="px-6 py-4 text-sm text-gray-500">{{created_at}}</td>
                <td class="px-6 py-4 text-sm text-gray-500">{{last_login}}</td>
                <td class="px-6 py-4">
                    <div class="flex items-center justify-end gap-2">
                        <a href="/admin/users/{{id}}" class="cms-btn-action">
                            <span class="material-symbols-outlined text-lg">edit</span>
                            Edit
                        </a>
                        {{#if can_delete}}
                        <form method="post" action="/admin/users/{{id}}/delete" class="inline" data-confirm="Delete this user?">
                            {{{csrf_field}}}
                            <button type="submit" class="cms-btn-danger">
                                <span class="material-symbols-outlined text-lg">delete</span>
                                Delete
                            </button>
                        </form>
                        {{/if}}
                    </div>
                </td>
            </tr>
            {{/each}}
        </tbody>
        </table>
    </div>
</div>
HTML,

        // ─── ADMIN: USER EDIT ────────────────────────────────────────
        'admin/user_edit' => <<<'HTML'
<div class="cms-page-header mb-6">
    <div class="flex items-center gap-3">
        <a href="/admin/users" class="cms-back">
        <span class="material-symbols-outlined">arrow_back</span>
        </a>
        <div>
        <h1 class="text-2xl font-bold text-gray-900">{{#if is_new}}Add User{{else}}Edit User{{/if}}</h1>
        <p class="text-gray-500 mt-0.5 text-sm">{{#if is_new}}Create a new user account.{{else}}Update user details and permissions.{{/if}}</p>
        </div>
    </div>
</div>

<form method="post" action="/admin/users/{{id}}" class="space-y-6">
    {{{csrf_field}}}

    <div class="cms-card">
        <h2 class="cms-card-header">
        <span class="material-symbols-outlined text-[#135bec]">person</span>
        Account Details
        </h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
            <label class="cms-label">Name</label>
            <input type="text" name="name" value="{{name}}" maxlength="100"
                   class="cms-input" placeholder="First name or display name">
            <p class="cms-hint">Shown as author on blog posts</p>
        </div>
        <div>
            <label class="cms-label">Email *</label>
            <input type="email" name="email" value="{{email}}" required
                   class="cms-input" placeholder="user@example.com">
        </div>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
        <div>
            <label class="cms-label">Role *</label>
            <select name="role" class="cms-input">
                <option value="admin" {{#if is_admin}}selected{{/if}}>Admin — Full access</option>
                <option value="editor" {{#if is_editor}}selected{{/if}}>Editor — Manage content</option>
                <option value="viewer" {{#if is_viewer}}selected{{/if}}>Viewer — Read only</option>
            </select>
        </div>
        </div>
    </div>

    <div class="cms-card">
        <h2 class="cms-card-header">
        <span class="material-symbols-outlined text-[#135bec]">lock</span>
        Password
        </h2>
        <p class="text-sm text-slate-500 mb-4">{{#if is_new}}Set a strong password for this account.{{else}}Leave blank to keep the current password.{{/if}}</p>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
            <label class="cms-label">{{#if is_new}}Password *{{else}}New Password{{/if}}</label>
            <input type="password" name="password" minlength="12" {{#if is_new}}required{{/if}}
                   class="cms-input" autocomplete="new-password">
            <p class="cms-hint">Minimum 12 characters</p>
        </div>
        <div>
            <label class="cms-label">Confirm Password</label>
            <input type="password" name="password_confirm"
                   class="cms-input" autocomplete="new-password">
        </div>
        </div>
    </div>

    <div class="flex items-center gap-3">
        <button type="submit" class="cms-btn">
        <span class="material-symbols-outlined text-xl">save</span>
        {{#if is_new}}Create User{{else}}Save Changes{{/if}}
        </button>
        <a href="/admin/users" class="cms-btn-ghost">Cancel</a>
    </div>
</form>
HTML,

    ];
}
// ─────────────────────────────────────────────────────────────────────────────
// SECTION 13: AI AND APPROVAL TEMPLATES
// ─────────────────────────────────────────────────────────────────────────────

function _tpl_ai(): array {
    return [

        'admin_ai' => <<<'HTML'
<!-- Page Header -->
<div class="cms-page-header">
    <div>
        <h1 class="cms-page-title">AI Site Generator</h1>
        <p class="cms-page-subtitle">Describe your vision and the AI will design a fully custom website — no templates, no constraints.</p>
    </div>
    <a href="/admin/ai/logs" class="cms-btn-ghost text-sm flex items-center gap-1">
        <span class="material-symbols-outlined text-base">query_stats</span> View Logs
    </a>
</div>

{{#if is_configured}}
<!-- AI Status Banner -->
<div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-xl flex items-center justify-between">
    <div class="flex items-center gap-3">
        <span class="material-symbols-outlined text-green-600">check_circle</span>
        <div>
        <p class="font-medium text-green-800">AI is configured and ready</p>
        <p class="text-sm text-green-700">Provider: {{current_provider_name}} &bull; Model: {{current_model}}</p>
        </div>
    </div>
    <a href="/admin/settings" class="flex items-center gap-2 border border-green-200 text-green-700 px-4 py-2 rounded-lg text-sm font-semibold whitespace-nowrap hover:bg-green-100 transition-colors">
        Change in Settings →
    </a>
</div>

<form method="post" action="/admin/ai/generate" class="space-y-6" id="ai-generate-form">
    {{{csrf_field}}}

    <!-- Your Project -->
    <div class="cms-card">
        <h2 class="cms-card-header">
        <span class="material-symbols-outlined text-[#135bec]">edit_note</span>
        Your Project
        </h2>
        <div class="space-y-4">
        <div>
            <label class="cms-label">Project or business name *</label>
            <input type="text" name="business_name" required
                   placeholder="e.g., Luminary Studio, Peak Performance Coaching, The Corner Bakery"
                   value="{{brief_business_name}}"
                   class="cms-input">
        </div>
        <div>
            <label class="cms-label">Tell us about it — what it is, who it's for, and what makes it special *</label>
            <textarea name="description" rows="5" required
                      placeholder="Be as specific as you like. The more you share, the more tailored your site will be. For example: 'We&#39;re a boutique architecture firm specialising in passive-house residential design for eco-conscious families in the Pacific Northwest. We believe great homes should be beautiful, efficient, and built to last generations.'"
                      class="cms-input">{{brief_description}}</textarea>
            <p class="cms-hint">This is the most important field — it drives the AI's entire creative direction.</p>
        </div>
        <div>
            <label class="cms-label">Who are your visitors and what do they care about?</label>
            <input type="text" name="target_audience"
                   placeholder="e.g., First-time homeowners aged 30–50 who care about sustainability and quality craftsmanship"
                   value="{{brief_target_audience}}"
                   class="cms-input">
        </div>
        </div>
    </div>

    <!-- Vision & Style -->
    <div class="cms-card">
        <h2 class="cms-card-header">
        <span class="material-symbols-outlined text-[#135bec]">palette</span>
        Vision &amp; Style
        </h2>
        <div class="space-y-4">
        <div>
            <label class="cms-label">How should the site feel? Describe the visual personality</label>
            <input type="text" name="visual_style"
                   placeholder="e.g., Warm and handcrafted · Bold and energetic · Clean and minimal · Dark and sophisticated · Bright and playful"
                   value="{{brief_visual_style}}"
                   class="cms-input">
            <p class="cms-hint">Use your own words — the AI will interpret your intent creatively.</p>
        </div>
        <div>
            <label class="cms-label">Any color direction in mind?</label>
            <input type="text" name="color_preference"
                   placeholder="e.g., Deep forest greens with gold accents · Monochrome with a single pop of coral · Whatever feels right for a law firm"
                   value="{{brief_color_preference}}"
                   class="cms-input">
        </div>
        <div>
            <label class="cms-label">Any websites or brands you admire, or want to look different from?</label>
            <input type="text" name="design_inspiration"
                   placeholder="e.g., Linear.app for the clean aesthetic, but warmer · Nothing like typical corporate consultant sites"
                   value="{{brief_design_inspiration}}"
                   class="cms-input">
        </div>
        </div>
    </div>

    <!-- Content & Structure -->
    <div class="cms-card">
        <h2 class="cms-card-header">
        <span class="material-symbols-outlined text-[#135bec]">view_quilt</span>
        Content &amp; Structure
        </h2>
        <div class="space-y-4">
        <div>
            <label class="cms-label">What pages do you need?</label>
            <input type="text" name="pages_needed"
                   placeholder="e.g., Home, Services, About us, Case studies, Contact · or just: Home + Contact and let AI decide the rest"
                   value="{{brief_pages_needed}}"
                   class="cms-input">
        </div>
        <div>
            <label class="cms-label">Any specific features or sections?</label>
            <input type="text" name="features"
                   placeholder="e.g., A team section, pricing table, FAQ, portfolio gallery, newsletter signup, contact form"
                   value="{{brief_features}}"
                   class="cms-input">
        </div>
        <div>
            <label class="cms-label">Existing copy &amp; verified facts</label>
            <textarea name="user_content" rows="5"
                      placeholder="Real filenames, commands, URLs, version requirements, bios, copy — anything that must appear verbatim. The AI treats this as ground truth; details not listed here may be invented."
                      class="cms-input">{{brief_user_content}}</textarea>
        </div>
        </div>
    </div>

    <!-- Actions -->
    <div class="flex items-center gap-3">
        <button type="submit" id="ai-generate-btn" class="cms-btn">
        <span class="material-symbols-outlined text-xl">auto_awesome</span>
        Generate My Site
        </button>
        <a href="/admin/ai/chat" class="cms-btn-ghost">
        <span class="material-symbols-outlined text-xl">chat</span>
        Use chat wizard instead
        </a>
        <a href="/admin" class="cms-btn-ghost">Cancel</a>
    </div>
</form>

<!-- AI Streaming Progress Modal -->
<div id="ai-loading-modal" class="hidden fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center">
    <div class="bg-white rounded-2xl shadow-2xl p-8 max-w-lg w-full mx-4">
        <div class="text-center mb-6">
        <div class="relative w-16 h-16 mx-auto mb-4">
            <svg class="animate-spin w-16 h-16" viewBox="0 0 50 50">
                <circle class="opacity-20" cx="25" cy="25" r="20" stroke="#135bec" stroke-width="4" fill="none"/>
                <circle cx="25" cy="25" r="20" stroke="#135bec" stroke-width="4" fill="none"
                        stroke-linecap="round" stroke-dasharray="80, 200" stroke-dashoffset="0"/>
            </svg>
            <div class="absolute inset-0 flex items-center justify-center">
                <span class="material-symbols-outlined text-2xl text-[#135bec] animate-pulse">auto_awesome</span>
            </div>
        </div>
        <h3 class="text-xl font-bold text-gray-900">Building Your Website</h3>
        <p class="text-sm text-gray-500 mt-1">AI is working in multiple passes for best results</p>
        </div>

        <!-- Stage progress -->
        <div class="space-y-3">
        <div id="stage-1" class="flex items-center gap-3 p-3 rounded-lg bg-gray-50 transition-colors">
            <span id="stage-1-icon" class="material-symbols-outlined text-gray-400 text-xl w-6 text-center">radio_button_unchecked</span>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-gray-700">Site Structure</p>
                <p class="text-xs text-gray-500">Planning pages, colors &amp; navigation</p>
            </div>
        </div>
        <div id="stage-2" class="flex items-center gap-3 p-3 rounded-lg bg-gray-50 transition-colors">
            <span id="stage-2-icon" class="material-symbols-outlined text-gray-400 text-xl w-6 text-center">radio_button_unchecked</span>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-gray-700">Page Content</p>
                <p id="stage-2-detail" class="text-xs text-gray-500">Writing content for each page</p>
            </div>
        </div>
        <div id="stage-3" class="flex items-center gap-3 p-3 rounded-lg bg-gray-50 transition-colors">
            <span id="stage-3-icon" class="material-symbols-outlined text-gray-400 text-xl w-6 text-center">radio_button_unchecked</span>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-gray-700">Final Assembly</p>
                <p class="text-xs text-gray-500">Putting it all together</p>
            </div>
        </div>
        </div>

        <!-- Status line -->
        <p id="ai-status-text" class="text-xs text-center text-gray-400 mt-5 flex items-center justify-center gap-2">
        <span class="w-1.5 h-1.5 bg-[#135bec] rounded-full animate-pulse inline-block"></span>
        Starting generation...
        </p>

        <p class="text-xs text-center text-gray-400 mt-3 leading-relaxed">
            This typically takes 2&ndash;10 minutes.<br>
            On some servers, stage progress may not update live &mdash;<br>the page will redirect automatically when complete.
        </p>

        <!-- Error state (hidden by default) -->
        <div id="ai-error-box" class="hidden mt-4 p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700"></div>
    </div>
</div>

<!-- Hidden form to POST completed plan to approvals -->
<form id="ai-plan-form" method="post" action="/admin/approvals" class="hidden">
    {{{csrf_field}}}
    <input type="hidden" name="plan_json" id="ai-plan-json">
    <input type="hidden" name="brief_json" id="ai-brief-json">
</form>

<script src="/js/admin-ai" nonce="{{csp_nonce}}"></script>
{{else}}
<div class="cms-card text-center py-12">
    <div class="flex flex-col items-center gap-4">
        <div class="w-16 h-16 rounded-full bg-amber-100 flex items-center justify-center">
        <span class="material-symbols-outlined text-amber-600 text-3xl">key</span>
        </div>
        <div>
        <h3 class="text-lg font-semibold text-gray-900">AI Not Configured</h3>
        <p class="text-sm text-gray-500 mt-1">Add your API key in Settings to start generating websites.</p>
        </div>
        <a href="/admin/settings" class="cms-btn">
        <span class="material-symbols-outlined text-xl">tune</span>
        Go to Settings
        </a>
    </div>
</div>
{{/if}}
HTML,

        'admin_approvals' => <<<'HTML'
<!-- Page Header -->
<div class="mb-8">
    <h1 class="text-2xl font-bold text-gray-900 dark:text-slate-100">Approvals Queue</h1>
    <p class="text-gray-600 dark:text-slate-400 mt-1">Review and approve AI-generated site plans before applying them.</p>
</div>

<div class="grid grid-cols-1 gap-6">
    <!-- Pending Approval -->
    <div class="cms-table-wrap">
        <div class="p-4 border-b border-gray-200 dark:border-white/10 bg-amber-50 dark:bg-amber-900/20">
        <h2 class="font-semibold text-gray-900 dark:text-slate-100 flex items-center gap-2">
            <span class="material-symbols-outlined text-amber-600 dark:text-amber-400">pending</span>
            Pending Approval
        </h2>
        </div>
        <div class="divide-y divide-gray-100 dark:divide-white/5">
        {{#if has_pending}}
        {{#each pending}}
        <div class="p-4 flex items-center justify-between hover:bg-gray-50 dark:hover:bg-white/5">
            <div class="flex items-center gap-4">
                <div class="w-10 h-10 rounded-lg bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center">
                    <span class="material-symbols-outlined text-amber-600 dark:text-amber-400">description</span>
                </div>
                <div>
                    <p class="font-medium text-gray-900 dark:text-slate-100">Site Plan #{{id}}</p>
                    <p class="text-sm text-gray-500 dark:text-slate-400">Created: {{created_at}}</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <span class="px-2 py-1 text-xs font-medium bg-amber-100 dark:bg-amber-900/30 text-amber-800 dark:text-amber-300 rounded-full">Pending</span>
                {{#if has_brief}}
                <a href="/admin/ai?from={{id}}"
                   class="flex items-center gap-2 px-4 py-2 text-sm font-semibold text-[#135bec] hover:bg-[#135bec]/5 rounded-lg transition-colors"
                   title="Re-run generation with saved brief">
                    <span class="material-symbols-outlined text-sm">refresh</span> Rebuild
                </a>
                {{/if}}
                <a href="/admin/approvals/{{id}}"
                   class="flex items-center gap-2 px-4 py-2 text-sm font-semibold text-[#135bec] hover:bg-[#135bec]/5 rounded-lg transition-colors">
                    Review
                </a>
            </div>
        </div>
        {{/each}}
        {{else}}
        <div class="p-8 text-center text-gray-500 dark:text-slate-400">
            <span class="material-symbols-outlined text-4xl text-gray-300 dark:text-slate-600 mb-2">inbox</span>
            <p>No pending approvals.</p>
            <a href="/admin/ai" class="text-[#135bec] hover:underline text-sm">Generate a new site plan</a>
        </div>
        {{/if}}
        </div>
    </div>

    <!-- Approved -->
    <div class="cms-table-wrap">
        <div class="p-4 border-b border-gray-200 dark:border-white/10 bg-green-50 dark:bg-green-900/20">
        <h2 class="font-semibold text-gray-900 dark:text-slate-100 flex items-center gap-2">
            <span class="material-symbols-outlined text-green-600 dark:text-green-400">check_circle</span>
            Approved (Ready to Apply)
        </h2>
        </div>
        <div class="divide-y divide-gray-100 dark:divide-white/5">
        {{#if has_approved}}
        {{#each approved}}
        <div class="p-4 flex items-center justify-between hover:bg-gray-50 dark:hover:bg-white/5">
            <div class="flex items-center gap-4">
                <div class="w-10 h-10 rounded-lg bg-green-100 dark:bg-green-900/30 flex items-center justify-center">
                    <span class="material-symbols-outlined text-green-600 dark:text-green-400">task_alt</span>
                </div>
                <div>
                    <p class="font-medium text-gray-900 dark:text-slate-100">Site Plan #{{id}}</p>
                    <p class="text-sm text-gray-500 dark:text-slate-400">Approved: {{approved_at}}</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <span class="px-2 py-1 text-xs font-medium bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300 rounded-full">Approved</span>
                {{#if has_brief}}
                <a href="/admin/ai?from={{id}}"
                   class="flex items-center gap-2 px-4 py-2 text-sm font-semibold text-[#135bec] hover:bg-[#135bec]/5 rounded-lg transition-colors"
                   title="Re-run generation with saved brief">
                    <span class="material-symbols-outlined text-sm">refresh</span> Rebuild
                </a>
                {{/if}}
                <a href="/admin/approvals/{{id}}"
                   class="flex items-center gap-2 px-4 py-2 text-sm font-semibold text-gray-600 dark:text-slate-300 hover:bg-gray-100 dark:hover:bg-white/5 rounded-lg transition-colors">
                    View
                </a>
                <form method="post" action="/admin/approvals/{{id}}/apply" class="inline" data-confirm="Apply this site plan? This will update your pages, navigation, and theme.">
                    {{{csrf_field}}}
                    <button type="submit"
                            class="flex items-center gap-2 bg-green-600 text-white px-4 py-2 rounded-lg text-sm font-semibold whitespace-nowrap hover:bg-green-700 transition-colors">
                        Apply
                    </button>
                </form>
            </div>
        </div>
        {{/each}}
        {{else}}
        <div class="p-6 text-center text-gray-500 dark:text-slate-400 text-sm">
            No approved plans waiting to be applied.
        </div>
        {{/if}}
        </div>
    </div>

    <!-- Applied -->
    <div class="cms-table-wrap">
        <div class="p-4 border-b border-gray-200 dark:border-white/10 bg-[#135bec]/5 dark:bg-[#135bec]/10">
        <h2 class="font-semibold text-gray-900 dark:text-slate-100 flex items-center gap-2">
            <span class="material-symbols-outlined text-[#135bec]">rocket_launch</span>
            Recently Applied
        </h2>
        </div>
        <div class="divide-y divide-gray-100 dark:divide-white/5">
        {{#if has_applied}}
        {{#each applied}}
        <div class="p-4 flex items-center justify-between {{#if is_active}}border-l-4 border-[#135bec] bg-[#135bec]/5 dark:bg-[#135bec]/10{{else}}hover:bg-gray-50 dark:hover:bg-white/5{{/if}}">
            <div class="flex items-center gap-4">
                <div class="w-10 h-10 rounded-lg bg-[#135bec]/10 dark:bg-[#135bec]/20 flex items-center justify-center">
                    <span class="material-symbols-outlined text-[#135bec]">check_circle</span>
                </div>
                <div>
                    <p class="font-medium text-gray-900 dark:text-slate-100 flex items-center gap-2">
                        Site Plan #{{id}}
                        {{#if is_active}}<span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-semibold bg-[#135bec] text-white rounded-full"><span class="material-symbols-outlined" style="font-size:11px">wifi_tethering</span>Currently Live</span>{{/if}}
                    </p>
                    <p class="text-sm text-gray-500 dark:text-slate-400">Applied: {{applied_at}}</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <span class="px-2 py-1 text-xs font-medium bg-[#135bec]/10 dark:bg-[#135bec]/20 text-[#135bec] rounded-full">Applied</span>
                {{#if has_brief}}
                <a href="/admin/ai?from={{id}}"
                   class="flex items-center gap-2 px-4 py-2 text-sm font-semibold text-[#135bec] hover:bg-[#135bec]/5 rounded-lg transition-colors"
                   title="Re-run generation with saved brief">
                    <span class="material-symbols-outlined text-sm">refresh</span> Rebuild
                </a>
                {{/if}}
                <a href="/admin/approvals/{{id}}"
                   class="flex items-center gap-2 px-4 py-2 text-sm font-semibold text-gray-600 dark:text-slate-300 hover:bg-gray-100 dark:hover:bg-white/5 rounded-lg transition-colors">
                    View
                </a>
            </div>
        </div>
        {{/each}}
        {{else}}
        <div class="p-6 text-center text-gray-500 dark:text-slate-400 text-sm">
            No plans have been applied yet.
        </div>
        {{/if}}
        </div>
    </div>
</div>
HTML,

        'admin_approval_view' => <<<'HTML'
<!-- Page Header -->
<div class="mb-6 flex items-center justify-between">
    <div>
        <div class="flex items-center gap-3 mb-2">
        <a href="/admin/approvals" class="text-gray-500 hover:text-gray-700">
            <span class="material-symbols-outlined">arrow_back</span>
        </a>
        <h1 class="text-2xl font-bold text-gray-900">Site Plan #{{item.id}}</h1>
        {{#if item.is_pending}}
        <span class="px-2 py-1 text-xs font-medium bg-amber-100 text-amber-800 rounded-full">Pending</span>
        {{/if}}
        {{#if item.is_approved}}
        <span class="px-2 py-1 text-xs font-medium bg-green-100 text-green-800 rounded-full">Approved</span>
        {{/if}}
        {{#if item.is_applied}}
        <span class="px-2 py-1 text-xs font-medium bg-blue-100 text-blue-800 rounded-full">Applied</span>
        {{/if}}
        </div>
        <p class="text-gray-600">Created: {{item.created_at}}</p>
    </div>
    <div class="flex items-center gap-2">
        <a href="/admin/approvals/{{item.id}}/preview"
           class="flex items-center gap-2 bg-primary text-white px-4 py-2 rounded-lg text-sm font-semibold whitespace-nowrap hover:bg-primary/90 transition-colors">
        <span class="material-symbols-outlined text-xl">preview</span>
        Preview & Edit
        </a>
        {{#if item.is_pending}}
        <form method="post" action="/admin/approvals/{{item.id}}/approve" class="inline">
        {{{csrf_field}}}
        <button type="submit"
                class="flex items-center gap-2 bg-green-600 text-white px-4 py-2 rounded-lg text-sm font-semibold whitespace-nowrap hover:bg-green-700 transition-colors">
            <span class="material-symbols-outlined text-sm">check</span>
            Approve
        </button>
        </form>
        <form method="post" action="/admin/approvals/{{item.id}}/reject" class="inline" data-confirm="Reject this site plan?">
        {{{csrf_field}}}
        <button type="submit"
                class="flex items-center gap-2 bg-red-600 text-white px-4 py-2 rounded-lg text-sm font-semibold whitespace-nowrap hover:bg-red-700 transition-colors">
            <span class="material-symbols-outlined text-sm">close</span>
            Reject
        </button>
        </form>
        {{/if}}
        {{#if item.is_approved}}
        <form method="post" action="/admin/approvals/{{item.id}}/apply" class="inline" data-confirm="Apply this site plan? This will update your pages, navigation, and theme.">
        {{{csrf_field}}}
        <button type="submit"
                class="flex items-center gap-2 bg-primary text-white px-4 py-2 rounded-lg text-sm font-semibold whitespace-nowrap hover:bg-primary/90 transition-colors">
            <span class="material-symbols-outlined text-sm">rocket_launch</span>
            Apply to Site
        </button>
        </form>
        {{/if}}
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Left Column: Overview -->
    <div class="lg:col-span-2 space-y-6">
        <!-- Site Overview -->
        <div class="cms-card">
        <h2 class="cms-card-header">
            <span class="material-symbols-outlined text-[#135bec]">info</span>
            Site Overview
        </h2>
        <div class="space-y-3">
            <div>
                <label class="text-sm text-gray-500">Site Name</label>
                <p class="font-medium text-gray-900">{{plan.site_name}}</p>
            </div>
            <div>
                <label class="text-sm text-gray-500">Tagline</label>
                <p class="text-gray-700">{{plan.tagline}}</p>
            </div>
        </div>
        </div>

        <!-- Navigation -->
        <div class="cms-card">
        <h2 class="cms-card-header">
            <span class="material-symbols-outlined text-[#135bec]">menu</span>
            Navigation
        </h2>
        <div class="space-y-2">
            {{#each plan.nav}}
            <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                <span class="font-medium text-gray-900">{{label}}</span>
                <span class="text-sm text-gray-500">{{url}}</span>
            </div>
            {{/each}}
        </div>
        </div>

        <!-- Pages -->
        <div class="cms-card">
        <h2 class="cms-card-header">
            <span class="material-symbols-outlined text-[#135bec]">description</span>
            Pages ({{pages_count}})
        </h2>
        <div class="space-y-4">
            {{#each plan.pages}}
            <div class="border border-gray-200 rounded-lg overflow-hidden">
                <div class="p-4 bg-gray-50 border-b border-gray-200">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <span class="font-medium text-gray-900">{{title}}</span>
                            <span class="text-sm text-gray-500 ml-2">/{{slug}}</span>
                        </div>
                        {{#if can_regenerate}}
                        <button class="regen-page-btn cms-btn-ghost" style="font-size:.8125rem;padding:.3rem .75rem;gap:.25rem;flex-shrink:0" data-slug="{{slug}}" data-title="{{title}}">
                            <span class="material-symbols-outlined" style="font-size:.9rem;line-height:1">refresh</span>Regenerate
                        </button>
                        {{/if}}
                    </div>
                    {{#if meta_description}}
                    <p class="text-sm text-gray-600 mt-1">{{meta_description}}</p>
                    {{/if}}
                </div>
                <div class="p-4">
                    <div class="space-y-2">
                        {{#each blocks}}
                        <div class="flex items-center gap-2 p-2 bg-blue-50 rounded border-l-4 border-[#135bec]">
                            <span class="material-symbols-outlined text-[#135bec] text-sm">widgets</span>
                            <span class="text-sm font-medium text-gray-700">{{type}}</span>
                        </div>
                        {{/each}}
                    </div>
                </div>
            </div>
            {{/each}}
        </div>
        </div>

        <!-- Footer -->
        {{#if plan.footer}}
        <div class="cms-card">
        <h2 class="cms-card-header">
            <span class="material-symbols-outlined text-[#135bec]">bottom_navigation</span>
            Footer
        </h2>
        <p class="text-gray-700">{{plan.footer.text}}</p>
        </div>
        {{/if}}
    </div>

    <!-- Right Column: Colors & JSON -->
    <div class="space-y-6">
        <!-- Color Scheme -->
        {{#if plan.colors}}
        <div class="cms-card">
        <h2 class="cms-card-header">
            <span class="material-symbols-outlined text-[#135bec]">palette</span>
            Color Scheme
        </h2>
        <div class="space-y-3">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg border border-gray-200" style="background: {{plan.colors.primary}}"></div>
                <div>
                    <p class="text-sm text-gray-500">Primary</p>
                    <p class="font-mono text-sm text-gray-900">{{plan.colors.primary}}</p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg border border-gray-200" style="background: {{plan.colors.secondary}}"></div>
                <div>
                    <p class="text-sm text-gray-500">Secondary</p>
                    <p class="font-mono text-sm text-gray-900">{{plan.colors.secondary}}</p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg border border-gray-200" style="background: {{plan.colors.accent}}"></div>
                <div>
                    <p class="text-sm text-gray-500">Accent</p>
                    <p class="font-mono text-sm text-gray-900">{{plan.colors.accent}}</p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg border border-gray-200" style="background: {{plan.colors.background}}"></div>
                <div>
                    <p class="text-sm text-gray-500">Background</p>
                    <p class="font-mono text-sm text-gray-900">{{plan.colors.background}}</p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg border border-gray-200" style="background: {{plan.colors.text}}"></div>
                <div>
                    <p class="text-sm text-gray-500">Text</p>
                    <p class="font-mono text-sm text-gray-900">{{plan.colors.text}}</p>
                </div>
            </div>
        </div>
        </div>
        {{/if}}

        <!-- Raw JSON -->
        <details class="cms-table-wrap">
        <summary class="p-4 cursor-pointer hover:bg-gray-50 flex items-center gap-2 font-medium text-gray-900">
            <span class="material-symbols-outlined text-gray-500">code</span>
            Raw JSON
        </summary>
        <div class="p-4 border-t border-gray-200 bg-gray-50">
            <pre class="text-xs text-gray-700 overflow-x-auto whitespace-pre-wrap">{{plan_json}}</pre>
        </div>
        </details>
    </div>
</div>
<script nonce="{{csp_nonce}}">
(function(){
    var ITEM_ID = {{item.id}};
    var CSRF = '{{csrf_token}}';
    document.querySelectorAll('.regen-page-btn').forEach(function(btn){
        btn.addEventListener('click', async function(){
            var slug = btn.dataset.slug;
            var title = btn.dataset.title || slug;
            if (!confirm('Regenerate the "' + title + '" page? Its current blocks will be replaced.')) return;
            btn.disabled = true;
            var orig = btn.innerHTML;
            btn.innerHTML = '<span class="material-symbols-outlined" style="font-size:.9rem;line-height:1">hourglass_top</span> Regenerating\u2026';
            try {
                var fd = new FormData();
                fd.append('_csrf', CSRF);
                fd.append('page_slug', slug);
                var resp = await fetch('/admin/approvals/' + ITEM_ID + '/regenerate-page', {method:'POST', body:fd});
                var r = await resp.json();
                if (r.success) {
                    location.reload();
                } else {
                    alert('Failed: ' + (r.error || 'Unknown error'));
                    btn.disabled = false; btn.innerHTML = orig;
                }
            } catch(e) {
                alert('Request failed: ' + e.message);
                btn.disabled = false; btn.innerHTML = orig;
            }
        });
    });
})();
</script>
HTML,

        // ─── APPROVAL PREVIEW WITH EDITABLE BLOCKS ───────────────────
        'admin_approval_preview' => <<<'HTML'
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <title>Preview: {{plan.site_name}} | MonolithCMS</title>
    <meta name="csrf-token" content="{{csrf_token}}">
    <link href="/cdn/bulma.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/cdn/material-icons.css">
    <style>
        :root { color-scheme: light; }
        .editable-block { position: relative; cursor: pointer; transition: all 0.2s; }
        .editable-block:hover { outline: 2px dashed #3b82f6; outline-offset: 4px; }
        .editable-block:hover::before {
        content: 'Click to Edit';
        position: absolute; top: 8px; right: 8px; z-index: 100;
        background: #3b82f6; color: #fff;
        padding: 4px 12px; border-radius: 4px; font-size: 12px; font-weight: 600;
        }
        .preview-toolbar {
        position: fixed; top: 0; left: 0; right: 0; z-index: 1000;
        background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
        padding: 12px 24px; display: flex; align-items: center; gap: 16px;
        }
        .page-tabs { display: flex; gap: 4px; flex-wrap: wrap; }
        .page-tab { padding: 6px 16px; border-radius: 6px; font-size: 13px; cursor: pointer; transition: all 0.2s; color: #94a3b8; background: rgba(255,255,255,0.1); }
        .page-tab:hover { background: rgba(255,255,255,0.2); }
        .page-tab.active { background: #3b82f6; color: white; }
        .page-content { display: none; }
        .page-content.active { display: block; }
        body { padding-top: 70px; }
        .reveal { opacity: 1 !important; transform: none !important; }
        /* Editor form compatibility shim */
        .form-control { margin-bottom: .75rem; }
        .label { display: flex; align-items: center; margin-bottom: .2rem; }
        .label-text { font-size: .8rem; font-weight: 600; color: #555; }
        .input-bordered, .textarea-bordered, .select-bordered { border: 1px solid #dbdbdb; border-radius: 4px; width: 100%; padding: .4rem .6rem; font-size: .875rem; }
        .input-sm, .textarea-sm, .select-sm { font-size: .8rem; padding: .25rem .5rem; }
        .btn { display: inline-flex; align-items: center; gap: .3rem; border-radius: 4px; border: none; padding: .4rem .8rem; cursor: pointer; font-size: .875rem; }
        .btn-primary { background: var(--color-primary, #3b82f6); color: #fff; }
        .btn-outline { background: transparent; border: 1px solid #dbdbdb; }
        .btn-ghost { background: transparent; }
        .btn-xs, .btn-sm { font-size: .8rem; padding: .25rem .5rem; }
        .text-error { color: #f00; }
        .card.bg-base-200 { background: #f5f5f5; border-radius: .5rem; }
        .grid { display: grid; }
        .grid-cols-2 { grid-template-columns: 1fr 1fr; }
        .gap-2 { gap: .5rem; }
        .flex-1 { flex: 1; }
        .divider { border-top: 1px solid #dbdbdb; margin: 1rem 0; text-align: center; font-weight: 600; font-size: .85rem; color: #888; }
        .checkbox { margin-right: .4rem; }
        .alert-info { background: #e0f0ff; border-radius: 4px; padding: .75rem; margin-bottom: .75rem; color: #1d4ed8; }
        .font-mono { font-family: monospace; }
        .h-48 { height: 12rem; }
        .w-full { width: 100%; }
    </style>
</head>
<body data-queue-id="{{item.id}}">
    <!-- Preview Toolbar -->
    <div class="preview-toolbar">
        <a href="/admin/approvals/{{item.id}}" style="color:#94a3b8;text-decoration:none;font-size:.875rem;display:flex;align-items:center;gap:.25rem">
        <span class="material-symbols-outlined" style="font-size:1.1rem">arrow_back</span>
        Back
        </a>
        <span style="color:#fff;font-weight:700">Preview: {{plan.site_name}}</span>
        <div class="page-tabs" style="margin-left:1rem" id="pageTabs"></div>
        <div style="flex:1"></div>
        <span style="color:#94a3b8;font-size:.8rem">Click any block to edit</span>
        {{#if item.is_pending}}
        <form method="post" action="/admin/approvals/{{item.id}}/approve" class="inline">
        <input type="hidden" name="_csrf" value="{{csrf_token}}">
        <button type="submit" class="button is-success is-small">Approve &amp; Continue</button>
        </form>
        {{/if}}
        {{#if item.is_approved}}
        <form method="post" action="/admin/approvals/{{item.id}}/apply" class="inline">
        <input type="hidden" name="_csrf" value="{{csrf_token}}">
        <button type="submit" class="button is-primary is-small">Apply to Site</button>
        </form>
        {{/if}}
    </div>

    <!-- Page Content Areas -->
    <div id="pageContents"></div>

    <!-- Block Editor Modal -->
    <div id="blockEditorModal" class="modal">
      <div class="modal-background" data-action="close-modal"></div>
      <div class="modal-card" style="width:90%;max-width:52rem">
        <header class="modal-card-head">
          <p class="modal-card-title" id="modalTitle">Edit Block</p>
          <button type="button" class="delete" aria-label="close" data-action="close-modal"></button>
        </header>
        <section class="modal-card-body">
          <div id="editorContent"></div>
        </section>
        <footer class="modal-card-foot">
          <button type="button" class="button" data-action="close-modal">Cancel</button>
          <button type="button" class="button is-primary" id="saveBlockBtn">Save Changes</button>
        </footer>
      </div>
    </div>

    <!-- Image Gallery Modal -->
    {{{media_picker_html}}}

    <!-- Hidden data -->
    <script id="planData" type="application/json">{{{plan_json}}}</script>
    <script src="/js/admin-global" nonce="{{csp_nonce}}"></script>
    <script src="/js/approval" nonce="{{csp_nonce}}"></script>
</body>
</html>
HTML,

        // ─── AI CHAT WIZARD ──────────────────────────────────────────────
        'admin_ai_chat' => <<<'HTML'
<div class="max-w-3xl mx-auto">
    <div class="mb-6 flex items-center justify-between">
        <div>
        <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
            <span class="material-symbols-outlined text-[#135bec]">forum</span>
            AI Chat Wizard
        </h1>
        <p class="text-gray-500 text-sm mt-1">Describe your business in your own words — AI will ask questions and build your site.</p>
        </div>
        <div class="flex gap-2">
        <a href="/admin/ai" class="flex items-center gap-2 border border-gray-200 text-gray-600 px-4 py-2 rounded-lg text-sm font-semibold whitespace-nowrap hover:bg-gray-50 transition-colors">
            <span class="material-symbols-outlined text-sm">auto_awesome</span> Form Mode
        </a>
        <button id="reset-btn" class="flex items-center gap-2 border border-red-200 text-red-600 px-4 py-2 rounded-lg text-sm font-semibold whitespace-nowrap hover:bg-red-50 transition-colors">
            <span class="material-symbols-outlined text-sm">refresh</span> Start Over
        </button>
        </div>
    </div>

    {{#if is_configured}}
    <!-- Chat Window -->
    <div class="bg-white rounded-2xl border border-gray-200 flex flex-col" style="height: 60vh; min-height: 420px;">
        <div class="flex-1 overflow-y-auto p-6 space-y-4" id="chat-messages">
        {{#if history}}
        {{#each history}}
        <div class="flex {{#if role_is_user}}justify-end{{else}}justify-start{{/if}}">
            <div class="max-w-[80%] px-4 py-2.5 rounded-2xl text-sm leading-relaxed whitespace-pre-wrap
                {{#if role_is_user}}bg-[#135bec] text-white rounded-br-sm{{else}}bg-gray-100 text-gray-800 rounded-bl-sm{{/if}}">
                {{content}}
            </div>
        </div>
        {{/each}}
        {{else}}
        <div class="flex justify-start">
            <div class="max-w-[80%] px-4 py-2.5 rounded-2xl rounded-bl-sm bg-gray-100 text-gray-800 text-sm leading-relaxed">
                👋 What are we building?
            </div>
        </div>
        {{/if}}
        <div id="typing-indicator" class="hidden flex justify-start">
            <div class="px-4 py-3 rounded-2xl rounded-bl-sm bg-gray-100 text-gray-400 text-sm flex gap-1 items-center">
                <span class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay:0ms"></span>
                <span class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay:150ms"></span>
                <span class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay:300ms"></span>
            </div>
        </div>
        </div>

        <!-- Input area -->
        <div class="border-t border-gray-100 p-4">
        <div class="flex gap-3 items-end">
            <textarea id="message-input" rows="2"
                placeholder="Describe your business, answer questions..."
                class="flex-1 px-3 py-2 border border-gray-300 rounded-xl resize-none text-sm focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"></textarea>
            <button id="send-btn"
                class="px-4 py-2 bg-[#135bec] text-white rounded-xl hover:bg-[#0f4fd1] transition-colors flex items-center gap-1 text-sm font-medium self-end">
                <span class="material-symbols-outlined text-sm">send</span>
                Send
            </button>
        </div>
        </div>
    </div>

    <!-- Generate CTA (shown when AI is ready) -->
    <div id="generate-cta" class="hidden mt-4 bg-green-50 border border-green-200 rounded-xl p-5 flex items-center justify-between gap-4">
        <div>
        <p class="font-semibold text-green-800">✅ Ready to build your website!</p>
        <p class="text-sm text-green-700 mt-0.5">AI has gathered enough information. Click to generate your full site.</p>
        </div>
        <button id="generate-btn"
        class="px-5 py-2.5 bg-[#135bec] text-white rounded-xl font-medium text-sm hover:bg-[#0f4fd1] transition-colors flex items-center gap-2 shrink-0">
        <span class="material-symbols-outlined text-sm">rocket_launch</span>
        Generate My Site
        </button>
    </div>

    {{else}}
    <div class="bg-amber-50 border border-amber-200 rounded-xl p-6 text-center">
        <span class="material-symbols-outlined text-amber-500 text-4xl">warning</span>
        <p class="font-semibold text-amber-800 mt-2">AI Not Configured</p>
        <p class="text-sm text-amber-700 mt-1 mb-4">Set up your AI API key to use the chat wizard.</p>
        <a href="/admin/ai" class="flex items-center gap-2 bg-amber-600 text-white px-4 py-2 rounded-lg text-sm font-semibold whitespace-nowrap hover:bg-amber-700 transition-colors">
        <span class="material-symbols-outlined text-sm">settings</span>Configure AI
        </a>
    </div>
    {{/if}}
</div>

<!-- SSE Generation Modal (reused from AI form) -->
<div id="ai-loading-modal" class="hidden fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center">
    <div class="bg-white rounded-2xl shadow-2xl p-8 max-w-lg w-full mx-4">
        <div class="text-center mb-6">
        <div class="relative w-16 h-16 mx-auto mb-4">
            <svg class="animate-spin w-16 h-16" viewBox="0 0 50 50">
                <circle class="opacity-20" cx="25" cy="25" r="20" stroke="#135bec" stroke-width="4" fill="none"/>
                <circle cx="25" cy="25" r="20" stroke="#135bec" stroke-width="4" fill="none" stroke-linecap="round" stroke-dasharray="80, 200" stroke-dashoffset="0"/>
            </svg>
            <div class="absolute inset-0 flex items-center justify-center">
                <span class="material-symbols-outlined text-2xl text-[#135bec] animate-pulse">auto_awesome</span>
            </div>
        </div>
        <h3 class="text-xl font-bold text-gray-900">Building Your Website</h3>
        <p class="text-sm text-gray-500 mt-1">Working in multiple passes for best results</p>
        </div>
        <div class="space-y-3">
        <div id="stage-1" class="flex items-center gap-3 p-3 rounded-lg bg-gray-50">
            <span id="stage-1-icon" class="material-symbols-outlined text-gray-400 text-xl w-6 text-center">radio_button_unchecked</span>
            <div><p class="text-sm font-medium text-gray-700">Site Structure</p><p class="text-xs text-gray-500">Planning pages &amp; colors</p></div>
        </div>
        <div id="stage-2" class="flex items-center gap-3 p-3 rounded-lg bg-gray-50">
            <span id="stage-2-icon" class="material-symbols-outlined text-gray-400 text-xl w-6 text-center">radio_button_unchecked</span>
            <div><p class="text-sm font-medium text-gray-700">Page Content</p><p id="stage-2-detail" class="text-xs text-gray-500">Writing content for each page</p></div>
        </div>
        <div id="stage-3" class="flex items-center gap-3 p-3 rounded-lg bg-gray-50">
            <span id="stage-3-icon" class="material-symbols-outlined text-gray-400 text-xl w-6 text-center">radio_button_unchecked</span>
            <div><p class="text-sm font-medium text-gray-700">Final Assembly</p><p class="text-xs text-gray-500">Putting it all together</p></div>
        </div>
        </div>
        <p id="ai-status-text" class="text-xs text-center text-gray-400 mt-5"></p>
        <p class="text-xs text-center text-gray-400 mt-3 leading-relaxed">
            This typically takes 2&ndash;10 minutes.<br>
            On some servers, stage progress may not update live &mdash;<br>the page will redirect automatically when complete.
        </p>
        <div id="ai-error-box" class="hidden mt-4 p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700"></div>
    </div>
</div>
<form id="ai-plan-form" method="post" action="/admin/approvals" class="hidden">
    <input type="hidden" name="_csrf" value="{{csrf_token}}">
    <input type="hidden" name="plan_json" id="ai-plan-json">
    <input type="hidden" name="brief_json" id="ai-brief-json">
</form>

<script src="/js/admin-ai-chat" nonce="{{csp_nonce}}"></script>
HTML,

        // ─── AI LOGS TEMPLATE ─────────────────────────────────────────────
        'admin_ai_logs' => <<<'HTML'
<div class="max-w-screen-xl mx-auto px-4 py-8">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">AI Request Logs</h1>
            <p class="text-sm text-slate-500 mt-1">Every AI::generate() call — raw responses stored for debugging.</p>
        </div>
        <form method="post" action="/admin/ai/logs/clear" onsubmit="return confirm('Clear all logs?');">
            <input type="hidden" name="_csrf" value="{{csrf_token}}">
            <button class="cms-btn text-sm" style="background:#dc2626;color:#fff;">Clear All Logs</button>
        </form>
    </div>

    {{#if logs}}
    <div class="cms-card overflow-x-auto">
        <table class="w-full text-sm border-collapse">
            <thead>
                <tr class="border-b border-slate-200">
                    <th class="text-left py-2 px-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Time</th>
                    <th class="text-left py-2 px-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">St.</th>
                    <th class="text-left py-2 px-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Page</th>
                    <th class="text-left py-2 px-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Provider / Model</th>
                    <th class="text-right py-2 px-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Prompt</th>
                    <th class="text-right py-2 px-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Response</th>
                    <th class="text-center py-2 px-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Parsed</th>
                    <th class="text-right py-2 px-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">ms</th>
                    <th class="py-2 px-3"></th>
                </tr>
            </thead>
            <tbody>
            {{#each logs}}
                <tr class="border-t border-slate-100 hover:bg-slate-50">
                    <td class="py-2 px-3 text-slate-500 whitespace-nowrap text-xs">{{this.created_at}}</td>
                    <td class="py-2 px-3 font-mono text-xs text-slate-600">S{{this.stage}}</td>
                    <td class="py-2 px-3 font-mono text-xs text-slate-600">{{this.page_slug}}</td>
                    <td class="py-2 px-3 text-slate-700 text-xs">{{this.provider}} <span class="text-slate-400">/ {{this.model}}</span></td>
                    <td class="py-2 px-3 text-right font-mono text-xs text-slate-600">{{this.prompt_length}}</td>
                    <td class="py-2 px-3 text-right font-mono text-xs text-slate-600">{{this.response_length}}</td>
                    <td class="py-2 px-3 text-center text-base">{{#if this.parsed_ok}}<span style="color:#16a34a;font-weight:700">✓</span>{{else}}<span style="color:#dc2626;font-weight:700">✗</span>{{/if}}</td>
                    <td class="py-2 px-3 text-right font-mono text-xs text-slate-600">{{this.duration_ms}}</td>
                    <td class="py-2 px-3"><a href="/admin/ai/logs/{{this.id}}" target="_blank" class="text-xs text-[#135bec] hover:underline">Raw</a></td>
                </tr>
            {{/each}}
            </tbody>
        </table>
    </div>
    {{else}}
    <div class="cms-card text-center py-12 text-slate-500">
        <span class="material-symbols-outlined text-4xl mb-3 block">query_stats</span>
        <p>No logs yet. Run a generation to see AI request logs here.</p>
    </div>
    {{/if}}
</div>
HTML,

        // ─── BLOG PUBLIC TEMPLATES ───────────────────────────────────────
    ];
}
// ─────────────────────────────────────────────────────────────────────────────
// SECTION 14: BLOG TEMPLATES
// ─────────────────────────────────────────────────────────────────────────────

function _tpl_blog(): array {
    return [

        'blog_index' => <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{blog_title}} — {{site_name}}</title>
    <meta name="description" content="{{blog_description}}">
    <link rel="stylesheet" href="/cdn/bulma.min.css">
    <link rel="stylesheet" href="/css?v={{css_hash}}">
</head>
<body>
{{{nav_html}}}
<main class="section">
  <div class="container">
    <div class="mb-6">
      <h1 class="title is-2">{{blog_title}}</h1>
      <p class="has-text-grey">{{blog_description}}</p>
    </div>
    {{#if posts}}
    <div class="columns is-multiline">
        {{#each posts}}
        <div class="column is-one-third-desktop is-half-tablet">
          <article class="card h-100">
        {{#if cover_url}}
        <div class="card-image"><figure class="image is-3by2"><a href="/blog/{{slug}}"><img src="{{cover_url}}" alt="{{title}}"></a></figure></div>
        {{/if}}
        <div class="card-content">
          {{#if category_name}}
          <a href="/blog/category/{{category_slug}}" class="tag mb-2" style="background:var(--p-r);color:var(--p);border:none">{{category_name}}</a>
          {{/if}}
          <h2 class="title is-5 mt-2 mb-2"><a href="/blog/{{slug}}">{{title}}</a></h2>
          <p class="has-text-grey is-size-7">{{excerpt}}</p>
          <p class="has-text-grey-light is-size-7 mt-3">{{author_name}} &middot; {{published_at_fmt}}</p>
        </div>
          </article>
        </div>
        {{/each}}
    </div>
    {{#if has_pagination}}
    <nav class="pagination is-centered mt-6" role="navigation">
      {{#if prev_page}}<a href="/blog?page={{prev_page}}" class="pagination-previous">&larr; Previous</a>{{/if}}
      {{#if next_page}}<a href="/blog?page={{next_page}}" class="pagination-next">Next &rarr;</a>{{/if}}
      <div class="pagination-list"><span class="has-text-grey">Page {{current_page}} of {{total_pages}}</span></div>
    </nav>
    {{/if}}
    {{else}}
    <div class="has-text-centered py-6 has-text-grey">
      <p class="is-size-4">No posts published yet.</p>
    </div>
    {{/if}}
  </div>
</main>
{{{footer_html}}}
</body></html>
HTML,

        'blog_post' => <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{post_title}} — {{site_name}}</title>
    <meta name="description" content="{{meta_description}}">
    {{#if og_image_url}}<meta property="og:image" content="{{og_image_url}}">{{/if}}
    <link rel="stylesheet" href="/cdn/bulma.min.css">
    <link rel="stylesheet" href="/cdn/quill.snow.css">
    <link rel="stylesheet" href="/css?v={{css_hash}}">
    <style>.ql-editor{padding:0}.content img{max-width:100%;border-radius:.5rem}
    /* Pin heading colours to the theme text colour so Bulma's built-in
       prefers-color-scheme:dark rules can't make them near-white on a
       light-coloured background */
    .content h1,.content h2,.content h3,.content h4,.content h5,.content h6{color:var(--color-text)!important;}</style>
</head>
<body>
{{{nav_html}}}
<main class="section">
  <div class="container" style="max-width:48rem">
    {{#if category_name}}
    <a href="/blog/category/{{category_slug}}" class="tag mb-3" style="background:var(--p-r);color:var(--p);border:none">{{category_name}}</a>
    {{/if}}
    <h1 class="title is-2 mt-3">{{post_title}}</h1>
    <div class="is-flex is-align-items-center" style="gap:.75rem;flex-wrap:wrap;margin-bottom:1.5rem">
      <span class="has-text-grey-dark">{{author_name}}</span>
      <span class="has-text-grey">&middot;</span>
      <span class="has-text-grey">{{published_at_fmt}}</span>
      {{#if tags}}
      <span class="has-text-grey">&middot;</span>
      {{#each tags}}<a href="/blog/tag/{{slug}}" class="tag" style="background:var(--bg-alt);color:var(--tx);border:1px solid var(--bd)">{{name}}</a>{{/each}}
      {{/if}}
    </div>
    {{#if cover_url}}
    <figure class="image mb-6" style="max-height:24rem;overflow:hidden;border-radius:.5rem">
      <img src="{{cover_url}}" alt="{{post_title}}" style="width:100%;height:100%;object-fit:cover">
    </figure>
    {{/if}}
    <div class="content is-medium">{{{body_html}}}</div>
    <div class="mt-6 pt-5" style="border-top:1px solid var(--bd);display:flex;justify-content:space-between">
      {{#if prev_post}}<a href="/blog/{{prev_post.slug}}" class="button" style="background:var(--bg-alt);color:var(--tx);border:1px solid var(--bd)">&larr; {{prev_post.title}}</a>{{else}}<span></span>{{/if}}
      {{#if next_post}}<a href="/blog/{{next_post.slug}}" class="button" style="background:var(--bg-alt);color:var(--tx);border:1px solid var(--bd)">{{next_post.title}} &rarr;</a>{{else}}<span></span>{{/if}}
    </div>
  </div>
</main>
{{{footer_html}}}
</body></html>
HTML,

        // ─── BLOG ADMIN TEMPLATES ────────────────────────────────────────
        'admin/blog_posts' => <<<'HTML'
<div class="cms-page-header">
    <div>
        <h1 class="cms-page-title">Blog Posts</h1>
        <p class="cms-page-subtitle">{{total_posts}} post(s) total</p>
    </div>
    <div class="flex gap-2">
        <a href="/admin/blog/posts/new?ai=1" class="cms-btn-ghost cms-btn flex items-center gap-1.5">
            <span class="material-symbols-outlined text-sm">smart_toy</span>Generate with AI
        </a>
        <a href="/admin/blog/posts/new" class="cms-btn">
            <span class="material-symbols-outlined text-sm">add</span>New Post
        </a>
    </div>
</div>

<div class="cms-filter-tabs">
    <a href="/admin/blog/posts" class="cms-filter-tab {{#if filter_all}}active{{/if}}">All</a>
    <a href="/admin/blog/posts?status=published" class="cms-filter-tab {{#if filter_published}}active{{/if}}">Published</a>
    <a href="/admin/blog/posts?status=draft" class="cms-filter-tab {{#if filter_draft}}active{{/if}}">Drafts</a>
</div>

<div class="cms-table-wrap">
    {{#if posts}}
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-200">
        <tr>
            <th class="text-left px-4 py-3 font-medium text-gray-600">Title</th>
            <th class="text-left px-4 py-3 font-medium text-gray-600 hidden md:table-cell">Category</th>
            <th class="text-left px-4 py-3 font-medium text-gray-600">Status</th>
            <th class="text-left px-4 py-3 font-medium text-gray-600 hidden md:table-cell">Date</th>
            <th class="px-4 py-3"></th>
        </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
        {{#each posts}}
        <tr class="hover:bg-gray-50 transition-colors">
            <td class="px-4 py-3">
                <div class="font-medium text-gray-900">{{title}}</div>
                <div class="text-xs text-gray-400">/blog/{{slug}}</div>
            </td>
            <td class="px-4 py-3 text-gray-500 hidden md:table-cell">{{category_name}}</td>
            <td class="px-4 py-3">
                <span class="cms-badge {{#if status_published}}cms-badge-published{{else}}{{#if status_archived}}cms-badge-archived{{else}}cms-badge-draft{{/if}}{{/if}}">{{status}}</span>
            </td>
            <td class="px-4 py-3 text-gray-500 hidden md:table-cell">{{published_at_fmt}}</td>
            <td class="px-4 py-3">
                <div class="flex items-center gap-2 justify-end">
                    <a href="/admin/blog/posts/{{id}}/edit" class="p-1.5 text-gray-500 hover:text-[#135bec] hover:bg-blue-50 rounded" title="Edit">
                        <span class="material-symbols-outlined text-[18px]">edit</span>
                    </a>
                    {{#if status_published}}
                    <form method="post" action="/admin/blog/posts/{{id}}/unpublish" class="inline">
                        {{{csrf_field}}}
                        <button class="p-1.5 text-gray-500 hover:text-amber-600 hover:bg-amber-50 rounded" title="Unpublish">
                            <span class="material-symbols-outlined text-[18px]">visibility_off</span>
                        </button>
                    </form>
                    {{else}}
                    <form method="post" action="/admin/blog/posts/{{id}}/publish" class="inline">
                        {{{csrf_field}}}
                        <button class="p-1.5 text-gray-500 hover:text-green-600 hover:bg-green-50 rounded" title="Publish">
                            <span class="material-symbols-outlined text-[18px]">publish</span>
                        </button>
                    </form>
                    {{/if}}
                    <form method="post" action="/admin/blog/posts/{{id}}/delete" class="inline" data-confirm="Delete this post permanently?">
                        {{{csrf_field}}}
                        <button class="p-1.5 text-gray-500 hover:text-red-600 hover:bg-red-50 rounded" title="Delete">
                            <span class="material-symbols-outlined text-[18px]">delete</span>
                        </button>
                    </form>
                </div>
            </td>
        </tr>
        {{/each}}
        </tbody>
    </table>
    {{else}}
    <div class="text-center py-16 text-gray-400">
        <span class="material-symbols-outlined text-5xl">article</span>
        <p class="mt-3">No posts yet. <a href="/admin/blog/posts/new" class="text-[#135bec] hover:underline">Create your first post →</a></p>
    </div>
    {{/if}}
</div>
HTML,

        'admin/blog_post_edit' => <<<'HTML'
<div class="mb-6 flex items-center gap-3">
    <a href="/admin/blog/posts" class="cms-back">
        <span class="material-symbols-outlined">arrow_back</span>
    </a>
    <div>
        <h1 class="text-2xl font-bold text-gray-900">{{#if post_id}}Edit Post{{else}}New Post{{/if}}</h1>
    </div>
</div>

<form method="post" action="/admin/blog/posts/save" class="space-y-6" id="post-form">
    {{{csrf_field}}}
    {{#if post_id}}<input type="hidden" name="post_id" value="{{post_id}}">{{/if}}

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main content -->
        <div class="lg:col-span-2 space-y-4">
        <div class="cms-card">
            <input type="text" name="title" value="{{post_title}}" required
                placeholder="Post Title"
                class="w-full text-2xl font-bold border-none outline-none focus:ring-0 placeholder-slate-300 text-gray-900 dark:text-slate-100">
            <div class="mt-2 flex items-center gap-2 text-sm text-gray-400">
                <span>/blog/</span>
                <input type="text" name="slug" id="slug-input" value="{{post_slug}}"
                    class="flex-1 border-b border-dashed border-gray-300 outline-none focus:border-[#135bec] text-gray-600 bg-transparent">
            </div>
        </div>

        <div class="cms-card">
            <label class="cms-label">Excerpt</label>
            <textarea name="excerpt" rows="2" placeholder="Brief summary shown in listing..."
                class="cms-input">{{post_excerpt}}</textarea>
        </div>

        <!-- Body Content with tab editor -->
        <div class="cms-card overflow-visible" id="body-card">
            <!-- Tab bar -->
            <div class="flex items-center -mx-5 px-3 border-b border-gray-200 dark:border-slate-700 mb-4">
                <button type="button" id="tab-visual"
                    class="px-4 py-2.5 text-sm font-medium border-b-2 flex items-center gap-1.5 transition-colors -mb-px"
                    data-tab="visual">
                    <span class="material-symbols-outlined text-[16px]">format_paint</span> Visual
                </button>
                <button type="button" id="tab-md"
                    class="px-4 py-2.5 text-sm font-medium border-b-2 flex items-center gap-1.5 transition-colors -mb-px"
                    data-tab="md">
                    <span class="material-symbols-outlined text-[16px]">code</span> Markdown
                </button>
                <div class="ml-auto py-1">
                    <button type="button" id="ai-write-btn"
                        class="flex items-center gap-1.5 px-3 py-1.5 bg-[#135bec] hover:bg-[#0e47c1] text-white rounded-lg text-xs font-medium transition-colors">
                        <span class="material-symbols-outlined text-sm leading-none">smart_toy</span> AI Write
                    </button>
                </div>
            </div>

            <!-- Visual pane (Quill) -->
            <div id="pane-visual">
                <div id="quill-editor" style="min-height:400px;">{{{post_body}}}</div>
            </div>

            <!-- Markdown pane -->
            <div id="pane-md" class="hidden">
                <div class="flex gap-3" style="min-height:450px;">
                    <textarea id="md-input" name="body_markdown"
                        placeholder="Write in Markdown…"
                        class="flex-1 font-mono text-sm p-3 border border-gray-200 dark:border-slate-600 rounded-lg resize-none focus:ring-2 focus:ring-[#135bec] outline-none bg-white dark:bg-slate-900 dark:text-slate-100"
                        style="min-height:450px;tab-size:2;"></textarea>
                    <div id="md-preview"
                        class="flex-1 overflow-y-auto p-4 border border-gray-200 dark:border-slate-600 rounded-lg bg-gray-50 dark:bg-slate-900"
                        style="min-height:450px;">
                        <p class="text-gray-400 text-sm italic">Preview will appear here as you type…</p>
                    </div>
                </div>
                <p class="mt-2 text-xs text-gray-400">Supports GFM: **bold**, *italic*, ~~strike~~, `code`, fenced blocks (```), tables, task lists, links, images.</p>
            </div>

            <!-- Hidden fields for form submission -->
            <input type="hidden" name="body_html" id="body-html-input">
        </div>
        </div>

        <!-- Sidebar -->
        <div class="space-y-4">
        <!-- Publish box -->
        <div class="cms-card">
            <h3 class="font-semibold text-gray-800 mb-3">Publish</h3>
            <div class="space-y-3">
                <div>
                    <label class="cms-label-sm">Status</label>
                    <select name="status" class="cms-input">
                        <option value="draft" {{#if status_draft}}selected{{/if}}>Draft</option>
                        <option value="published" {{#if status_published}}selected{{/if}}>Published</option>
                        <option value="archived" {{#if status_archived}}selected{{/if}}>Archived</option>
                    </select>
                </div>
                <div>
                    <label class="cms-label-sm">Publish Date</label>
                    <input type="datetime-local" name="published_at" value="{{published_at_input}}"
                        class="cms-input">
                </div>
            </div>
            <div class="mt-4 flex gap-2">
                <button type="submit" class="cms-btn flex-1">Save</button>
                <a href="/admin/blog/posts" class="cms-btn-ghost cms-btn-sm">Cancel</a>
            </div>
        </div>

        <!-- Category -->
        <div class="cms-card">
            <h3 class="font-semibold text-gray-800 mb-3">Category</h3>
            <select name="category_id" class="cms-input">
                <option value="">Uncategorized</option>
                {{#each categories}}
                <option value="{{id}}" {{#if selected}}selected{{/if}}>{{name}}</option>
                {{/each}}
            </select>
        </div>

        <!-- Tags -->
        <div class="cms-card">
            <h3 class="font-semibold text-gray-800 mb-3">Tags</h3>
            <div class="flex flex-wrap gap-2 mb-3" id="selected-tags">
                {{#each post_tags}}
                <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-blue-50 text-blue-700 rounded-full text-xs">
                    {{name}}
                    <button type="button" class="hover:text-red-500" data-remove-tag="{{id}}">×</button>
                    <input type="hidden" name="tags[]" value="{{id}}">
                </span>
                {{/each}}
            </div>
            <div class="flex gap-2">
                <select id="tag-select" class="flex-1 px-2 py-1.5 border border-gray-200 rounded-lg text-xs focus:ring-2 focus:ring-[#135bec] outline-none">
                    <option value="">Add tag…</option>
                    {{#each all_tags}}
                    <option value="{{id}}" data-name="{{name}}">{{name}}</option>
                    {{/each}}
                </select>
            </div>
            <div class="mt-2 flex gap-1">
                <input id="new-tag-input" type="text" placeholder="New tag name" class="flex-1 px-2 py-1.5 border border-gray-200 rounded-lg text-xs outline-none focus:ring-1 focus:ring-[#135bec]">
                <button type="button" id="add-tag-btn" class="px-2 py-1.5 bg-gray-100 hover:bg-gray-200 rounded-lg text-xs">Add</button>
            </div>
        </div>

        <!-- Cover Image -->
        <div class="cms-card">
            <h3 class="font-semibold text-gray-800 mb-3">Cover Image</h3>
            {{#if cover_url}}
            <img src="{{cover_url}}" class="w-full rounded-lg mb-2 max-h-32 object-cover" id="cover-preview">
            {{else}}
            <div id="cover-preview" class="hidden w-full rounded-lg mb-2 max-h-32 object-cover bg-gray-100"></div>
            {{/if}}
            <input type="hidden" name="cover_asset_id" id="cover-asset-id" value="{{cover_asset_id}}">
            <button type="button" id="pick-cover-btn" class="w-full px-3 py-2 border-2 border-dashed border-gray-300 rounded-lg text-sm text-gray-500 hover:border-[#135bec] hover:text-[#135bec] transition-colors">
                <span class="material-symbols-outlined text-sm align-middle">image</span>
                {{#if cover_url}}Change Image{{else}}Pick Cover Image{{/if}}
            </button>
        </div>

        <!-- SEO -->
        <div class="cms-card">
            <h3 class="font-semibold text-gray-800 mb-3">SEO</h3>
            <textarea name="meta_description" rows="3" placeholder="Meta description…"
                class="w-full px-3 py-2 border border-gray-200 rounded-lg text-xs resize-none focus:ring-2 focus:ring-[#135bec] outline-none">{{meta_description}}</textarea>
        </div>
        </div>
    </div>
</form>

<!-- AI Write Chat Modal -->
<div id="ai-modal" style="display:none;position:fixed;bottom:1.5rem;right:1.5rem;z-index:8000;width:400px;max-height:72vh;flex-direction:column;background:var(--sf);border:1px solid var(--bd);border-radius:1rem;box-shadow:0 24px 64px rgba(0,0,0,.28);overflow:hidden;">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:.75rem 1rem;border-bottom:1px solid var(--bd);flex-shrink:0;">
        <span style="font-size:.875rem;font-weight:600;color:var(--tx);display:flex;align-items:center;gap:.375rem;">
        <span class="material-symbols-outlined" style="font-size:1rem;color:#135bec;vertical-align:middle;">smart_toy</span>
        AI Blog Writer
        </span>
        <button type="button" id="ai-modal-close" style="background:none;border:none;cursor:pointer;color:var(--mx);font-size:1.1rem;line-height:1;padding:.25rem .375rem;border-radius:.375rem;" aria-label="Close">&times;</button>
    </div>
    <div id="ai-chat-history" style="flex:1;overflow-y:auto;padding:1rem;display:flex;flex-direction:column;gap:.75rem;min-height:0;"></div>
    <div id="ai-insert-bar" style="display:none;padding:.5rem 1rem;border-top:1px solid var(--bd);flex-shrink:0;display:none;gap:.5rem;justify-content:flex-end;">
        <button type="button" id="ai-insert-btn" style="padding:.375rem .875rem;background:#135bec;color:#fff;border:none;border-radius:.5rem;font-size:.75rem;font-weight:600;cursor:pointer;">Insert into Markdown</button>
        <button type="button" id="ai-discard-btn" style="padding:.375rem .875rem;background:none;border:1px solid var(--bd);color:var(--mx);border-radius:.5rem;font-size:.75rem;cursor:pointer;">Clear</button>
    </div>
    <div style="padding:.75rem 1rem;border-top:1px solid var(--bd);flex-shrink:0;display:flex;gap:.5rem;align-items:flex-end;">
        <textarea id="ai-input" rows="2" placeholder="Describe the blog post you want to write…"
        style="flex:1;padding:.5rem .75rem;border:1px solid var(--bd);border-radius:.625rem;font-size:.8rem;resize:none;background:var(--bg);color:var(--tx);outline:none;line-height:1.5;font-family:inherit;"></textarea>
        <button type="button" id="ai-send-btn"
        style="padding:.5rem .875rem;background:#135bec;color:#fff;border:none;border-radius:.625rem;font-size:.8rem;font-weight:600;cursor:pointer;white-space:nowrap;flex-shrink:0;">Send</button>
    </div>
</div>

<link rel="stylesheet" href="/cdn/quill.snow.css">
<script src="/cdn/quill.min.js"></script>
<style nonce="{{csp_nonce}}">
/* Quill dark mode */
html.dark .ql-toolbar.ql-snow{background:var(--bg);border-color:var(--bd);}
html.dark .ql-container.ql-snow{border-color:var(--bd);background:var(--sf);}
html.dark .ql-editor{color:var(--tx);}
html.dark .ql-toolbar .ql-stroke{stroke:var(--mx);}
html.dark .ql-toolbar .ql-fill{fill:var(--mx);}
html.dark .ql-toolbar .ql-picker{color:var(--mx);}
html.dark .ql-snow .ql-picker-options{background:var(--sf);border-color:var(--bd);}
html.dark .ql-snow .ql-picker-item:hover,.ql-snow .ql-picker-label:hover{color:var(--p);}
/* Tab active state */
.tab-editor-active{border-color:#135bec!important;color:#135bec!important;}
.tab-editor-inactive{border-color:transparent!important;color:var(--mx)!important;}
/* Markdown rendered content */
.md-content{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",system-ui,sans-serif;line-height:1.75;color:#1e293b;max-width:100%;word-break:break-word;}
.md-content>*:first-child{margin-top:0!important;}.md-content>*:last-child{margin-bottom:0!important;}
.md-content h1,.md-content h2,.md-content h3,.md-content h4,.md-content h5,.md-content h6{font-weight:700;line-height:1.3;margin:1.4em 0 .5em;color:#0f172a;}
.md-content h1{font-size:2em;padding-bottom:.3em;border-bottom:2px solid #e2e8f0;}
.md-content h2{font-size:1.5em;padding-bottom:.2em;border-bottom:1px solid #e2e8f0;}
.md-content h3{font-size:1.25em;}.md-content h4{font-size:1.05em;}
.md-content h5{font-size:.9em;}.md-content h6{font-size:.85em;color:#64748b;}
.md-content p{margin:1em 0;}
.md-content a{color:#135bec;text-decoration:underline;text-underline-offset:2px;}
.md-content a:hover{color:#0e47c1;}
.md-content strong{font-weight:700;}.md-content em{font-style:italic;}.md-content del{text-decoration:line-through;color:#94a3b8;}
.md-content code{background:#f1f5f9;border:1px solid #e2e8f0;border-radius:4px;padding:.1em .4em;font-family:Consolas,"Courier New",monospace;font-size:.875em;}
.md-content pre{background:#0f172a;border-radius:8px;padding:1.1em 1.4em;overflow-x:auto;margin:1.25em 0;}
.md-content pre code{background:none;border:none;padding:0;color:#e2e8f0;font-size:.875em;white-space:pre;}
.md-content blockquote{border-left:4px solid #135bec;margin:1.25em 0;padding:.5em 1.1em;background:#eff6ff;border-radius:0 8px 8px 0;color:#334155;}
.md-content blockquote p:first-child{margin-top:0;}.md-content blockquote p:last-child{margin-bottom:0;}
.md-content ul,.md-content ol{padding-left:1.5em;margin:1em 0;}
.md-content li{margin:.25em 0;}.md-content ul{list-style:disc;}.md-content ul ul{list-style:circle;}
.md-content ol{list-style:decimal;}.md-content li.task-item{list-style:none;margin-left:-1.5em;padding-left:1.5em;}
.md-content li.task-item input[type="checkbox"]{margin-right:.4em;cursor:default;}
.md-content table{width:100%;border-collapse:collapse;margin:1.25em 0;}
.md-content th,.md-content td{border:1px solid #e2e8f0;padding:.5em .9em;}
.md-content th{background:#f8fafc;font-weight:600;}
.md-content tbody tr:nth-child(even) td{background:#f8fafc;}
.md-content hr{border:none;border-top:2px solid #e2e8f0;margin:1.75em 0;}
.md-content img{max-width:100%;border-radius:8px;}
.md-content .table-wrap{overflow-x:auto;}
/* Markdown dark mode */
html.dark .md-content{color:#e2e8f0;}
html.dark .md-content h1,html.dark .md-content h2,html.dark .md-content h3,html.dark .md-content h4,html.dark .md-content h5,html.dark .md-content h6{color:#f1f5f9;}
html.dark .md-content h1,html.dark .md-content h2{border-color:#334155;}
html.dark .md-content code{background:#1e293b;border-color:#475569;color:#f1f5f9;}
html.dark .md-content pre{background:#020617;}
html.dark .md-content pre code{color:#e2e8f0;}
html.dark .md-content blockquote{background:#172554;border-color:#3b82f6;color:#cbd5e1;}
html.dark .md-content th{background:#1e293b;}
html.dark .md-content th,html.dark .md-content td{border-color:#334155;}
html.dark .md-content tbody tr:nth-child(even) td{background:#1e293b;}
html.dark .md-content hr{border-color:#334155;}
/* AI chat bubbles */
.ai-bubble-user{background:#eff6ff;color:#1e3a5f;padding:.625rem .875rem;border-radius:1rem 1rem .25rem 1rem;font-size:.8rem;line-height:1.5;max-width:90%;align-self:flex-end;white-space:pre-wrap;}
.ai-bubble-ai{background:var(--bg);border:1px solid var(--bd);color:var(--tx);padding:.625rem .875rem;border-radius:1rem 1rem 1rem .25rem;font-size:.8rem;line-height:1.5;max-width:95%;white-space:pre-wrap;word-break:break-word;}
.ai-bubble-error{background:#fef2f2;border:1px solid #fecaca;color:#7f1d1d;padding:.5rem .75rem;border-radius:.5rem;font-size:.8rem;}
</style>
<script nonce="{{csp_nonce}}">window._mdB64='{{post_markdown_b64}}';window._autoAi={{autoopen_ai}};</script>
<script src="/js/admin-blog-editor" nonce="{{csp_nonce}}"></script>
HTML,

        'admin/blog_categories' => <<<'HTML'
<div class="cms-page-header">
    <div>
        <h1 class="cms-page-title">Blog Categories</h1>
        <p class="cms-page-subtitle">Organize your blog posts into categories.</p>
    </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 gap-6">
    <!-- Category list -->
    <div class="cms-table-wrap">
        <div class="px-5 py-3 border-b border-gray-100 font-medium text-sm text-gray-700">All Categories</div>
        {{#if categories}}
        <table class="w-full text-sm">
        <tbody class="divide-y divide-gray-100">
            {{#each categories}}
            <tr class="hover:bg-gray-50">
                <td class="px-5 py-3">
                    <div class="font-medium text-gray-800">{{name}}</div>
                    <div class="text-xs text-gray-400">/blog/category/{{slug}}</div>
                </td>
                <td class="px-5 py-3 text-gray-400 text-right">{{post_count}} posts</td>
                <td class="px-3 py-3">
                    <div class="flex gap-1 justify-end">
                        <button type="button" class="p-1.5 text-gray-400 hover:text-[#135bec] hover:bg-blue-50 rounded edit-cat-btn"
                            data-id="{{id}}" data-name="{{name}}" data-desc="{{description}}" title="Edit">
                            <span class="material-symbols-outlined text-[16px]">edit</span>
                        </button>
                        <form method="post" action="/admin/blog/categories/{{id}}/delete" data-confirm="Delete this category?">
                            {{{csrf_field}}}
                            <button type="submit" class="p-1.5 text-gray-400 hover:text-red-500 hover:bg-red-50 rounded" title="Delete">
                                <span class="material-symbols-outlined text-[16px]">delete</span>
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
            {{/each}}
        </tbody>
        </table>
        {{else}}
        <div class="text-center py-10 text-gray-400 text-sm">No categories yet.</div>
        {{/if}}
    </div>

    <!-- Add / Edit form -->
    <div class="cms-card">
        <h2 class="font-semibold text-gray-800 mb-4" id="cat-form-title">Add Category</h2>
        <form method="post" action="/admin/blog/categories/save" class="space-y-3">
        {{{csrf_field}}}
        <input type="hidden" name="cat_id" id="cat-id-input" value="">
        <div>
            <label class="cms-label-sm">Name *</label>
            <input type="text" name="cat_name" id="cat-name-input" required
                class="cms-input">
        </div>
        <div>
            <label class="cms-label-sm">Description</label>
            <textarea name="cat_description" id="cat-desc-input" rows="2" resize-none
                class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-[#135bec] outline-none resize-none"></textarea>
        </div>
        <div class="flex gap-2">
            <button type="submit" class="cms-btn">Save</button>
            <button type="button" id="cat-clear-btn" class="cms-btn-ghost cms-btn-sm">Clear</button>
        </div>
        </form>
    </div>
</div>

<script nonce="{{csp_nonce}}">
document.querySelectorAll('.edit-cat-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('cat-id-input').value = btn.dataset.id;
        document.getElementById('cat-name-input').value = btn.dataset.name;
        document.getElementById('cat-desc-input').value = btn.dataset.desc;
        document.getElementById('cat-form-title').textContent = 'Edit Category';
    });
});
document.getElementById('cat-clear-btn')?.addEventListener('click', () => {
    document.getElementById('cat-id-input').value='';
    document.getElementById('cat-name-input').value='';
    document.getElementById('cat-desc-input').value='';
    document.getElementById('cat-form-title').textContent='Add Category';
});
</script>
HTML,
    ];
}
// ─────────────────────────────────────────────────────────────────────────────
// SECTION 15: CACHE MANAGEMENT
// ─────────────────────────────────────────────────────────────────────────────

class Cache {
    private static bool $versionChecked = false;

    // On first page-cache access per request, check whether the app version has changed since the last deployment. If it has, invalidate all page/partial caches so stale HTML (with old JS/CSS references) is never served after an upgrade.
    private static function checkVersionOnce(): void {
        if (self::$versionChecked || MONOLITHCMS_DEV) {
            self::$versionChecked = true;
            return;
        }
        self::$versionChecked = true;
        $stored = DB::fetch("SELECT value FROM settings WHERE key = 'app_version'");
        if (($stored['value'] ?? null) !== MONOLITHCMS_VERSION) {
            self::invalidateAll();
            DB::execute(
                "INSERT INTO settings (key, value) VALUES ('app_version', ?)
                 ON CONFLICT(key) DO UPDATE SET value = excluded.value",
                [MONOLITHCMS_VERSION]
            );
        }
    }

    // ─── PAGE CACHE ──────────────────────────────────────────────────────────
    public static function getPage(string $slug): ?string {
        self::checkVersionOnce();
        // Always bypass cache in dev mode or for logged-in editors
        if (MONOLITHCMS_DEV || Auth::check()) return null;
        $file = MONOLITHCMS_CACHE . '/pages/' . md5($slug) . '.html';
        if (file_exists($file) && filemtime($file) > time() - 3600) {
            return file_get_contents($file);
        }
        return null;
    }

    public static function setPage(string $slug, string $html): void {
        self::checkVersionOnce();
        // Skip writing cache in dev mode
        if (MONOLITHCMS_DEV) return;
        $file = MONOLITHCMS_CACHE . '/pages/' . md5($slug) . '.html';
        file_put_contents($file, $html);
    }

    public static function invalidatePage(string $slug): void {
        $file = MONOLITHCMS_CACHE . '/pages/' . md5($slug) . '.html';
        if (file_exists($file)) {
            unlink($file);
        }
    }

    // ─── PARTIAL CACHE ───────────────────────────────────────────────────────
    public static function invalidatePartial(string $name): void {
        $file = MONOLITHCMS_CACHE . '/partials/' . $name . '.html';
        if (file_exists($file)) {
            unlink($file);
        }
    }

    // ─── FULL INVALIDATION ───────────────────────────────────────────────────
    public static function invalidateAll(): void {
        $dirs = ['pages', 'partials'];
        foreach ($dirs as $dir) {
            $path = MONOLITHCMS_CACHE . '/' . $dir;
            foreach (glob($path . '/*.html') as $file) {
                unlink($file);
            }
        }
    }

    // ─── EVENT-DRIVEN INVALIDATION & REGENERATION ─────────────────────────────
    public static function onContentChange(string $table, ?int $recordId = null): void {
        match ($table) {
            'nav' => self::regenerateNavAndPages(),
            'theme_header' => self::regenerateHeaderAndPages(),
            'theme_footer' => self::regenerateFooterAndPages(),
            'theme_styles' => self::regenerateCSSAndPages(),
            'pages', 'content_blocks' => self::regenerateAllPagesAsync(),
            default => null
        };
    }

    private static function regenerateNavAndPages(): void {
        self::invalidatePartial('nav');
        self::invalidateAllPages();
        // Regenerate nav partial
        try {
            Template::partial('nav');
        } catch (Exception $e) {}
        // Regenerate all pages
        self::regenerateAllPagesAsync();
    }

    private static function regenerateHeaderAndPages(): void {
        self::invalidatePartial('header');
        self::invalidateAllPages();
        try {
            Template::partial('header');
        } catch (Exception $e) {}
        self::regenerateAllPagesAsync();
    }

    private static function regenerateFooterAndPages(): void {
        self::invalidatePartial('footer');
        self::invalidateAllPages();
        try {
            Template::partial('footer');
        } catch (Exception $e) {}
        self::regenerateAllPagesAsync();
    }

    private static function regenerateCSSAndPages(): void {
        self::invalidateCSS();
        CSSGenerator::cacheAndGetHash();
        self::invalidateAllPages();
        self::regenerateAllPagesAsync();
    }

    private static function regenerateAllPagesAsync(): void {
        // Regenerate all published pages
        $pages = DB::fetchAll("SELECT * FROM pages WHERE status = 'published'");
        foreach ($pages as $page) {
            try {
                self::regeneratePage($page);
            } catch (Exception $e) {
                // Silently fail - page will be regenerated on next visit
            }
        }
    }

    private static function invalidateAllPages(): void {
        foreach (glob(MONOLITHCMS_CACHE . '/pages/*.html') as $file) {
            unlink($file);
        }
    }

    private static function invalidateCSS(): void {
        foreach (glob(MONOLITHCMS_CACHE . '/assets/app.*.css') as $file) {
            unlink($file);
        }
    }

    // ─── CACHE REGENERATION ──────────────────────────────────────────────────
    public static function regenerateAll(): array {
        $stats = ['pages' => 0, 'partials' => 0, 'errors' => []];

        // Clear all cache first
        self::invalidateAll();

        // Regenerate partials (header, nav, footer)
        try {
            Template::partial('header');
            $stats['partials']++;
        } catch (Exception $e) {
            $stats['errors'][] = 'Header: ' . $e->getMessage();
        }

        try {
            Template::partial('nav');
            $stats['partials']++;
        } catch (Exception $e) {
            $stats['errors'][] = 'Nav: ' . $e->getMessage();
        }

        try {
            Template::partial('footer');
            $stats['partials']++;
        } catch (Exception $e) {
            $stats['errors'][] = 'Footer: ' . $e->getMessage();
        }

        // Regenerate all published pages
        $pages = DB::fetchAll("SELECT * FROM pages WHERE status = 'published'");
        foreach ($pages as $page) {
            try {
                self::regeneratePage($page);
                $stats['pages']++;
            } catch (Exception $e) {
                $stats['errors'][] = 'Page "' . $page['slug'] . '": ' . $e->getMessage();
            }
        }

        // Regenerate CSS
        CSSGenerator::cacheAndGetHash();

        return $stats;
    }

    private static function regeneratePage(array $page): void {
        // Load content blocks
        $blocks = DB::fetchAll(
            "SELECT * FROM content_blocks WHERE page_id = ? ORDER BY sort_order ASC",
            [$page['id']]
        );

        $blocksHtml = '';
        foreach ($blocks as $block) {
            $blocksHtml .= PageController::renderBlockForCache($block);
        }

        // Load layout config from meta_json
        $meta = json_decode($page['meta_json'] ?? '{}', true) ?? [];
        $layout  = $meta['layout'] ?? [];
        $showNav       = (bool)($layout['show_nav']        ?? true);
        $showFooter    = (bool)($layout['show_footer']     ?? true);
        $showPageTitle = (bool)($layout['show_page_title'] ?? ($page['slug'] !== 'home'));
        $navStyle      = $layout['nav_style'] ?? 'sticky';
        $pageCustomCss = !empty($meta['custom_css']) ? '<style>' . strip_tags($meta['custom_css']) . '</style>' : '';

        $navWrapperStyle = $navStyle === 'transparent'
            ? 'position:absolute;width:100%;top:0;z-index:30'
            : 'position:sticky;top:0;z-index:30';

        // Load theme colors and custom scripts
        $colorRows = DB::fetchAll("SELECT key, value FROM theme_styles WHERE key IN ('color_primary','color_secondary','color_accent','head_scripts','footer_scripts')");
        $colorMap  = array_column($colorRows, 'value', 'key');

        // Inject CSP nonce so scripts pass CSP; cache system replaces actual nonce with placeholder before storing
        $injectNonce = static function (string $code): string {
            return preg_replace('/<script(?![^>]*\bnonce=)([^>]*)>/i', '<script nonce="' . CSP_NONCE . '"$1>', $code);
        };

        $html = Template::render('page', array_merge($page, [
            'blocks_html'       => $blocksHtml,
            'show_nav'          => $showNav,
            'show_footer'       => $showFooter,
            'show_page_title'   => $showPageTitle,
            'nav_wrapper_style' => $navWrapperStyle,
            'ui_theme'          => Settings::get('ui_theme', 'light'),
            'page_custom_css'   => $pageCustomCss,
            'color_primary'     => $colorMap['color_primary']   ?? '#3b82f6',
            'color_secondary'   => $colorMap['color_secondary'] ?? '#1e40af',
            'color_accent'      => $colorMap['color_accent']    ?? '#f59e0b',
            'head_scripts'      => $injectNonce($colorMap['head_scripts']   ?? ''),
            'footer_scripts'    => $injectNonce($colorMap['footer_scripts'] ?? ''),
        ]));

        // Store with nonce placeholder so fresh nonce is injected on each serve
        $html = str_replace(CSP_NONCE, '{{CSP_NONCE_PLACEHOLDER}}', $html);

        self::setPage($page['slug'], $html);
    }

    // ─── CSS HASH ────────────────────────────────────────────────────────────
    public static function getCSSHash(): string {
        // In dev mode always regenerate — never serve stale CSS
        if (MONOLITHCMS_DEV) {
            return CSSGenerator::cacheAndGetHash();
        }
        // Find existing CSS file
        $files = glob(MONOLITHCMS_CACHE . '/assets/app.*.css');
        if (!empty($files)) {
            return basename($files[0], '.css');
        }
        return CSSGenerator::cacheAndGetHash();
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 16: CDN CACHE
// ─────────────────────────────────────────────────────────────────────────────

class CDNCache {
    // CDN resources to cache locally. 'eager'=>true = downloaded on first request; others = downloaded on first use.
    private static array $resources = [
        'bulma' => [
            'url' => 'https://cdn.jsdelivr.net/npm/bulma@1.0.4/css/bulma.min.css',
            'type' => 'css', 'filename' => 'bulma.min.css', 'eager' => true
        ],
        'tailwind-forms' => [
            'url' => 'https://cdn.tailwindcss.com?plugins=forms,container-queries',
            'type' => 'js', 'filename' => 'tailwind-forms.min.js', 'eager' => true
        ],
        'material-icons' => [
            'url' => 'https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200',
            'type' => 'css', 'filename' => 'material-icons.css', 'eager' => true
        ],
        'inter-font' => [
            'url' => 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap',
            'type' => 'css', 'filename' => 'inter-font.css', 'eager' => true
        ],
        'quill-css' => [
            'url' => 'https://cdn.jsdelivr.net/npm/quill@2/dist/quill.snow.css',
            'type' => 'css', 'filename' => 'quill.snow.css'
        ],
        'quill-js' => [
            'url' => 'https://cdn.jsdelivr.net/npm/quill@2/dist/quill.min.js',
            'type' => 'js', 'filename' => 'quill.min.js'
        ],
        'grapes-js' => [
            'url' => 'https://unpkg.com/grapesjs@0.22.14/dist/grapes.min.js',
            'type' => 'js', 'filename' => 'grapes.min.js'
        ],
        'grapes-css' => [
            'url' => 'https://unpkg.com/grapesjs@0.22.14/dist/css/grapes.min.css',
            'type' => 'css', 'filename' => 'grapes.min.css'
        ]
    ];

    // Download CDN resources. $eagerOnly=true skips lazy resources (grapes, quill) — used on boot pre-warm.
    public static function initialize(bool $eagerOnly = false): array {
        $results = [];
        $cacheDir = MONOLITHCMS_CACHE . '/assets/cdn';

        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        foreach (self::$resources as $name => $resource) {
            if ($eagerOnly && empty($resource['eager'])) {
                continue; // skip grapes, quill — downloaded lazily on first use
            }
            $filePath = $cacheDir . '/' . $resource['filename'];

            // Skip if already cached and not expired (7 days)
            if (file_exists($filePath) && filemtime($filePath) > time() - 604800) {
                $results[$name] = ['status' => 'cached', 'path' => $filePath];
                continue;
            }

            // Download the resource
            $content = self::downloadResource($resource['url'], $resource['type']);

            if ($content !== false) {
                // For CSS files with font references, download fonts too
                if ($resource['type'] === 'css' && str_contains($resource['url'], 'fonts.googleapis.com')) {
                    $content = self::processFontCSS($content, $cacheDir);
                }

                // Strip Tailwind CDN production warning so it never fires in browser console
                if ($name === 'tailwind-forms' && $resource['type'] === 'js') {
                    $content = preg_replace(
                        '/console\.warn\("cdn\.tailwindcss\.com should not be used in production[^"]*"\)/',
                        '',
                        $content
                    );
                }

                // Prepend full BSD 3-Clause license notice for GrapesJS (required for redistribution in binary form). The minified file only ships a one-line banner with no copyright notice or disclaimer.
                if ($name === 'grapes-js') {
                    $license = "/*!\n"
                        . " * GrapesJS v0.22.14\n"
                        . " * Copyright (c) 2016-present, GrapesJS\n"
                        . " * https://github.com/GrapesJS/grapesjs\n"
                        . " *\n"
                        . " * BSD 3-Clause License\n"
                        . " *\n"
                        . " * Redistribution and use in source and binary forms, with or without\n"
                        . " * modification, are permitted provided that the following conditions are met:\n"
                        . " *\n"
                        . " * 1. Redistributions of source code must retain the above copyright notice,\n"
                        . " *    this list of conditions and the following disclaimer.\n"
                        . " * 2. Redistributions in binary form must reproduce the above copyright\n"
                        . " *    notice, this list of conditions and the following disclaimer in the\n"
                        . " *    documentation and/or other materials provided with the distribution.\n"
                        . " * 3. Neither the name of the copyright holder nor the names of its\n"
                        . " *    contributors may be used to endorse or promote products derived from\n"
                        . " *    this software without specific prior written permission.\n"
                        . " *\n"
                        . " * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS\n"
                        . " * \"AS IS\" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT\n"
                        . " * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A\n"
                        . " * PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER\n"
                        . " * OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL,\n"
                        . " * EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO,\n"
                        . " * PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR\n"
                        . " * PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF\n"
                        . " * LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING\n"
                        . " * NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS\n"
                        . " * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.\n"
                        . " */\n";
                    // Remove the minimal existing banner (no copyright/disclaimer) then prepend full notice
                    $content = preg_replace('/^\/\*!\s*grapesjs[^*]*\*\/\n?/', '', $content);
                    $content = $license . $content;
                }

                file_put_contents($filePath, $content);
                $results[$name] = ['status' => 'downloaded', 'path' => $filePath];
            } else {
                $results[$name] = ['status' => 'failed', 'url' => $resource['url']];
            }
        }

        return $results;
    }

    // Download a resource from URL
    private static function downloadResource(string $url, string $type): string|false {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept: */*'
                ],
                'timeout' => 30
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true
            ]
        ]);

        $content = @file_get_contents($url, false, $context);
        return $content;
    }

    // Process Google Fonts CSS - download font files and update URLs
    private static function processFontCSS(string $css, string $cacheDir): string {
        $fontsDir = $cacheDir . '/fonts';
        if (!is_dir($fontsDir)) {
            mkdir($fontsDir, 0755, true);
        }

        // Find all font URLs in the CSS
        preg_match_all('/url\(([^)]+)\)/i', $css, $matches);

        foreach ($matches[1] as $fontUrl) {
            $fontUrl = trim($fontUrl, '"\'');

            if (str_starts_with($fontUrl, 'https://')) {
                // Generate a local filename
                $fontFilename = md5($fontUrl) . '.' . pathinfo(parse_url($fontUrl, PHP_URL_PATH), PATHINFO_EXTENSION);
                $localPath = $fontsDir . '/' . $fontFilename;

                // Download if not exists
                if (!file_exists($localPath)) {
                    $fontContent = self::downloadResource($fontUrl, 'font');
                    if ($fontContent !== false) {
                        file_put_contents($localPath, $fontContent);
                    }
                }

                // Replace URL in CSS
                $localUrl = '/cdn/fonts/' . $fontFilename;
                $css = str_replace($fontUrl, $localUrl, $css);
            }
        }

        return $css;
    }

    // Get the local path for a CDN resource
    public static function getPath(string $name): ?string {
        if (!isset(self::$resources[$name])) {
            return null;
        }

        $filePath = MONOLITHCMS_CACHE . '/assets/cdn/' . self::$resources[$name]['filename'];

        // Auto-download if not cached
        if (!file_exists($filePath)) {
            self::initialize();
        }

        return file_exists($filePath) ? '/cache/assets/cdn/' . self::$resources[$name]['filename'] : null;
    }

    // Get all local paths as an array for templates
    public static function getPaths(): array {
        $paths = [];
        foreach (self::$resources as $name => $resource) {
            $paths[$name] = self::getPath($name);
        }
        return $paths;
    }

    // Serve a cached CDN resource
    public static function serve(string $filename): void {
        // Release session lock before serving static content
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $filePath = MONOLITHCMS_CACHE . '/assets/cdn/' . basename($filename);

        // Auto-download on first request if not yet cached
        if (!file_exists($filePath)) {
            self::initialize();
        }

        if (!file_exists($filePath)) {
            http_response_code(404);
            exit('Not Found');
        }

        // Determine content type
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $contentType = match($ext) {
            'css' => 'text/css',
            'js' => 'application/javascript',
            'woff2' => 'font/woff2',
            'woff' => 'font/woff',
            'ttf' => 'font/ttf',
            default => 'application/octet-stream'
        };

        header('Content-Type: ' . $contentType);
        header('Cache-Control: public, max-age=31536000, immutable');
        readfile($filePath);
        // Append .material-icons alias so both class names work (AI may generate either)
        if (basename($filename) === 'material-icons.css') {
            echo ".material-icons{font-family:'Material Symbols Outlined';font-weight:normal;font-style:normal;font-size:24px;line-height:1;letter-spacing:normal;text-transform:none;display:inline-block;white-space:nowrap;word-wrap:normal;direction:ltr;-webkit-font-smoothing:antialiased;}";
        }
        exit;
    }

    // Clear the CDN cache
    public static function clear(): void {
        $cacheDir = MONOLITHCMS_CACHE . '/assets/cdn';
        if (is_dir($cacheDir)) {
            self::deleteDirectory($cacheDir);
        }
    }

    private static function deleteDirectory(string $dir): void {
        if (!is_dir($dir)) return;

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? self::deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 17: CSS GENERATOR
// ─────────────────────────────────────────────────────────────────────────────

class CSSGenerator {
    public static function generate(): string {
        $styles = DB::fetchAll("SELECT key, value FROM theme_styles");
        $vars = [];
        foreach ($styles as $row) {
            $vars[$row['key']] = $row['value'];
        }

        // Default values with design tokens
        $defaults = [
            // Colors
            'color_primary' => '#3b82f6',
            'color_secondary' => '#1e40af',
            'color_accent' => '#f59e0b',
            'color_background' => '#ffffff',
            'color_background_secondary' => '#f8fafc',
            'color_text' => '#1f2937',
            'color_text_muted' => '#6b7280',
            'color_border' => '#e5e7eb',
            'color_header_bg' => '',
            'color_header_text' => '',
            'color_footer_bg' => '',
            'color_footer_text' => '',
            'color_cta_bg' => '',
            'color_cta_text' => '#ffffff',
            'font_family' => 'system-ui, -apple-system, sans-serif',
            // Design tokens
            'radius_sm' => '4px',
            'radius_md' => '8px',
            'radius_lg' => '16px',
            'radius_xl' => '24px',
            'shadow_sm' => '0 1px 2px 0 rgb(0 0 0 / 0.05)',
            'shadow_md' => '0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1)',
            'shadow_lg' => '0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1)',
            'shadow_xl' => '0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1)',
        ];

        // Merge with stored values
        foreach ($defaults as $key => $default) {
            if (!isset($vars[$key]) || empty($vars[$key])) {
                $vars[$key] = $default;
            }
        }

        // Theme coherence: if background is dark, auto-derive secondary/border/muted This prevents dark-bg + light-secondary mismatch when AI only sets primary colors
        $bgHex = ltrim($vars['color_background'], '#');
        if (strlen($bgHex) === 6) {
            $bgR = hexdec(substr($bgHex, 0, 2));
            $bgG = hexdec(substr($bgHex, 2, 2));
            $bgB = hexdec(substr($bgHex, 4, 2));
            $bgLum = (0.299 * $bgR + 0.587 * $bgG + 0.114 * $bgB) / 255;
            if ($bgLum < 0.3) { // Dark background
                if ($vars['color_background_secondary'] === '#f8fafc') {
                    $vars['color_background_secondary'] = sprintf('#%02x%02x%02x', min(255,$bgR+25), min(255,$bgG+25), min(255,$bgB+25));
                }
                if ($vars['color_border'] === '#e5e7eb') {
                    $vars['color_border'] = sprintf('#%02x%02x%02x', min(255,$bgR+50), min(255,$bgG+50), min(255,$bgB+50));
                }
                if ($vars['color_text_muted'] === '#6b7280') {
                    $vars['color_text_muted'] = '#9ca3af';
                }
            }
        }

        // Derive header/CTA/footer vars from existing palette if not explicitly set
        if (empty($vars['color_header_bg']))   $vars['color_header_bg']   = $vars['color_background'];
        if (empty($vars['color_header_text'])) $vars['color_header_text'] = $vars['color_text'];
        if (empty($vars['color_footer_bg']))   $vars['color_footer_bg']   = $vars['color_secondary'];
        if (empty($vars['color_footer_text'])) $vars['color_footer_text'] = $vars['color_text_muted'];
        if (empty($vars['color_cta_bg']))      $vars['color_cta_bg']      = $vars['color_primary'];

        $css = <<<CSS
/* Site Theme */
:root {
    --color-primary: {$vars['color_primary']};
    --color-secondary: {$vars['color_secondary']};
    --color-accent: {$vars['color_accent']};
    --color-background: {$vars['color_background']};
    --color-background-secondary: {$vars['color_background_secondary']};
    --color-text: {$vars['color_text']};
    --color-text-muted: {$vars['color_text_muted']};
    --color-border: {$vars['color_border']};
    --color-header-bg: {$vars['color_header_bg']};
    --color-header-text: {$vars['color_header_text']};
    --color-footer-bg: {$vars['color_footer_bg']};
    --color-footer-text: {$vars['color_footer_text']};
    --color-cta-bg: {$vars['color_cta_bg']};
    --color-cta-text: {$vars['color_cta_text']};
    --font-family: {$vars['font_family']};
    --radius-sm: {$vars['radius_sm']};
    --radius-md: {$vars['radius_md']};
    --radius-lg: {$vars['radius_lg']};
    --radius-xl: {$vars['radius_xl']};
    --shadow-sm: {$vars['shadow_sm']};
    --shadow-md: {$vars['shadow_md']};
    --shadow-lg: {$vars['shadow_lg']};
    --shadow-xl: {$vars['shadow_xl']};
    /* Shorthand aliases — used by blog templates and portable components */
    --p: var(--color-primary);
    --p-r: color-mix(in srgb, var(--color-primary) 12%, transparent);
    --tx: var(--color-text);
    --bg-alt: var(--color-background-secondary);
    --bd: var(--color-border);
}

html { scroll-behavior: smooth; }
body {
    font-family: var(--font-family);
    background: var(--color-background);
    color: var(--color-text);
}
a { color: var(--color-primary); }

/* Material Symbols Outlined — required class (font-face only comes from CDN CSS) */
.material-symbols-outlined {
    font-family: 'Material Symbols Outlined';
    font-weight: normal;
    font-style: normal;
    font-size: 1.5rem;
    display: inline-block;
    line-height: 1;
    text-transform: none;
    letter-spacing: normal;
    word-wrap: normal;
    white-space: nowrap;
    direction: ltr;
    font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
    vertical-align: middle;
}

/* Bulma primary color overrides */
.button.is-primary,
.tag.is-primary {
    background-color: var(--color-primary) !important;
    border-color: transparent !important;
    color: #fff !important;
}
.button.is-primary:hover {
    filter: brightness(1.1);
}
.button.is-link {
    background-color: var(--color-secondary) !important;
    border-color: transparent !important;
    color: #fff !important;
}
.has-text-primary { color: var(--color-primary) !important; }
.has-background-primary { background-color: var(--color-primary) !important; }
.hero.is-primary {
    background-color: var(--color-primary) !important;
    color: #fff !important;
}
.hero.is-primary .title,
.hero.is-primary .subtitle { color: #fff !important; }

/* Bulma component theming — driven by CSS vars so any AI-chosen theme applies consistently */
.navbar, .navbar.is-light, .navbar.is-white { background-color: var(--color-background-secondary) !important; }
.navbar-item, .navbar-link { color: var(--color-text) !important; }
.navbar-item:hover, .navbar-link:hover, .navbar-item.is-active { background-color: var(--color-background) !important; color: var(--color-primary) !important; }
.navbar-burger span { background-color: var(--color-text) !important; }
.navbar-menu { background-color: var(--color-background-secondary); }
.card { background-color: var(--color-background-secondary); color: var(--color-text); border: 1px solid var(--color-border); }
.card-content, .card-header-title { color: var(--color-text); }
.box { background-color: var(--color-background-secondary); color: var(--color-text); border: 1px solid var(--color-border); box-shadow: none; }
.footer, .footer.has-background-light { background-color: var(--color-background-secondary) !important; }
.footer p, .footer .content { color: var(--color-text-muted); }
.footer .has-text-grey { color: var(--color-text-muted) !important; }
.footer a { color: var(--color-primary); }
.section { background-color: var(--color-background); }
.hero:not([class*='is-']), .hero.is-medium:not([class*='is-']), .hero.is-large:not([class*='is-']) { background-color: var(--color-background); color: var(--color-text); }
.title { color: var(--color-text); }
.subtitle { color: var(--color-text-muted); }
.has-text-grey, .has-text-grey-dark, .has-text-grey-light { color: var(--color-text-muted); }
.content { color: var(--color-text); }
strong { color: inherit; } /* Override Bulma 1.0.2's var(--bulma-strong-color) which goes near-white in OS dark mode */
hr { background-color: var(--color-border); height: 1px; }
table thead th { background-color: var(--color-background-secondary); color: var(--color-text); border-color: var(--color-border); }
table tbody td { border-color: var(--color-border); color: var(--color-text); }
.notification:not([class*='is-']) { background-color: var(--color-background-secondary); color: var(--color-text); }
.heading { color: var(--color-text-muted); }

/* Flash Messages */
.flash { padding: 1rem; margin-bottom: 1rem; border-radius: 4px; }
.flash-success { background: #d1fae5; color: #065f46; }
.flash-error   { background: #fee2e2; color: #991b1b; }
.flash-info    { background: #dbeafe; color: #1e40af; }

/* Admin edit bar */
.monolithcms-edit-bar {
    position: fixed;
    top: 0; left: 0; right: 0;
    z-index: 10000;
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
    color: #fff;
    padding: 0.5rem 1rem;
    font-family: system-ui, -apple-system, sans-serif;
    font-size: 14px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.3);
}
.edit-bar-inner {
    max-width: 1400px;
    margin: 0 auto;
    display: flex;
    align-items: center;
    gap: 1rem;
}
.edit-bar-logo { font-weight: 700; font-size: 16px; color: #60a5fa; margin-right: auto; }
.edit-bar-btn {
    padding: 0.4rem 1rem;
    border-radius: 6px;
    text-decoration: none;
    font-weight: 500;
    font-size: 13px;
    transition: all 0.2s;
}
.edit-bar-btn-edit  { background: #3b82f6; color: #fff; }
.edit-bar-btn-edit:hover  { background: #2563eb; }
.edit-bar-btn-exit  { background: #ef4444; color: #fff; }
.edit-bar-btn-exit:hover  { background: #dc2626; }
.edit-bar-btn:not(.edit-bar-btn-edit):not(.edit-bar-btn-exit) { background: rgba(255,255,255,0.1); color: #fff; }
.edit-bar-btn:not(.edit-bar-btn-edit):not(.edit-bar-btn-exit):hover { background: rgba(255,255,255,0.2); }
body:has(.monolithcms-edit-bar) { padding-top: 50px; }

/* Public page utilities */
a:hover { color: color-mix(in srgb, var(--color-primary) 80%, #000); }
.animate-fade-in { animation: fadeIn 0.6s ease-out; }
.animate-slide-up { animation: slideUp 0.6s ease-out; }
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
@keyframes slideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
.reveal { opacity: 0; transform: translateY(30px); transition: all 0.6s ease; }
.reveal.visible { opacity: 1; transform: translateY(0); }
.is-cursor-pointer { cursor: pointer; }
details summary { cursor: pointer; }
details summary::-webkit-details-marker { display: none; }
.section-sm { padding: 2rem 1.5rem; }
.hero-cta .hero-body { padding: 3rem 1.5rem; }
CSS;

        // Minify
        $css = preg_replace('/\/\*.*?\*\//s', '', $css); // Remove comments
        $css = preg_replace('/\s+/', ' ', $css);
        $css = preg_replace('/\s*([{}:;,])\s*/', '$1', $css);
        $css = trim($css);

        return $css;
    }

    public static function cacheAndGetHash(): string {
        $css = self::generate();
        // Append custom CSS from settings (admin-controlled)
        $customCss = Settings::get('custom_css', '');
        if (!empty($customCss)) {
            $css .= "\n/* Custom CSS */\n" . $customCss;
        }

        // In dev mode always regenerate; never read from stale cache
        if (MONOLITHCMS_DEV) {
            $file = MONOLITHCMS_CACHE . '/assets/app.dev.css';
            file_put_contents($file, $css);
            return 'app.dev';
        }

        $hash = 'app.' . substr(md5($css), 0, 12);
        $file = MONOLITHCMS_CACHE . '/assets/' . $hash . '.css';
        if (!file_exists($file)) {
            // Clear old CSS files
            foreach (glob(MONOLITHCMS_CACHE . '/assets/app.*.css') as $oldFile) {
                unlink($oldFile);
            }
            file_put_contents($file, $css);
        }
        return $hash;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 18: ASSET MANAGEMENT
// ─────────────────────────────────────────────────────────────────────────────

class Asset {
    private const ALLOWED_TYPES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/svg+xml', 'image/webp',
        'application/pdf', 'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    ];

    private const MAX_SIZE = 10 * 1024 * 1024; // 10MB

    /** Directory (relative to MONOLITHCMS_CACHE) where static asset files live */
    private const FILES_DIR = 'assets/files';

    // Return the path where a given asset is (or will be) stored on disk. The filename is <hash>.<ext>, making it permanently immutable.
    private static function staticPath(string $hash, string $mimeType): string {
        $ext = match ($mimeType) {
            'image/jpeg'       => 'jpg',
            'image/png'        => 'png',
            'image/gif'        => 'gif',
            'image/webp'       => 'webp',
            'image/svg+xml'    => 'svg',
            'application/pdf'  => 'pdf',
            default            => 'bin',
        };
        return MONOLITHCMS_CACHE . '/' . self::FILES_DIR . '/' . $hash . '.' . $ext;
    }

    // Ensure the asset BLOB has been extracted to a static file. Once written it is never rewritten — the hash guarantees immutability. Returns the absolute path to the static file.
    private static function ensureStaticFile(string $hash, string $mimeType, string $blobData): string {
        $path = self::staticPath($hash, $mimeType);
        if (!file_exists($path)) {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($path, $blobData);
        }
        return $path;
    }

    public static function store(array $file): int {
        // Validate upload
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Upload failed: ' . self::uploadErrorMessage($file['error']));
        }

        if ($file['size'] > self::MAX_SIZE) {
            throw new Exception('File too large. Maximum size is 10MB.');
        }

        // Validate MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, self::ALLOWED_TYPES, true)) {
            throw new Exception('File type not allowed: ' . $mimeType);
        }

        // Read file content
        $content = file_get_contents($file['tmp_name']);

        // Sanitize SVG before hashing so clean content is stored and deduplicated
        if ($mimeType === 'image/svg+xml') {
            $content = self::sanitizeSvg($content);
            if ($content === '') {
                throw new Exception('SVG file could not be parsed and was rejected.');
            }
        }

        $hash = hash('sha256', $content);

        // Check for duplicate
        $existing = DB::fetch("SELECT id FROM assets WHERE hash = ?", [$hash]);
        if ($existing) {
            return (int) $existing['id'];
        }

        // Strip metadata from raster images (security)
        if (str_starts_with($mimeType, 'image/') && $mimeType !== 'image/svg+xml') {
            $content = self::stripMetadata($content, $mimeType);
        }

        // Store in database
        DB::execute(
            "INSERT INTO assets (filename, mime_type, blob_data, hash, file_size, created_at)
             VALUES (?, ?, ?, ?, ?, datetime('now'))",
            [Sanitize::filename($file['name']), $mimeType, $content, $hash, strlen($content)]
        );

        // Write to static file immediately — avoids DB reads on every asset request
        self::ensureStaticFile($hash, $mimeType, $content);

        return DB::lastInsertId();
    }

    public static function get(int $id): ?array {
        return DB::fetch("SELECT * FROM assets WHERE id = ?", [$id]);
    }

    public static function getByHash(string $hash): ?array {
        return DB::fetch("SELECT * FROM assets WHERE hash = ?", [$hash]);
    }

    public static function serve(string $hash): void {
        // Release session lock before serving binary data
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        // Respond to conditional GET immediately (no DB or disk I/O needed)
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === '"' . $hash . '"') {
            http_response_code(304);
            exit;
        }

        // Look up metadata only (avoid loading the full BLOB if static file exists)
        $asset = DB::fetch("SELECT hash, mime_type FROM assets WHERE hash = ?", [$hash]);
        if (!$asset) {
            http_response_code(404);
            exit;
        }

        $staticPath = self::staticPath($asset['hash'], $asset['mime_type']);

        // Populate static file from DB blob on first request after a fresh deploy
        if (!file_exists($staticPath)) {
            $full = DB::fetch("SELECT blob_data FROM assets WHERE hash = ?", [$hash]);
            if (!$full) {
                http_response_code(404);
                exit;
            }
            self::ensureStaticFile($asset['hash'], $asset['mime_type'], $full['blob_data']);
        }

        header('Content-Type: ' . $asset['mime_type']);
        header('Content-Length: ' . filesize($staticPath));
        header('Cache-Control: public, max-age=31536000, immutable');
        header('ETag: "' . $hash . '"');

        readfile($staticPath);
        exit;
    }

    public static function url(int $id): string {
        $asset = self::get($id);
        return $asset ? '/assets/' . $asset['hash'] : '';
    }

    public static function delete(int $id): void {
        $asset = self::get($id);
        if ($asset) {
            $staticPath = self::staticPath($asset['hash'], $asset['mime_type']);
            if (file_exists($staticPath)) {
                unlink($staticPath);
            }
        }
        DB::execute("DELETE FROM assets WHERE id = ?", [$id]);
    }

    private static function sanitizeSvg(string $content): string {
        $dom = new DOMDocument();
        if (!@$dom->loadXML($content, LIBXML_NONET)) {
            return '';
        }

        $xpath = new DOMXPath($dom);

        // Remove <script> elements
        foreach (iterator_to_array($xpath->query('//*[local-name()="script"]')) as $node) {
            $node->parentNode->removeChild($node);
        }

        // Remove <foreignObject> (can embed arbitrary HTML)
        foreach (iterator_to_array($xpath->query('//*[local-name()="foreignObject"]')) as $node) {
            $node->parentNode->removeChild($node);
        }

        // Remove inline event handler attributes (onclick, onload, onerror, …)
        foreach (iterator_to_array($xpath->query('//@*[starts-with(local-name(), "on")]')) as $attr) {
            $attr->ownerElement->removeAttributeNode($attr);
        }

        // Remove javascript: URIs from href / xlink:href / src / action
        foreach (iterator_to_array($xpath->query('//@*[local-name()="href" or local-name()="src" or local-name()="action"]')) as $attr) {
            if (stripos(trim($attr->value), 'javascript:') === 0) {
                $attr->ownerElement->removeAttributeNode($attr);
            }
        }

        return $dom->saveXML() ?: '';
    }

    private static function stripMetadata(string $content, string $mimeType): string {
        $img = @imagecreatefromstring($content);
        if (!$img) {
            return $content;
        }

        // Preserve transparency for PNG
        if ($mimeType === 'image/png') {
            imagesavealpha($img, true);
        }

        ob_start();
        match ($mimeType) {
            'image/jpeg' => imagejpeg($img, null, 90),
            'image/png' => imagepng($img, null, 9),
            'image/gif' => imagegif($img),
            'image/webp' => imagewebp($img, null, 90),
            default => null
        };
        $clean = ob_get_clean();
        imagedestroy($img);

        return $clean ?: $content;
    }

    private static function uploadErrorMessage(int $error): string {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File is too large',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            default => 'Unknown upload error'
        };
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 19: CONTROLLERS
// ─────────────────────────────────────────────────────────────────────────────

class SetupController {
    public static function index(): void {
        // Fully complete → send to login
        if (Settings::get('setup_complete') === '1') {
            Response::redirect('/admin/login');
        }

        $step = (int) Request::input('step', 1);

        // If an admin account already exists (created in step 1), step 1 is no longer accessible — redirect to step 2 so the wizard continues from where it left off and the Create Admin form is never shown again.
        $hasAdmin = DB::fetch("SELECT COUNT(*) as cnt FROM users WHERE role = 'admin'");
        if ($hasAdmin && $hasAdmin['cnt'] > 0 && $step <= 1) {
            Response::redirect('/setup?step=2');
        }

        $stepClasses = [
            'step1_class' => $step === 1 ? 'active' : ($step > 1 ? 'done' : 'pending'),
            'step2_class' => $step === 2 ? 'active' : ($step > 2 ? 'done' : 'pending'),
            'step3_class' => $step === 3 ? 'active' : ($step > 3 ? 'done' : 'pending'),
            'step4_class' => $step >= 4 ? 'active' : 'pending',
        ];

        $stepContent = match ($step) {
            1 => Template::render('setup/step1'),
            2 => Template::render('setup/step2'),
            3 => Template::render('setup/step3'),
            4 => Template::render('setup/step4'),
            5 => Template::render('setup/complete'),
            default => Template::render('setup/step1')
        };

        $html = Template::render('setup/welcome', array_merge($stepClasses, [
            'step_content' => $stepContent
        ]));

        Response::html($html);
    }

    public static function process(): void {
        $step = (int) Request::input('step', 1);

        match ($step) {
            1 => self::processStep1(),
            2 => self::processStep2(),
            3 => self::processStep3(),
            4 => self::processStep4(),
            default => Response::redirect('/setup')
        };
    }

    private static function processStep1(): void {
        CSRF::require();

        // Guard: if an admin already exists, step 1 is already done
        $hasAdmin = DB::fetch("SELECT COUNT(*) as cnt FROM users WHERE role = 'admin'");
        if ($hasAdmin && $hasAdmin['cnt'] > 0) {
            Response::redirect('/setup?step=2');
        }

        $email = Sanitize::email(Request::input('email', ''));
        $password = Request::input('password', '');
        $confirm = Request::input('password_confirm', '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Session::flash('error', 'Please enter a valid email address.');
            Response::redirect('/setup?step=1');
        }

        if ($password !== $confirm) {
            Session::flash('error', 'Passwords do not match.');
            Response::redirect('/setup?step=1');
        }

        $passwordErrors = Password::isStrong($password);
        if (!empty($passwordErrors)) {
            Session::flash('error', implode(' ', $passwordErrors));
            Response::redirect('/setup?step=1');
        }

        DB::execute(
            "INSERT INTO users (email, password_hash, role, created_at) VALUES (?, ?, 'admin', datetime('now'))",
            [$email, Password::hash($password)]
        );

        Response::redirect('/setup?step=2');
    }

    private static function processStep2(): void {
        CSRF::require();

        $siteName = trim(Request::input('site_name', 'MonolithCMS'));
        $tagline = trim(Request::input('tagline', ''));

        Settings::set('site_name', $siteName);

        DB::execute(
            "UPDATE theme_header SET tagline = ? WHERE id = 1",
            [$tagline]
        );

        Response::redirect('/setup?step=3');
    }

    private static function processStep3(): void {
        CSRF::require();

        $provider = trim(Request::input('ai_provider', ''));
        $apiKey   = trim(Request::input('ai_api_key', ''));
        $model    = trim(Request::input('ai_model', ''));

        if ($provider && $apiKey) {
            Settings::set('ai_provider', $provider);
            Settings::setEncrypted('ai_api_key', $apiKey);
            if ($model) {
                Settings::set('ai_model', $model);
            } else {
                Settings::set('ai_model', match ($provider) {
                    'anthropic' => 'claude-sonnet-4-6',
                    'google'    => 'gemini-3-flash',
                    default     => 'gpt-5.4-pro',
                });
            }
        }

        Response::redirect('/setup?step=4');
    }

    private static function processStep4(): void {
        CSRF::require();

        $driver = trim(Request::input('email_driver', 'smtp'));
        $from   = trim(Request::input('smtp_from', ''));

        Settings::set('email_driver', $driver);
        if ($from) Settings::set('smtp_from', $from);

        switch ($driver) {
            case 'smtp':
                $host = trim(Request::input('smtp_host', ''));
                if ($host) {
                    Settings::set('smtp_host', $host);
                    Settings::set('smtp_port', (string)(int) Request::input('smtp_port', 587));
                    Settings::set('smtp_user', trim(Request::input('smtp_user', '')));
                    if (($p = Request::input('smtp_pass', '')) !== '') {
                        Settings::setEncrypted('smtp_pass', $p);
                    }
                }
                break;
            case 'mailgun':
                if (($k = Request::input('mailgun_api_key', '')) !== '') {
                    Settings::setEncrypted('mailgun_api_key', $k);
                }
                Settings::set('mailgun_domain', trim(Request::input('mailgun_domain', '')));
                break;
            case 'sendgrid':
                if (($k = Request::input('sendgrid_api_key', '')) !== '') {
                    Settings::setEncrypted('sendgrid_api_key', $k);
                }
                break;
            case 'postmark':
                if (($k = Request::input('postmark_api_key', '')) !== '') {
                    Settings::setEncrypted('postmark_api_key', $k);
                }
                break;
        }

        Settings::set('setup_complete', '1');
        // Enable MFA by default if email is configured (prevents lockout if no email)
        if (Settings::get('smtp_host', '') !== '' || Settings::get('email_driver', 'smtp') !== 'smtp') {
            Settings::set('mfa_enabled', '1');
        }
        Response::redirect('/setup?step=5');
    }
}

class AuthController {
    public static function loginForm(): void {
        if (Auth::check()) {
            Response::redirect('/admin');
        }

        Response::html(Template::render('admin/login', [
            'csp_nonce' => defined('CSP_NONCE') ? CSP_NONCE : '',
            'csrf_field' => '<input type="hidden" name="_csrf" value="' . CSRF::token() . '">'
        ]));
    }

    public static function login(): void {
        // No CSRF check on login — login CSRF is not a meaningful attack vector (attacker cannot know your password; rate limiting protects brute force)
        $email = Sanitize::email(Request::input('email', ''));
        $password = Request::input('password', '');

        $userId = Auth::attempt($email, $password);

        if ($userId === false) {
            Session::flash('error', 'Invalid email or password.');
            Response::redirect('/admin/login');
        }

        // Check if MFA is enabled
        if (MFA::isRequired()) {
            $code = MFA::generateOTP($userId);
            Mailer::sendMFACode($email, $code);
            Session::set('mfa_user_id', $userId);
            Response::redirect('/admin/mfa');
        }

        Auth::login($userId);
        Session::flash('success', 'Welcome back!');
        Response::redirect('/admin');
    }

    public static function mfaForm(): void {
        if (!Session::get('mfa_user_id')) {
            Response::redirect('/admin/login');
        }
        Response::html(Template::render('admin/mfa'));
    }

    public static function mfaVerify(): void {
        CSRF::require();

        $userId = Session::get('mfa_user_id');
        if (!$userId) {
            Response::redirect('/admin/login');
        }

        $code = Request::input('code', '');

        if (!MFA::verifyOTP($userId, $code)) {
            Session::flash('error', 'Invalid or expired code. Please try again.');
            Response::redirect('/admin/mfa');
        }

        Session::delete('mfa_user_id');
        Auth::login($userId);
        Session::flash('success', 'Welcome back!');
        Response::redirect('/admin');
    }

    public static function logout(): void {
        CSRF::require();
        Auth::logout();
        Response::redirect('/admin/login');
    }
}

class AdminController {
    // Helper to render admin page with common layout data
    private static function renderAdmin(string $template, string $title, array $data = [], array $layoutFlags = []): void {
        $user = Auth::user();
        $pendingCount = DB::fetch("SELECT COUNT(*) as c FROM build_queue WHERE status = 'pending'")['c'] ?? 0;
        $pendingItems = $pendingCount > 0
            ? DB::fetchAll("SELECT id, created_at FROM build_queue WHERE status = 'pending' ORDER BY created_at DESC LIMIT 5")
            : [];

        $flash = Session::getFlash();
        $flashError   = ($flash && $flash['type'] === 'error')   ? $flash['message'] : null;
        $flashSuccess = ($flash && $flash['type'] === 'success') ? $flash['message'] : null;

        $content = Template::render($template, $data);

        $layoutData = array_merge([
            'title'         => $title,
            'content'       => $content,
            'user_email'    => $user['email'] ?? '',
            'user_role'     => ucfirst($user['role'] ?? 'User'),
            'pending_count' => $pendingCount,
            'pending_items' => $pendingItems,
            'has_pending'   => $pendingCount > 0,
            'flash_error'   => $flashError,
            'flash_success' => $flashSuccess,
            'csp_nonce'     => defined('CSP_NONCE') ? CSP_NONCE : '',
            'csrf_field'    => '<input type="hidden" name="_csrf" value="' . CSRF::token() . '">',
            'blog_enabled'  => Settings::get('blog_enabled', '0') === '1',
            'is_dashboard'  => false,
            'is_pages'      => false,
            'is_nav'        => false,
            'is_media'      => false,
            'is_theme'      => false,
            'is_users'      => false,
            'is_ai'         => false,
            'is_ai_chat'    => false,
            'is_approvals'  => false,
            'is_blog'       => false,
            'is_blog_cats'  => false,
            'is_settings'   => false,
            'app_version'   => MONOLITHCMS_VERSION,
        ], $layoutFlags);

        Response::html(Template::render('admin_layout', $layoutData));
    }

    // ─── DASHBOARD ───────────────────────────────────────────────────────────
    public static function dashboard(): void {
        Auth::require();

        $stats = [
            'pages_count'    => DB::fetch("SELECT COUNT(*) as c FROM pages")['c'] ?? 0,
            'published_count'=> DB::fetch("SELECT COUNT(*) as c FROM pages WHERE status = 'published'")['c'] ?? 0,
            'assets_count'   => DB::fetch("SELECT COUNT(*) as c FROM assets")['c'] ?? 0,
            'pending_count'  => DB::fetch("SELECT COUNT(*) as c FROM build_queue WHERE status = 'pending'")['c'] ?? 0,
        ];

        $recentPages = DB::fetchAll("SELECT * FROM pages ORDER BY updated_at DESC LIMIT 5");
        foreach ($recentPages as &$page) {
            $page['is_published'] = $page['status'] === 'published';
        }
        $stats['recent_pages'] = $recentPages;
        $stats['has_pages']    = count($recentPages) > 0;

        // ── Security health checks ──────────────────────────────────────────
        // Skip warnings on the PHP built-in dev server (no .htaccess needed)
        $isCliServer = PHP_SAPI === 'cli-server';
        $stats['warn_sqlite_live_exposed'] = false;
        $stats['sqlite_probe_url']         = '';
        if (!$isCliServer) {
            $htaccessPath    = MONOLITHCMS_ROOT . '/.htaccess';
            $htaccessContent = file_exists($htaccessPath) ? file_get_contents($htaccessPath) : '';
            // Warn if .htaccess is completely absent
            $stats['warn_no_htaccess']    = $htaccessContent === '';
            // Warn if the FilesMatch block protecting SQLite/DB files is missing
            $stats['warn_sqlite_exposed'] = $htaccessContent !== '' && !str_contains($htaccessContent, 'sqlite');
            // Warn if the front-controller RewriteRule is missing
            $stats['warn_no_rewrite']     = $htaccessContent !== '' && !str_contains($htaccessContent, 'RewriteRule');

            // Live HTTP probe: actually request site.sqlite to confirm it is
            // unreachable — catches misconfigured nginx, missing .htaccess on
            // Apache, and any server that bypasses the front-controller.
            if (ini_get('allow_url_fopen')) {
                $scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $docRoot  = rtrim($_SERVER['DOCUMENT_ROOT'] ?? MONOLITHCMS_ROOT, '/');
                $appRoot  = rtrim(MONOLITHCMS_ROOT, '/');
                $basePath = ($docRoot !== '' && str_starts_with($appRoot, $docRoot))
                    ? substr($appRoot, strlen($docRoot))
                    : '';
                $probeUrl = $scheme . '://' . $host . $basePath . '/site.sqlite';
                $ctx = stream_context_create(['http' => [
                    'method'          => 'HEAD',
                    'timeout'         => 2.0,
                    'ignore_errors'   => true,
                    'follow_location' => false,
                ]]);
                @file_get_contents($probeUrl, false, $ctx);
                $statusLine = $http_response_header[0] ?? '';
                if (preg_match('#HTTP/\S+ 2\d\d#', $statusLine)) {
                    $stats['warn_sqlite_live_exposed'] = true;
                    $stats['sqlite_probe_url']         = $probeUrl;
                    // Suppress the static guesses — live proof supersedes them
                    $stats['warn_no_htaccess'] = $stats['warn_sqlite_exposed'] = false;
                }
            }
        } else {
            $stats['warn_no_htaccess'] = $stats['warn_sqlite_exposed'] = $stats['warn_no_rewrite'] = false;
        }

        self::renderAdmin('admin/dashboard', 'Dashboard', $stats, ['is_dashboard' => true]);
    }

    // ─── PAGES LIST ──────────────────────────────────────────────────────────
    public static function pages(): void {
        Auth::require('content.view');

        $pages = DB::fetchAll("SELECT * FROM pages ORDER BY updated_at DESC");

        // Add display flags
        foreach ($pages as &$page) {
            $page['is_published'] = $page['status'] === 'published';
        }

        self::renderAdmin('admin/pages', 'Pages', ['pages' => $pages, 'has_pages' => count($pages) > 0], ['is_pages' => true]);
    }

    // ─── PAGE EDIT/CREATE ────────────────────────────────────────────────────
    public static function editPage(string $id): void {
        Auth::require('content.edit');

        if ($id === 'new') {
            $page = [
                'id' => 'new',
                'title' => '',
                'slug' => '',
                'status' => 'draft',
                'meta_description' => '',
                'og_image' => '',
                'is_new' => true,
                'is_draft' => true,
                'is_published' => false,
            ];
            $blocks = [];
        } else {
            $page = DB::fetch("SELECT * FROM pages WHERE id = ?", [$id]);
            if (!$page) {
                Session::flash('error', 'Page not found.');
                Response::redirect('/admin/pages');
            }
            $page['is_new'] = false;
            $page['is_draft'] = $page['status'] === 'draft';
            $page['is_published'] = $page['status'] === 'published';

            // Extract og_image from meta_json
            $meta = json_decode($page['meta_json'] ?? '{}', true) ?? [];
            $page['og_image'] = $meta['og_image'] ?? '';

            $blocks = DB::fetchAll(
                "SELECT * FROM content_blocks WHERE page_id = ? ORDER BY sort_order ASC",
                [$id]
            );
        }

        // Prepare blocks for template
        $typeLabels = [
            'hero' => 'Hero Section',
            'text' => 'Text Content',
            'image' => 'Image',
            'cta' => 'Call to Action',
            'gallery' => 'Gallery',
            'form' => 'Contact Form'
        ];

        $typeIcons = [
            'hero' => 'featured_video',
            'text' => 'article',
            'image' => 'image',
            'cta' => 'campaign',
            'gallery' => 'photo_library',
            'form' => 'contact_mail'
        ];

        foreach ($blocks as &$block) {
            $block['type_label'] = $typeLabels[$block['type']] ?? ucfirst($block['type']);
            $block['type_icon'] = $typeIcons[$block['type']] ?? 'widgets';
            $block['block_fields'] = self::renderBlockFields($block);
        }

        $title = $id === 'new' ? 'Create Page' : 'Edit: ' . $page['title'];
        self::renderAdmin('admin/page_edit', $title, array_merge($page, [
            'csp_nonce' => defined('CSP_NONCE') ? CSP_NONCE : ''
        ]), ['is_pages' => true]);
    }

    private static function renderBlockFields(array $block): string {
        $data = json_decode($block['block_json'], true) ?? [];
        $id = $block['id'];
        $inputClass = 'w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none';
        $labelClass = 'block text-sm font-medium text-gray-700 mb-1';

        // AI-generated blocks: textarea for raw HTML + live preview
        if (isset($data['html'])) {
            $b = "blocks[{$id}][data]";
            $htmlContent = htmlspecialchars($data['html'] ?? '', ENT_QUOTES);
            return sprintf(
                '<div class="ai-html-block" data-block-id="%s">' .
                '<div class="flex items-center justify-between mb-1">' .
                '<label class="%s">HTML Source</label>' .
                '<button type="button" class="preview-ai-html text-xs px-2 py-1 bg-gray-100 hover:bg-gray-200 rounded border border-gray-200 transition-colors inline-flex items-center gap-1">' .
                '<span class="material-symbols-outlined text-sm">visibility</span> Preview</button>' .
                '</div>' .
                '<textarea name="%s[html]" rows="14" class="%s font-mono text-xs" placeholder="<section>...</section>" spellcheck="false">%s</textarea>' .
                '<div class="ai-html-preview hidden mt-2 border border-gray-200 rounded-lg overflow-hidden" style="height:300px;">' .
                '<iframe class="w-full h-full border-0" sandbox="allow-scripts allow-same-origin"></iframe>' .
                '</div>' .
                '</div>',
                $id, $labelClass,
                $b, $inputClass, $htmlContent
            );
        }

        // Helper: plain text input
        $inp = fn(string $label, string $name, string $val) => sprintf(
            '<div><label class="%s">%s</label><input type="text" name="%s" value="%s" class="%s"></div>',
            $labelClass, $label, $name, Sanitize::html($val), $inputClass
        );
        // Helper: textarea
        $ta = fn(string $label, string $name, string $val, int $rows = 3) => sprintf(
            '<div><label class="%s">%s</label><textarea name="%s" rows="%d" class="%s">%s</textarea></div>',
            $labelClass, $label, $name, $rows, $inputClass, Sanitize::html($val)
        );
        // Helper: JSON array textarea (uses *_json field names that savePage() decodes)
        $jsn = fn(string $label, string $name, mixed $val, int $rows = 6) => sprintf(
            '<div><label class="%s">%s</label><textarea name="%s" rows="%d" class="%s font-mono text-xs">%s</textarea></div>',
            $labelClass, $label, $name, $rows, $inputClass,
            htmlspecialchars(json_encode($val ?: [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
        );
        // Helper: image picker — thumbnail preview + text input + "Choose from Gallery" button
        $img = function(string $label, string $name, string $val) use ($labelClass, $inputClass): string {
            $esc = Sanitize::html($val);
            $thumb = $val
                ? sprintf('<img src="%s" alt="" class="w-full h-full object-cover">', $esc)
                : '<img src="https://picsum.photos/seed/placeholder/320/200" alt="placeholder" class="w-full h-full object-cover opacity-40">';
            return sprintf(
                '<div class="image-picker-widget">' .
                    '<label class="%s">%s</label>' .
                    '<div class="flex items-center gap-3 p-2 border border-gray-200 rounded-lg bg-gray-50 mt-1">' .
                        '<div class="image-thumb h-14 w-20 flex items-center justify-center rounded overflow-hidden bg-white border border-gray-100 shrink-0">%s</div>' .
                        '<div class="flex flex-col gap-2 min-w-0 flex-1">' .
                            '<input type="text" name="%s" value="%s" class="%s text-xs" placeholder="/assets/... or https://...">' .
                            '<button type="button" class="pick-image-btn self-start inline-flex items-center gap-1 px-2 py-1 text-xs font-medium text-[#135bec] bg-[#135bec]/5 border border-[#135bec]/20 rounded hover:bg-[#135bec]/10 transition-colors">' .
                                '<span class="material-symbols-outlined text-sm">photo_library</span> Choose from Gallery' .
                            '</button>' .
                        '</div>' .
                    '</div>' .
                '</div>',
                $labelClass, $label, $thumb, $name, $esc, $inputClass
            );
        };

        $b = "blocks[{$id}][data]";

        // Helper: single field inside a repeater item (uses data-key, not name)
        $repField = function(array $field, mixed $val) use ($inputClass): string {
            $esc = Sanitize::html((string)($val ?? ''));
            $lbl = sprintf('<label class="block text-xs font-medium text-gray-600 mb-0.5">%s</label>', $field['label']);
            $ic  = 'w-full px-2 py-1 border border-gray-300 rounded text-sm focus:ring-1 focus:ring-[#135bec] focus:border-[#135bec] outline-none';
            return match($field['type'] ?? 'text') {
                'image' => sprintf(
                    '<div class="image-picker-widget">%s' .
                    '<div class="flex items-center gap-2 p-1.5 border border-gray-200 rounded-lg bg-gray-50 mt-0.5">' .
                    '<div class="image-thumb h-10 w-14 flex items-center justify-center rounded overflow-hidden bg-white border border-gray-100 shrink-0">%s</div>' .
                    '<div class="flex flex-col gap-1 min-w-0 flex-1">' .
                    '<input type="text" data-key="%s" value="%s" class="%s text-xs" placeholder="/assets/... or https://...">' .
                    '<button type="button" class="pick-image-btn self-start inline-flex items-center gap-1 px-1.5 py-0.5 text-xs font-medium text-[#135bec] bg-[#135bec]/5 border border-[#135bec]/20 rounded hover:bg-[#135bec]/10 transition-colors">' .
                    '<span class="material-symbols-outlined text-xs">photo_library</span> Choose</button>' .
                    '</div></div></div>',
                    $lbl,
                    $esc ? sprintf('<img src="%s" alt="" class="w-full h-full object-cover">', $esc)
                         : sprintf('<img src="https://picsum.photos/seed/%s/160/112" alt="placeholder" class="w-full h-full object-cover opacity-40">', $field['key']),
                    $field['key'], $esc, $ic
                ),
                'textarea' => sprintf(
                    '<div>%s<textarea data-key="%s" rows="2" class="%s">%s</textarea></div>',
                    $lbl, $field['key'], $ic, Sanitize::html((string)($val ?? ''))
                ),
                default => sprintf(
                    '<div>%s<input type="text" data-key="%s" value="%s" class="%s"></div>',
                    $lbl, $field['key'], $esc, $ic
                ),
            };
        };

        // Helper: structured repeater widget (add/remove/reorder rows, each row has typed sub-fields)
        $rep = function(string $label, string $name, array $items, array $fields) use ($labelClass, &$repField): string {
            $fieldsJson = htmlspecialchars(
                json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ENT_QUOTES
            );
            $itemsHtml = '';
            foreach ($items as $i => $item) {
                if (!is_array($item)) continue;
                $fh = '';
                foreach ($fields as $f) { $fh .= $repField($f, $item[$f['key']] ?? ''); }
                $itemsHtml .= sprintf(
                    '<div class="repeater-item border border-gray-200 rounded-lg p-3 bg-white">' .
                    '<div class="flex items-center justify-between mb-2">' .
                    '<span class="repeater-item-label text-xs font-medium text-gray-400">Item %d</span>' .
                    '<div class="flex gap-1">' .
                    '<button type="button" class="repeater-move-up p-0.5 text-gray-400 hover:text-gray-600 rounded" title="Move up"><span class="material-symbols-outlined text-sm">arrow_upward</span></button>' .
                    '<button type="button" class="repeater-move-down p-0.5 text-gray-400 hover:text-gray-600 rounded" title="Move down"><span class="material-symbols-outlined text-sm">arrow_downward</span></button>' .
                    '<button type="button" class="repeater-remove p-0.5 text-red-400 hover:text-red-600 rounded" title="Remove"><span class="material-symbols-outlined text-sm">close</span></button>' .
                    '</div></div><div class="space-y-2">%s</div></div>',
                    $i + 1, $fh
                );
            }
            $empty = !$itemsHtml
                ? '<p class="repeater-empty-state text-xs text-gray-400 py-2 text-center">No items yet — click Add to create one.</p>'
                : '';
            return sprintf(
                '<div class="block-repeater" data-fields="%s">' .
                '<div class="flex items-center justify-between mb-1.5">' .
                '<label class="%s">%s</label>' .
                '<button type="button" class="repeater-add-btn inline-flex items-center gap-1 px-2 py-1 text-xs font-medium text-[#135bec] bg-[#135bec]/5 border border-[#135bec]/20 rounded hover:bg-[#135bec]/10 transition-colors">' .
                '<span class="material-symbols-outlined text-sm">add</span> Add</button>' .
                '</div>' .
                '<div class="repeater-items space-y-2">%s%s</div>' .
                '<textarea name="%s" class="hidden repeater-json">%s</textarea>' .
                '</div>',
                $fieldsJson, $labelClass, $label, $itemsHtml, $empty, $name,
                htmlspecialchars(json_encode(array_values($items ?: []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            );
        };

        $fields = match ($block['type']) {
            'hero' => '<div class="space-y-3">' .
                $inp('Heading', "{$b}[title]", $data['title'] ?? '') .
                $inp('Subtitle', "{$b}[subtitle]", $data['subtitle'] ?? '') .
                '<div class="grid grid-cols-2 gap-3">' .
                $inp('Button Text', "{$b}[button]", $data['button'] ?? '') .
                $inp('Button URL', "{$b}[url]", $data['url'] ?? '') .
                '</div>' .
                $img('Background Image', "{$b}[image]", $data['image'] ?? '') .
                '</div>',

            'text' => sprintf(
                '<div><label class="%s">Content</label><div class="wysiwyg-editor bg-white border border-gray-300 rounded-lg" data-field="%s">%s</div><textarea name="%s" class="wysiwyg-content hidden">%s</textarea></div>',
                $labelClass, $id, $data['content'] ?? '',
                "{$b}[content]", Sanitize::html($data['content'] ?? '')
            ),

            'image' => '<div class="space-y-3">' .
                $img('Image', "{$b}[url]", $data['url'] ?? '') .
                $inp('Alt Text', "{$b}[alt]", $data['alt'] ?? '') .
                $inp('Caption', "{$b}[caption]", $data['caption'] ?? '') .
                '</div>',

            'cta' => '<div class="space-y-3">' .
                $inp('Title', "{$b}[title]", $data['title'] ?? '') .
                $ta('Text', "{$b}[text]", $data['text'] ?? '', 2) .
                '<div class="grid grid-cols-2 gap-3">' .
                $inp('Button Text', "{$b}[button]", $data['button'] ?? '') .
                $inp('Button URL', "{$b}[url]", $data['url'] ?? '') .
                '</div></div>',

            'quote' => '<div class="space-y-3">' .
                $ta('Quote Text', "{$b}[text]", $data['text'] ?? '', 3) .
                $inp('Author', "{$b}[author]", $data['author'] ?? '') .
                $inp('Role / Title', "{$b}[role]", $data['role'] ?? '') .
                '</div>',

            'newsletter' => '<div class="space-y-3">' .
                $inp('Heading', "{$b}[title]", $data['title'] ?? '') .
                $ta('Subtext', "{$b}[text]", $data['text'] ?? '', 2) .
                '<div class="grid grid-cols-2 gap-3">' .
                $inp('Button Label', "{$b}[button]", $data['button'] ?? 'Subscribe') .
                $inp('Placeholder', "{$b}[placeholder]", $data['placeholder'] ?? 'Enter your email') .
                '</div></div>',

            'gallery' => sprintf(
                '<div class="space-y-3">
                    <div class="gallery-images-field"><div class="flex items-center justify-between mb-1"><label class="%s">Images (one URL per line)</label><button type="button" class="gallery-add-image-btn inline-flex items-center gap-1 px-2 py-1 text-xs font-medium text-[#135bec] bg-[#135bec]/5 border border-[#135bec]/20 rounded hover:bg-[#135bec]/10 transition-colors"><span class="material-symbols-outlined text-sm">add_photo_alternate</span>Pick Image</button></div><textarea name="blocks[%s][data][images]" rows="4" class="%s">%s</textarea></div>
                    <div><label class="%s">Columns</label><select name="blocks[%s][data][columns]" class="%s">
                        <option value="2" %s>2 columns</option>
                        <option value="3" %s>3 columns</option>
                        <option value="4" %s>4 columns</option>
                    </select></div>
                </div>',
                $labelClass, $id, $inputClass,
                Sanitize::html(is_array($data['images'] ?? null)
                    ? implode("\n", array_map(fn($i) => is_array($i) ? ($i['url'] ?? '') : (string)$i, $data['images']))
                    : ($data['images'] ?? '')),
                $labelClass, $id, $inputClass,
                ($data['columns'] ?? '3') == '2' ? 'selected' : '',
                ($data['columns'] ?? '3') == '3' ? 'selected' : '',
                ($data['columns'] ?? '3') == '4' ? 'selected' : ''
            ),

            'features' => '<div class="space-y-3">' .
                $inp('Title', "{$b}[title]", $data['title'] ?? '') .
                $inp('Subtitle', "{$b}[subtitle]", $data['subtitle'] ?? '') .
                $rep('Feature Items', "{$b}[items_json]", $data['items'] ?? [], [
                    ['key' => 'icon',        'label' => 'Icon (Material icon or emoji)', 'type' => 'text'],
                    ['key' => 'title',       'label' => 'Title',                         'type' => 'text'],
                    ['key' => 'description', 'label' => 'Description',                   'type' => 'textarea'],
                ]) .
                '</div>',

            'cards' => '<div class="space-y-3">' .
                $inp('Title', "{$b}[title]", $data['title'] ?? '') .
                $rep('Cards', "{$b}[items_json]", $data['items'] ?? [], [
                    ['key' => 'image',       'label' => 'Image',       'type' => 'image'],
                    ['key' => 'title',       'label' => 'Title',       'type' => 'text'],
                    ['key' => 'description', 'label' => 'Description', 'type' => 'textarea'],
                    ['key' => 'icon',        'label' => 'Icon',        'type' => 'text'],
                    ['key' => 'url',         'label' => 'Link URL',   'type' => 'url'],
                    ['key' => 'button',      'label' => 'Button Text', 'type' => 'text'],
                ]) .
                '</div>',

            'stats' => '<div class="space-y-3">' .
                $inp('Title', "{$b}[title]", $data['title'] ?? '') .
                $rep('Stats', "{$b}[items_json]", $data['items'] ?? [], [
                    ['key' => 'value', 'label' => 'Value',  'type' => 'text'],
                    ['key' => 'label', 'label' => 'Label',  'type' => 'text'],
                    ['key' => 'icon',  'label' => 'Icon',   'type' => 'text'],
                ]) .
                '</div>',

            'testimonials' => '<div class="space-y-3">' .
                $inp('Section Title', "{$b}[title]", $data['title'] ?? '') .
                $rep('Testimonials', "{$b}[items_json]", $data['items'] ?? [], [
                    ['key' => 'quote',  'label' => 'Quote',         'type' => 'textarea'],
                    ['key' => 'name',   'label' => 'Name',          'type' => 'text'],
                    ['key' => 'role',   'label' => 'Role',          'type' => 'text'],
                    ['key' => 'rating', 'label' => 'Rating (1–5)', 'type' => 'text'],
                ]) .
                '</div>',

            'pricing' => '<div class="space-y-3">' .
                $inp('Title', "{$b}[title]", $data['title'] ?? '') .
                $inp('Subtitle', "{$b}[subtitle]", $data['subtitle'] ?? '') .
                $jsn('Plans — [{&quot;name&quot;, &quot;price&quot;, &quot;period&quot;, &quot;features&quot;:[], &quot;button&quot;, &quot;url&quot;, &quot;featured&quot;:false}]', "{$b}[plans_json]", $data['plans'] ?? $data['items'] ?? [], 10) .
                '</div>',

            'team' => '<div class="space-y-3">' .
                $inp('Section Title', "{$b}[title]", $data['title'] ?? '') .
                $rep('Members', "{$b}[members_json]", $data['members'] ?? $data['items'] ?? [], [
                    ['key' => 'photo', 'label' => 'Photo',        'type' => 'image'],
                    ['key' => 'name',  'label' => 'Name',         'type' => 'text'],
                    ['key' => 'role',  'label' => 'Role / Title', 'type' => 'text'],
                    ['key' => 'bio',   'label' => 'Bio',          'type' => 'textarea'],
                    ['key' => 'url',   'label' => 'Profile URL',  'type' => 'url'],
                ]) .
                '</div>',

            'faq' => '<div class="space-y-3">' .
                $inp('Title', "{$b}[title]", $data['title'] ?? '') .
                $rep('Questions', "{$b}[items_json]", $data['items'] ?? [], [
                    ['key' => 'question', 'label' => 'Question', 'type' => 'text'],
                    ['key' => 'answer',   'label' => 'Answer',   'type' => 'textarea'],
                ]) .
                '</div>',

            'timeline' => '<div class="space-y-3">' .
                $inp('Title', "{$b}[title]", $data['title'] ?? '') .
                $rep('Events', "{$b}[items_json]", $data['items'] ?? [], [
                    ['key' => 'date',        'label' => 'Date',        'type' => 'text'],
                    ['key' => 'title',       'label' => 'Title',       'type' => 'text'],
                    ['key' => 'description', 'label' => 'Description', 'type' => 'textarea'],
                ]) .
                '</div>',

            'steps' => '<div class="space-y-3">' .
                $inp('Title', "{$b}[title]", $data['title'] ?? '') .
                $rep('Steps', "{$b}[items_json]", $data['items'] ?? [], [
                    ['key' => 'number',      'label' => 'Number',      'type' => 'text'],
                    ['key' => 'title',       'label' => 'Title',       'type' => 'text'],
                    ['key' => 'description', 'label' => 'Description', 'type' => 'textarea'],
                ]) .
                '</div>',

            'checklist' => '<div class="space-y-3">' .
                $inp('Title', "{$b}[title]", $data['title'] ?? '') .
                sprintf('<div><label class="%s">Items (one per line)</label><textarea name="%s" rows="5" class="%s">%s</textarea></div>',
                    $labelClass, "{$b}[items_text]", $inputClass,
                    Sanitize::html(implode("\n", array_filter(array_map(
                        fn($i) => is_string($i) ? $i : (string)($i['text'] ?? $i['label'] ?? ''),
                        $data['items'] ?? []
                    ))))
                ) .
                '</div>',

            'contact_info' => '<div class="space-y-3">' .
                $inp('Title', "{$b}[title]", $data['title'] ?? '') .
                $rep('Contact Items', "{$b}[items_json]", $data['items'] ?? [], [
                    ['key' => 'icon',  'label' => 'Icon (Material icon)', 'type' => 'text'],
                    ['key' => 'label', 'label' => 'Label',                'type' => 'text'],
                    ['key' => 'value', 'label' => 'Value',                'type' => 'text'],
                    ['key' => 'url',   'label' => 'URL (optional)',       'type' => 'url'],
                ]) .
                '</div>',

            'logo_cloud' => '<div class="space-y-3">' .
                $inp('Label', "{$b}[title]", $data['title'] ?? '') .
                $rep('Logos', "{$b}[items_json]", $data['items'] ?? $data['logos'] ?? [], [
                    ['key' => 'url',  'label' => 'Logo Image',   'type' => 'image'],
                    ['key' => 'name', 'label' => 'Company Name', 'type' => 'text'],
                ]) .
                '</div>',

            'comparison' => '<div class="space-y-3">' .
                $inp('Title', "{$b}[title]", $data['title'] ?? '') .
                $jsn('Headers — [&quot;Feature&quot;, &quot;Basic&quot;, &quot;Pro&quot;]', "{$b}[headers_json]", $data['headers'] ?? [], 3) .
                $jsn('Rows — [[&quot;Feature name&quot;, &quot;Yes&quot;, &quot;No&quot;], ...]', "{$b}[rows_json]", $data['rows'] ?? [], 7) .
                '</div>',

            'tabs' => $rep('Tabs', "{$b}[items_json]", $data['items'] ?? [], [
                ['key' => 'label',   'label' => 'Tab Label', 'type' => 'text'],
                ['key' => 'content', 'label' => 'Content',   'type' => 'textarea'],
            ]),

            'accordion' => '<div class="space-y-3">' .
                $inp('Title', "{$b}[title]", $data['title'] ?? '') .
                $rep('Sections', "{$b}[items_json]", $data['items'] ?? [], [
                    ['key' => 'title',   'label' => 'Title',   'type' => 'text'],
                    ['key' => 'content', 'label' => 'Content', 'type' => 'textarea'],
                ]) .
                '</div>',

            'columns' => '<div class="space-y-3">' .
                $jsn('Columns — [{&quot;content&quot;:&quot;HTML or text&quot;}, ...]', "{$b}[columns_json]", $data['columns'] ?? [], 6) .
                '</div>',

            'social' => '<div class="space-y-3">' .
                $inp('Label', "{$b}[title]", $data['title'] ?? '') .
                $rep('Links', "{$b}[items_json]", $data['items'] ?? $data['links'] ?? [], [
                    ['key' => 'platform', 'label' => 'Platform',     'type' => 'text'],
                    ['key' => 'url',      'label' => 'URL',          'type' => 'url'],
                    ['key' => 'icon',     'label' => 'Icon (emoji)', 'type' => 'text'],
                ]) .
                '</div>',

            'carousel' => '<div class="space-y-3">' .
                $inp('Title', "{$b}[title]", $data['title'] ?? '') .
                $rep('Slides', "{$b}[items_json]", $data['items'] ?? [], [
                    ['key' => 'image', 'label' => 'Slide Image',    'type' => 'image'],
                    ['key' => 'title', 'label' => 'Title',           'type' => 'text'],
                    ['key' => 'text',  'label' => 'Caption / Text', 'type' => 'textarea'],
                ]) .
                '</div>',

            'progress' => '<div class="space-y-3">' .
                $inp('Title', "{$b}[title]", $data['title'] ?? '') .
                $rep('Progress Items', "{$b}[items_json]", $data['items'] ?? [], [
                    ['key' => 'label', 'label' => 'Label',        'type' => 'text'],
                    ['key' => 'value', 'label' => 'Value (0–100)', 'type' => 'text'],
                ]) .
                '</div>',

            'form' => '<div class="p-4 bg-blue-50 rounded-lg"><p class="text-sm text-blue-700 flex items-center gap-2"><span class="material-symbols-outlined">info</span>This block displays a contact form. No additional configuration needed.</p></div>',

            default => '<div class="space-y-2">' .
                sprintf('<p class="text-xs text-gray-500">Block type: <code>%s</code> — edit raw JSON:</p>', Sanitize::html($block['type'])) .
                $jsn('Block data (JSON)', "{$b}[items_json]", $data, 6) .
                '</div>',
        };

        // Anchor field — appended to every block type
        $anchorVal = Sanitize::html($data['anchor'] ?? '');
        $anchorField = sprintf(
            '<div class="pt-3 mt-3 border-t border-gray-200">
                <label class="%s flex items-center gap-1">
                    <span class="material-symbols-outlined text-base text-gray-400">link</span>
                    Anchor ID <span class="font-normal text-gray-400">(for /#hash nav links)</span>
                </label>
                <input type="text" name="blocks[%s][data][anchor]" value="%s"
                       placeholder="e.g. stack, about, contact"
                       pattern="[a-z0-9\-_]*"
                       class="%s font-mono text-sm">
                <p class="mt-1 text-xs text-gray-400">Lowercase letters, numbers, hyphens only. Leave blank to auto-generate from the block title.</p>
            </div>',
            $labelClass, $id, $anchorVal, $inputClass
        );

        return $fields . $anchorField;
    }

    // ─── PAGE SAVE ───────────────────────────────────────────────────────────
    public static function savePage(string $id): void {
        Auth::require('content.edit');
        CSRF::require();

        $title = trim(Request::input('title', ''));
        $slug = Sanitize::slug(Request::input('slug', ''));
        $status = Request::input('status', 'draft');
        $metaDesc = trim(Request::input('meta_description', ''));
        $ogImage = trim(Request::input('og_image', ''));

        if (empty($title) || empty($slug)) {
            Session::flash('error', 'Title and slug are required.');
            Response::redirect($id === 'new' ? '/admin/pages/new' : '/admin/pages/' . $id);
        }

        // Check slug uniqueness
        $existing = DB::fetch("SELECT id FROM pages WHERE slug = ? AND id != ?", [$slug, $id === 'new' ? 0 : $id]);
        if ($existing) {
            Session::flash('error', 'A page with this slug already exists.');
            Response::redirect($id === 'new' ? '/admin/pages/new' : '/admin/pages/' . $id);
        }

        $db = DB::connect();
        $db->beginTransaction();

        try {
            $now = date('Y-m-d H:i:s');
            // Preserve existing layout settings when form controls are absent (sidebar removed)
            $existingMeta = [];
            if ($id !== 'new') {
                $existingRow = DB::fetch("SELECT meta_json FROM pages WHERE id = ?", [$id]);
                $existingMeta = json_decode($existingRow['meta_json'] ?? '{}', true) ?? [];
            }
            $existingLayout = $existingMeta['layout'] ?? [];
            $layoutShowNav    = isset($_POST['layout_show_nav'])        ? (Request::input('layout_show_nav','0') === '1')        : ($existingLayout['show_nav'] ?? true);
            $layoutShowFooter = isset($_POST['layout_show_footer'])     ? (Request::input('layout_show_footer','0') === '1')     : ($existingLayout['show_footer'] ?? true);
            $layoutShowTitle  = isset($_POST['layout_show_page_title']) ? (Request::input('layout_show_page_title','0') === '1') : ($existingLayout['show_page_title'] ?? true);
            $rawNavStyle      = isset($_POST['layout_nav_style']) ? Request::input('layout_nav_style','sticky') : ($existingLayout['nav_style'] ?? 'sticky');
            $layoutNavStyle   = in_array($rawNavStyle, ['sticky', 'transparent']) ? $rawNavStyle : 'sticky';
            $meta = json_encode([
                'og_image' => $ogImage,
                'layout'   => [
                    'show_nav'        => $layoutShowNav,
                    'show_footer'     => $layoutShowFooter,
                    'show_page_title' => $layoutShowTitle,
                    'nav_style'       => $layoutNavStyle,
                ],
            ]);

            if ($id === 'new') {
                $stmt = $db->prepare("INSERT INTO pages (title, slug, status, meta_description, meta_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$title, $slug, $status, $metaDesc, $meta, $now, $now]);
                $pageId = $db->lastInsertId();
            } else {
                // Create full page snapshot (page row + all blocks) before update
                Revision::snapshotPage((int)$id);

                $stmt = $db->prepare("UPDATE pages SET title = ?, slug = ?, status = ?, meta_description = ?, meta_json = ?, updated_at = ? WHERE id = ?");
                $stmt->execute([$title, $slug, $status, $metaDesc, $meta, $now, $id]);
                $pageId = $id;
            }

            // Handle content blocks
            $blocks = Request::input('blocks', []);
            $existingBlockIds = [];

            foreach ($blocks as $blockKey => $blockData) {
                $blockId = $blockData['id'] ?? '';
                $blockType = $blockData['type'] ?? 'text';
                $blockOrder = (int)($blockData['order'] ?? 0);

                // Process block data - convert JSON text fields back to arrays
                $data = $blockData['data'] ?? [];

                // Parse all JSON fields
                $jsonMappings = [
                    'items_json' => 'items',
                    'plans_json' => 'plans',
                    'members_json' => 'members',
                    'columns_json' => 'columns',
                    'headers_json' => 'headers',
                    'rows_json' => 'rows'
                ];

                foreach ($jsonMappings as $jsonField => $targetField) {
                    if (!empty($data[$jsonField])) {
                        $parsed = json_decode($data[$jsonField], true);
                        if (is_array($parsed)) {
                            $data[$targetField] = $parsed;
                        }
                        unset($data[$jsonField]);
                    }
                }

                // Parse text-based array fields (one item per line)
                if (!empty($data['items_text'])) {
                    $lines = array_filter(array_map('trim', explode("\n", $data['items_text'])));
                    $data['items'] = array_values($lines);
                    unset($data['items_text']);
                }

                $blockJson = json_encode($data);

                if (empty($blockId) || str_starts_with((string)$blockKey, 'new_')) {
                    // New block
                    $stmt = $db->prepare("INSERT INTO content_blocks (page_id, type, block_json, sort_order) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$pageId, $blockType, $blockJson, $blockOrder]);
                    $existingBlockIds[] = $db->lastInsertId(); // keep from immediate DELETE
                } else {
                    // Update existing block
                    $stmt = $db->prepare("UPDATE content_blocks SET type = ?, block_json = ?, sort_order = ? WHERE id = ? AND page_id = ?");
                    $stmt->execute([$blockType, $blockJson, $blockOrder, $blockId, $pageId]);
                    $existingBlockIds[] = $blockId;
                }
            }

            // Delete removed blocks (only for existing pages)
            if ($id !== 'new' && !empty($existingBlockIds)) {
                $placeholders = str_repeat('?,', count($existingBlockIds) - 1) . '?';
                $stmt = $db->prepare("DELETE FROM content_blocks WHERE page_id = ? AND id NOT IN ($placeholders)");
                $stmt->execute(array_merge([$pageId], $existingBlockIds));
            } elseif ($id !== 'new' && empty($existingBlockIds) && isset($_POST['blocks'])) {
                // Blocks field submitted but empty — intentional delete of all blocks
                $db->prepare("DELETE FROM content_blocks WHERE page_id = ?")->execute([$pageId]);
            }

            $db->commit();

            // Handle add to navigation
            $addToNav = Request::input('add_to_nav', '');
            $navPosition = (int)Request::input('nav_position', 0);
            $pageUrl = '/' . $slug;

            // Check if page is already in nav
            $existingNav = DB::fetch("SELECT id, label, sort_order FROM nav WHERE url = ?", [$pageUrl]);

            if ($addToNav) {
                if (!$existingNav) {
                    // Add to navigation (works for both new and existing pages)
                    DB::execute("UPDATE nav SET sort_order = sort_order + 1 WHERE sort_order >= ?", [$navPosition]);
                    DB::execute(
                        "INSERT INTO nav (label, url, sort_order, visible) VALUES (?, ?, ?, 1)",
                        [$title, $pageUrl, $navPosition]
                    );
                    Cache::onContentChange('nav');
                } elseif ($existingNav['label'] !== $title) {
                    // Update nav item label if page title changed
                    DB::execute("UPDATE nav SET label = ? WHERE id = ?", [$title, $existingNav['id']]);
                    Cache::onContentChange('nav');
                }
            } else {
                // Remove from navigation if unchecked
                if ($existingNav) {
                    DB::execute("DELETE FROM nav WHERE id = ?", [$existingNav['id']]);
                    // Reorder remaining items
                    DB::execute("UPDATE nav SET sort_order = sort_order - 1 WHERE sort_order > ?", [$existingNav['sort_order']]);
                    Cache::onContentChange('nav');
                }
            }

            // Invalidate cache
            Cache::onContentChange('pages', (int)$pageId);

            Session::flash('success', 'Page saved successfully.');
            Response::redirect('/admin/pages/' . $pageId);
        } catch (Exception $e) {
            $db->rollBack();
            Session::flash('error', 'Failed to save page: ' . $e->getMessage());
            Response::redirect($id === 'new' ? '/admin/pages/new' : '/admin/pages/' . $id);
        }
    }

    // ─── PAGE DELETE ─────────────────────────────────────────────────────────
    public static function deletePage(string $id): void {
        Auth::require('content.edit');
        CSRF::require();

        $page = DB::fetch("SELECT slug FROM pages WHERE id = ?", [$id]);
        if (!$page) {
            Session::flash('error', 'Page not found.');
            Response::redirect('/admin/pages');
        }

        $db = DB::connect();
        $db->beginTransaction();

        try {
            // Delete blocks first
            $db->prepare("DELETE FROM content_blocks WHERE page_id = ?")->execute([$id]);
            // Delete page
            $db->prepare("DELETE FROM pages WHERE id = ?")->execute([$id]);

            $db->commit();

            // Invalidate cache
            Cache::invalidatePage($page['slug']);

            Session::flash('success', 'Page deleted.');
            Response::redirect('/admin/pages');
        } catch (Exception $e) {
            $db->rollBack();
            Session::flash('error', 'Failed to delete page.');
            Response::redirect('/admin/pages');
        }
    }

    // ─── NAVIGATION ──────────────────────────────────────────────────────────
    public static function nav(): void {
        Auth::require('content.edit');

        $items = DB::fetchAll("SELECT * FROM nav ORDER BY sort_order ASC");

        foreach ($items as &$item) {
            $item['visible'] = (bool)$item['visible'];
        }

        self::renderAdmin('admin/nav', 'Navigation', [
            'items' => $items,
            'nav_count' => count($items),
            'csp_nonce' => defined('CSP_NONCE') ? CSP_NONCE : ''
        ], ['is_nav' => true]);
    }

    public static function saveNav(): void {
        Auth::require('content.edit');
        CSRF::require();

        $navItems = Request::input('nav', []);

        $db = DB::connect();
        $db->beginTransaction();

        try {
            $existingIds = [];

            foreach ($navItems as $key => $item) {
                $itemId = $item['id'] ?? '';
                $label = trim($item['label'] ?? '');
                $url = trim($item['url'] ?? '');
                $visible = isset($item['visible']) ? 1 : 0;
                $order = (int)($item['order'] ?? 0);

                if (empty($label) || empty($url)) continue;

                if (empty($itemId) || str_starts_with((string)$key, 'new_')) {
                    // New item
                    $stmt = $db->prepare("INSERT INTO nav (label, url, visible, sort_order) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$label, $url, $visible, $order]);
                    $existingIds[] = (int)$db->lastInsertId();
                } else {
                    // Update existing
                    $stmt = $db->prepare("UPDATE nav SET label = ?, url = ?, visible = ?, sort_order = ? WHERE id = ?");
                    $stmt->execute([$label, $url, $visible, $order, $itemId]);
                    $existingIds[] = $itemId;
                }
            }

            // Delete removed items
            if (!empty($existingIds)) {
                $placeholders = str_repeat('?,', count($existingIds) - 1) . '?';
                $db->prepare("DELETE FROM nav WHERE id NOT IN ($placeholders)")->execute($existingIds);
            } else {
                $db->exec("DELETE FROM nav");
            }

            $db->commit();

            Cache::onContentChange('nav');

            Session::flash('success', 'Navigation saved.');
            Response::redirect('/admin/nav');
        } catch (Exception $e) {
            $db->rollBack();
            Session::flash('error', 'Failed to save navigation.');
            Response::redirect('/admin/nav');
        }
    }

    // ─── CACHE MANAGEMENT ────────────────────────────────────────────────────
    public static function regenerateCache(): void {
        Auth::require('*');  // Admin only
        CSRF::require();

        $stats = Cache::regenerateAll();

        if (empty($stats['errors'])) {
            Session::flash('success', sprintf(
                'Cache regenerated successfully! %d pages, %d partials rebuilt.',
                $stats['pages'],
                $stats['partials']
            ));
        } else {
            Session::flash('error', 'Cache regeneration completed with errors: ' . implode(', ', $stats['errors']));
        }

        Response::redirect('/admin');
    }

    // ─── MEDIA LIBRARY ───────────────────────────────────────────────────────
    public static function media(): void {
        Auth::require('media.upload');

        $assets = DB::fetchAll("SELECT * FROM assets ORDER BY created_at DESC");

        // Add display data
        foreach ($assets as &$asset) {
            $asset['is_image'] = str_starts_with($asset['mime_type'], 'image/');
            $asset['size'] = self::formatBytes(strlen($asset['blob_data']));
            $asset['icon'] = match(true) {
                str_starts_with($asset['mime_type'], 'image/') => '🖼️',
                $asset['mime_type'] === 'application/pdf' => '📄',
                str_contains($asset['mime_type'], 'word') => '📝',
                default => '📁'
            };
            // Don't send blob data to template
            unset($asset['blob_data']);
        }

        self::renderAdmin('admin/media', 'Media Library', [
            'assets' => $assets,
            'csp_nonce' => defined('CSP_NONCE') ? CSP_NONCE : '',
            'csrf_token' => CSRF::token()
        ], ['is_media' => true]);
    }

    private static function formatBytes(int $bytes): string {
        if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
        if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
        return $bytes . ' B';
    }

    public static function uploadMedia(): void {
        Auth::require('media.upload');
        CSRF::require();

        try {
            if (!isset($_FILES['file'])) {
                throw new Exception('No file uploaded.');
            }

            $id = Asset::store($_FILES['file']);
            $asset = Asset::get($id);

            Response::json([
                'success' => true,
                'id' => $id,
                'url' => '/assets/' . $asset['hash']
            ]);
        } catch (Exception $e) {
            Response::json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public static function deleteMedia(string $id): void {
        Auth::require('media.upload');
        CSRF::require();

        Asset::delete((int) $id);

        Session::flash('success', 'File deleted.');
        Response::redirect('/admin/media');
    }

    // Get images as JSON for gallery picker
    public static function mediaJson(): void {
        Auth::require('*');

        $assets = DB::fetchAll("SELECT id, filename, mime_type, hash, file_size, created_at FROM assets WHERE mime_type LIKE 'image/%' ORDER BY created_at DESC");

        $images = [];
        foreach ($assets as $asset) {
            $images[] = [
                'id' => $asset['id'],
                'filename' => $asset['filename'],
                'url' => '/assets/' . $asset['hash'],
                'size' => self::formatBytes($asset['file_size']),
                'created_at' => $asset['created_at']
            ];
        }

        Response::json(['success' => true, 'images' => $images]);
    }

    // ─── THEME SETTINGS ──────────────────────────────────────────────────────
    public static function theme(): void {
        Auth::require('*');

        // Get current theme settings
        $styles = DB::fetchAll("SELECT key, value FROM theme_styles");
        $vars = [];
        foreach ($styles as $row) {
            $vars[$row['key']] = $row['value'];
        }

        // Defaults
        $defaults = [
            'color_primary'           => '#3b82f6',
            'color_secondary'         => '#1e40af',
            'color_accent'            => '#f59e0b',
            'color_background'        => '#ffffff',
            'color_text'              => '#1f2937',
            'color_primary_dark'      => '#60a5fa',
            'color_secondary_dark'    => '#3b82f6',
            'color_accent_dark'       => '#fbbf24',
            'color_background_dark'   => '#0f172a',
            'color_text_dark'         => '#f1f5f9',
            'font_family'             => 'system-ui, -apple-system, sans-serif',
            'header_bg'               => '#3b82f6',
            'footer_text'             => '',
            'head_scripts'            => '',
            'footer_scripts'          => '',
        ];

        $data = array_merge($defaults, $vars, [
            'site_name' => Settings::get('site_name', ''),
            'tagline' => Settings::get('tagline', ''),
            'logo_url' => Settings::get('logo_url', ''),
        ]);

        // Font selection flags
        $data['font_system'] = str_contains($data['font_family'], 'system-ui');
        $data['font_inter'] = str_contains($data['font_family'], 'Inter');
        $data['font_roboto'] = str_contains($data['font_family'], 'Roboto');
        $data['font_opensans'] = str_contains($data['font_family'], 'Open Sans');
        $data['font_georgia'] = str_contains($data['font_family'], 'Georgia');

        self::renderAdmin('admin/theme', 'Theme Settings', $data, ['is_theme' => true]);
    }

    public static function saveTheme(): void {
        Auth::require('*');
        CSRF::require();

        $db = DB::connect();

        // Save site identity to settings
        Settings::set('site_name', Request::input('site_name', ''));
        Settings::set('tagline', Request::input('tagline', ''));
        Settings::set('logo_url', Request::input('logo_url', ''));

        // Save theme styles
        $styleKeys = [
            'color_primary', 'color_secondary', 'color_accent', 'color_background', 'color_text',
            'color_primary_dark', 'color_secondary_dark', 'color_accent_dark',
            'color_background_dark', 'color_text_dark',
            'font_family', 'header_bg', 'footer_text', 'head_scripts', 'footer_scripts',
        ];

        foreach ($styleKeys as $key) {
            $value = Request::input($key, '');
            $existing = DB::fetch("SELECT id FROM theme_styles WHERE key = ?", [$key]);

            if ($existing) {
                $db->prepare("UPDATE theme_styles SET value = ? WHERE key = ?")->execute([$value, $key]);
            } else {
                $db->prepare("INSERT INTO theme_styles (key, value) VALUES (?, ?)")->execute([$key, $value]);
            }
        }

        // Invalidate caches
        Cache::onContentChange('theme_styles');
        Cache::invalidatePartial('header');
        Cache::invalidatePartial('footer');

        Session::flash('success', 'Theme settings saved.');
        Response::redirect('/admin/theme');
    }

    // ─── SETTINGS (CREDENTIALS & INTEGRATIONS) ───────────────────────────────
    public static function settings(): void {
        Auth::require('*');

        $provider     = Settings::get('ai_provider', '');
        $currentModel = Settings::get('ai_model', '');
        $emailDriver  = Settings::get('email_driver', 'smtp');

        $data = [
            // AI
            'ai_provider'           => $provider,
            'ai_model'              => $currentModel,
            'ai_has_key'            => !empty(Settings::get('ai_api_key', '')),
            'ai_provider_openai'    => $provider === 'openai',
            'ai_provider_anthropic' => $provider === 'anthropic',
            'ai_provider_google'    => $provider === 'google',
            'ai_provider_name'      => match ($provider) {
                'anthropic' => 'Anthropic',
                'google'    => 'Google',
                default     => 'OpenAI',
            },
            // Email
            'email_driver'          => $emailDriver,
            'email_driver_smtp'     => $emailDriver === 'smtp',
            'email_driver_mailgun'  => $emailDriver === 'mailgun',
            'email_driver_sendgrid' => $emailDriver === 'sendgrid',
            'email_driver_postmark' => $emailDriver === 'postmark',
            'smtp_host'             => Settings::get('smtp_host', ''),
            'smtp_port'             => Settings::get('smtp_port', '587'),
            'smtp_user'             => Settings::get('smtp_user', ''),
            'smtp_from'             => Settings::get('smtp_from', ''),
            'mailgun_domain'        => Settings::get('mailgun_domain', ''),
            // General
            'contact_email'         => Settings::get('contact_email', ''),
            // Security
            'mfa_enabled'           => Settings::get('mfa_enabled', '0') === '1',
            // Blog
            'blog_enabled'          => Settings::get('blog_enabled', '0') === '1',
            'blog_title'            => Settings::get('blog_title', 'Blog'),
            'blog_description'      => Settings::get('blog_description', ''),
            'blog_posts_per_page'   => Settings::get('blog_posts_per_page', '10'),
        ];

        self::renderAdmin('admin/settings', 'Settings', $data, ['is_settings' => true]);
    }

    public static function saveSettings(): void {
        Auth::require('*');
        CSRF::require();

        // AI credentials
        $provider = trim(Request::input('ai_provider', ''));
        if ($provider) {
            Settings::set('ai_provider', $provider);
        }
        if (($key = trim(Request::input('ai_api_key', ''))) !== '') {
            Settings::setEncrypted('ai_api_key', $key);
        }
        $model = trim(Request::input('ai_model', ''));
        if ($model) {
            Settings::set('ai_model', $model);
        } elseif ($provider) {
            $defaultModel = match ($provider) {
                'anthropic' => 'claude-sonnet-4-6',
                'google'    => 'gemini-2.0-flash-exp',
                default     => 'gpt-5.2',
            };
            Settings::set('ai_model', $defaultModel);
        }

        // Email settings
        Settings::set('email_driver', Request::input('email_driver', 'smtp'));
        Settings::set('smtp_host', Request::input('smtp_host', ''));
        Settings::set('smtp_port', Request::input('smtp_port', '587'));
        Settings::set('smtp_user', Request::input('smtp_user', ''));
        Settings::set('smtp_from', Request::input('smtp_from', ''));
        Settings::set('mailgun_domain', Request::input('mailgun_domain', ''));
        if (($v = Request::input('smtp_pass', '')) !== '') {
            Settings::setEncrypted('smtp_pass', $v);
        }
        if (($v = Request::input('mailgun_api_key', '')) !== '') {
            Settings::setEncrypted('mailgun_api_key', $v);
        }
        if (($v = Request::input('sendgrid_api_key', '')) !== '') {
            Settings::setEncrypted('sendgrid_api_key', $v);
        }
        if (($v = Request::input('postmark_api_key', '')) !== '') {
            Settings::setEncrypted('postmark_api_key', $v);
        }

        // General
        Settings::set('contact_email', trim(Request::input('contact_email', '')));

        // Security
        Settings::set('mfa_enabled', Request::input('mfa_enabled', '') === '1' ? '1' : '0');

        // Blog
        Settings::set('blog_enabled', Request::input('blog_enabled', '0') === '1' ? '1' : '0');
        Settings::set('blog_title', Request::input('blog_title', 'Blog'));
        Settings::set('blog_description', Request::input('blog_description', ''));
        Settings::set('blog_posts_per_page', (string)max(1, (int)Request::input('blog_posts_per_page', '10')));

        Session::flash('success', 'Settings saved.');
        Response::redirect('/admin/settings');
    }

    // ─── USERS MANAGEMENT ────────────────────────────────────────────────────
    public static function users(): void {
        Auth::require('*'); // Admin only

        $currentUserId = Auth::user()['id'];
        $users = DB::fetchAll("SELECT id, email, role, created_at, last_login FROM users ORDER BY created_at DESC");

        foreach ($users as &$user) {
            $user['can_delete'] = $user['id'] != $currentUserId;
            $user['is_admin']   = $user['role'] === 'admin';
            $user['is_editor']  = $user['role'] === 'editor';
            $user['is_viewer']  = $user['role'] === 'viewer';
        }

        self::renderAdmin('admin/users', 'Users', ['users' => $users], ['is_users' => true]);
    }

    public static function editUser(string $id): void {
        Auth::require('*');

        if ($id === 'new') {
            $user = [
                'id' => 'new',
                'email' => '',
                'name' => '',
                'role' => 'editor',
                'is_admin' => false,
                'is_editor' => true,
                'is_viewer' => false,
            ];
        } else {
            $user = DB::fetch("SELECT id, email, role, name FROM users WHERE id = ?", [$id]);
            if (!$user) {
                Session::flash('error', 'User not found.');
                Response::redirect('/admin/users');
            }
            $user['name'] = $user['name'] ?? '';
            $user['is_admin'] = $user['role'] === 'admin';
            $user['is_editor'] = $user['role'] === 'editor';
            $user['is_viewer'] = $user['role'] === 'viewer';
        }

        $title = $id === 'new' ? 'Add User' : 'Edit User';
        self::renderAdmin('admin/user_edit', $title, array_merge($user, ['is_new' => ($id === 'new')]), ['is_users' => true]);
    }

    public static function saveUser(string $id): void {
        Auth::require('*');
        CSRF::require();

        $email = filter_var(Request::input('email', ''), FILTER_VALIDATE_EMAIL);
        $name = substr(strip_tags(Request::input('name', '')), 0, 100);
        $role = Request::input('role', 'editor');
        $password = Request::input('password', '');
        $passwordConfirm = Request::input('password_confirm', '');

        if (!$email) {
            Session::flash('error', 'Valid email is required.');
            Response::redirect($id === 'new' ? '/admin/users/new' : '/admin/users/' . $id);
        }

        if (!in_array($role, ['admin', 'editor', 'viewer'])) {
            $role = 'editor';
        }

        // Password validation
        if ($id === 'new' && empty($password)) {
            Session::flash('error', 'Password is required for new users.');
            Response::redirect('/admin/users/new');
        }

        if (!empty($password)) {
            $passwordErrors = Password::isStrong($password);
            if (!empty($passwordErrors)) {
                Session::flash('error', implode(' ', $passwordErrors));
                Response::redirect($id === 'new' ? '/admin/users/new' : '/admin/users/' . $id);
            }
            if ($password !== $passwordConfirm) {
                Session::flash('error', 'Passwords do not match.');
                Response::redirect($id === 'new' ? '/admin/users/new' : '/admin/users/' . $id);
            }
        }

        $db = DB::connect();

        try {
            if ($id === 'new') {
                $hash = Password::hash($password);
                $stmt = $db->prepare("INSERT INTO users (email, password_hash, role, name, created_at) VALUES (?, ?, ?, ?, datetime('now'))");
                $stmt->execute([$email, $hash, $role, $name ?: null]);
                Mailer::sendWelcome($email, $password, $role);
                Session::flash('success', 'User created.');
            } else {
                if (!empty($password)) {
                    $hash = Password::hash($password);
                    $stmt = $db->prepare("UPDATE users SET email = ?, role = ?, name = ?, password_hash = ? WHERE id = ?");
                    $stmt->execute([$email, $role, $name ?: null, $hash, $id]);
                } else {
                    $stmt = $db->prepare("UPDATE users SET email = ?, role = ?, name = ? WHERE id = ?");
                    $stmt->execute([$email, $role, $name ?: null, $id]);
                }
                Session::flash('success', 'User updated.');
            }

            Response::redirect('/admin/users');
        } catch (Exception $e) {
            Session::flash('error', 'Failed to save user. Email may already exist.');
            Response::redirect($id === 'new' ? '/admin/users/new' : '/admin/users/' . $id);
        }
    }

    public static function deleteUser(string $id): void {
        Auth::require('*');
        CSRF::require();

        $currentUserId = Auth::user()['id'];

        if ($id == $currentUserId) {
            Session::flash('error', 'You cannot delete your own account.');
            Response::redirect('/admin/users');
        }

        // Prevent deleting the last admin account
        $target = DB::fetch("SELECT role FROM users WHERE id = ?", [$id]);
        if ($target && $target['role'] === 'admin') {
            $adminCount = DB::fetch("SELECT COUNT(*) as cnt FROM users WHERE role = 'admin'");
            if ((int) $adminCount['cnt'] <= 1) {
                Session::flash('error', 'Cannot delete the last admin account.');
                Response::redirect('/admin/users');
            }
        }

        DB::connect()->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);

        Session::flash('success', 'User deleted.');
        Response::redirect('/admin/users');
    }

    public static function listRevisions(string $id): void {
        Auth::require('content.edit');

        $page = DB::fetch("SELECT * FROM pages WHERE id = ?", [$id]);
        if (!$page) {
            Session::flash('error', 'Page not found.');
            Response::redirect('/admin/pages');
        }

        $revisions = Revision::listForPage((int)$id);

        // Inject page_id and csrf_field into each revision so the template can access them inside {{#each revisions}} without needing unsupported {{../}} parent syntax.
        $csrfField = '<input type="hidden" name="csrf_token" value="' . CSRF::token() . '">';
        foreach ($revisions as &$rev) {
            $rev['page_id']    = $id;
            $rev['csrf_field'] = $csrfField;
        }
        unset($rev);

        self::renderAdmin('admin/page_revisions', 'Revisions — ' . htmlspecialchars($page['title']), [
            'page'            => $page,
            'revisions'       => $revisions,
            'revisions_count' => count($revisions),
        ]);
    }

    public static function restoreRevision(string $pageId, string $revId): void {
        Auth::require('content.edit');
        CSRF::require();

        $page = DB::fetch("SELECT id, title FROM pages WHERE id = ?", [$pageId]);
        if (!$page) {
            Session::flash('error', 'Page not found.');
            Response::redirect('/admin/pages');
        }

        $ok = Revision::restorePage((int)$revId, (int)$pageId);

        if ($ok) {
            Session::flash('success', 'Page restored to selected revision.');
        } else {
            Session::flash('error', 'Could not restore revision.');
        }

        Response::redirect('/admin/pages/' . $pageId);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// REVISION TRACKING
// ─────────────────────────────────────────────────────────────────────────────

class Revision {
    // Capture a full page snapshot: page row + all content_blocks. Called before any mutation (backend save, visual editor block edit/add/delete/move). Keeps at most 50 revisions per page, pruning oldest automatically.
    public static function snapshotPage(int $pageId): void {
        $page = DB::fetch("SELECT * FROM pages WHERE id = ?", [$pageId]);
        if (!$page) return;

        $blocks = DB::fetchAll(
            "SELECT * FROM content_blocks WHERE page_id = ? ORDER BY sort_order ASC",
            [$pageId]
        );

        $snapshot = json_encode(['page' => $page, 'blocks' => $blocks]);
        $userId   = Auth::user()['id'] ?? null;

        DB::execute(
            "INSERT INTO revisions (table_name, record_id, old_json, user_id, created_at) VALUES ('page_snapshot', ?, ?, ?, datetime('now'))",
            [$pageId, $snapshot, $userId]
        );

        // Prune: keep 50 most recent snapshots per page
        $ids = DB::fetchAll(
            "SELECT id FROM revisions WHERE table_name = 'page_snapshot' AND record_id = ? ORDER BY id DESC LIMIT -1 OFFSET 50",
            [$pageId]
        );
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            DB::execute(
                "DELETE FROM revisions WHERE id IN ($placeholders)",
                array_column($ids, 'id')
            );
        }
    }

    /** Return revisions list for a page (newest first). */
    public static function listForPage(int $pageId, int $limit = 50): array {
        return DB::fetchAll(
            "SELECT r.id, r.created_at, u.email AS user_email
               FROM revisions r
               LEFT JOIN users u ON u.id = r.user_id
              WHERE r.table_name = 'page_snapshot' AND r.record_id = ?
              ORDER BY r.id DESC
              LIMIT ?",
            [$pageId, $limit]
        );
    }

    // Restore a page to with a revision snapshot. Returns true on success, false if revision not found.
    public static function restorePage(int $revisionId, int $pageId): bool {
        $revision = DB::fetch(
            "SELECT old_json FROM revisions WHERE id = ? AND table_name = 'page_snapshot' AND record_id = ?",
            [$revisionId, $pageId]
        );
        if (!$revision) return false;

        $snapshot = json_decode($revision['old_json'], true);
        if (!$snapshot || !isset($snapshot['page'])) return false;

        // Snapshot the current state before restoring (so restore itself is reversible)
        self::snapshotPage($pageId);

        $p  = $snapshot['page'];
        $db = DB::connect();
        $db->beginTransaction();
        try {
            $db->prepare(
                "UPDATE pages SET title=?, slug=?, status=?, meta_description=?, meta_json=?, updated_at=datetime('now') WHERE id=?"
            )->execute([$p['title'], $p['slug'], $p['status'], $p['meta_description'] ?? '', $p['meta_json'] ?? '{}', $pageId]);

            $db->prepare("DELETE FROM content_blocks WHERE page_id = ?")->execute([$pageId]);

            $order = 0;
            foreach ($snapshot['blocks'] ?? [] as $b) {
                $db->prepare(
                    "INSERT INTO content_blocks (page_id, type, block_json, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, datetime('now'), datetime('now'))"
                )->execute([$pageId, $b['type'], $b['block_json'], $order++]);
            }

            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            return false;
        }

        Cache::onContentChange('pages', $pageId);
        return true;
    }
}

class PageController {
    private static bool $editMode = false;

    public static function show(array $page): void {
        // Check if in edit mode
        self::$editMode = Auth::can('content.edit') && isset($_GET['edit']);

        // Only use cache if NOT in edit mode
        if (!self::$editMode) {
            $cached = Cache::getPage($page['slug']);
            if ($cached !== null) {
                // Replace nonce placeholder with current request's nonce
                $cached = str_replace('{{CSP_NONCE_PLACEHOLDER}}', CSP_NONCE, $cached);
                // Inject admin bar for logged-in users
                $cached = self::injectAdminBar($cached);
                Response::html($cached);
            }
        }

        // Load content blocks
        $blocks = DB::fetchAll(
            "SELECT * FROM content_blocks WHERE page_id = ? ORDER BY sort_order ASC",
            [$page['id']]
        );

        // Render blocks
        $blocksHtml = '';
        foreach ($blocks as $index => $block) {
            $blocksHtml .= self::renderBlock($block, $index);
        }

        // Get UI theme from settings
        $uiTheme = Settings::get('ui_theme', 'emerald');

        // Per-page layout config and custom CSS from meta_json
        $meta = json_decode($page['meta_json'] ?? '{}', true) ?? [];
        $layout = $meta['layout'] ?? [];
        $showNav       = (bool)($layout['show_nav']        ?? true);
        $showFooter    = (bool)($layout['show_footer']     ?? true);
        $showPageTitle = (bool)($layout['show_page_title'] ?? ($page['slug'] !== 'home'));
        $navStyle      = $layout['nav_style'] ?? 'sticky';
        $pageCustomCss = !empty($meta['custom_css'])
            ? '<style>' . strip_tags($meta['custom_css']) . '</style>'
            : '';

        // Load theme colors, fonts, and custom scripts from DB
        $colorRows = DB::fetchAll("SELECT key, value FROM theme_styles WHERE key IN ('color_primary','color_secondary','color_accent','font_heading','font_body','head_scripts','footer_scripts')");
        $colorMap  = array_column($colorRows, 'value', 'key');
        $colorPrimary   = $colorMap['color_primary']   ?? '#3b82f6';
        $colorSecondary = $colorMap['color_secondary'] ?? '#1e40af';
        $colorAccent    = $colorMap['color_accent']    ?? '#f59e0b';

        // Inject CSP nonce into any <script> tags in custom head/footer code so they
        // pass the Content-Security-Policy. The cache system will swap the actual nonce
        // value with {{CSP_NONCE_PLACEHOLDER}} before storing, and back on serve.
        $injectNonce = static function (string $code): string {
            return preg_replace('/<script(?![^>]*\bnonce=)([^>]*)>/i', '<script nonce="' . CSP_NONCE . '"$1>', $code);
        };
        $headScripts   = $injectNonce($colorMap['head_scripts']   ?? '');
        $footerScripts = $injectNonce($colorMap['footer_scripts'] ?? '');

        // Build Google Fonts link tag (only for fonts actually set by an AI build)
        $googleFontsLink = '';
        $googleFontsCss  = '';
        $fontHeading = isset($colorMap['font_heading']) ? preg_replace('/[^A-Za-z0-9 \-]/', '', $colorMap['font_heading']) : '';
        $fontBody    = isset($colorMap['font_body'])    ? preg_replace('/[^A-Za-z0-9 \-]/', '', $colorMap['font_body'])    : '';
        if ($fontHeading || $fontBody) {
            $families = [];
            if ($fontHeading) {
                $families[] = 'family=' . urlencode($fontHeading) . ':ital,wght@0,400;0,600;0,700;0,800;1,400';
            }
            if ($fontBody && $fontBody !== $fontHeading) {
                $families[] = 'family=' . urlencode($fontBody) . ':ital,wght@0,400;0,500;0,600;1,400';
            }
            $gfUrl = 'https://fonts.googleapis.com/css2?' . implode('&', $families) . '&display=swap';
            $googleFontsLink = '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' .
                               '<link rel="stylesheet" href="' . htmlspecialchars($gfUrl) . '">';
            // Global font-family rules so AI-generated HTML inherits the chosen fonts
            $headingFamilyCss = $fontHeading ? "'" . addslashes($fontHeading) . "', serif" : 'serif';
            $bodyFamilyCss    = $fontBody    ? "'" . addslashes($fontBody)    . "', sans-serif" : 'sans-serif';
            $googleFontsCss   = "body,p,li,td,th,label,input,textarea,select{font-family:{$bodyFamilyCss}}"
                              . "h1,h2,h3,h4,h5,h6,.title,.subtitle{font-family:{$headingFamilyCss}}";
        }

        // Compute nav wrapper style (wraps the nav partial in the page template)
        $navWrapperStyle = $navStyle === 'transparent'
            ? 'position:absolute;width:100%;top:0;z-index:30'
            : 'position:sticky;top:0;z-index:30';

        // Deduplicated document title (avoids "Site Name | Site Name" when AI puts site name in page title)
        $siteName = Settings::get('site_name', 'MonolithCMS');
        $pageTitle = $page['title'] ?? '';
        $docTitle = (stripos($pageTitle, $siteName) !== false)
            ? $pageTitle
            : ($pageTitle ? $pageTitle . ' | ' . $siteName : $siteName);

        // Canonical URL
        $canonicalSlug = ($page['slug'] === 'home') ? '' : '/' . $page['slug'];
        $canonicalProto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $canonicalHost  = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $canonicalUrl   = $canonicalProto . '://' . $canonicalHost . $canonicalSlug;

        // Render page
        $html = Template::render('page', array_merge($page, [
            'blocks_html'       => $blocksHtml,
            'show_nav'          => $showNav,
            'show_footer'       => $showFooter,
            'show_page_title'   => $showPageTitle,
            'nav_wrapper_style' => $navWrapperStyle,
            'ui_theme'          => $uiTheme,
            'page_custom_css'   => $pageCustomCss,
            'color_primary'     => $colorPrimary,
            'color_secondary'   => $colorSecondary,
            'color_accent'      => $colorAccent,
            'doc_title'         => $docTitle,
            'canonical_url'     => $canonicalUrl,
            'google_fonts_link' => $googleFontsLink,
            'google_fonts_css'  => $googleFontsCss,
            'head_scripts'      => $headScripts,
            'footer_scripts'    => $footerScripts,
        ]));

        // Only cache if NOT in edit mode (cache without admin bar) Store with placeholder nonce so we can inject fresh nonce on serve
        if (!self::$editMode) {
            $cacheHtml = str_replace(CSP_NONCE, '{{CSP_NONCE_PLACEHOLDER}}', $html);
            Cache::setPage($page['slug'], $cacheHtml);
        }

        // Inject admin bar for display
        $html = self::injectAdminBar($html);
        Response::html($html);
    }

    // Inject admin bar for logged-in users (not cached)
    private static function injectAdminBar(string $html): string {
        if (!Auth::can('content.edit') || isset($_GET['embedded'])) {
            // Remove placeholder for non-logged-in users or when embedded in iframe
            return str_replace('<!--MONOLITHCMS_ADMIN_BAR-->', '', $html);
        }

        // Build admin bar HTML
        $editButton = self::$editMode
            ? '<a href="?" class="button is-danger is-small">Exit Editor</a>'
            : '<a href="?edit" class="button is-primary is-small">Edit Page</a>';

        $devBadge = MONOLITHCMS_DEV
            ? '<span style="background:#fef3c7;color:#92400e;font-size:11px;font-family:monospace;padding:2px 8px;border-radius:4px;border:1px solid #f59e0b;">⚡ DEV — cache disabled</span>'
            : '';

        $adminBar = <<<HTML
<div style="position:fixed;top:0;left:0;right:0;z-index:10000;background:#1e293b;color:#f1f5f9;padding:0.4rem 1rem;">
    <div style="max-width:1200px;margin:0 auto;display:flex;align-items:center;gap:1rem;">
        <span style="font-weight:700;color:var(--color-primary,#135bec)">MonolithCMS</span>
        {$devBadge}
        <div style="flex:1"></div>
        {$editButton}
        <a href="/admin" class="button is-light is-small">Dashboard</a>
    </div>
</div>
<div style="height:3.25rem"></div>
<style>#site-nav{top:3.25rem!important}#cms-nav-wrapper{top:3.25rem!important}</style>
HTML;

        return str_replace('<!--MONOLITHCMS_ADMIN_BAR-->', $adminBar, $html);
    }

    // Public method for cache regeneration
    public static function renderBlockForCache(array $block): string {
        return self::renderBlockContent($block);
    }

    private static function renderBlock(array $block, int $index = 0): string {
        $html = self::renderBlockContent($block);

        // Wrap with editor handles if in edit mode
        if (self::$editMode && !empty($html)) {
            $blockId = (int) $block['id'];
            $type = Sanitize::html($block['type']);
            // JSON in script tags doesn't need HTML escaping, but we need to escape </script>
            $dataJson = str_replace('</script>', '<\\/script>', $block['block_json']);

            $html = <<<WRAP
<div class="monolithcms-block-wrapper" data-block-id="{$blockId}" data-block-type="{$type}" data-block-index="{$index}">
    <div class="monolithcms-block-toolbar">
        <span class="block-type-label">{$type}</span>
        <div class="block-actions">
            <button type="button" class="block-action" data-action="insert-above" title="Insert Section Above">+↑</button>
            <button type="button" class="block-action" data-action="insert-below" title="Insert Section Below">+↓</button>
            <button type="button" class="block-action" data-action="move-up" title="Move Up">↑</button>
            <button type="button" class="block-action" data-action="move-down" title="Move Down">↓</button>
            <button type="button" class="block-action" data-action="edit" title="Edit">✎</button>
            <button type="button" class="block-action block-action-danger" data-action="delete" title="Delete">×</button>
        </div>
    </div>
    {$html}
    <script type="application/json" class="block-data">{$dataJson}</script>
</div>
WRAP;
        }

        return $html;
    }

    private static function renderBlockContent(array $block): string {
        $data = json_decode($block['block_json'], true) ?? [];
        $type = $block['type'];

        // AI-generated full HTML — passthrough directly (95 % AI path). Old structured blocks have no 'html' key and skip this entirely.
        if (!empty($data['html'])) {
            $html = $data['html'];
            // Inject nav anchor id as a safety-net if AI forgot to include it
            if (!empty($data['anchor'])) {
                $anchorId = preg_replace('/[^a-z0-9\-_]/', '', strtolower($data['anchor']));
                if ($anchorId && !str_contains($html, 'id="' . $anchorId . '"')) {
                    $html = preg_replace('/<section\b/', '<section id="' . $anchorId . '"', $html, 1);
                }
            }
            // Replace CSP nonce placeholder with actual nonce value
            $html = str_replace('{{csp_nonce}}', CSP_NONCE, $html);
            // Convert onclick handlers to event listeners (CSP forbids inline handlers even with nonces). Correctly handles single-quoted values within double-quoted attrs and vice versa.
            $evtCounter = 0;
            $evtScript = [];
            $convertOnclick = function(array $m) use (&$evtCounter, &$evtScript): string {
                $evtCounter++;
                $elId = 'cms-evt-' . $evtCounter;
                $handler = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $handler = str_replace(["\r\n", "\n", "\r"], ' ', $handler);
                $handler = str_replace('this', 'el', $handler);
                $evtScript[] = "(function(){var el=document.querySelector('[data-cmsevt=\"{$elId}\"]');if(el)el.addEventListener('click',function(){{$handler}});})();";
                return ' data-cmsevt="' . $elId . '"';
            };
            $html = preg_replace_callback('/\s+onclick\s*=\s*"([^"]*)"/i', $convertOnclick, $html);
            $html = preg_replace_callback("/\\s+onclick\\s*=\\s*'([^']*)'/i", $convertOnclick, $html);
            // Strip remaining non-onclick inline event handlers (onerror, onload, onmouseover, etc.)
            $html = preg_replace('/\s+on(?!click)[a-z]+\s*=\s*(?:"[^"]*"|\'[^\']*\')/i', '', $html);
            // Append event-listener shim if any onclick handlers were converted
            if ($evtScript) {
                $html .= '<script nonce="' . CSP_NONCE . '">' . implode('', $evtScript) . '</script>';
            }
            // Inject CSP nonce into any inline <script> tags that are still missing it (fallback)
            $html = preg_replace('/<script(?![^>]*nonce)([^>]*)>/', '<script$1 nonce="' . CSP_NONCE . '">', $html);
            return $html;
        }

        // Determine anchor id: explicit 'anchor' field, or slugified 'title'
        $anchorId = '';
        if (!empty($data['anchor'])) {
            $anchorId = preg_replace('/[^a-z0-9\-_]/', '', strtolower($data['anchor']));
        } elseif (!empty($data['title'])) {
            $anchorId = preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($data['title'])));
            $anchorId = trim($anchorId, '-');
        }
        $anchorAttr = $anchorId ? " id=\"{$anchorId}\"" : '';

        $html = match ($type) {
            'hero' => self::renderHero($data),
            'text' => '<section class="section reveal"><div class="container"><div class="content is-medium">' . Sanitize::richText($data['content'] ?? '') . '</div></div></section>',
            'image' => sprintf(
                '<section class="section reveal"><div class="container"><figure class="image"><img src="%s" alt="%s" style="border-radius:0.75rem;box-shadow:0 4px 24px rgba(0,0,0,.12);max-width:900px;margin:0 auto;display:block">%s</figure></div></section>',
                self::resolveImg($data['url'] ?? '', $data['alt'] ?? 'image', 1200, 600),
                Sanitize::html($data['alt'] ?? ''),
                !empty($data['caption']) ? '<figcaption class="has-text-grey has-text-centered mt-2">' . Sanitize::html($data['caption']) . '</figcaption>' : ''
            ),
            'cta' => self::renderCTA($data),
            'gallery' => self::renderGallery($data),
            'form' => self::renderContactForm(),
            'features' => self::renderFeatures($data),
            'stats' => self::renderStats($data),
            'testimonials' => self::renderTestimonials($data),
            'pricing' => self::renderPricing($data),
            'team' => self::renderTeam($data),
            'cards' => self::renderCards($data),
            'faq' => self::renderFAQ($data),
            // New block types
            'quote' => self::renderQuote($data),
            'divider' => self::renderDivider($data),
            'video' => self::renderVideo($data),
            'carousel' => self::renderCarousel($data),
            'checklist' => self::renderChecklist($data),
            'logo_cloud' => self::renderLogoCloud($data),
            'comparison' => self::renderComparison($data),
            'tabs' => self::renderTabs($data),
            'accordion' => self::renderAccordion($data),
            'table' => self::renderTable($data),
            'timeline' => self::renderTimeline($data),
            'list' => self::renderList($data),
            'newsletter' => self::renderNewsletter($data),
            'download' => self::renderDownload($data),
            'alert' => self::renderAlert($data),
            'progress' => self::renderProgress($data),
            'steps' => self::renderSteps($data),
            'columns' => self::renderColumns($data),
            'spacer' => self::renderSpacer($data),
            'map' => self::renderMap($data),
            'contact_info' => self::renderContactInfo($data),
            'social' => self::renderSocial($data),
            'raw_html' => self::renderRawHtml($data),
            'raw_js' => self::renderRawJs($data),
            'game' => self::renderGame($data),
            'link_tree' => self::renderLinkTree($data),
            'embed' => self::renderEmbed($data),
            default => ''
        };

        // Inject anchor id into the outermost <section> so hash links like /#stack work
        if ($anchorId && $html) {
            if (str_contains($html, '<section')) {
                $html = preg_replace('/<section/', "<section{$anchorAttr}", $html, 1);
            } else {
                $html = "<div{$anchorAttr}>{$html}</div>";
            }
        }

        return $html;
    }

    // Generate a deterministic picsum.photos URL for a given seed + dimensions. Returns a real-looking placeholder that is consistent across requests.
    private static function picsum(string $seed, int $w = 800, int $h = 450): string {
        $s = preg_replace('/[^a-z0-9]/i', '', $seed);
        $s = !empty($s) ? strtolower($s) : 'placeholder';
        return "https://picsum.photos/seed/{$s}/{$w}/{$h}";
    }

    // Return $url if it looks like a real URL, otherwise return a picsum placeholder. Treats empty strings and AI-generated fake paths (e.g. /img.jpg, /image.jpg) as missing.
    private static function resolveImg(string $url, string $seed, int $w = 800, int $h = 450): string {
        $url = trim($url);
        if (empty($url) || preg_match('#^/img[^/]*\.\w+$#i', $url) || $url === '#') {
            return self::picsum($seed, $w, $h);
        }
        return $url;
    }

    private static function renderHero(array $data): string {
        $title = Sanitize::html($data['title'] ?? '');
        $subtitle = Sanitize::html($data['subtitle'] ?? '');
        $button = Sanitize::html($data['button'] ?? '');
        $url = Sanitize::html($data['url'] ?? '#');
        $rawImage = $data['image'] ?? '';
        // Use picsum placeholder only if the AI specified an image field (even with a fake URL)
        $image = !empty($rawImage) ? Sanitize::html(self::resolveImg($rawImage, $title ?: 'hero', 1400, 600)) : '';

        $btnHtml = !empty($button) ? "<a href=\"{$url}\" class=\"button is-primary is-large mt-5\">{$button}</a>" : '';
        $bgStyle = !empty($image) ? "background-image:url('{$image}');background-size:cover;background-position:center" : '';
        $overlayStyle = !empty($image) ? 'background-color:rgba(0,0,0,.45);position:absolute;inset:0;' : '';
        $textColor = !empty($image) ? 'has-text-white' : '';
        $overlayHtml = $overlayStyle ? "<div style=\"{$overlayStyle}\"></div>" : '';

        return <<<HTML
<section class="hero is-medium reveal" style="position:relative;{$bgStyle}">
  {$overlayHtml}
  <div class="hero-body" style="position:relative;z-index:1">
    <div class="container has-text-centered">
      <h1 class="title is-1 animate-fade-in {$textColor}">{$title}</h1>
      <p class="subtitle is-4 animate-slide-up {$textColor}" style="opacity:.9">{$subtitle}</p>
      {$btnHtml}
    </div>
  </div>
</section>
HTML;
    }

    private static function renderCTA(array $data): string {
        $title = Sanitize::html($data['title'] ?? '');
        $text = Sanitize::html($data['text'] ?? '');
        $button = Sanitize::html($data['button'] ?? 'Learn More');
        $url = Sanitize::html($data['url'] ?? '#');

        return <<<HTML
<section class="section reveal" style="background-color:var(--color-primary)">
  <div class="container has-text-centered">
    <h2 class="title has-text-white">{$title}</h2>
    <p class="subtitle has-text-white" style="opacity:.9">{$text}</p>
    <a href="{$url}" class="button is-light is-large">{$button}</a>
  </div>
</section>
HTML;
    }

    // Render icon - supports Material Icons (lowercase names) or emojis/images
    private static function renderIconHtml(string $icon): string {
        if (empty($icon)) {
            return '<span class="material-symbols-outlined has-text-primary">star</span>';
        }

        // If it starts with / it's an image
        if (str_starts_with($icon, '/')) {
            return '<img src="' . Sanitize::html($icon) . '" alt="" style="width:2.5rem;height:2.5rem;object-fit:contain">';
        }

        // If it's a simple lowercase word (or underscore-separated), treat as Material Symbol
        if (preg_match('/^[a-z][a-z0-9_]*$/', $icon)) {
            return '<span class="material-symbols-outlined has-text-primary">' . Sanitize::html($icon) . '</span>';
        }

        // Otherwise render as-is (emoji or HTML entity)
        return Sanitize::html($icon);
    }

    private static function renderFeatures(array $data): string {
        $items = $data['items'] ?? [];
        if (empty($items)) return '';

        $title = Sanitize::html($data['title'] ?? '');
        $subtitle = Sanitize::html($data['subtitle'] ?? '');

        $titleHtml = !empty($title) ? "<h2 class=\"title is-2 has-text-centered mb-2\">{$title}</h2>" : '';
        $subtitleHtml = !empty($subtitle) ? "<p class=\"subtitle is-5 has-text-centered has-text-grey mb-6\">{$subtitle}</p>" : '';

        $cardsHtml = '';
        foreach ($items as $item) {
            $iconRaw = $item['icon'] ?? 'star';
            $icon = self::renderIconHtml($iconRaw);
            $itemTitle = Sanitize::html($item['title'] ?? '');
            $description = Sanitize::html($item['description'] ?? '');

            $cardsHtml .= <<<CARD
<div class="column is-one-third-desktop is-half-tablet">
  <div class="card h-100">
    <div class="card-content has-text-centered">
      <div class="mb-4 is-size-3 has-text-primary">{$icon}</div>
      <p class="title is-5">{$itemTitle}</p>
      <p class="has-text-grey">{$description}</p>
    </div>
  </div>
</div>
CARD;
        }

        return <<<HTML
<section class="section reveal">
  <div class="container">
    {$titleHtml}
    {$subtitleHtml}
    <div class="columns is-multiline">
      {$cardsHtml}
    </div>
  </div>
</section>
HTML;
    }

    private static function renderStats(array $data): string {
        $items = $data['items'] ?? [];
        if (empty($items)) return '';

        $title = Sanitize::html($data['title'] ?? '');
        $titleHtml = !empty($title) ? "<h2 class=\"title is-2 has-text-centered mb-6\">{$title}</h2>" : '';

        $statsHtml = '';
        foreach ($items as $item) {
            $raw = $item['value'] ?? '';
            $value = Sanitize::html($raw);
            $label = Sanitize::html($item['label'] ?? '');
            $iconRaw = $item['icon'] ?? '';
            $iconHtml = !empty($iconRaw) ? "<div class=\"is-size-4 mb-2\">" . self::renderIconHtml($iconRaw) . "</div>" : '';
            $numeric = preg_replace('/[^0-9.]/', '', $raw);
            $countAttr = $numeric !== '' ? " data-countup=\"{$numeric}\"" : '';
            $display = $numeric !== '' ? '0' : $value;

            $statsHtml .= <<<STAT
<div class="column has-text-centered">
  {$iconHtml}
  <p class="title is-1 has-text-primary"{$countAttr}>{$display}</p>
  <p class="has-text-grey">{$label}</p>
</div>
STAT;
        }

        $nonce = defined('CSP_NONCE') ? CSP_NONCE : '';
        return <<<HTML
<section class="section reveal" style="background:var(--color-background-secondary)">
  <div class="container">
    {$titleHtml}
    <div class="columns is-multiline">
      {$statsHtml}
    </div>
  </div>
</section>
<script nonce="{$nonce}">
(function(){
  if(window._monolithcmsCountupInit) return;
  window._monolithcmsCountupInit = true;
  var obs = new IntersectionObserver(function(entries){
    entries.forEach(function(e){
      if(!e.isIntersecting) return;
      var el = e.target;
      var end = parseFloat(el.dataset.countup);
      var isInt = String(el.dataset.countup).indexOf('.') === -1;
      var orig = el.dataset.countup;
      var suffix = orig.replace(/[\d.]/g,'');
      var dur = 1400, start = performance.now();
      (function frame(now){
        var p = Math.min((now-start)/dur,1);
        var v = end*(p<0.5?2*p*p:-1+(4-2*p)*p);
        el.textContent = (isInt?Math.round(v):v.toFixed(1))+suffix;
        if(p<1) requestAnimationFrame(frame);
        else el.textContent = (isInt?end:end.toFixed(1))+suffix;
      })(start);
      obs.unobserve(el);
    });
  },{threshold:0.5});
  function init(){
    document.querySelectorAll('[data-countup]').forEach(function(el){ obs.observe(el); });
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',init);
  else init();
})();
</script>
HTML;
    }

    private static function renderTestimonials(array $data): string {
        $items = $data['items'] ?? [];
        if (empty($items)) return '';

        $title = Sanitize::html($data['title'] ?? '');
        $titleHtml = !empty($title) ? "<h2 class=\"title is-2 has-text-centered mb-6\">{$title}</h2>" : '';

        $cardsHtml = '';
        foreach ($items as $item) {
            $quote = Sanitize::html($item['quote'] ?? '');
            $name = Sanitize::html($item['name'] ?? $item['author'] ?? '');
            $role = Sanitize::html($item['role'] ?? '');
            $rating = intval($item['rating'] ?? 5);

            $initials = strtoupper(substr($name, 0, 1) . (strpos($name, ' ') ? substr($name, strpos($name, ' ') + 1, 1) : ''));
            $starsHtml = str_repeat('⭐', min($rating, 5));

            $cardsHtml .= <<<CARD
<div class="column is-one-third-desktop is-half-tablet">
  <div class="card h-100">
    <div class="card-content">
      <p class="mb-2">{$starsHtml}</p>
      <p class="has-text-grey-dark is-italic mb-4">"{$quote}"</p>
      <div class="media">
        <div class="media-left">
          <span class="is-flex is-align-items-center is-justify-content-center has-background-primary has-text-white" style="width:2.5rem;height:2.5rem;border-radius:50%;font-weight:700">{$initials}</span>
        </div>
        <div class="media-content">
          <p class="has-text-weight-bold">{$name}</p>
          <p class="has-text-grey is-size-7">{$role}</p>
        </div>
      </div>
    </div>
  </div>
</div>
CARD;
        }

        return <<<HTML
<section class="section reveal" style="background:var(--color-background-secondary)">
  <div class="container">
    {$titleHtml}
    <div class="columns is-multiline">
      {$cardsHtml}
    </div>
  </div>
</section>
HTML;
    }

    private static function renderPricing(array $data): string {
        $items = $data['plans'] ?? $data['items'] ?? [];
        if (empty($items)) return '';

        $title = Sanitize::html($data['title'] ?? '');
        $subtitle = Sanitize::html($data['subtitle'] ?? '');

        $titleHtml = !empty($title) ? "<h2 class=\"title is-2 has-text-centered mb-2\">{$title}</h2>" : '';
        $subtitleHtml = !empty($subtitle) ? "<p class=\"subtitle is-5 has-text-centered has-text-grey mb-6\">{$subtitle}</p>" : '';

        $cardsHtml = '';
        foreach ($items as $item) {
            $name = Sanitize::html($item['name'] ?? '');
            $price = Sanitize::html($item['price'] ?? '');
            $period = Sanitize::html($item['period'] ?? '/month');
            $features = $item['features'] ?? [];
            $featured = !empty($item['featured']);
            $buttonText = Sanitize::html($item['button'] ?? 'Get Started');
            $url = Sanitize::html($item['url'] ?? '#');

            $cardStyle = $featured ? 'border:2px solid var(--color-primary);transform:scale(1.03)' : '';
            $badgeHtml = $featured ? '<span class="tag is-primary" style="position:absolute;top:1rem;right:1rem">Popular</span>' : '';
            $btnClass = $featured ? 'button is-primary is-fullwidth' : 'button is-outlined is-primary is-fullwidth';

            $featuresHtml = '';
            foreach ($features as $feature) {
                $featuresHtml .= '<li style="display:flex;align-items:center;gap:.5rem;margin-bottom:.5rem"><svg style="flex-shrink:0;color:green;width:1.25rem;height:1.25rem" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>' . Sanitize::html($feature) . '</li>';
            }

            $cardsHtml .= <<<CARD
<div class="column is-one-third-desktop is-half-tablet">
  <div class="card" style="position:relative;{$cardStyle}">
    {$badgeHtml}
    <div class="card-content has-text-centered">
      <p class="title is-5 mb-2">{$name}</p>
      <p class="title is-1 has-text-primary mb-0">{$price}</p>
      <p class="has-text-grey mb-4">{$period}</p>
      <ul style="text-align:left;margin-bottom:1.5rem">{$featuresHtml}</ul>
      <a href="{$url}" class="{$btnClass}">{$buttonText}</a>
    </div>
  </div>
</div>
CARD;
        }

        return <<<HTML
<section class="section reveal">
  <div class="container">
    {$titleHtml}
    {$subtitleHtml}
    <div class="columns is-multiline is-centered">
      {$cardsHtml}
    </div>
  </div>
</section>
HTML;
    }

    private static function renderTeam(array $data): string {
        $items = $data['members'] ?? $data['items'] ?? [];
        if (empty($items)) return '';

        $title = Sanitize::html($data['title'] ?? '');
        $titleHtml = !empty($title) ? "<h2 class=\"title is-2 has-text-centered mb-6\">{$title}</h2>" : '';

        $cardsHtml = '';
        foreach ($items as $item) {
            $name = Sanitize::html($item['name'] ?? '');
            $role = Sanitize::html($item['role'] ?? '');
            $bio = Sanitize::html($item['bio'] ?? '');
            $photo = $item['photo'] ?? '';
            $url = Sanitize::html($item['url'] ?? '');

            $photoUrl = Sanitize::html(self::resolveImg($photo, $item['name'] ?? 'person', 300, 300));
            $avatarHtml = "<figure class=\"image is-128x128\" style=\"margin:0 auto 1rem\"><img class=\"is-rounded\" src=\"{$photoUrl}\" alt=\"{$name}\" style=\"object-fit:cover;width:100%;height:100%\"></figure>";

            $linkHtml = !empty($url) ? "<a href=\"{$url}\" class=\"button is-primary is-small mt-4\">View Profile</a>" : '';

            $cardsHtml .= <<<CARD
<div class="column is-one-quarter-desktop is-half-tablet">
  <div class="card h-100">
    <div class="card-content has-text-centered">
      {$avatarHtml}
      <p class="title is-5 mb-1">{$name}</p>
      <span class="tag" style="background:var(--p-r);color:var(--p);border:none">{$role}</span>
      <p class="has-text-grey mt-3">{$bio}</p>
      {$linkHtml}
    </div>
  </div>
</div>
CARD;
        }

        return <<<HTML
<section class="section reveal">
  <div class="container">
    {$titleHtml}
    <div class="columns is-multiline">
      {$cardsHtml}
    </div>
  </div>
</section>
HTML;
    }

    private static function renderCards(array $data): string {
        $items = $data['items'] ?? [];
        if (empty($items)) return '';

        $title = Sanitize::html($data['title'] ?? '');
        $titleHtml = !empty($title) ? "<h2 class=\"title is-2 has-text-centered mb-6\">{$title}</h2>" : '';

        $cardsHtml = '';
        foreach ($items as $item) {
            $itemTitle = Sanitize::html($item['title'] ?? '');
            $description = Sanitize::html($item['description'] ?? '');
            $iconRaw = $item['icon'] ?? '';
            $icon = !empty($iconRaw) ? self::renderIconHtml($iconRaw) : '';
            $image = $item['image'] ?? '';
            $url = Sanitize::html($item['url'] ?? '#');
            $buttonText = Sanitize::html($item['button'] ?? '');

            $iconHtml = !empty($icon) ? "<div class=\"is-size-3 mb-3\">{$icon}</div>" : '';
            // Always show an image (picsum placeholder when none set)
            $resolvedImage = Sanitize::html(self::resolveImg($image, $itemTitle ?: 'card', 600, 340));
            $imageHtml = "<div class=\"card-image\"><figure class=\"image is-16by9\"><img src=\"{$resolvedImage}\" alt=\"{$itemTitle}\" style=\"object-fit:cover\"></figure></div>";
            $buttonHtml = !empty($buttonText)
                ? "<footer class=\"card-footer\"><a href=\"{$url}\" class=\"card-footer-item has-text-primary\">{$buttonText}</a></footer>"
                : '';

            $cardsHtml .= <<<CARD
<div class="column is-one-third-desktop is-half-tablet">
  <div class="card h-100">
    {$imageHtml}
    <div class="card-content">
      {$iconHtml}
      <p class="title is-5">{$itemTitle}</p>
      <p class="has-text-grey">{$description}</p>
    </div>
    {$buttonHtml}
  </div>
</div>
CARD;
        }

        return <<<HTML
<section class="section reveal">
  <div class="container">
    {$titleHtml}
    <div class="columns is-multiline">
      {$cardsHtml}
    </div>
  </div>
</section>
HTML;
    }

    private static function renderFAQ(array $data): string {
        $items = $data['items'] ?? [];
        if (empty($items)) return '';

        $title = Sanitize::html($data['title'] ?? '');
        $titleHtml = !empty($title) ? "<h2 class=\"title is-2 has-text-centered mb-6\">{$title}</h2>" : '';

        $faqHtml = '';
        foreach ($items as $i => $item) {
            $question = Sanitize::html($item['question'] ?? '');
            $answer = Sanitize::html($item['answer'] ?? '');
            $open = $i === 0 ? ' open' : '';

            $faqHtml .= <<<FAQ
<details class="box mb-3 faq-item"{$open}>
  <summary style="font-size:1.1rem;font-weight:600;cursor:pointer;list-style:none;display:flex;justify-content:space-between;align-items:center;gap:1rem">
    <span>{$question}</span>
    <span class="faq-chevron" style="font-size:1.1rem;line-height:1;transition:transform .25s ease;display:inline-block;flex-shrink:0">&#9660;</span>
  </summary>
  <p class="has-text-grey mt-3">{$answer}</p>
</details>
FAQ;
        }

        $nonce = defined('CSP_NONCE') ? CSP_NONCE : '';
        return <<<HTML
<section class="section reveal">
  <div class="container" style="max-width:48rem">
    {$titleHtml}
    {$faqHtml}
  </div>
</section>
<script nonce="{$nonce}">
(function(){
  if(window._monolithcmsFaqInit) return;
  window._monolithcmsFaqInit = true;
  function initFaq(){
    document.querySelectorAll('details.faq-item').forEach(function(d){
      if(d._faqBound) return;
      d._faqBound = true;
      var ch = d.querySelector('.faq-chevron');
      if(!ch) return;
      ch.style.transform = d.open ? 'rotate(-180deg)' : '';
      d.addEventListener('toggle', function(){
        ch.style.transform = d.open ? 'rotate(-180deg)' : '';
      });
    });
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',initFaq);
  else initFaq();
})();
</script>
HTML;
    }

    private static function renderGallery(array $data): string {
        $columns = $data['columns'] ?? 3;
        $rawImages = $data['images'] ?? [];

        // AI may return an array of objects [{"url":"...","alt":"..."}] or a newline-separated string
        if (is_string($rawImages)) {
            $rawImages = array_filter(array_map('trim', explode("\n", $rawImages)));
            $rawImages = array_map(fn($u) => ['url' => $u, 'alt' => ''], $rawImages);
        } elseif (is_array($rawImages) && isset($rawImages[0]) && is_string($rawImages[0])) {
            // Plain array of URL strings
            $rawImages = array_map(fn($u) => ['url' => $u, 'alt' => ''], $rawImages);
        }

        if (empty($rawImages)) {
            return '<section class="section reveal"><div class="container has-text-centered" style="padding:3rem;border:2px dashed var(--color-border);border-radius:.75rem"><span class="material-symbols-outlined" style="font-size:3rem;opacity:.4">photo_library</span><p class="has-text-grey mt-3">Gallery block — add images via the block editor.</p></div></section>';
        }

        $cols = (int)$columns;
        $colClass = match($cols) {
            1 => 'is-full',
            2 => 'is-half',
            4 => 'is-one-quarter',
            default => 'is-one-third'
        };

        $imagesHtml = '';
        foreach ($rawImages as $i => $img) {
            $rawUrl = is_array($img) ? ($img['url'] ?? '') : (string)$img;
            $alt    = is_array($img) ? ($img['alt'] ?? '') : '';
            $src    = Sanitize::html(self::resolveImg($rawUrl, 'gallery' . $i, 600, 600));
            $altE   = Sanitize::html($alt);
            $imagesHtml .= "<div class=\"column {$colClass}\"><figure class=\"image is-1by1\"><img src=\"{$src}\" alt=\"{$altE}\" style=\"object-fit:cover;height:100%\" loading=\"lazy\"></figure></div>";
        }

        return <<<HTML
<section class="section-sm reveal">
  <div class="columns is-multiline">
    {$imagesHtml}
  </div>
</section>
HTML;
    }

    private static function renderContactForm(): string {
        $csrfToken = CSRF::token();
        return <<<HTML
<section class="section reveal">
  <div class="container" style="max-width:40rem">
    <div class="card">
      <div class="card-content">
        <h2 class="title is-3 mb-5">Contact Us</h2>
        <form method="post" action="/contact">
          <input type="hidden" name="_csrf" value="{$csrfToken}">
          <div class="field">
            <label class="label">Name</label>
            <div class="control"><input class="input" type="text" name="name" required placeholder="Your name"></div>
          </div>
          <div class="field">
            <label class="label">Email</label>
            <div class="control"><input class="input" type="email" name="email" required placeholder="your@email.com"></div>
          </div>
          <div class="field">
            <label class="label">Message</label>
            <div class="control"><textarea class="textarea" name="message" required placeholder="Your message..."></textarea></div>
          </div>
          <div class="field">
            <div class="control"><button type="submit" class="button is-primary is-fullwidth">Send Message</button></div>
          </div>
        </form>
      </div>
    </div>
  </div>
</section>
HTML;
    }

    // === NEW BLOCK RENDERERS ===

    private static function renderQuote(array $data): string {
        $text = Sanitize::html($data['text'] ?? '');
        $author = Sanitize::html($data['author'] ?? '');
        $role = Sanitize::html($data['role'] ?? '');

        $attribution = '';
        if ($author) {
            $attribution = "<footer class=\"mt-4\"><cite class=\"has-text-weight-bold\">{$author}";
            if ($role) $attribution .= " <span class=\"has-text-grey\">— {$role}</span>";
            $attribution .= "</cite></footer>";
        }

        return <<<HTML
<section class="section-sm reveal">
  <blockquote class="is-size-4" style="border-left:4px solid var(--color-primary);padding-left:1.5rem;max-width:48rem;margin:0 auto">
    <p class="has-text-grey-dark is-italic">"{$text}"</p>
    {$attribution}
  </blockquote>
</section>
HTML;
    }

    private static function renderDivider(array $data): string {
        $style = $data['style'] ?? 'line';
        $icon = Sanitize::html($data['icon'] ?? '⭐');

        return match($style) {
            'dots' => '<hr style="margin:2rem 0;border:none"><p style="text-align:center;letter-spacing:.5rem;color:#aaa">&bull;&bull;&bull;</p>',
            'icon' => "<p style=\"text-align:center;margin:2rem 0;font-size:1.5rem\">{$icon}</p>",
            default => '<hr style="margin:2rem 0">'
        };
    }

    private static function renderVideo(array $data): string {
        $title   = Sanitize::html($data['title'] ?? '');
        $rawUrl  = $data['url'] ?? '';
        $caption = Sanitize::html($data['caption'] ?? '');

        // Normalize YouTube watch/short URLs → embed format
        if (preg_match('/youtube\.com\/watch\?(?:[^#]*&)?v=([A-Za-z0-9_-]+)/', $rawUrl, $m)) {
            $rawUrl = 'https://www.youtube.com/embed/' . $m[1];
        } elseif (preg_match('/youtu\.be\/([A-Za-z0-9_-]+)/', $rawUrl, $m)) {
            $rawUrl = 'https://www.youtube.com/embed/' . $m[1];
        }

        $url = Sanitize::html($rawUrl);

        $titleHtml = $title ? "<h3 class=\"title is-3 has-text-centered mb-5\">{$title}</h3>" : '';
        $captionHtml = $caption ? "<p class=\"has-text-grey has-text-centered mt-4\">{$caption}</p>" : '';

        return <<<HTML
<section class="section reveal">
  <div class="container">
    {$titleHtml}
    <figure class="image is-16by9" style="max-width:900px;margin:0 auto;border-radius:.75rem;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.12)">
      <iframe class="has-ratio" src="{$url}" allowfullscreen loading="lazy"></iframe>
    </figure>
    {$captionHtml}
  </div>
</section>
HTML;
    }

    private static function renderCarousel(array $data): string {
        $items = $data['items'] ?? [];
        if (empty($items)) return '';

        $id = 'cr' . substr(md5(serialize($items)), 0, 6);
        $count = count($items);

        $slidesHtml = '';
        $dotsHtml = '';
        foreach ($items as $i => $item) {
            $image = Sanitize::html(self::resolveImg($item['image'] ?? '', $item['title'] ?? ('slide' . $i), 900, 506));
            $title = Sanitize::html($item['title'] ?? '');
            $text = Sanitize::html($item['text'] ?? '');
            $captionHtml = ($title || $text)
                ? "<div style=\"position:absolute;bottom:0;left:0;right:0;background:rgba(0,0,0,.55);color:#fff;padding:1rem\"><strong>{$title}</strong>" . ($text ? "<br><span style='font-size:.9rem'>{$text}</span>" : '') . "</div>"
                : '';

            $slidesHtml .= <<<SLIDE
<div class="cr-slide" data-index="{$i}" style="position:absolute;inset:0;opacity:0;transition:opacity .6s ease;pointer-events:none">
  <img src="{$image}" alt="{$title}" style="width:100%;height:100%;object-fit:cover">
  {$captionHtml}
</div>
SLIDE;
            $dotN = $i + 1;
            $dotsHtml .= "<button class=\"cr-dot\" aria-label=\"Slide {$dotN}\" data-goto=\"{$i}\" style=\"width:.75rem;height:.75rem;border-radius:50%;background:#ccc;border:none;cursor:pointer;margin:0 .25rem;padding:0;transition:background .2s\"></button>";
        }

        $nonce = defined('CSP_NONCE') ? CSP_NONCE : '';
        return <<<HTML
<section class="section-sm reveal">
  <div id="{$id}" style="max-width:900px;margin:0 auto">
    <div style="position:relative;border-radius:.75rem;overflow:hidden;padding-top:56.25%">
      {$slidesHtml}
      <button data-prev="{$id}" aria-label="Previous" style="position:absolute;left:.75rem;top:50%;transform:translateY(-50%);z-index:10;background:rgba(0,0,0,.45);color:#fff;border:none;border-radius:50%;width:2.5rem;height:2.5rem;font-size:1.4rem;cursor:pointer;display:flex;align-items:center;justify-content:center;line-height:1">&#8249;</button>
      <button data-next="{$id}" aria-label="Next" style="position:absolute;right:.75rem;top:50%;transform:translateY(-50%);z-index:10;background:rgba(0,0,0,.45);color:#fff;border:none;border-radius:50%;width:2.5rem;height:2.5rem;font-size:1.4rem;cursor:pointer;display:flex;align-items:center;justify-content:center;line-height:1">&#8250;</button>
    </div>
    <p style="text-align:center;margin-top:.75rem">{$dotsHtml}</p>
  </div>
  <script nonce="{$nonce}">
  (function(){
    var wrap = document.getElementById('{$id}');
    if(!wrap) return;
    var slides = wrap.querySelectorAll('.cr-slide');
    var dots   = wrap.querySelectorAll('.cr-dot');
    var cur = 0, total = {$count}, timer;
    function show(n){
      n = (n + total) % total;
      slides[cur].style.opacity = '0'; slides[cur].style.pointerEvents = 'none';
      dots[cur].style.background = '#ccc';
      cur = n;
      slides[cur].style.opacity = '1'; slides[cur].style.pointerEvents = '';
      dots[cur].style.background = '#4a90e2';
      clearInterval(timer); timer = setInterval(function(){ show(cur+1); }, 5000);
    }
    wrap.querySelector('[data-prev]').addEventListener('click', function(){ show(cur-1); });
    wrap.querySelector('[data-next]').addEventListener('click', function(){ show(cur+1); });
    dots.forEach(function(d){ d.addEventListener('click', function(){ show(+d.dataset.goto); }); });
    show(0);
  })();
  </script>
</section>
HTML;
    }

    private static function renderChecklist(array $data): string {
        $title = Sanitize::html($data['title'] ?? '');
        $items = $data['items'] ?? [];
        if (empty($items)) return '';

        $titleHtml = $title ? "<h3 class=\"title is-3 mb-5\">{$title}</h3>" : '';
        $listHtml = '';
        foreach ($items as $item) {
            $text = is_string($item) ? Sanitize::html($item) : Sanitize::html($item['text'] ?? '');
            $listHtml .= <<<ITEM
<li style="display:flex;align-items:flex-start;gap:.75rem;margin-bottom:.75rem">
  <svg style="flex-shrink:0;color:green;width:1.25rem;height:1.25rem;margin-top:.15rem" fill="currentColor" viewBox="0 0 20 20">
    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
  </svg>
  <span class="has-text-grey-dark">{$text}</span>
</li>
ITEM;
        }

        return <<<HTML
<section class="section-sm reveal">
  <div style="max-width:40rem;margin:0 auto">
    {$titleHtml}
    <ul style="list-style:none">{$listHtml}</ul>
  </div>
</section>
HTML;
    }

    private static function renderLogoCloud(array $data): string {
        $title = Sanitize::html($data['title'] ?? '');
        $items = $data['items'] ?? $data['logos'] ?? [];
        if (empty($items)) return '';

        $titleHtml = $title ? "<p class=\"has-text-grey has-text-centered mb-6 is-size-6 has-text-weight-semibold\" style=\"text-transform:uppercase;letter-spacing:.1em\">{$title}</p>" : '';
        $logosHtml = '';
        foreach ($items as $i => $item) {
            $name = Sanitize::html($item['name'] ?? '');
            $resolvedUrl = Sanitize::html(self::resolveImg($item['url'] ?? '', $item['name'] ?? ('logo' . $i), 200, 100));
            $logosHtml .= "<div style=\"padding:1rem\"><img src=\"{$resolvedUrl}\" alt=\"{$name}\" style=\"max-height:3rem;filter:grayscale(1);opacity:.6;transition:all .2s\" onmouseover=\"this.style.filter='';this.style.opacity=1\" onmouseout=\"this.style.filter='grayscale(1)';this.style.opacity=.6\" loading=\"lazy\"></div>";
        }

        return <<<HTML
<section class="section-sm reveal" style="background:var(--color-background-secondary)">
  <div class="container">
    {$titleHtml}
    <div style="display:flex;flex-wrap:wrap;justify-content:center;align-items:center">
      {$logosHtml}
    </div>
  </div>
</section>
HTML;
    }

    private static function renderComparison(array $data): string {
        $title = Sanitize::html($data['title'] ?? '');
        $titleHtml = $title ? "<h3 class=\"title is-3 has-text-centered mb-6\">{$title}</h3>" : '';

        // Normalise to headers + rows regardless of input format
        $check = '<svg style="color:green;width:1.25rem;height:1.25rem;margin:auto;display:block" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>';
        $cross  = '<svg style="color:#cc0000;width:1.25rem;height:1.25rem;margin:auto;display:block" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>';

        $plans    = $data['plans']   ?? $data['items']   ?? $data['columns'] ?? [];
        $features = $data['features'] ?? [];

        if (!empty($plans) && !empty($features)) {
            // plans/features format: {plans:[{name,features:[bool]}], features:[string]}
            $headers = array_merge(['Feature'], array_map(fn($p) => $p['name'] ?? '', $plans));
            $rows = [];
            foreach ($features as $fi => $feat) {
                $row = [Sanitize::html($feat)];
                foreach ($plans as $plan) {
                    $val = $plan['features'][$fi] ?? false;
                    $row[] = ($val === true || $val === 'yes' || $val === '✓') ? $check : $cross;
                }
                $rows[] = $row;
            }
        } else {
            // Legacy headers/rows format
            $headers = $data['headers'] ?? [];
            $rows    = $data['rows']    ?? [];
        }

        if (empty($headers) || empty($rows)) return '';

        $headerHtml = '';
        foreach ($headers as $i => $h) {
            $bg = $i === 0 ? 'background:var(--color-background-secondary)' : 'background:var(--color-primary);color:#fff';
            $headerHtml .= "<th style=\"{$bg}\">" . ($i === 0 ? Sanitize::html($h) : Sanitize::html($h)) . '</th>';
        }

        $rowsHtml = '';
        foreach ($rows as $row) {
            $rowsHtml .= '<tr>';
            foreach ($row as $i => $cell) {
                $class = $i === 0 ? 'has-text-weight-semibold' : 'has-text-centered';
                // cell may already be pre-rendered SVG (from plans/features path) or raw text
                $cellContent = is_string($cell) && str_starts_with($cell, '<') ? $cell : Sanitize::html((string)$cell);
                if (is_string($cell) && !str_starts_with($cell, '<')) {
                    if ($cell === true || strtolower($cell) === 'yes' || $cell === '✓') $cellContent = $check;
                    elseif ($cell === false || strtolower($cell) === 'no' || $cell === '✗') $cellContent = $cross;
                }
                $rowsHtml .= "<td class=\"{$class}\">{$cellContent}</td>";
            }
            $rowsHtml .= '</tr>';
        }

        return <<<HTML
<section class="section reveal">
  <div class="container">
    {$titleHtml}
    <div class="table-container">
      <table class="table is-striped is-fullwidth">
        <thead><tr>{$headerHtml}</tr></thead>
        <tbody>{$rowsHtml}</tbody>
      </table>
    </div>
  </div>
</section>
HTML;
    }

    private static function renderTabs(array $data): string {
        $items = $data['items'] ?? [];
        if (empty($items)) return '';

        $tabId = 'tab' . substr(md5(serialize($items)), 0, 6);

        $tabsHtml = '';
        $panelsHtml = '';
        foreach ($items as $i => $item) {
            $label = Sanitize::html($item['label'] ?? 'Tab ' . ($i + 1));
            $content = Sanitize::richText($item['content'] ?? '');
            $activeClass = $i === 0 ? ' is-active' : '';
            $hidden = $i === 0 ? '' : 'style="display:none"';

            $tabsHtml .= "<li class=\"tab-item{$activeClass}\" style=\"cursor:pointer\"><a class=\"tab-link\" data-tab=\"{$tabId}-{$i}\">{$label}</a></li>";
            $panelsHtml .= "<div id=\"{$tabId}-{$i}\" class=\"tab-panel\" {$hidden}><div class=\"content\">{$content}</div></div>";
        }

        $nonce = defined('CSP_NONCE') ? CSP_NONCE : '';
        return <<<HTML
<section class="section reveal">
  <div class="container" data-tabs="{$tabId}">
    <div class="tabs is-boxed">
      <ul class="tab-list">
        {$tabsHtml}
      </ul>
    </div>
    {$panelsHtml}
    <script nonce="{$nonce}">
    (function(){
      var wrap = document.querySelector('[data-tabs="{$tabId}"]');
      if(!wrap) return;
      wrap.querySelectorAll('.tab-link').forEach(function(t){
        t.addEventListener('click', function(){
          var id = t.dataset.tab;
          wrap.querySelectorAll('.tab-panel').forEach(function(p){ p.style.display = 'none'; });
          wrap.querySelectorAll('.tab-item').forEach(function(i){ i.classList.remove('is-active'); });
          var panel = document.getElementById(id);
          if(panel) panel.style.display = '';
          var li = t.closest('.tab-item');
          if(li) li.classList.add('is-active');
        });
      });
    })();
    </script>
  </div>
</section>
HTML;
    }

    private static function renderAccordion(array $data): string {
        $title = Sanitize::html($data['title'] ?? '');
        $items = $data['items'] ?? [];
        if (empty($items)) return '';

        $titleHtml = $title ? "<h3 class=\"title is-3 has-text-centered mb-6\">{$title}</h3>" : '';

        $accordionHtml = '';
        foreach ($items as $i => $item) {
            $itemTitle = Sanitize::html($item['title'] ?? '');
            $content = Sanitize::richText($item['content'] ?? '');
            $open = $i === 0 ? ' open' : '';

            $accordionHtml .= <<<ITEM
<details class="box mb-3 acc-item"{$open}>
  <summary style="font-weight:600;font-size:1.1rem;cursor:pointer;list-style:none;display:flex;justify-content:space-between;align-items:center;gap:1rem">
    <span>{$itemTitle}</span>
    <span class="acc-chevron" style="font-size:1.1rem;transition:transform .25s ease;display:inline-block;flex-shrink:0">&#9660;</span>
  </summary>
  <div class="content mt-3">{$content}</div>
</details>
ITEM;
        }

        $nonce = defined('CSP_NONCE') ? CSP_NONCE : '';
        return <<<HTML
<section class="section reveal">
  <div class="container" style="max-width:48rem">
    {$titleHtml}
    {$accordionHtml}
  </div>
</section>
<script nonce="{$nonce}">
(function(){
  if(window._monolithcmsAccordionInit) return;
  window._monolithcmsAccordionInit = true;
  function initAcc(){
    document.querySelectorAll('details.acc-item').forEach(function(d){
      if(d._accBound) return;
      d._accBound = true;
      var ch = d.querySelector('.acc-chevron');
      if(!ch) return;
      ch.style.transform = d.open ? 'rotate(-180deg)' : '';
      d.addEventListener('toggle', function(){
        ch.style.transform = d.open ? 'rotate(-180deg)' : '';
      });
    });
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',initAcc);
  else initAcc();
})();
</script>
HTML;
    }

    private static function renderTable(array $data): string {
        $title = Sanitize::html($data['title'] ?? '');
        $headers = $data['headers'] ?? [];
        $rows = $data['rows'] ?? [];
        if (empty($rows)) return '';

        $titleHtml = $title ? "<h3 class=\"title is-3 has-text-centered mb-5\">{$title}</h3>" : '';

        $headerHtml = '';
        foreach ($headers as $h) {
            $headerHtml .= '<th>' . Sanitize::html($h) . '</th>';
        }

        $rowsHtml = '';
        foreach ($rows as $row) {
            $rowsHtml .= '<tr>';
            foreach ($row as $cell) {
                $rowsHtml .= '<td>' . Sanitize::html($cell) . '</td>';
            }
            $rowsHtml .= '</tr>';
        }

        return <<<HTML
<section class="section-sm reveal">
  <div class="table-container">
    {$titleHtml}
    <table class="table is-striped is-fullwidth">
      <thead><tr>{$headerHtml}</tr></thead>
      <tbody>{$rowsHtml}</tbody>
    </table>
  </div>
</section>
HTML;
    }

    private static function renderTimeline(array $data): string {
        $title = Sanitize::html($data['title'] ?? '');
        $items = $data['items'] ?? [];
        if (empty($items)) return '';

        $titleHtml = $title ? "<h3 class=\"title is-3 has-text-centered mb-8\">{$title}</h3>" : '';

        $timelineHtml = '';
        foreach ($items as $item) {
            $date = Sanitize::html($item['date'] ?? '');
            $itemTitle = Sanitize::html($item['title'] ?? '');
            $desc = Sanitize::html($item['description'] ?? '');

            $timelineHtml .= <<<ITEM
<div style="display:flex;gap:1.5rem;margin-bottom:2rem">
  <div style="display:flex;flex-direction:column;align-items:center">
    <span style="width:1rem;height:1rem;border-radius:50%;background:var(--color-primary);flex-shrink:0;margin-top:.3rem"></span>
    <span style="flex:1;width:2px;background:#e0e0e0;margin-top:.5rem"></span>
  </div>
  <div class="box" style="flex:1;margin-bottom:0">
    <p class="has-text-grey is-size-7 mb-1">{$date}</p>
    <p class="has-text-weight-bold">{$itemTitle}</p>
    <p class="has-text-grey">{$desc}</p>
  </div>
</div>
ITEM;
        }

        return <<<HTML
<section class="section reveal">
  <div class="container" style="max-width:48rem">
    {$titleHtml}
    {$timelineHtml}
  </div>
</section>
HTML;
    }

    private static function renderList(array $data): string {
        $title = Sanitize::html($data['title'] ?? '');
        $style = $data['style'] ?? 'bullet';
        $items = $data['items'] ?? [];
        if (empty($items)) return '';

        $titleHtml = $title ? "<h3 class=\"title is-3 mb-5\">{$title}</h3>" : '';

        $listHtml = '';
        foreach ($items as $item) {
            $text = is_string($item) ? Sanitize::html($item) : Sanitize::html($item['text'] ?? '');
            if ($style === 'check') {
                $listHtml .= <<<ITEM
<li style="display:flex;align-items:flex-start;gap:.5rem;margin-bottom:.5rem">
  <svg style="color:green;width:1rem;height:1rem;flex-shrink:0;margin-top:.25rem" fill="currentColor" viewBox="0 0 20 20">
    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
  </svg>
  <span>{$text}</span>
</li>
ITEM;
            } else {
                $listHtml .= "<li style=\"margin-bottom:.375rem\">{$text}</li>";
            }
        }

        $tag = $style === 'number' ? 'ol' : 'ul';
        $listStyle = $style === 'bullet' ? 'list-disc' : ($style === 'number' ? 'list-decimal' : 'list-none');
        $mlStyle = ($style === 'check') ? 'margin-left:0' : 'margin-left:1.5rem';

        return <<<HTML
<section class="section-sm reveal">
  <div style="max-width:40rem;margin:0 auto">
    {$titleHtml}
    <{$tag} style="{$mlStyle}" class="content">{$listHtml}</{$tag}>
  </div>
</section>
HTML;
    }

    private static function renderNewsletter(array $data): string {
        $title = Sanitize::html($data['title'] ?? 'Stay Updated');
        $text = Sanitize::html($data['text'] ?? '');
        $button = Sanitize::html($data['button'] ?? 'Subscribe');
        $placeholder = Sanitize::html($data['placeholder'] ?? 'Enter your email');
        $csrf = CSRF::token();

        $textHtml = $text ? "<p class=\"has-text-grey mb-5\">{$text}</p>" : '';

        return <<<HTML
<section class="section reveal" style="background:var(--color-background-secondary)">
  <div class="container" style="max-width:36rem">
    <div class="has-text-centered">
      <h3 class="title is-3">{$title}</h3>
      {$textHtml}
      <form method="post" action="/newsletter">
        <input type="hidden" name="_csrf" value="{$csrf}">
        <div class="field has-addons" style="justify-content:center">
          <div class="control is-expanded"><input class="input" type="email" name="email" placeholder="{$placeholder}" required></div>
          <div class="control"><button type="submit" class="button is-primary">{$button}</button></div>
        </div>
      </form>
    </div>
  </div>
</section>
HTML;
    }

    private static function renderDownload(array $data): string {
        $title = Sanitize::html($data['title'] ?? '');
        $desc = Sanitize::html($data['description'] ?? '');
        $file = Sanitize::html($data['file'] ?? '');
        $button = Sanitize::html($data['button'] ?? 'Download');
        $icon = Sanitize::html($data['icon'] ?? '📄');

        $descHtml = $desc ? "<p class=\"has-text-grey\">{$desc}</p>" : '';

        return <<<HTML
<section class="section-sm reveal">
  <div class="box" style="max-width:32rem;margin:0 auto;display:flex;align-items:center;gap:1.5rem">
    <span style="font-size:2.5rem">{$icon}</span>
    <div style="flex:1">
      <p class="has-text-weight-bold">{$title}</p>
      {$descHtml}
    </div>
    <a href="{$file}" class="button is-primary" download>
      <svg xmlns="http://www.w3.org/2000/svg" style="width:1.25rem;height:1.25rem;margin-right:.4rem" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
      {$button}
    </a>
  </div>
</section>
HTML;
    }

    private static function renderAlert(array $data): string {
        $type = $data['type'] ?? 'info';
        $title = Sanitize::html($data['title'] ?? '');
        $text = Sanitize::html($data['text'] ?? '');

        $notifClass = match($type) {
            'success' => 'is-success is-light',
            'warning' => 'is-warning is-light',
            'error'   => 'is-danger is-light',
            default   => 'is-info is-light'
        };

        $titleHtml = $title ? "<strong>{$title}</strong> " : '';
        $nonce = CSP_NONCE;

        return <<<HTML
<section class="section-sm reveal">
  <div class="notification {$notifClass}">
    <button class="delete" data-dismiss="alert" aria-label="Dismiss"></button>
    {$titleHtml}{$text}
  </div>
</section>
<script nonce="{$nonce}">(function(){var b=document.currentScript.previousElementSibling.querySelector('[data-dismiss="alert"]');if(b)b.addEventListener('click',function(){b.closest('section').remove();});})();</script>
HTML;
    }

    private static function renderProgress(array $data): string {
        $title = Sanitize::html($data['title'] ?? '');
        $items = $data['items'] ?? [];
        if (empty($items)) return '';

        $titleHtml = $title ? "<h3 class=\"title is-3 mb-5\">{$title}</h3>" : '';

        $progressHtml = '';
        foreach ($items as $item) {
            $label = Sanitize::html($item['label'] ?? '');
            $value = min(100, max(0, (int)($item['value'] ?? 0)));

            $progressHtml .= <<<ITEM
<div class="mb-4">
  <div style="display:flex;justify-content:space-between;margin-bottom:.25rem">
    <span class="has-text-grey-dark">{$label}</span>
    <span class="has-text-grey">{$value}%</span>
  </div>
  <progress class="progress is-primary" value="0" max="100" data-value="{$value}">{$value}%</progress>
</div>
ITEM;
        }

        $nonce = defined('CSP_NONCE') ? CSP_NONCE : '';
        return <<<HTML
<section class="section-sm reveal">
  <div style="max-width:40rem;margin:0 auto">
    {$titleHtml}
    {$progressHtml}
  </div>
</section>
<script nonce="{$nonce}">
(function(){
  if(window._monolithcmsProgressInit) return;
  window._monolithcmsProgressInit = true;
  var obs = new IntersectionObserver(function(entries){
    entries.forEach(function(e){
      if(!e.isIntersecting) return;
      var bar = e.target;
      var end = +bar.dataset.value;
      var dur = 1000, start = performance.now();
      (function frame(now){
        var p = Math.min((now-start)/dur,1);
        bar.value = Math.round(end * (p<0.5?2*p*p:-1+(4-2*p)*p));
        if(p<1) requestAnimationFrame(frame);
        else bar.value = end;
      })(start);
      obs.unobserve(bar);
    });
  },{threshold:0.4});
  function init(){
    document.querySelectorAll('progress[data-value]').forEach(function(b){ obs.observe(b); });
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',init);
  else init();
})();
</script>
HTML;
    }

    private static function renderSteps(array $data): string {
        $title = Sanitize::html($data['title'] ?? '');
        $items = $data['items'] ?? [];
        if (empty($items)) return '';

        $titleHtml = $title ? "<h3 class=\"title is-2 has-text-centered mb-8\">{$title}</h3>" : '';

        $stepsHtml = '';
        foreach ($items as $item) {
            $icon = Sanitize::html($item['icon'] ?? $item['number'] ?? '');
            $itemTitle = Sanitize::html($item['title'] ?? '');
            $desc = Sanitize::html($item['description'] ?? '');
            $iconHtml = $icon
                ? "<span class=\"material-symbols-outlined\" style=\"font-size:2.25rem;color:var(--color-primary);margin-bottom:.75rem;display:block\">{$icon}</span>"
                : '';

            $stepsHtml .= <<<STEP
<div class="column is-one-third-desktop is-half-tablet">
  <div class="card h-100">
    <div class="card-content has-text-centered">
      {$iconHtml}
      <p class="title is-5 mt-2">{$itemTitle}</p>
      <p class="has-text-grey">{$desc}</p>
    </div>
  </div>
</div>
STEP;
        }

        return <<<HTML
<section class="section reveal">
  <div class="container">
    {$titleHtml}
    <div class="columns is-multiline">
      {$stepsHtml}
    </div>
  </div>
</section>
HTML;
    }

    private static function renderColumns(array $data): string {
        $columns = $data['columns'] ?? [];
        if (empty($columns)) return '';

        $count = count($columns);
        $colWidth = match($count) {
            1 => '',
            2 => 'is-half',
            3 => 'is-one-third',
            4 => 'is-one-quarter',
            default => 'is-one-third'
        };

        $colsHtml = '';
        foreach ($columns as $col) {
            $content = Sanitize::richText($col['content'] ?? '');
            $colsHtml .= "<div class=\"column {$colWidth}\"><div class=\"content\">{$content}</div></div>";
        }

        return <<<HTML
<section class="section-sm reveal">
  <div class="container">
    <div class="columns is-multiline">
      {$colsHtml}
    </div>
  </div>
</section>
HTML;
    }

    private static function renderSpacer(array $data): string {
        $size = $data['size'] ?? 'medium';
        $heights = ['small' => '2rem', 'medium' => '4rem', 'large' => '8rem', 'xlarge' => '12rem'];
        $height = $heights[$size] ?? $heights['medium'];
        return "<div style=\"height:{$height}\"></div>";
    }

    private static function renderMap(array $data): string {
        $title = Sanitize::html($data['title'] ?? '');
        $address = Sanitize::html($data['address'] ?? '');
        $embed = Sanitize::html($data['embed'] ?? '');

        $titleHtml = $title ? "<h3 class=\"title is-3 has-text-centered mb-5\">{$title}</h3>" : '';
        $embedHtml = $embed ? "<div class=\"image is-16by9\"><iframe class=\"has-ratio\" src=\"{$embed}\" allowfullscreen loading=\"lazy\"></iframe></div>" : '';
        $addressHtml = $address ? "<p class=\"has-text-grey has-text-centered mt-4\">{$address}</p>" : '';

        return <<<HTML
<section class="section-sm reveal">
  <div class="container">
    {$titleHtml}
    {$embedHtml}
    {$addressHtml}
  </div>
</section>
HTML;
    }

    private static function renderContactInfo(array $data): string {
        $title = Sanitize::html($data['title'] ?? '');
        $items = $data['items'] ?? [];
        if (empty($items)) return '';

        $titleHtml = $title ? "<h3 class=\"title is-3 has-text-centered mb-6\">{$title}</h3>" : '';

        $itemsHtml = '';
        foreach ($items as $item) {
            $iconRaw  = $item['icon'] ?? '📧';
            $icon     = preg_match('/^[a-z_]+$/', $iconRaw) ? self::renderIconHtml($iconRaw) : Sanitize::html($iconRaw);
            $label = Sanitize::html($item['label'] ?? '');
            $value = Sanitize::html($item['value'] ?? '');
            $url = Sanitize::html($item['url'] ?? '');

            $valueHtml = $url
                ? "<a href=\"{$url}\" class=\"has-text-primary\">{$value}</a>"
                : "<span>{$value}</span>";

            $itemsHtml .= <<<ITEM
<div class="column is-one-third-desktop is-half-tablet">
  <div class="box has-text-centered">
    <p style="font-size:2rem;margin-bottom:.5rem">{$icon}</p>
    <p class="heading">{$label}</p>
    <p class="has-text-weight-medium">{$valueHtml}</p>
  </div>
</div>
ITEM;
        }

        return <<<HTML
<section class="section reveal">
  <div class="container">
    {$titleHtml}
    <div class="columns is-multiline is-centered">
      {$itemsHtml}
    </div>
  </div>
</section>
HTML;
    }

    private static function renderSocial(array $data): string {
        $title = Sanitize::html($data['title'] ?? '');
        $items = $data['items'] ?? $data['links'] ?? [];
        if (empty($items)) return '';

        $titleHtml = $title ? "<p class=\"has-text-weight-semibold mb-3\">{$title}</p>" : '';

        $linksHtml = '';
        foreach ($items as $item) {
            $platform = strtolower($item['platform'] ?? '');
            $url = Sanitize::html($item['url'] ?? '#');
            $icon = Sanitize::html($item['icon'] ?? '🔗');
            $label = ucfirst($platform) ?: 'Link';
            $linksHtml .= "<a href=\"{$url}\" class=\"button\" style=\"font-size:1.25rem;background:var(--bg-alt);color:var(--tx);border:1px solid var(--bd)\" target=\"_blank\" rel=\"noopener\" title=\"{$label}\">{$icon}</a>";
        }

        return <<<HTML
<div class="section-sm has-text-centered reveal">
  {$titleHtml}
  <div class="buttons is-centered">
    {$linksHtml}
  </div>
</div>
HTML;
    }
    private static function renderRawHtml(array $data): string {
        $html = $data['html'] ?? $data['content'] ?? '';
        if (empty($html)) return '';
        return '<div class="reveal">' . $html . '</div>';
    }

    private static function renderRawJs(array $data): string {
        $js = $data['js'] ?? $data['content'] ?? '';
        if (empty($js)) return '';
        $id = 'rawjs_' . substr(md5($js), 0, 8);
        $title = Sanitize::html($data['title'] ?? '');
        $titleHtml = $title ? "<p class=\"has-text-grey is-size-7 mb-2\">{$title}</p>" : '';
        $nonce = defined('CSP_NONCE') ? CSP_NONCE : '';
        return "<div class=\"reveal\" id=\"{$id}\">{$titleHtml}<script nonce=\"{$nonce}\">{$js}</script></div>";
    }

    private static function renderGame(array $data): string {
        $url    = Sanitize::html($data['url'] ?? '');
        $title  = Sanitize::html($data['title'] ?? 'Game');
        $height = (int) ($data['height'] ?? 600);
        if (empty($url)) return '';
        return <<<HTML
<section class="section-sm reveal">
  <div class="container">
    <h3 class="title is-4 has-text-centered mb-4">{$title}</h3>
    <div style="overflow:hidden;border-radius:.5rem;border:1px solid #e0e0e0;max-width:900px;margin:0 auto">
      <iframe src="{$url}" title="{$title}" width="100%" height="{$height}" frameborder="0" allowfullscreen allow="fullscreen; pointer-lock"></iframe>
    </div>
  </div>
</section>
HTML;
    }

    private static function renderLinkTree(array $data): string {
        $title  = Sanitize::html($data['title'] ?? '');
        $avatar = Sanitize::html($data['avatar'] ?? '');
        $bio    = Sanitize::html($data['bio'] ?? '');
        $links  = $data['links'] ?? [];
        if (empty($links)) return '';

        $avatarHtml = $avatar ? "<figure class=\"image is-96x96\" style=\"margin:0 auto 1rem\"><img style=\"border-radius:50%\" src=\"{$avatar}\" alt=\"{$title}\"></figure>" : '';
        $titleHtml  = $title ? "<h2 class=\"title is-4 mb-1\">{$title}</h2>" : '';
        $bioHtml    = $bio ? "<p class=\"has-text-grey mb-4\">{$bio}</p>" : '';

        $linksHtml = '';
        foreach ($links as $link) {
            $label    = Sanitize::html($link['label'] ?? $link['title'] ?? '');
            $href     = Sanitize::html($link['url'] ?? '#');
            $icon     = Sanitize::html($link['icon'] ?? '');
            $iconHtml = $icon ? "<span style=\"margin-right:.5rem\">{$icon}</span>" : '';
            $linksHtml .= "<a href=\"{$href}\" class=\"button is-fullwidth is-outlined mb-3\" target=\"_blank\" rel=\"noopener\">{$iconHtml}{$label}</a>";
        }

        return <<<HTML
<section class="section-sm reveal">
  <div style="max-width:24rem;margin:0 auto;text-align:center">
    {$avatarHtml}
    {$titleHtml}
    {$bioHtml}
    {$linksHtml}
  </div>
</section>
HTML;
    }

    private static function renderEmbed(array $data): string {
        $url    = Sanitize::html($data['url'] ?? '');
        $title  = Sanitize::html($data['title'] ?? 'Embedded Content');
        $height = (int) ($data['height'] ?? 400);
        if (empty($url)) return '';
        return <<<HTML
<section class="section-sm reveal">
  <div class="container">
    <div style="overflow:hidden;border-radius:.5rem;border:1px solid #e0e0e0">
      <iframe src="{$url}" title="{$title}" width="100%" height="{$height}" frameborder="0" loading="lazy" allowfullscreen></iframe>
    </div>
    <p class="has-text-centered has-text-grey is-size-7 mt-2">{$title}</p>
  </div>
</section>
HTML;
    }
}

class AssetController {
    public static function serve(string $hash): void {
        Asset::serve($hash);
    }

    public static function serveCSS(string $hash): void {
        // Release session lock before serving static CSS
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $file = MONOLITHCMS_CACHE . '/assets/' . $hash . '.css';

        if (!file_exists($file)) {
            // Regenerate CSS
            CSSGenerator::cacheAndGetHash();
            $files = glob(MONOLITHCMS_CACHE . '/assets/app.*.css');
            if (empty($files)) {
                http_response_code(404);
                exit;
            }
            $file = $files[0];
        }

        header('Content-Type: text/css');
        header('Cache-Control: public, max-age=31536000, immutable');
        readfile($file);
        exit;
    }

    public static function serveAdminCSS(): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        header('Content-Type: text/css');
        header('Cache-Control: public, max-age=86400');
        echo <<<'CSS'
/* ── MonolithCMS Admin Design System ────────────────────────────── */
:root{
  --p:#135bec;--p-h:#1251d4;--p-r:rgba(19,91,236,.16);
  --bd:#e2e8f0;--bd-i:#cbd5e1;
  --tx:#0f172a;--mx:#64748b;--px:#94a3b8;
  --sf:#fff;--bg:#f6f7fb;--bg-alt:#f1f5f9;
  --r:8px;--r-sm:6px;--r-lg:12px;
  --sh:0 1px 3px rgba(0,0,0,.07),0 1px 2px rgba(0,0,0,.04);
}
html.dark{
  --bd:#1e293b;--bd-i:#334155;
  --tx:#f1f5f9;--mx:#94a3b8;--px:#64748b;
  --sf:#1e293b;--bg:#0f172a;--bg-alt:#1e2940;
  --sh:0 1px 3px rgba(0,0,0,.3),0 1px 2px rgba(0,0,0,.2);
}
/* ── Form Controls ── */
html .cms-input,html .cms-select,html .cms-textarea{
  display:block;width:100%;padding:.5625rem .875rem;
  border:1.5px solid var(--bd-i);border-radius:var(--r);
  background:var(--sf);color:var(--tx);
  font-size:.875rem;line-height:1.5;font-family:inherit;
  transition:border-color .15s,box-shadow .15s;outline:none;
  box-shadow:none;
}
html .cms-input::placeholder,html .cms-textarea::placeholder{color:var(--px);}
html .cms-input:focus,html .cms-select:focus,html .cms-textarea:focus{
  border-color:var(--p);box-shadow:0 0 0 3.5px var(--p-r);
}
html .cms-input:disabled,html .cms-select:disabled,html .cms-textarea:disabled{
  background:var(--bg);color:var(--mx);cursor:not-allowed;
}
.cms-textarea{resize:vertical;min-height:5rem;}
.cms-select{cursor:pointer;appearance:none;-webkit-appearance:none;padding-right:2.5rem;
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke-width='2' stroke='%2364748b'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' d='M19 9l-7 7-7-7'/%3E%3C/svg%3E");
  background-repeat:no-repeat;background-position:right .75rem center;background-size:1rem;}
/* ── Labels & Hints ── */
.cms-label{display:block;font-size:.8125rem;font-weight:600;color:var(--tx);margin-bottom:.375rem;}
.cms-label-sm{display:block;font-size:.75rem;font-weight:500;color:var(--mx);margin-bottom:.25rem;}
.cms-hint{font-size:.75rem;color:var(--px);margin-top:.375rem;line-height:1.4;}
/* ── Card ── */
.cms-card{background:var(--sf);border:1px solid var(--bd);border-radius:var(--r-lg);padding:1.5rem;box-shadow:var(--sh);margin-bottom:1.25rem;}
.cms-card:last-child{margin-bottom:0;}
.cms-card-header{display:flex;align-items:center;gap:.5rem;font-size:1rem;font-weight:700;color:var(--tx);margin-bottom:1.125rem;padding-bottom:.875rem;border-bottom:1px solid var(--bd);}
.cms-card-header .material-symbols-outlined{font-size:1.25rem;color:var(--p);}
.cms-card-title{font-size:.9375rem;font-weight:600;color:var(--tx);margin-bottom:.875rem;}
/* ── Buttons ── */
.cms-btn{display:inline-flex;align-items:center;justify-content:center;gap:.4375rem;padding:.5625rem 1.125rem;background:var(--p);color:#fff;font-size:.875rem;font-weight:600;line-height:1;border:none;border-radius:var(--r);cursor:pointer;text-decoration:none;transition:background-color .15s;white-space:nowrap;}
.cms-btn:hover{background:var(--p-h);}
.cms-btn:disabled{opacity:.55;cursor:not-allowed;}
.cms-btn-ghost{display:inline-flex;align-items:center;justify-content:center;gap:.4375rem;padding:.5625rem 1.125rem;background:var(--bg-alt);color:var(--mx);font-size:.875rem;font-weight:600;line-height:1;border:1px solid var(--bd);border-radius:var(--r);cursor:pointer;text-decoration:none;transition:background-color .15s;white-space:nowrap;}
.cms-btn-ghost:hover{background:var(--bg);color:var(--tx);}
.cms-btn-danger{display:inline-flex;align-items:center;justify-content:center;gap:.375rem;padding:.4375rem .875rem;background:#fef2f2;color:#dc2626;font-size:.8125rem;font-weight:600;line-height:1;border:1px solid #fee2e2;border-radius:var(--r-sm);cursor:pointer;text-decoration:none;transition:background-color .15s;white-space:nowrap;}
.cms-btn-danger:hover{background:#fee2e2;}
html.dark .cms-btn-danger{background:#450a0a;color:#fca5a5;border-color:#7f1d1d;}
html.dark .cms-btn-danger:hover{background:#7f1d1d;}
.cms-btn-action{display:inline-flex;align-items:center;justify-content:center;gap:.375rem;padding:.4375rem .875rem;background:var(--bg);color:var(--mx);font-size:.8125rem;font-weight:500;line-height:1;border:1px solid var(--bd);border-radius:var(--r-sm);cursor:pointer;text-decoration:none;transition:background-color .15s;white-space:nowrap;}
.cms-btn-action:hover{background:var(--bg-alt);color:var(--tx);}
.cms-btn-sm{padding:.375rem .75rem;font-size:.8125rem;}
.cms-btn-icon{padding:.4375rem;}
/* ── Alerts ── */
.cms-alert{display:flex;align-items:flex-start;gap:.75rem;padding:.875rem 1rem;border-radius:var(--r);border:1px solid;margin-bottom:1.25rem;font-size:.875rem;line-height:1.5;}
.cms-alert .material-symbols-outlined{font-size:1.25rem;flex-shrink:0;margin-top:.05rem;}
.cms-alert-success{background:#f0fdf4;border-color:#bbf7d0;color:#166534;}
.cms-alert-success .material-symbols-outlined{color:#16a34a;}
.cms-alert-error{background:#fef2f2;border-color:#fecaca;color:#991b1b;}
.cms-alert-error .material-symbols-outlined{color:#dc2626;}
.cms-alert-warning{background:#fffbeb;border-color:#fde68a;color:#92400e;}
.cms-alert-warning .material-symbols-outlined{color:#d97706;}
.cms-alert-info{background:#eff6ff;border-color:#bfdbfe;color:#1e40af;}
.cms-alert-info .material-symbols-outlined{color:#3b82f6;}
html.dark .cms-alert-success{background:#052e16;border-color:#166534;color:#86efac;}
html.dark .cms-alert-success .material-symbols-outlined{color:#4ade80;}
html.dark .cms-alert-error{background:#450a0a;border-color:#991b1b;color:#fca5a5;}
html.dark .cms-alert-error .material-symbols-outlined{color:#f87171;}
html.dark .cms-alert-warning{background:#422006;border-color:#92400e;color:#fde68a;}
html.dark .cms-alert-warning .material-symbols-outlined{color:#fbbf24;}
html.dark .cms-alert-info{background:#0c1a3d;border-color:#1e40af;color:#93c5fd;}
html.dark .cms-alert-info .material-symbols-outlined{color:#60a5fa;}
/* ── Badges ── */
.cms-badge{display:inline-flex;align-items:center;gap:.3125rem;padding:.25rem .625rem;border-radius:9999px;font-size:.6875rem;font-weight:700;letter-spacing:.02em;border:1px solid transparent;white-space:nowrap;}
.cms-badge::before{content:'';width:.3125rem;height:.3125rem;border-radius:9999px;display:inline-block;flex-shrink:0;}
.cms-badge-published{background:#f0fdf4;border-color:#86efac;color:#15803d;}
.cms-badge-published::before{background:#22c55e;}
.cms-badge-draft{background:var(--bg);border-color:var(--bd-i);color:var(--mx);}
.cms-badge-draft::before{background:var(--px);}
.cms-badge-archived{background:var(--bg);border-color:var(--bd);color:var(--mx);}
.cms-badge-archived::before{background:var(--px);}
.cms-badge-admin{background:#fff1f2;border-color:#fecdd3;color:#be123c;}
.cms-badge-admin::before{background:#e11d48;}
.cms-badge-editor{background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8;}
.cms-badge-editor::before{background:#3b82f6;}
.cms-badge-viewer{background:var(--bg);border-color:var(--bd-i);color:var(--mx);}
.cms-badge-viewer::before{background:var(--px);}
html.dark .cms-badge-published{background:#052e16;border-color:#166534;color:#86efac;}
html.dark .cms-badge-admin{background:#450a0a;border-color:#7f1d1d;color:#fca5a5;}
html.dark .cms-badge-editor{background:#0c1a3d;border-color:#1e40af;color:#93c5fd;}
/* ── Table ── */
.cms-table-wrap{background:var(--sf);border:1px solid var(--bd);border-radius:var(--r-lg);overflow:hidden;box-shadow:var(--sh);}
.cms-table-wrap table{width:100%;text-align:left;border-collapse:collapse;}
.cms-table-wrap thead{background:var(--bg);}
.cms-table-wrap th{padding:.75rem 1.25rem;font-size:.6875rem;font-weight:700;color:var(--mx);text-transform:uppercase;letter-spacing:.06em;border-bottom:1px solid var(--bd);}
.cms-table-wrap td{padding:.9375rem 1.25rem;font-size:.875rem;color:var(--tx);border-bottom:1px solid var(--bd);}
.cms-table-wrap tbody tr:last-child td{border-bottom:none;}
.cms-table-wrap tbody tr:hover{background:var(--bg);}
/* ── Page Header ── */
.cms-page-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.75rem;gap:1rem;flex-wrap:wrap;}
.cms-page-title{font-size:1.375rem;font-weight:800;color:var(--tx);letter-spacing:-.01em;line-height:1.2;}
.cms-page-subtitle{font-size:.875rem;color:var(--mx);margin-top:.25rem;}
.cms-back{display:inline-flex;align-items:center;color:var(--mx);text-decoration:none;transition:color .15s;padding:.25rem;border-radius:var(--r-sm);}
.cms-back:hover{color:var(--p);background:var(--bg-alt);}
/* ── Filter Tabs ── */
.cms-filter-tabs{display:flex;gap:.375rem;margin-bottom:1.25rem;flex-wrap:wrap;}
.cms-filter-tab{padding:.375rem .875rem;border-radius:var(--r-sm);font-size:.8125rem;font-weight:500;color:var(--mx);background:var(--sf);border:1px solid var(--bd);text-decoration:none;transition:all .15s;white-space:nowrap;}
.cms-filter-tab:hover{background:var(--bg);color:var(--tx);}
.cms-filter-tab.active{background:var(--p);color:#fff;border-color:var(--p);}
/* ── Empty State ── */
.cms-empty{text-align:center;padding:3rem 1.5rem;}
.cms-empty-icon{width:3.5rem;height:3.5rem;border-radius:9999px;background:var(--bg-alt);display:flex;align-items:center;justify-content:margin:0 auto .875rem;}
.cms-empty-icon .material-symbols-outlined{font-size:1.75rem;color:var(--px);}
.cms-empty h4{font-size:1rem;font-weight:700;color:var(--tx);margin-bottom:.375rem;}
.cms-empty p{font-size:.875rem;color:var(--mx);margin-bottom:1.25rem;}
/* ── Dark mode overrides for Tailwind utilities ── */
html.dark .bg-white{background:var(--sf);}
html.dark .bg-gray-50,html.dark .bg-slate-50{background:var(--bg-alt);}
html.dark .bg-gray-100,html.dark .bg-slate-100{background:#1e293b;}
html.dark .bg-gray-200,html.dark .bg-slate-200{background:#334155;}
html.dark .bg-amber-50{background:#2d1f05;}
html.dark .bg-green-50{background:#052e16;}
html.dark .bg-blue-50{background:#0c1a3d;}
html.dark .text-green-800{color:#86efac;}
html.dark .text-green-700{color:#4ade80;}
html.dark .text-amber-800{color:#fde68a;}
html.dark .text-amber-700,html.dark .text-amber-600{color:#fbbf24;}
html.dark .text-blue-800{color:#93c5fd;}
html.dark .text-blue-700{color:#93c5fd;}
html.dark .text-blue-600{color:#60a5fa;}
html.dark .text-red-700{color:#fca5a5;}
html.dark .text-red-600{color:#f87171;}
html.dark .bg-red-50{background:#450a0a;}
html.dark .border-gray-100,html.dark .border-b-gray-100{border-color:var(--bd);}
html.dark .border-gray-200,html.dark .border-slate-200{border-color:var(--bd-i);}
html.dark .border-b.border-gray-200{border-color:var(--bd-i);}
html.dark .border-gray-300,html.dark .border-slate-300{border-color:var(--bd-i);}
html.dark .border-dashed.border-gray-300{border-color:var(--bd-i);}
html.dark .divide-gray-100>:not([hidden])~:not([hidden]),html.dark .divide-y.divide-gray-100>:not([hidden])~:not([hidden]){border-color:var(--bd);}
html.dark .divide-gray-200>:not([hidden])~:not([hidden]),html.dark .divide-slate-200>:not([hidden])~:not([hidden]){border-color:var(--bd-i);}
html.dark .text-gray-900,html.dark .text-slate-900{color:var(--tx);}
html.dark .text-gray-800,html.dark .text-slate-800{color:var(--tx);}
html.dark .text-gray-700,html.dark .text-slate-700{color:var(--mx);}
html.dark .text-gray-600,html.dark .text-slate-600{color:var(--mx);}
html.dark .text-gray-500,html.dark .text-slate-500{color:var(--mx);}
html.dark .text-gray-400,html.dark .text-slate-400{color:var(--px);}
html.dark .hover\:bg-gray-50:hover{background:var(--bg-alt);}
html.dark .hover\:bg-gray-100:hover{background:#1e293b;}
html.dark .hover\:bg-gray-200:hover{background:#334155;}
html.dark .hover\:bg-green-100:hover{background:#052e16;}
html.dark .hover\:bg-red-50:hover{background:#450a0a;}
html.dark .hover\:bg-amber-100:hover{background:#2d1f05;}
html.dark .hover\:bg-blue-50:hover{background:#0c1a3d;}
html.dark .hover\:text-gray-600:hover{color:var(--mx);}
html.dark .hover\:text-gray-700:hover{color:var(--tx);}
html.dark summary:hover.hover\:bg-gray-50{background:var(--bg-alt);}
html.dark .font-mono{color:var(--tx);}
html.dark pre{color:var(--tx);}
html.dark input[disabled].bg-gray-50,html.dark input:disabled.bg-gray-50{background:var(--bg-alt);border-color:var(--bd-i);color:var(--mx);}
html.dark .bg-blue-100{background:#0c1a3d;}
html.dark .bg-emerald-50,html.dark .bg-emerald-100{background:#052e16;}
html.dark .bg-orange-50{background:#3b1a04;}
html.dark .bg-amber-100{background:#2d1f05;}
html.dark .bg-green-100{background:#052e16;}
html.dark .text-emerald-600{color:#34d399;}
html.dark .text-orange-600{color:#fb923c;}
html.dark input.border-gray-200,html.dark input.border-gray-300,html.dark textarea.border-gray-200,html.dark textarea.border-gray-300,html.dark select.border-gray-200,html.dark select.border-gray-300{background:var(--bg-alt);border-color:var(--bd-i);color:var(--tx);}
html.dark input:focus.border-gray-200,html.dark textarea:focus.border-gray-200{background:var(--sf);}
html.dark .border-red-200{border-color:#991b1b;}
html.dark .border-green-200{border-color:#166534;}
html.dark .border-amber-200{border-color:#92400e;}
html.dark .border-blue-200{border-color:#1e40af;}
/* ── Quill editor dark mode ── */
html.dark .ql-toolbar.ql-snow{background:var(--bg-alt);border-color:var(--bd-i);}
html.dark .ql-container.ql-snow{background:var(--sf);border-color:var(--bd-i);}
html.dark .ql-editor{color:var(--tx);}
html.dark .ql-editor.ql-blank::before{color:var(--px);}
html.dark .ql-toolbar .ql-stroke{stroke:#94a3b8;}
html.dark .ql-toolbar .ql-fill{fill:#94a3b8;}
html.dark .ql-toolbar .ql-picker{color:#94a3b8;}
html.dark .ql-toolbar button:hover .ql-stroke,html.dark .ql-toolbar .ql-active .ql-stroke{stroke:#f1f5f9;}
html.dark .ql-toolbar button:hover .ql-fill,html.dark .ql-toolbar .ql-active .ql-fill{fill:#f1f5f9;}
html.dark .ql-toolbar button:hover,html.dark .ql-toolbar .ql-active{color:#f1f5f9;}
html.dark .ql-picker-label{color:#94a3b8;border-color:var(--bd-i);}
html.dark .ql-picker-label:hover,html.dark .ql-picker-label.ql-active{color:#f1f5f9;}
html.dark .ql-picker-options{background:var(--sf);border-color:var(--bd-i);}
html.dark .ql-picker-item{color:#94a3b8;}
html.dark .ql-picker-item:hover,html.dark .ql-picker-item.ql-selected{color:#f1f5f9;}
html.dark .ql-formats button{opacity:.8;}
html.dark .ql-formats button:hover{opacity:1;}
html.dark .ql-editor blockquote{border-left-color:var(--bd-i);color:var(--mx);}
html.dark .ql-editor code,html.dark .ql-editor pre{background:var(--bg-alt);color:var(--tx);}
html.dark .wysiwyg-editor,html.dark .ql-toolbar.ql-snow+.ql-container.ql-snow{border-color:var(--bd-i);}
CSS;
        exit;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 20: VISUAL EDITOR
// ─────────────────────────────────────────────────────────────────────────────

class EditorAssets {
    public static function css(): void {
        header('Content-Type: text/css');
        header('Cache-Control: no-cache');
        echo self::getEditorCSS();
        exit;
    }

    public static function js(): void {
        header('Content-Type: application/javascript');
        header('Cache-Control: no-cache');
        echo self::getEditorJS();
        exit;
    }

    public static function revealJs(): void {
        header('Content-Type: application/javascript');
        header('Cache-Control: public, max-age=31536000');
        echo <<<'JS'
// Reveal on scroll animation
document.addEventListener('DOMContentLoaded', function() {
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) entry.target.classList.add('visible');
        });
    }, { threshold: 0, rootMargin: '50px' });
    document.querySelectorAll('.reveal').forEach(el => observer.observe(el));
    // Fallback: make all visible after a short delay if observer fails
    setTimeout(() => {
        document.querySelectorAll('.reveal:not(.visible)').forEach(el => el.classList.add('visible'));
    }, 500);
});
JS;
        exit;
    }

    public static function themeJs(): void {
        header('Content-Type: application/javascript');
        header('Cache-Control: no-cache');
        echo <<<'JS'
(function() {
    var stored = localStorage.getItem('monolithcms-theme');
    if (stored) {
        document.documentElement.setAttribute('data-theme', stored);
    }
})();
JS;
        exit;
    }

    private static function getEditorCSS(): string {
        return <<<'CSS'
/* MonolithCMS Visual Editor — design-system vars (light / dark) */
:root {
    --p:#135bec;--p-h:#1251d4;--p-r:rgba(19,91,236,.16);
    --bd:#e2e8f0;--bd-i:#cbd5e1;
    --tx:#0f172a;--mx:#64748b;--px:#94a3b8;
    --sf:#fff;--bg:#f6f7fb;--bg-alt:#f1f5f9;
    --r:8px;--r-sm:6px;--r-lg:12px;
}
html.dark {
    --bd:#1e293b;--bd-i:#334155;
    --tx:#f1f5f9;--mx:#94a3b8;--px:#64748b;
    --sf:#1e293b;--bg:#0f172a;--bg-alt:#1e2940;
}

/* MonolithCMS Visual Editor */
.monolithcms-edit-bar {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    z-index: 10000;
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
    color: #fff;
    padding: 0.5rem 1rem;
    font-family: system-ui, -apple-system, sans-serif;
    font-size: 14px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.3);
}
.edit-bar-inner {
    max-width: 1400px;
    margin: 0 auto;
    display: flex;
    align-items: center;
    gap: 1rem;
}
.edit-bar-logo {
    font-weight: 700;
    font-size: 16px;
    color: #60a5fa;
    margin-right: auto;
}
.edit-bar-btn {
    padding: 0.4rem 1rem;
    border-radius: 6px;
    text-decoration: none;
    font-weight: 500;
    font-size: 13px;
    transition: all 0.2s;
}
.edit-bar-btn-edit {
    background: var(--p);
    color: #fff;
}
.edit-bar-btn-edit:hover {
    background: var(--p-h);
}
.edit-bar-btn-exit {
    background: #ef4444;
    color: #fff;
}
.edit-bar-btn-exit:hover {
    background: #dc2626;
}
.edit-bar-btn:not(.edit-bar-btn-edit):not(.edit-bar-btn-exit) {
    background: rgba(255,255,255,0.1);
    color: #fff;
}
.edit-bar-btn:not(.edit-bar-btn-edit):not(.edit-bar-btn-exit):hover {
    background: rgba(255,255,255,0.2);
}

/* Adjust page for edit bar */
html[data-edit-mode="true"] body {
    padding-top: 50px;
}

/* Block Wrapper */
.monolithcms-block-wrapper {
    position: relative;
    margin-bottom: 2rem;
    transition: all 0.2s;
}
.monolithcms-block-wrapper:hover {
    outline: 2px dashed var(--p);
    outline-offset: 8px;
}
.monolithcms-block-wrapper:hover .monolithcms-block-toolbar {
    opacity: 1;
    transform: translateY(0);
}

/* Block Toolbar */
.monolithcms-block-toolbar {
    position: absolute;
    top: -40px;
    left: 0;
    right: 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #1e293b;
    color: #fff;
    padding: 0.4rem 0.75rem;
    border-radius: 8px;
    font-size: 12px;
    opacity: 0;
    transform: translateY(5px);
    transition: all 0.2s;
    z-index: 100;
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
}
.block-type-label {
    text-transform: uppercase;
    font-weight: 600;
    color: #94a3b8;
    letter-spacing: 0.5px;
}
.block-actions {
    display: flex;
    gap: 0.25rem;
}
.block-action {
    background: rgba(255,255,255,0.1);
    border: none;
    color: #fff;
    width: 28px;
    height: 28px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.15s;
}
.block-action:hover {
    background: var(--p);
}
.block-action-danger:hover {
    background: #ef4444;
}

/* Add Block Button */
.monolithcms-add-block {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1.5rem;
    border: 2px dashed var(--bd-i);
    border-radius: var(--r-lg);
    margin: 2rem 0;
    cursor: pointer;
    transition: all 0.2s;
    color: var(--mx);
    font-weight: 500;
}
.monolithcms-add-block:hover {
    border-color: var(--p);
    color: var(--p);
    background: var(--p-r);
}

/* Block Editor Modal */
.monolithcms-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.6);
    backdrop-filter: blur(4px);
    z-index: 10001;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 2rem;
}
.monolithcms-modal {
    background: var(--sf);
    border: 1px solid var(--bd);
    border-radius: var(--r-lg);
    box-shadow: 0 25px 50px rgba(0,0,0,0.25);
    width: 100%;
    max-width: 600px;
    max-height: 80vh;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}
.monolithcms-modal-header {
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--bd);
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.monolithcms-modal-title {
    font-weight: 600;
    font-size: 18px;
    color: var(--tx);
}
.monolithcms-modal-close {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: var(--mx);
    padding: 0;
    line-height: 1;
}
.monolithcms-modal-close:hover {
    color: var(--tx);
}
.monolithcms-modal-body {
    padding: 1.5rem;
    overflow-y: auto;
    flex: 1;
}
.monolithcms-modal-footer {
    padding: 1rem 1.5rem;
    border-top: 1px solid var(--bd);
    display: flex;
    gap: 0.75rem;
    justify-content: flex-end;
}

/* Form Elements */
.editor-field {
    margin-bottom: 1.25rem;
}
.editor-label {
    display: block;
    font-weight: 500;
    font-size: 13px;
    color: var(--mx);
    margin-bottom: 0.5rem;
}
.editor-input, .editor-textarea, .editor-select {
    width: 100%;
    padding: 0.625rem 0.875rem;
    border: 1.5px solid var(--bd-i);
    border-radius: var(--r);
    font-size: 14px;
    background: var(--sf);
    color: var(--tx);
    transition: border-color 0.2s, box-shadow 0.2s;
}
.editor-input:focus, .editor-textarea:focus, .editor-select:focus {
    outline: none;
    border-color: var(--p);
    box-shadow: 0 0 0 3px var(--p-r);
}
.editor-textarea {
    min-height: 100px;
    resize: vertical;
}
.editor-color {
    width: 60px;
    height: 40px;
    padding: 2px;
    border: 1.5px solid var(--bd-i);
    border-radius: var(--r);
    cursor: pointer;
    background: var(--sf);
}

/* Buttons */
.editor-btn {
    padding: 0.625rem 1.25rem;
    border-radius: var(--r);
    font-weight: 500;
    font-size: 14px;
    cursor: pointer;
    transition: all 0.2s;
    border: none;
}
.editor-btn-primary {
    background: var(--p);
    color: #fff;
}
.editor-btn-primary:hover {
    background: var(--p-h);
}
.editor-btn-secondary {
    background: var(--bg-alt);
    color: var(--mx);
    border: 1px solid var(--bd);
}
.editor-btn-secondary:hover {
    background: var(--bg);
    color: var(--tx);
}
.editor-btn-danger {
    background: #fee2e2;
    color: #dc2626;
}
.editor-btn-danger:hover {
    background: #fecaca;
}

/* Block Type Selector */
.block-type-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 0.75rem;
}
.block-type-option {
    padding: 1rem;
    border: 2px solid var(--bd);
    border-radius: var(--r-lg);
    text-align: center;
    cursor: pointer;
    transition: all 0.2s;
    background: var(--sf);
}
.block-type-option:hover {
    border-color: var(--p);
    background: var(--p-r);
}
.block-type-option.selected {
    border-color: var(--p);
    background: var(--p-r);
}
.block-type-icon {
    font-family: 'Material Symbols Outlined';
    font-size: 28px;
    display: block;
    margin-bottom: 0.5rem;
    color: var(--p);
}
.block-type-name {
    font-weight: 500;
    font-size: 13px;
    color: var(--tx);
}

/* Toast Notifications */
.monolithcms-toast {
    position: fixed;
    bottom: 2rem;
    right: 2rem;
    background: #1e293b;
    color: #fff;
    padding: 1rem 1.5rem;
    border-radius: 12px;
    box-shadow: 0 10px 25px rgba(0,0,0,0.2);
    z-index: 10002;
    animation: slideIn 0.3s ease;
}
.monolithcms-toast.success {
    background: #059669;
}
.monolithcms-toast.error {
    background: #dc2626;
}
@keyframes slideIn {
    from { transform: translateY(20px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}

/* Section Wrappers (Header, Nav, Footer) */
.monolithcms-section-wrapper {
    position: relative;
}
.monolithcms-section-wrapper:hover {
    outline: 2px dashed var(--p);
    outline-offset: 0;
}
.section-edit-btn {
    position: absolute;
    top: 8px;
    right: 8px;
    background: #1e293b;
    color: #fff;
    border: none;
    padding: 0.4rem 0.75rem;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    z-index: 9999;
    opacity: 0;
    transition: all 0.2s;
    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
}
/* Nav section edit button needs extra offset due to navbar button */
.monolithcms-section-wrapper[data-section="nav"] .section-edit-btn {
    right: 100px;
}
.monolithcms-section-wrapper:hover .section-edit-btn {
    opacity: 1;
}
.section-edit-btn:hover {
    background: var(--p);
}

/* Dragging State */
.monolithcms-block-wrapper.dragging {
    opacity: 0.5;
    outline: 2px solid var(--p);
}
.monolithcms-block-wrapper.drag-over {
    outline: 2px solid #10b981;
    outline-offset: 8px;
}

/* Inline Editable */
[data-editable] {
    cursor: text;
    transition: background 0.2s;
}
[data-editable]:hover {
    background: var(--p-r);
}
[data-editable]:focus {
    outline: 2px solid var(--p);
    outline-offset: 2px;
}
CSS;
    }

    private static function getEditorJS(): string {
        return <<<'JS'
// MonolithCMS Visual Editor - Unified with Preview Editor
(function() {
    'use strict';

    const pageId = document.querySelector('main[data-page-id]')?.dataset.pageId;
    const csrfToken = document.getElementById('monolithcms-csrf')?.value || '';

    function getCsrfToken() { return csrfToken; }

    // Material Icons list
    const materialIcons = [
        { name: 'star', label: 'Star' },
        { name: 'check_circle', label: 'Check Circle' },
        { name: 'verified', label: 'Verified' },
        { name: 'favorite', label: 'Favorite' },
        { name: 'thumb_up', label: 'Thumb Up' },
        { name: 'lightbulb', label: 'Lightbulb' },
        { name: 'rocket_launch', label: 'Rocket' },
        { name: 'shield', label: 'Shield' },
        { name: 'security', label: 'Security' },
        { name: 'speed', label: 'Speed' },
        { name: 'trending_up', label: 'Trending Up' },
        { name: 'insights', label: 'Insights' },
        { name: 'analytics', label: 'Analytics' },
        { name: 'settings', label: 'Settings' },
        { name: 'support', label: 'Support' },
        { name: 'help', label: 'Help' },
        { name: 'info', label: 'Info' },
        { name: 'schedule', label: 'Schedule' },
        { name: 'payments', label: 'Payments' },
        { name: 'handshake', label: 'Handshake' },
        { name: 'eco', label: 'Eco' },
        { name: 'public', label: 'Globe' },
        { name: 'groups', label: 'Groups' },
        { name: 'person', label: 'Person' },
        { name: 'home', label: 'Home' },
        { name: 'bolt', label: 'Bolt' },
        { name: 'auto_awesome', label: 'Auto Awesome' },
        { name: 'workspace_premium', label: 'Premium' },
        { name: 'diamond', label: 'Diamond' },
        { name: 'smartphone', label: 'Smartphone' },
        { name: 'computer', label: 'Computer' },
        { name: 'cloud', label: 'Cloud' },
        { name: 'code', label: 'Code' },
        { name: 'extension', label: 'Extension' },
        { name: 'lock', label: 'Lock' },
        { name: 'visibility', label: 'Visibility' },
        { name: 'edit', label: 'Edit' },
        { name: 'build', label: 'Build' },
        { name: 'palette', label: 'Palette' },
        { name: 'mail', label: 'Mail' },
        { name: 'phone', label: 'Phone' },
        { name: 'location_on', label: 'Location' }
    ];

    function getIconOptions(selectedValue) {
        return materialIcons.map(icon =>
            `<option value="${icon.name}" ${selectedValue === icon.name ? 'selected' : ''}>${icon.label}</option>`
        ).join('');
    }

    function escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function toast(message, type = 'success') {
        const t = document.createElement('div');
        t.className = `monolithcms-toast ${type}`;
        t.textContent = message;
        document.body.appendChild(t);
        setTimeout(() => t.remove(), 3000);
    }

    function createModal(title, content, onSave) {
        const overlay = document.createElement('div');
        overlay.className = 'monolithcms-modal-overlay';
        overlay.innerHTML = `
            <div class="monolithcms-modal" style="max-width:700px;max-height:90vh;">
                <div class="monolithcms-modal-header">
                    <span class="monolithcms-modal-title">${title}</span>
                    <button class="monolithcms-modal-close">&times;</button>
                </div>
                <div class="monolithcms-modal-body" style="max-height:60vh;overflow-y:auto;">${content}</div>
                <div class="monolithcms-modal-footer">
                    <button class="editor-btn editor-btn-secondary cancel-btn">Cancel</button>
                    <button class="editor-btn editor-btn-primary save-btn">Save Changes</button>
                </div>
            </div>
        `;

        document.body.appendChild(overlay);

        overlay.querySelector('.monolithcms-modal-close').onclick = () => overlay.remove();
        overlay.querySelector('.cancel-btn').onclick = () => overlay.remove();
        overlay.querySelector('.save-btn').onclick = async () => {
            await onSave();
            overlay.remove();
        };
        overlay.onclick = (e) => { if (e.target === overlay) overlay.remove(); };

        // ── Focus trap: keep Tab key inside the modal ──
        overlay.addEventListener('keydown', function(e) {
            if (e.key !== 'Tab') return;
            const focusable = Array.from(overlay.querySelectorAll(
                'a[href],button:not([disabled]),input,select,textarea,[tabindex]:not([tabindex="-1"])'
            ));
            if (focusable.length === 0) return;
            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (e.shiftKey ? document.activeElement === first : document.activeElement === last) {
                e.preventDefault();
                (e.shiftKey ? last : first).focus();
            }
        });

        // ── Escape closes modal ──
        overlay.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') overlay.remove();
        });

        // Focus the first focusable element
        setTimeout(() => {
            const first = overlay.querySelector('input,textarea,select,button');
            if (first) first.focus();
        }, 50);

        // Add event delegation for actions
        overlay.addEventListener('click', function(e) {
            // Gallery "Add Image" button — must use parent frame's media picker (we're in an iframe)
            const galleryBtn = e.target.closest('.gallery-add-image-btn');
            if (galleryBtn) {
                e.preventDefault();
                e.stopPropagation();
                const ta = galleryBtn.closest('.editor-field')?.querySelector('textarea[name="images"]');
                window.parent.openMediaPicker(function(url) {
                    if (ta) ta.value = (ta.value.trim() ? ta.value.trim() + '\n' : '') + url;
                });
                return;
            }

            const target = e.target.closest('[data-action]');
            if (!target) return;
            e.preventDefault();
            e.stopPropagation();

            const action = target.dataset.action;
            switch(action) {
                case 'remove-item':
                    removeItem(parseInt(target.dataset.index));
                    break;
                case 'add-team-member':
                    addTeamMember();
                    break;
                case 'add-pricing-plan':
                    addPricingPlan();
                    break;
                case 'add-generic-item':
                    addGenericItem(target.dataset.type);
                    break;
                case 'open-gallery':
                    openGallery(target.dataset.target);
                    break;
                case 'clear-image':
                    clearImage(target.dataset.target);
                    break;
                case 'toggle-html-preview': {
                    const modal = target.closest('.monolithcms-modal-body') || document.querySelector('.monolithcms-modal');
                    const textarea = modal ? modal.querySelector('[name="html_source"]') : null;
                    const frame = modal ? modal.querySelector('#htmlPreviewFrame') : null;
                    if (!textarea || !frame) break;
                    if (frame.style.display === 'none') {
                        frame.style.display = 'block';
                        textarea.style.display = 'none';
                        frame.srcdoc = `<!DOCTYPE html><html><head><link rel="stylesheet" href="/cdn/bulma.min.css"><link rel="stylesheet" href="/cdn/material-icons.css"><link rel="stylesheet" href="/css?v=app.dev"><style>body{margin:0;overflow:auto;}</style></head><body>${textarea.value}</body></html>`;
                        target.textContent = '\u270F Edit HTML';
                    } else {
                        frame.style.display = 'none';
                        textarea.style.display = 'block';
                        target.innerHTML = '&#128065; Preview';
                    }
                    break;
                }
            }
        });

        // Icon preview update
        overlay.addEventListener('change', function(e) {
            if (e.target.matches('select[name*="[icon]"]')) {
                const preview = e.target.closest('.editor-field, .form-control, div').querySelector('.icon-preview');
                if (preview) preview.textContent = e.target.value;
            }
        });

        return overlay;
    }

    // Gallery state
    let currentGalleryTarget = null;

    function openGallery(targetName) {
        currentGalleryTarget = targetName;
        window.parent.openMediaPicker(url => {
            if (!currentGalleryTarget) return;
            const input = document.querySelector(`[name="${currentGalleryTarget}"]`);
            if (input) {
                input.value = url;
                const container = input.closest('.editor-field, .form-control, div');
                const preview = container?.querySelector('.image-preview');
                if (preview) preview.innerHTML = `<img src="${escapeHtml(url)}" style="max-height:100px;border-radius:8px;margin-top:8px;">`;
            }
            currentGalleryTarget = null;
        });
    }

    function clearImage(targetName) {
        const input = document.querySelector(`[name="${targetName}"]`);
        if (input) {
            input.value = '';
            const container = input.closest('.editor-field, .form-control, div');
            let preview = container?.querySelector('.image-preview');
            if (preview) preview.innerHTML = '';
        }
    }

    function removeItem(index) {
        document.querySelector(`[data-item-index="${index}"]`)?.remove();
        // Re-index remaining items
        document.querySelectorAll('#itemsContainer > div, #itemsContainer > .editor-item-card').forEach((el, i) => {
            el.dataset.itemIndex = i;
            el.querySelectorAll('[name*="items["]').forEach(input => {
                input.name = input.name.replace(/items\[\d+\]/, `items[${i}]`);
            });
        });
    }

    function addTeamMember() {
        const container = document.getElementById('itemsContainer');
        const i = container.children.length;
        container.insertAdjacentHTML('beforeend', `
            <div class="editor-item-card" data-item-index="${i}" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:16px;margin-bottom:16px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                    <span style="font-weight:600;">Team Member ${i+1}</span>
                    <button type="button" class="editor-btn editor-btn-danger" style="padding:4px 12px;font-size:12px;" data-action="remove-item" data-index="${i}">Remove</button>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div class="editor-field"><label class="editor-label">Name</label><input type="text" name="items[${i}][name]" class="editor-input"></div>
                    <div class="editor-field"><label class="editor-label">Role</label><input type="text" name="items[${i}][role]" class="editor-input"></div>
                </div>
                <div class="editor-field"><label class="editor-label">Bio</label><textarea name="items[${i}][bio]" class="editor-textarea" rows="2"></textarea></div>
                <div class="editor-field"><label class="editor-label">Photo</label>
                    <div style="display:flex;gap:8px;">
                        <input type="text" name="items[${i}][photo]" class="editor-input" style="flex:1;" readonly>
                        <button type="button" class="editor-btn editor-btn-primary" data-action="open-gallery" data-target="items[${i}][photo]">Browse</button>
                    </div>
                    <div class="image-preview"></div>
                </div>
            </div>
        `);
    }

    function addPricingPlan() {
        const container = document.getElementById('itemsContainer');
        const i = container.children.length;
        container.insertAdjacentHTML('beforeend', `
            <div class="editor-item-card" data-item-index="${i}" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:16px;margin-bottom:16px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                    <span style="font-weight:600;">Plan ${i+1}</span>
                    <button type="button" class="editor-btn editor-btn-danger" style="padding:4px 12px;font-size:12px;" data-action="remove-item" data-index="${i}">Remove</button>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div class="editor-field"><label class="editor-label">Name</label><input type="text" name="items[${i}][name]" class="editor-input"></div>
                    <div class="editor-field"><label class="editor-label">Price</label><input type="text" name="items[${i}][price]" class="editor-input" placeholder="$29"></div>
                </div>
                <div class="editor-field"><label class="editor-label">Period</label><input type="text" name="items[${i}][period]" class="editor-input" value="/month"></div>
                <div class="editor-field"><label class="editor-label">Features (one per line)</label><textarea name="items[${i}][features]" class="editor-textarea" rows="3"></textarea></div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div class="editor-field"><label class="editor-label">Button Text</label><input type="text" name="items[${i}][button]" class="editor-input" value="Get Started"></div>
                    <div class="editor-field"><label class="editor-label">Button URL</label><input type="text" name="items[${i}][url]" class="editor-input" value="#"></div>
                </div>
                <div class="editor-field">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                        <input type="checkbox" name="items[${i}][featured]" style="width:18px;height:18px;">
                        <span class="editor-label" style="margin:0;">Featured/Popular</span>
                    </label>
                </div>
            </div>
        `);
    }

    function addGenericItem(type) {
        const fieldMap = { features: ['icon', 'title', 'description'], stats: ['icon', 'value', 'label'], testimonials: ['name', 'role', 'quote', 'rating'], faq: ['question', 'answer'], accordion: ['title', 'content'], tabs: ['title', 'content'], checklist: ['text'], timeline: ['date', 'title', 'text'], steps: ['icon', 'title', 'description'], social: ['platform', 'url'], logo_cloud: ['name', 'url'], cards: ['image', 'icon', 'title', 'description', 'button', 'url'], carousel: ['image', 'title', 'text'] };
        const fields = fieldMap[type] || ['title', 'description'];
        const container = document.getElementById('itemsContainer');
        const i = container.children.length;

        let fieldsHtml = fields.map(f => {
            const isTextarea = ['description', 'quote', 'answer', 'content', 'text'].includes(f);
            const isIcon = f === 'icon';
            const isImage = f === 'image' || (type === 'logo_cloud' && f === 'url');

            if (isImage) {
                const label = f === 'url' ? 'Logo Image' : 'Image';
                return `<div class="editor-field"><label class="editor-label">${label}</label>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <input type="text" name="items[${i}][${f}]" class="editor-input" style="flex:1;" readonly>
                        <button type="button" class="editor-btn editor-btn-primary" data-action="open-gallery" data-target="items[${i}][${f}]">Browse</button>
                    </div>
                    <div class="image-preview"></div>
                </div>`;
            }

            if (isIcon) {
                return `<div class="editor-field"><label class="editor-label">Icon</label>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <select name="items[${i}][${f}]" class="editor-select" style="flex:1;">${getIconOptions('star')}</select>
                        <span class="icon-preview" style="font-family:'Material Symbols Outlined';font-size:24px;color:var(--p);">star</span>
                    </div>
                </div>`;
            }

            const label = f.charAt(0).toUpperCase() + f.slice(1);
            return `<div class="editor-field"><label class="editor-label">${label}</label>
                ${isTextarea ? `<textarea name="items[${i}][${f}]" class="editor-textarea" rows="2"></textarea>`
                             : `<input type="text" name="items[${i}][${f}]" class="editor-input">`}</div>`;
        }).join('');

        container.insertAdjacentHTML('beforeend', `
            <div class="editor-item-card" data-item-index="${i}" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:16px;margin-bottom:16px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                    <span style="font-weight:600;">Item ${i+1}</span>
                    <button type="button" class="editor-btn editor-btn-danger" style="padding:4px 12px;font-size:12px;" data-action="remove-item" data-index="${i}">Remove</button>
                </div>
                ${fieldsHtml}
            </div>
        `);
    }

    // Generate editor form based on block type - unified with preview editor
    function getEditorForm(type, data) {
        switch(type) {
            case 'hero':
                return `
                    <div class="editor-field"><label class="editor-label">Title</label>
                        <input type="text" name="title" class="editor-input" value="${escapeHtml(data.title || '')}"></div>
                    <div class="editor-field"><label class="editor-label">Subtitle</label>
                        <textarea name="subtitle" class="editor-textarea">${escapeHtml(data.subtitle || '')}</textarea></div>
                    <div class="editor-field"><label class="editor-label">Background Image</label>
                        <div style="display:flex;gap:8px;">
                            <input type="text" name="image" class="editor-input" style="flex:1;" value="${escapeHtml(data.image || '')}" readonly>
                            <button type="button" class="editor-btn editor-btn-primary" data-action="open-gallery" data-target="image">Browse</button>
                            ${data.image ? `<button type="button" class="editor-btn editor-btn-danger" data-action="clear-image" data-target="image">Clear</button>` : ''}
                        </div>
                        <div class="image-preview">${data.image ? `<img src="${escapeHtml(data.image)}" style="max-height:100px;border-radius:8px;margin-top:8px;">` : ''}</div>
                    </div>
                    <div class="editor-field"><label class="editor-label">Button Text</label>
                        <input type="text" name="button" class="editor-input" value="${escapeHtml(data.button || '')}"></div>
                    <div class="editor-field"><label class="editor-label">Button URL</label>
                        <input type="text" name="url" class="editor-input" value="${escapeHtml(data.url || '')}"></div>`;

            case 'cta':
                return `
                    <div class="editor-field"><label class="editor-label">Title</label>
                        <input type="text" name="title" class="editor-input" value="${escapeHtml(data.title || '')}"></div>
                    <div class="editor-field"><label class="editor-label">Text</label>
                        <textarea name="text" class="editor-textarea">${escapeHtml(data.text || '')}</textarea></div>
                    <div class="editor-field"><label class="editor-label">Button Text</label>
                        <input type="text" name="button" class="editor-input" value="${escapeHtml(data.button || 'Learn More')}"></div>
                    <div class="editor-field"><label class="editor-label">Button URL</label>
                        <input type="text" name="url" class="editor-input" value="${escapeHtml(data.url || '')}"></div>`;

            case 'text':
                return `<div class="editor-field"><label class="editor-label">Content (HTML)</label>
                    <textarea name="content" class="editor-textarea" style="height:200px;">${escapeHtml(data.content || '')}</textarea></div>`;

            case 'team':
                return getTeamEditor(data);

            case 'pricing':
                return getPricingEditor(data);

            case 'features':
            case 'stats':
            case 'testimonials':
            case 'faq':
                return getItemsEditor(type, data);

            case 'image':
                return `
                    <div class="editor-field"><label class="editor-label">Image</label>
                        <div style="display:flex;gap:8px;">
                            <input type="text" name="url" class="editor-input" style="flex:1;" value="${escapeHtml(data.url || '')}" readonly>
                            <button type="button" class="editor-btn editor-btn-primary" data-action="open-gallery" data-target="url">Browse</button>
                        </div>
                        <div class="image-preview">${data.url ? `<img src="${escapeHtml(data.url)}" style="max-height:100px;border-radius:8px;margin-top:8px;">` : ''}</div>
                    </div>
                    <div class="editor-field"><label class="editor-label">Alt Text</label>
                        <input type="text" name="alt" class="editor-input" value="${escapeHtml(data.alt || '')}"></div>
                    <div class="editor-field"><label class="editor-label">Caption</label>
                        <input type="text" name="caption" class="editor-input" value="${escapeHtml(data.caption || '')}"></div>`;

            case 'form':
                return `
                    <div class="editor-field"><label class="editor-label">Form Title</label>
                        <input type="text" name="title" class="editor-input" value="${escapeHtml(data.title || 'Contact Us')}"></div>
                    <div class="editor-field"><label class="editor-label">Button Text</label>
                        <input type="text" name="button" class="editor-input" value="${escapeHtml(data.button || 'Send Message')}"></div>
                    <p style="color:#64748b;font-size:13px;margin-top:16px;">Form fields are automatically generated (Name, Email, Message).</p>`;

            case 'quote':
                return `
                    <div class="editor-field"><label class="editor-label">Quote Text</label>
                        <textarea name="text" class="editor-textarea">${escapeHtml(data.text || '')}</textarea></div>
                    <div class="editor-field"><label class="editor-label">Author</label>
                        <input type="text" name="author" class="editor-input" value="${escapeHtml(data.author || '')}"></div>
                    <div class="editor-field"><label class="editor-label">Role/Title</label>
                        <input type="text" name="role" class="editor-input" value="${escapeHtml(data.role || '')}"></div>`;

            case 'newsletter':
                return `
                    <div class="editor-field"><label class="editor-label">Title</label>
                        <input type="text" name="title" class="editor-input" value="${escapeHtml(data.title || '')}"></div>
                    <div class="editor-field"><label class="editor-label">Text</label>
                        <textarea name="text" class="editor-textarea">${escapeHtml(data.text || '')}</textarea></div>
                    <div class="editor-field"><label class="editor-label">Button Text</label>
                        <input type="text" name="button" class="editor-input" value="${escapeHtml(data.button || 'Subscribe')}"></div>`;

            case 'cards':
            case 'carousel':
            case 'accordion':
            case 'tabs':
            case 'checklist':
            case 'timeline':
            case 'steps':
            case 'social':
            case 'logo_cloud':
                return getItemsEditor(type, data);

            case 'video':
                return `
                    <div class="editor-field"><label class="editor-label">Title</label>
                        <input type="text" name="title" class="editor-input" value="${escapeHtml(data.title || '')}"></div>
                    <div class="editor-field"><label class="editor-label">Embed URL</label>
                        <input type="text" name="url" class="editor-input" value="${escapeHtml(data.url || '')}" placeholder="https://www.youtube.com/embed/..."></div>
                    <div class="editor-field"><label class="editor-label">Caption</label>
                        <input type="text" name="caption" class="editor-input" value="${escapeHtml(data.caption || '')}"></div>`;

            case 'divider':
                return `
                    <div class="editor-field"><label class="editor-label">Style</label>
                        <select name="style" class="editor-select">
                            <option value="line" ${(data.style||'line')==='line'?'selected':''}>Line</option>
                            <option value="dots" ${data.style==='dots'?'selected':''}>Dots</option>
                            <option value="icon" ${data.style==='icon'?'selected':''}>Icon</option>
                        </select></div>
                    <div class="editor-field"><label class="editor-label">Icon (when style = icon)</label>
                        <input type="text" name="icon" class="editor-input" value="${escapeHtml(data.icon || '⭐')}" placeholder="⭐"></div>`;

            case 'gallery': {
                const imgs = Array.isArray(data.images)
                    ? data.images.map(i => (typeof i === 'object' ? (i.url || '') : i)).filter(Boolean).join('\n')
                    : (data.images || '');
                const cols = parseInt(data.columns) || 3;
                return `
                    <div class="editor-field"><label class="editor-label">Title</label>
                        <input type="text" name="title" class="editor-input" value="${escapeHtml(data.title || '')}"></div>
                    <div class="editor-field">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                            <label class="editor-label" style="margin:0">Images (one URL per line)</label>
                            <button type="button" class="gallery-add-image-btn editor-btn editor-btn-secondary" style="font-size:12px;padding:4px 10px;"><span class="material-symbols-outlined" style="font-size:14px;vertical-align:-2px">add_photo_alternate</span> Add Image</button>
                        </div>
                        <div class="gallery-images-field">
                            <textarea name="images" class="editor-textarea" rows="5" placeholder="/assets/...">${escapeHtml(imgs)}</textarea>
                        </div>
                    </div>
                    <div class="editor-field"><label class="editor-label">Columns</label>
                        <select name="columns" class="editor-select">
                            <option value="2" ${cols===2?'selected':''}>2</option>
                            <option value="3" ${cols===3?'selected':''}>3</option>
                            <option value="4" ${cols===4?'selected':''}>4</option>
                        </select></div>`;
            }

            case 'map':
                return `
                    <div class="editor-field"><label class="editor-label">Title</label>
                        <input type="text" name="title" class="editor-input" value="${escapeHtml(data.title || '')}" placeholder="Find Us"></div>
                    <div class="editor-field"><label class="editor-label">Address</label>
                        <input type="text" name="address" class="editor-input" value="${escapeHtml(data.address || '')}" placeholder="123 Main St, City, Country"></div>
                    <div class="editor-field"><label class="editor-label">Google Maps Embed URL</label>
                        <input type="text" name="embed" class="editor-input" value="${escapeHtml(data.embed || '')}" placeholder="https://maps.google.com/maps?q=...&output=embed">
                        <div style="color:#94a3b8;font-size:11px;margin-top:4px;">Go to Google Maps → Share → Embed a map → copy the <em>src</em> URL</div></div>`;

            default:
                if (data.html !== undefined) return getHtmlBlockEditor(data);
                return `<div style="color:#64748b;margin-bottom:16px;">Editor for ${type} blocks - edit raw JSON:</div>
                    <textarea name="raw_json" class="editor-textarea" style="height:200px;font-family:monospace;font-size:12px;">${escapeHtml(JSON.stringify(data, null, 2))}</textarea>`;
        }
    }

    function getHtmlBlockEditor(data) {
        const html = data.html || '';
        const anchor = data.anchor || '';
        return `
            <div class="editor-field" style="margin-bottom:12px;">
                <label class="editor-label">Section Anchor ID <span style="color:#94a3b8;font-weight:400;font-size:11px;">(for nav links like /#features)</span></label>
                <input type="text" name="anchor" class="editor-input" placeholder="e.g. features" value="${escapeHtml(anchor)}">
            </div>
            <div style="display:flex;align-items:center;margin-bottom:8px;">
                <label class="editor-label" style="margin:0;">HTML Source</label>
                <button type="button" data-action="toggle-html-preview" style="margin-left:auto;padding:4px 14px;font-size:12px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:6px;cursor:pointer;font-weight:500;">&#128065; Preview</button>
            </div>
            <textarea name="html_source" class="editor-textarea" style="height:360px;font-family:Consolas,'Fira Mono',monospace;font-size:12px;line-height:1.5;tab-size:2;white-space:pre;resize:vertical;">${escapeHtml(html)}</textarea>
            <iframe id="htmlPreviewFrame" style="display:none;width:100%;height:360px;border:1px solid #e2e8f0;border-radius:8px;background:#fff;"></iframe>`;
    }

    function getTeamEditor(data) {
        const items = data.items || data.members || [];
        const itemsHtml = items.map((item, i) => `
            <div class="editor-item-card" data-item-index="${i}" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:16px;margin-bottom:16px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                    <span style="font-weight:600;">Team Member ${i+1}</span>
                    <button type="button" class="editor-btn editor-btn-danger" style="padding:4px 12px;font-size:12px;" data-action="remove-item" data-index="${i}">Remove</button>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div class="editor-field"><label class="editor-label">Name</label><input type="text" name="items[${i}][name]" class="editor-input" value="${escapeHtml(item.name || '')}"></div>
                    <div class="editor-field"><label class="editor-label">Role</label><input type="text" name="items[${i}][role]" class="editor-input" value="${escapeHtml(item.role || '')}"></div>
                </div>
                <div class="editor-field"><label class="editor-label">Bio</label><textarea name="items[${i}][bio]" class="editor-textarea" rows="2">${escapeHtml(item.bio || '')}</textarea></div>
                <div class="editor-field"><label class="editor-label">Photo</label>
                    <div style="display:flex;gap:8px;">
                        <input type="text" name="items[${i}][photo]" class="editor-input" style="flex:1;" value="${escapeHtml(item.photo || '')}" readonly>
                        <button type="button" class="editor-btn editor-btn-primary" data-action="open-gallery" data-target="items[${i}][photo]">Browse</button>
                        ${item.photo ? `<button type="button" class="editor-btn editor-btn-danger" data-action="clear-image" data-target="items[${i}][photo]">Clear</button>` : ''}
                    </div>
                    <div class="image-preview">${item.photo ? `<img src="${escapeHtml(item.photo)}" style="max-height:60px;border-radius:8px;margin-top:8px;">` : ''}</div>
                </div>
            </div>
        `).join('');

        return `
            <div class="editor-field"><label class="editor-label">Section Title</label>
                <input type="text" name="title" class="editor-input" value="${escapeHtml(data.title || '')}"></div>
            <h4 style="font-weight:600;margin:20px 0 12px;padding-bottom:8px;border-bottom:1px solid #e2e8f0;">Team Members</h4>
            <div id="itemsContainer">${itemsHtml}</div>
            <button type="button" class="editor-btn editor-btn-secondary" data-action="add-team-member">+ Add Team Member</button>`;
    }

    function getPricingEditor(data) {
        const items = data.plans || data.items || [];
        const itemsHtml = items.map((item, i) => `
            <div class="editor-item-card" data-item-index="${i}" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:16px;margin-bottom:16px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                    <span style="font-weight:600;">Plan ${i+1}</span>
                    <button type="button" class="editor-btn editor-btn-danger" style="padding:4px 12px;font-size:12px;" data-action="remove-item" data-index="${i}">Remove</button>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div class="editor-field"><label class="editor-label">Name</label><input type="text" name="items[${i}][name]" class="editor-input" value="${escapeHtml(item.name || '')}"></div>
                    <div class="editor-field"><label class="editor-label">Price</label><input type="text" name="items[${i}][price]" class="editor-input" value="${escapeHtml(item.price || '')}"></div>
                </div>
                <div class="editor-field"><label class="editor-label">Period</label><input type="text" name="items[${i}][period]" class="editor-input" value="${escapeHtml(item.period || '/month')}"></div>
                <div class="editor-field"><label class="editor-label">Features (one per line)</label><textarea name="items[${i}][features]" class="editor-textarea" rows="3">${(item.features || []).join('\n')}</textarea></div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div class="editor-field"><label class="editor-label">Button Text</label><input type="text" name="items[${i}][button]" class="editor-input" value="${escapeHtml(item.button || 'Get Started')}"></div>
                    <div class="editor-field"><label class="editor-label">Button URL</label><input type="text" name="items[${i}][url]" class="editor-input" value="${escapeHtml(item.url || '#')}"></div>
                </div>
                <div class="editor-field">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                        <input type="checkbox" name="items[${i}][featured]" style="width:18px;height:18px;" ${item.featured ? 'checked' : ''}>
                        <span class="editor-label" style="margin:0;">Featured/Popular</span>
                    </label>
                </div>
            </div>
        `).join('');

        return `
            <div class="editor-field"><label class="editor-label">Section Title</label>
                <input type="text" name="title" class="editor-input" value="${escapeHtml(data.title || '')}"></div>
            <div class="editor-field"><label class="editor-label">Subtitle</label>
                <input type="text" name="subtitle" class="editor-input" value="${escapeHtml(data.subtitle || '')}"></div>
            <h4 style="font-weight:600;margin:20px 0 12px;padding-bottom:8px;border-bottom:1px solid #e2e8f0;">Plans</h4>
            <div id="itemsContainer">${itemsHtml}</div>
            <button type="button" class="editor-btn editor-btn-secondary" data-action="add-pricing-plan">+ Add Plan</button>`;
    }

    function getItemsEditor(type, data) {
        const items = data.items || [];
        const fieldMap = {
            features:     ['icon', 'title', 'description'],
            stats:        ['icon', 'value', 'label'],
            testimonials: ['name', 'role', 'quote', 'rating'],
            faq:          ['question', 'answer'],
            accordion:    ['title', 'content'],
            tabs:         ['title', 'content'],
            checklist:    ['text'],
            timeline:     ['date', 'title', 'text'],
            steps:        ['icon', 'title', 'description'],
            social:       ['platform', 'url'],
            logo_cloud:   ['name', 'url'],
            cards:        ['image', 'icon', 'title', 'description', 'button', 'url'],
            carousel:     ['image', 'title', 'text'],
        };
        const fields = fieldMap[type] || ['title', 'description'];

        const itemsHtml = items.map((item, i) => {
            const fieldsHtml = fields.map(f => {
                const isTextarea = ['description', 'quote', 'answer', 'content', 'text'].includes(f);
                const isIcon = f === 'icon';
                const isImage = f === 'image' || (type === 'logo_cloud' && f === 'url');
                const val = escapeHtml(item[f] || '');

                if (isImage) {
                    const label = f === 'url' ? 'Logo Image' : 'Image';
                    return `<div class="editor-field"><label class="editor-label">${label}</label>
                        <div style="display:flex;gap:8px;align-items:center;">
                            <input type="text" name="items[${i}][${f}]" class="editor-input" style="flex:1;" value="${val}" readonly>
                            <button type="button" class="editor-btn editor-btn-primary" data-action="open-gallery" data-target="items[${i}][${f}]">Browse</button>
                        </div>
                        <div class="image-preview">${val ? `<img src="${val}" style="max-height:80px;border-radius:6px;margin-top:6px;">` : ''}</div>
                    </div>`;
                }

                if (isIcon) {
                    return `<div class="editor-field"><label class="editor-label">Icon</label>
                        <div style="display:flex;gap:8px;align-items:center;">
                            <select name="items[${i}][${f}]" class="editor-select" style="flex:1;">${getIconOptions(item[f] || 'star')}</select>
                            <span class="icon-preview" style="font-family:'Material Symbols Outlined';font-size:24px;color:var(--p);">${item[f] || 'star'}</span>
                        </div>
                    </div>`;
                }

                const label = f.charAt(0).toUpperCase() + f.slice(1);
                return `<div class="editor-field"><label class="editor-label">${label}</label>
                    ${isTextarea ? `<textarea name="items[${i}][${f}]" class="editor-textarea" rows="2">${val}</textarea>`
                                 : `<input type="text" name="items[${i}][${f}]" class="editor-input" value="${val}">`}</div>`;
            }).join('');

            return `<div class="editor-item-card" data-item-index="${i}" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:16px;margin-bottom:16px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                    <span style="font-weight:600;">Item ${i+1}</span>
                    <button type="button" class="editor-btn editor-btn-danger" style="padding:4px 12px;font-size:12px;" data-action="remove-item" data-index="${i}">Remove</button>
                </div>
                ${fieldsHtml}
            </div>`;
        }).join('');

        return `
            <div class="editor-field"><label class="editor-label">Section Title</label>
                <input type="text" name="title" class="editor-input" value="${escapeHtml(data.title || '')}"></div>
            ${data.subtitle !== undefined ? `<div class="editor-field"><label class="editor-label">Subtitle</label>
                <input type="text" name="subtitle" class="editor-input" value="${escapeHtml(data.subtitle || '')}"></div>` : ''}
            <h4 style="font-weight:600;margin:20px 0 12px;padding-bottom:8px;border-bottom:1px solid #e2e8f0;">Items</h4>
            <div id="itemsContainer">${itemsHtml}</div>
            <button type="button" class="editor-btn editor-btn-secondary" data-action="add-generic-item" data-type="${type}">+ Add Item</button>`;
    }

    // Collect form data from modal
    function collectFormData(modal, blockType) {
        const data = {};

        // Simple fields (exclude raw_json and html_source — handled separately below)
        modal.querySelectorAll('input:not([type="checkbox"]):not([name*="["]), textarea:not([name*="["]), select:not([name*="["])').forEach(el => {
            if (el.name && el.name !== 'raw_json' && el.name !== 'html_source' && el.name !== 'anchor') {
                data[el.name] = el.value;
            }
        });

        // HTML block editor — return {html, anchor} without JSON wrapping
        const htmlSource = modal.querySelector('[name="html_source"]');
        if (htmlSource) {
            const anchor = (modal.querySelector('[name="anchor"]')?.value || '').trim();
            const result = { html: htmlSource.value };
            if (anchor) result.anchor = anchor;
            return result;
        }

        // Raw JSON fallback
        const rawJson = modal.querySelector('[name="raw_json"]');
        if (rawJson) {
            try {
                return JSON.parse(rawJson.value);
            } catch(e) {
                return data;
            }
        }

        // Items arrays (for features, stats, team, pricing, etc.)
        const itemsContainer = modal.querySelector('#itemsContainer');
        if (itemsContainer && itemsContainer.children.length > 0) {
            const items = [];
            itemsContainer.querySelectorAll('[data-item-index]').forEach(itemEl => {
                const item = {};
                itemEl.querySelectorAll('input, textarea, select').forEach(field => {
                    const match = field.name.match(/items\[\d+\]\[(\w+)\]/);
                    if (match) {
                        const key = match[1];
                        if (field.type === 'checkbox') {
                            item[key] = field.checked;
                        } else if (key === 'features') {
                            // Split by newlines for pricing features
                            item[key] = field.value.split('\n').map(s => s.trim()).filter(s => s);
                        } else if (key === 'rating') {
                            item[key] = parseInt(field.value) || 5;
                        } else {
                            item[key] = field.value;
                        }
                    }
                });
                items.push(item);
            });

            // Use 'plans' key for pricing, 'members' for team, 'items' for others
            if (blockType === 'pricing') {
                data.plans = items;
            } else if (blockType === 'team') {
                data.members = items;
            } else {
                data.items = items;
            }
        }

        return data;
    }

    // Edit block Swap rendered block content in-place without reloading the page
    function swapBlockContent(blockId, renderedHtml, newData) {
        const w = document.querySelector(`[data-block-id="${blockId}"]`);
        if (!w) return;
        Array.from(w.childNodes).forEach(node => {
            if (!node.classList?.contains('monolithcms-block-toolbar') && !node.classList?.contains('block-data')) {
                node.remove();
            }
        });
        const toolbar = w.querySelector('.monolithcms-block-toolbar');
        const tmp = document.createElement('div');
        tmp.innerHTML = renderedHtml;
        // IntersectionObserver only runs once on page load — mark .reveal elements visible immediately so swapped-in content isn't stuck at opacity:0
        tmp.querySelectorAll('.reveal').forEach(el => el.classList.add('visible'));
        Array.from(tmp.childNodes).reverse().forEach(n => toolbar.after(n));
        const bds = w.querySelector('.block-data');
        if (bds) bds.textContent = JSON.stringify(newData);
    }

    // Lazy-load GrapesJS CSS + JS from local CDN cache
    function loadGrapesJS(callback) {
        if (window.grapesjs) { callback(); return; }
        if (!document.querySelector('link[href="/cdn/grapes.min.css"]')) {
            const link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = '/cdn/grapes.min.css';
            document.head.appendChild(link);
        }
        const script = document.createElement('script');
        script.src = '/cdn/grapes.min.js';
        script.onload = callback;
        script.onerror = () => toast('Failed to load GrapesJS', 'error');
        document.head.appendChild(script);
    }

    // Full-screen GrapesJS visual editor for raw-HTML blocks
    function openGrapesEditor(wrapper, blockId, currentData) {
        loadGrapesJS(() => {
            const anchor = currentData.anchor || '';

            // Preserve original IDs so we can strip only GrapesJS ephemeral ones on save.
            const originalIds = new Set();
            const tmpDiv = document.createElement('div');
            tmpDiv.innerHTML = currentData.html || '';
            tmpDiv.querySelectorAll('[id]').forEach(el => originalIds.add(el.id));

            // ── Overlay ──────────────────────────────────────────────────────────
            const overlay = document.createElement('div');
            overlay.id = 'grapes-overlay';
            overlay.style.cssText = 'position:fixed;inset:0;z-index:99999;display:flex;flex-direction:column;background:#0f172a;font-family:system-ui,sans-serif;';
            overlay.innerHTML = `
                <!-- Top toolbar -->
                <div style="display:flex;align-items:center;gap:8px;padding:0 12px;height:44px;background:#1e293b;border-bottom:1px solid #334155;flex-shrink:0;">
                    <span style="font-weight:700;color:#60a5fa;font-size:14px;white-space:nowrap;">&#9998; Visual Editor</span>
                    <span style="color:#475569;font-size:11px;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;">${escapeHtml(wrapper.dataset.blockType||'')}</span>
                    <!-- Device buttons -->
                    <div id="gjsDevs" style="display:flex;gap:3px;margin:0 6px;">
                        <button data-dev="" title="Full Width"
                            style="padding:3px 10px;border-radius:4px;background:#2563eb;color:#fff;border:1px solid #2563eb;cursor:pointer;font-size:11px;font-weight:600;">Full</button>
                        <button data-dev="Desktop" title="Desktop"
                            style="padding:3px 9px;border-radius:4px;background:transparent;color:#94a3b8;border:1px solid #334155;cursor:pointer;font-size:11px;">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>
                        </button>
                        <button data-dev="Tablet" title="Tablet"
                            style="padding:3px 9px;border-radius:4px;background:transparent;color:#94a3b8;border:1px solid #334155;cursor:pointer;font-size:11px;">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="2" width="16" height="20" rx="2"/><circle cx="12" cy="18" r="1" fill="currentColor"/></svg>
                        </button>
                        <button data-dev="Mobile" title="Mobile"
                            style="padding:3px 9px;border-radius:4px;background:transparent;color:#94a3b8;border:1px solid #334155;cursor:pointer;font-size:11px;">
                            <svg width="11" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2"/><circle cx="12" cy="18" r="1" fill="currentColor"/></svg>
                        </button>
                    </div>
                    <!-- Anchor + actions -->
                    <div style="margin-left:auto;display:flex;align-items:center;gap:6px;">
                        <label style="font-size:12px;color:#94a3b8;white-space:nowrap;">Anchor:</label>
                        <input id="gjsAnchor" type="text" placeholder="e.g. features" value="${escapeHtml(anchor)}"
                            style="padding:3px 8px;border-radius:4px;border:1px solid #475569;background:#334155;color:#e2e8f0;font-size:12px;width:100px;">
                        <button id="gjsCancel"
                            style="padding:4px 12px;border-radius:5px;background:#475569;color:#e2e8f0;border:none;cursor:pointer;font-size:13px;">Cancel</button>
                        <button id="gjsSave"
                            style="padding:4px 14px;border-radius:5px;background:#2563eb;color:#fff;border:none;cursor:pointer;font-size:13px;font-weight:600;">Save</button>
                    </div>
                </div>
                <!-- Editor shell: left blocks | canvas | right panels -->
                <div style="display:flex;flex:1;min-height:0;overflow:hidden;">
                    <!-- Left: Block manager -->
                    <div style="width:200px;flex-shrink:0;background:#1e293b;border-right:1px solid #334155;display:flex;flex-direction:column;overflow:hidden;">
                        <div style="padding:7px 12px;font-size:10px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.8px;border-bottom:1px solid #334155;flex-shrink:0;">Blocks</div>
                        <div id="gjsBlocks" style="flex:1;overflow-y:auto;"></div>
                    </div>
                    <!-- Canvas -->
                    <div id="gjs" style="flex:1;min-width:0;min-height:0;overflow:hidden;"></div>
                    <!-- Right: Style / Settings / Layers -->
                    <div style="width:240px;flex-shrink:0;background:#1e293b;border-left:1px solid #334155;display:flex;flex-direction:column;overflow:hidden;">
                        <div style="display:flex;border-bottom:1px solid #334155;flex-shrink:0;">
                            <button class="gjsrtab" data-panel="gjsStyles"
                                style="flex:1;padding:7px 4px;font-size:11px;font-weight:600;border:none;cursor:pointer;background:#2563eb;color:#fff;border-bottom:2px solid #2563eb;">Style</button>
                            <button class="gjsrtab" data-panel="gjsSettings"
                                style="flex:1;padding:7px 4px;font-size:11px;font-weight:600;border:none;cursor:pointer;background:transparent;color:#64748b;border-bottom:2px solid transparent;">Settings</button>
                            <button class="gjsrtab" data-panel="gjsLayers"
                                style="flex:1;padding:7px 4px;font-size:11px;font-weight:600;border:none;cursor:pointer;background:transparent;color:#64748b;border-bottom:2px solid transparent;">Layers</button>
                        </div>
                        <div id="gjsStyles"   style="flex:1;overflow-y:auto;"></div>
                        <div id="gjsSettings" style="flex:1;overflow-y:auto;display:none;"></div>
                        <div id="gjsLayers"   style="flex:1;overflow-y:auto;display:none;"></div>
                    </div>
                </div>`;

            document.body.appendChild(overlay);

            // Hide GrapesJS's own chrome — we provide our own panels and device bar.
            const gjsStyle = document.createElement('style');
            gjsStyle.textContent =
                // ── Hide GrapesJS chrome elements ──────────────────────────────────
                '.gjs-editor-sp,.gjs-pn-panels,.gjs-canvas-top,.gjs-frame-wrapper__top{display:none!important;height:0!important;width:0!important;overflow:hidden!important}' +

                // ── Canvas: fill container, no offsets left by GrapesJS panel placeholders
                '#gjs .gjs-editor{padding:0!important;width:100%!important;height:100%!important;overflow:hidden!important}' +
                '#gjs .gjs-cv-canvas{top:0!important;left:0!important;right:0!important;bottom:0!important;width:100%!important;height:100%!important;overflow:hidden!important}' +
                '#gjs .gjs-frame-wrapper{width:100%!important;height:100%!important;overflow:hidden!important}' +

                // ── Block manager ───────────────────────────────────────────────────
                '#gjsBlocks{background:#0f172a!important;color:#cbd5e1!important}' +
                '#gjsBlocks .gjs-block-categories{background:#0f172a!important}' +
                '#gjsBlocks .gjs-block-category{background:#0f172a!important;border-color:#334155!important}' +
                // category title — all known class name variants
                '#gjsBlocks .gjs-block-category__title,' +
                '#gjsBlocks .gjs-block-category-title,' +
                '#gjsBlocks .gjs-title,' +
                '#gjsBlocks [class*="block-category"][class*="title"]' +
                '{background:#1e293b!important;color:#64748b!important;padding:6px 8px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid #334155}' +
                '#gjsBlocks .gjs-block{background:#0f172a!important;border-color:#334155!important;color:#cbd5e1!important}' +
                '#gjsBlocks .gjs-block:hover{border-color:#2563eb!important;background:#1e293b!important}' +
                '#gjsBlocks .gjs-block__label{color:#cbd5e1!important;font-size:11px}' +
                '#gjsBlocks .gjs-block__media{color:#94a3b8!important}' +

                // ── Style manager: base (catches GrapesJS default gray wrapper) ────
                '#gjsStyles{background:#0f172a!important;color:#cbd5e1!important}' +
                '#gjsStyles .gjs-sm-sector{border-bottom:1px solid #334155;background:#0f172a}' +
                // sector title — all known class name variants across GrapesJS versions
                '#gjsStyles .gjs-sm-sector__title,' +
                '#gjsStyles .gjs-sm-sector-title,' +
                '#gjsStyles .gjs-sm-title,' +
                '#gjsStyles [class*="sm-sector"][class*="title"]' +
                '{background:#1e293b!important;color:#94a3b8!important;padding:6px 10px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;cursor:pointer}' +
                '#gjsStyles .gjs-sm-sector__title:hover,' +
                '#gjsStyles .gjs-sm-sector-title:hover,' +
                '#gjsStyles .gjs-sm-title:hover{color:#cbd5e1!important}' +
                '#gjsStyles .gjs-sm-properties{padding:8px;background:#0f172a!important}' +
                '#gjsStyles .gjs-sm-property{margin-bottom:6px;background:#0f172a}' +
                '#gjsStyles .gjs-sm-label{color:#94a3b8!important;font-size:11px;margin-bottom:2px}' +
                '#gjsStyles .gjs-field{background:#0f172a!important;border:1px solid #334155!important;border-radius:4px;color:#cbd5e1!important}' +
                '#gjsStyles .gjs-field input,#gjsStyles .gjs-field select{background:transparent!important;color:#cbd5e1!important;border:none!important;font-size:12px}' +
                '#gjsStyles .gjs-field:focus-within{border-color:#2563eb!important}' +
                '#gjsStyles .gjs-field-units{background:#1e293b!important;border-left:1px solid #334155!important;color:#94a3b8}' +
                '#gjsStyles .gjs-field-units select{background:#1e293b!important;color:#94a3b8!important}' +
                '#gjsStyles .gjs-radio-items{background:#0f172a!important;border:1px solid #334155;border-radius:4px;overflow:hidden}' +
                '#gjsStyles .gjs-radio-item{color:#94a3b8;border-right:1px solid #334155;font-size:11px}' +
                '#gjsStyles .gjs-radio-item:hover{background:#1e293b;color:#cbd5e1}' +
                '#gjsStyles .gjs-radio-item.gjs-radio-item--active{background:#2563eb!important;color:#fff!important;border-color:#2563eb!important}' +
                '#gjsStyles .gjs-field-colorp{border:1px solid #334155;border-radius:3px}' +
                '#gjsStyles .gjs-sm-btn{background:#1e293b;color:#94a3b8;border:1px solid #334155;border-radius:4px;font-size:11px}' +
                '#gjsStyles .gjs-sm-btn:hover{background:#334155;color:#cbd5e1}' +
                '#gjsStyles .gjs-clm-tags,.gjs-selector-tags{background:#0f172a!important;border:1px solid #334155!important;border-radius:4px}' +
                '#gjsStyles .gjs-selector-name{background:#1e293b;color:#93c5fd;border-radius:3px;font-size:11px}' +
                '#gjsStyles .gjs-clm-add-input{background:#0f172a!important;color:#cbd5e1!important;border:none}' +
                '#gjsStyles .gjs-add-trasp{color:#64748b}' +
                '#gjsStyles .gjs-add-trasp:hover{color:#2563eb}' +

                // ── Trait manager ───────────────────────────────────────────────────
                '#gjsSettings{background:#0f172a!important;color:#cbd5e1!important}' +
                '#gjsSettings .gjs-trt-header{background:#1e293b;color:#94a3b8;padding:6px 10px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em}' +
                '#gjsSettings .gjs-trt-trait{padding:6px 8px;border-bottom:1px solid #1e293b;background:#0f172a}' +
                '#gjsSettings .gjs-trt-trait__label{color:#94a3b8;font-size:11px}' +
                '#gjsSettings .gjs-trt-trait__wrp-field .gjs-field{background:#0f172a!important;border:1px solid #334155!important;border-radius:4px}' +
                '#gjsSettings .gjs-trt-trait__wrp-field input,#gjsSettings .gjs-trt-trait__wrp-field select{background:transparent!important;color:#cbd5e1!important;font-size:12px}' +
                '#gjsSettings .gjs-trt-trait__wrp-field .gjs-field:focus-within{border-color:#2563eb!important}' +
                '#gjsSettings .gjs-sm-sector__title,#gjsSettings .gjs-sm-sector-title,#gjsSettings .gjs-sm-title{background:#1e293b!important;color:#94a3b8!important;padding:6px 10px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em}' +

                // ── Layer manager (real v0.22 class names from DOM) ─────────────────
                '#gjsLayers{background:#0f172a!important}' +
                '#gjsLayers .gjs-one-bg{background:#0f172a!important}' +
                '#gjsLayers .gjs-layer-item{background:#0f172a!important;border-bottom:1px solid #1e293b!important}' +
                '#gjsLayers .gjs-layer-item:hover{background:#1e293b!important}' +
                '#gjsLayers .gjs-layer-item.gjs-selected,#gjsLayers .gjs-layer-item--active{background:#1e3a8a!important}' +
                '#gjsLayers .gjs-layer-name{color:#cbd5e1!important;font-size:12px!important}' +
                '#gjsLayers .gjs-layer-caret{color:#64748b!important}' +
                '#gjsLayers .gjs-layer-vis{color:#475569!important}' +
                '#gjsLayers .gjs-layer-vis:hover{color:#94a3b8!important}' +
                '#gjsLayers .gjs-layer-count{color:#475569!important;font-size:10px!important}' +
                '#gjsLayers .gjs-layer-move{color:#475569!important}' +
                '#gjsLayers .gjs-layer-move:hover{color:#94a3b8!important}' +

                // ── gjs-one-bg: GrapesJS stamps this on all panel backgrounds ────────
                '#gjsBlocks .gjs-one-bg{background:#0f172a!important}' +
                '#gjsStyles .gjs-one-bg{background:#0f172a!important}' +
                '#gjsSettings .gjs-one-bg{background:#0f172a!important}' +

                // ── Canvas element toolbar ──────────────────────────────────────────
                '.gjs-toolbar{background:#1e293b;border:1px solid #334155;border-radius:4px;box-shadow:0 2px 8px rgba(0,0,0,.5)}' +
                '.gjs-toolbar-item{color:#94a3b8}' +
                '.gjs-toolbar-item:hover{color:#fff;background:#2563eb}' +
                '.gjs-badge{background:#2563eb;color:#fff;font-size:10px;border-radius:3px}' +
                '.gjs-selected{outline:2px solid #2563eb!important}' +
                '.gjs-hovered{outline:1px solid #93c5fd!important}' +

                // ── Resizer handles: dark theme so right-edge handle blends in ───────
                '.gjs-resizer-h{background:#334155!important;border:1px solid #475569!important;' +
                'width:8px!important;height:8px!important;border-radius:2px!important}' +
                '.gjs-resizer-h:hover{background:#2563eb!important;border-color:#2563eb!important}' +

                // ── Dark scrollbars for all panel containers ─────────────────────────
                '#gjsBlocks::-webkit-scrollbar,#gjsStyles::-webkit-scrollbar,' +
                '#gjsSettings::-webkit-scrollbar,#gjsLayers::-webkit-scrollbar{width:4px}' +
                '#gjsBlocks::-webkit-scrollbar-track,#gjsStyles::-webkit-scrollbar-track,' +
                '#gjsSettings::-webkit-scrollbar-track,#gjsLayers::-webkit-scrollbar-track{background:#0f172a}' +
                '#gjsBlocks::-webkit-scrollbar-thumb,#gjsStyles::-webkit-scrollbar-thumb,' +
                '#gjsSettings::-webkit-scrollbar-thumb,#gjsLayers::-webkit-scrollbar-thumb{background:#334155;border-radius:2px}' +
                '#gjsBlocks::-webkit-scrollbar-thumb:hover,#gjsStyles::-webkit-scrollbar-thumb:hover,' +
                '#gjsSettings::-webkit-scrollbar-thumb:hover,#gjsLayers::-webkit-scrollbar-thumb:hover{background:#475569}';
            document.head.appendChild(gjsStyle);

            // ── GrapesJS init ─────────────────────────────────────────────────
            const editor = grapesjs.init({
                container: '#gjs',
                fromElement: false,
                height: '100%',
                storageManager: false,
                telemetry: false,
                forceClass: false,
                avoidInlineStyle: false,
                panels: { defaults: [] },
                assetManager: {
                    upload: '/admin/media/upload',
                    uploadName: 'file',
                    params: { _csrf: (document.querySelector('meta[name="csrf-token"]') || {}).content || '' },
                    assets: [],
                    autoAdd: true,
                    uploadText: 'Drop files here or click to upload',
                    addBtnText: 'Add image',
                    // Map server response to GrapesJS asset format
                    handleAdd(textFromServer) {
                        try {
                            const data = JSON.parse(textFromServer);
                            if (data.success && data.url) {
                                editor.AssetManager.add({ src: data.url, name: data.url.split('/').pop() });
                            }
                        } catch(e) {}
                    }
                },
                deviceManager: {
                    devices: [
                        { name: 'Full Width', width: '' },
                        { name: 'Desktop',    width: '1280px' },
                        { name: 'Tablet',     width: '768px' },
                        { name: 'Mobile',     width: '390px', widthMedia: '480px' }
                    ]
                },
                canvas: {
                    styles: ['/cdn/bulma.min.css', '/cdn/material-icons.css', '/css?v=app.dev'],
                    scripts: []
                },
                // Block manager — mounted in our left panel
                blockManager: {
                    appendTo: '#gjsBlocks',
                    blocks: [
                        { id: 'heading',   label: 'Heading',   category: 'Basic',  content: '<h2 class="title is-2">Heading</h2>' },
                        { id: 'link',      label: 'Link',      category: 'Basic',  content: '<a href="#" class="has-text-primary">Link text</a>' },
                        { id: 'button',    label: 'Button',    category: 'Basic',  content: '<a href="#" class="button is-primary">Button</a>' },
                        { id: 'container', label: 'Container', category: 'Layout', content: '<div class="container"><p>Container content</p></div>' },
                        { id: '2cols',     label: '2 Columns', category: 'Layout', content: '<div class="columns"><div class="column"><p>Column 1</p></div><div class="column"><p>Column 2</p></div></div>' },
                        { id: '3cols',     label: '3 Columns', category: 'Layout', content: '<div class="columns"><div class="column"><p>Col 1</p></div><div class="column"><p>Col 2</p></div><div class="column"><p>Col 3</p></div></div>' },
                        { id: '4cols',     label: '4 Columns', category: 'Layout', content: '<div class="columns"><div class="column"><p>Col 1</p></div><div class="column"><p>Col 2</p></div><div class="column"><p>Col 3</p></div><div class="column"><p>Col 4</p></div></div>' },

                        { id: 'cms-text',    label: 'Text',    category: 'CMS Sections', content: '<section class="section reveal"><div class="container"><div class="content is-medium"><p>Add your content here. You can write paragraphs, add <strong>bold text</strong>, <em>italic text</em>, and more.</p></div></div></section>' },
                        { id: 'cms-image',   label: 'Image',   category: 'CMS Sections', content: '<section class="section reveal"><div class="container"><figure class="image"><img src="https://picsum.photos/900/400" alt="Image" style="border-radius:.75rem;box-shadow:0 4px 24px rgba(0,0,0,.12);max-width:900px;margin:0 auto;display:block"></figure></div></section>' },
                        { id: 'cms-divider', label: 'Divider', category: 'CMS Sections', content: '<hr style="margin:2rem 0">' },
                        { id: 'cms-spacer',  label: 'Spacer',  category: 'CMS Sections', content: '<div style="height:4rem"></div>' },
                        { id: 'cms-hero',         label: 'Hero',         category: 'CMS Sections', content: '<section class="hero is-medium reveal"><div class="hero-body"><div class="container has-text-centered"><h1 class="title is-1 animate-fade-in">Your Compelling Headline</h1><p class="subtitle is-4 animate-slide-up" style="opacity:.9">A clear value proposition that makes visitors want to learn more.</p><a href="#" class="button is-primary is-large mt-5">Get Started</a></div></div></section>' },
                        { id: 'cms-features',     label: 'Features',     category: 'CMS Sections', content: '<section class="section reveal"><div class="container"><h2 class="title is-2 has-text-centered mb-2">Our Features</h2><p class="subtitle is-5 has-text-centered has-text-grey mb-6">Everything you need to succeed</p><div class="columns is-multiline"><div class="column is-one-third-desktop is-half-tablet"><div class="card h-100"><div class="card-content has-text-centered"><div class="mb-4 is-size-3 has-text-primary">⭐</div><p class="title is-5">Feature One</p><p class="has-text-grey">Description of this amazing feature.</p></div></div></div><div class="column is-one-third-desktop is-half-tablet"><div class="card h-100"><div class="card-content has-text-centered"><div class="mb-4 is-size-3 has-text-primary">🚀</div><p class="title is-5">Feature Two</p><p class="has-text-grey">Another great feature description.</p></div></div></div><div class="column is-one-third-desktop is-half-tablet"><div class="card h-100"><div class="card-content has-text-centered"><div class="mb-4 is-size-3 has-text-primary">💡</div><p class="title is-5">Feature Three</p><p class="has-text-grey">Yet another feature description.</p></div></div></div></div></div></section>' },
                        { id: 'cms-stats',        label: 'Stats',        category: 'CMS Sections', content: '<section class="section reveal" style="background:#f9fafb"><div class="container"><h2 class="title is-2 has-text-centered mb-6">By the Numbers</h2><div class="columns is-multiline"><div class="column has-text-centered"><p class="title is-1 has-text-primary">100+</p><p class="has-text-grey">Happy Clients</p></div><div class="column has-text-centered"><p class="title is-1 has-text-primary">50K</p><p class="has-text-grey">Users</p></div><div class="column has-text-centered"><p class="title is-1 has-text-primary">99%</p><p class="has-text-grey">Satisfaction</p></div><div class="column has-text-centered"><p class="title is-1 has-text-primary">24/7</p><p class="has-text-grey">Support</p></div></div></div></section>' },
                        { id: 'cms-cta',          label: 'CTA',          category: 'CMS Sections', content: '<section class="section reveal" style="background-color:var(--color-primary)"><div class="container has-text-centered"><h2 class="title has-text-white">Ready to get started?</h2><p class="subtitle has-text-white" style="opacity:.9">Join thousands of happy customers today.</p><a href="#" class="button is-light is-large">Get Started</a></div></section>' },
                        { id: 'cms-testimonials', label: 'Testimonials', category: 'CMS Sections', content: '<section class="section has-background-light reveal"><div class="container"><h2 class="title is-2 has-text-centered mb-6">What our clients say</h2><div class="columns is-multiline"><div class="column is-one-third-desktop is-half-tablet"><div class="card h-100"><div class="card-content"><p class="mb-2">⭐⭐⭐⭐⭐</p><p class="has-text-grey-dark is-italic mb-4">"This product changed everything for us!"</p><div class="media"><div class="media-left"><span class="is-flex is-align-items-center is-justify-content-center has-background-primary has-text-white" style="width:2.5rem;height:2.5rem;border-radius:50%;font-weight:700">JD</span></div><div class="media-content"><p class="has-text-weight-bold">Jane Doe</p><p class="has-text-grey is-size-7">CEO, Company</p></div></div></div></div></div><div class="column is-one-third-desktop is-half-tablet"><div class="card h-100"><div class="card-content"><p class="mb-2">⭐⭐⭐⭐⭐</p><p class="has-text-grey-dark is-italic mb-4">"Highly recommend to anyone looking to grow."</p><div class="media"><div class="media-left"><span class="is-flex is-align-items-center is-justify-content-center has-background-primary has-text-white" style="width:2.5rem;height:2.5rem;border-radius:50%;font-weight:700">JS</span></div><div class="media-content"><p class="has-text-weight-bold">John Smith</p><p class="has-text-grey is-size-7">Marketing Director</p></div></div></div></div></div></div></div></section>' },
                        { id: 'cms-pricing',      label: 'Pricing',      category: 'CMS Sections', content: '<section class="section reveal"><div class="container"><h2 class="title is-2 has-text-centered mb-2">Simple Pricing</h2><p class="subtitle is-5 has-text-centered has-text-grey mb-6">No hidden fees</p><div class="columns is-multiline is-centered"><div class="column is-one-third-desktop is-half-tablet"><div class="card"><div class="card-content has-text-centered"><p class="title is-5 mb-2">Starter</p><p class="title is-1 has-text-primary mb-0">$9</p><p class="has-text-grey mb-4">/month</p><a href="#" class="button is-outlined is-primary is-fullwidth">Get Started</a></div></div></div><div class="column is-one-third-desktop is-half-tablet"><div class="card" style="position:relative;border:2px solid var(--color-primary);transform:scale(1.03)"><span class="tag is-primary" style="position:absolute;top:1rem;right:1rem">Popular</span><div class="card-content has-text-centered"><p class="title is-5 mb-2">Pro</p><p class="title is-1 has-text-primary mb-0">$29</p><p class="has-text-grey mb-4">/month</p><a href="#" class="button is-primary is-fullwidth">Get Started</a></div></div></div></div></div></section>' },
                        { id: 'cms-team',         label: 'Team',         category: 'CMS Sections', content: '<section class="section reveal"><div class="container"><h2 class="title is-2 has-text-centered mb-6">Meet the Team</h2><div class="columns is-multiline"><div class="column is-one-quarter-desktop is-half-tablet"><div class="card h-100"><div class="card-content has-text-centered"><figure class="image is-128x128" style="margin:0 auto 1rem"><img class="is-rounded" src="https://picsum.photos/128/128" alt="Team Member" style="object-fit:cover;width:100%;height:100%"></figure><p class="title is-5 mb-1">Jane Doe</p><span class="tag is-primary is-light">CEO</span><p class="has-text-grey mt-3">Passionate leader with 10 years of experience.</p></div></div></div><div class="column is-one-quarter-desktop is-half-tablet"><div class="card h-100"><div class="card-content has-text-centered"><figure class="image is-128x128" style="margin:0 auto 1rem"><img class="is-rounded" src="https://picsum.photos/128/129" alt="Team Member" style="object-fit:cover;width:100%;height:100%"></figure><p class="title is-5 mb-1">John Smith</p><span class="tag is-primary is-light">CTO</span><p class="has-text-grey mt-3">Full-stack engineer and problem solver.</p></div></div></div></div></div></section>' },
                        { id: 'cms-faq',          label: 'FAQ',          category: 'CMS Sections', content: '<section class="section reveal"><div class="container" style="max-width:48rem"><h2 class="title is-2 has-text-centered mb-6">Frequently Asked Questions</h2><details class="box mb-3" open><summary style="font-size:1.1rem;font-weight:600;cursor:pointer;list-style:none;display:flex;justify-content:space-between;align-items:center;gap:1rem"><span>What is this product?</span><span>&#9660;</span></summary><p class="has-text-grey mt-3">This is a great product that solves real problems.</p></details><details class="box mb-3"><summary style="font-size:1.1rem;font-weight:600;cursor:pointer;list-style:none;display:flex;justify-content:space-between;align-items:center;gap:1rem"><span>How does it work?</span><span>&#9660;</span></summary><p class="has-text-grey mt-3">It works by leveraging cutting-edge technology.</p></details></div></section>' },
                        { id: 'cms-cards',        label: 'Cards',        category: 'CMS Sections', content: '<section class="section reveal"><div class="container"><h2 class="title is-2 has-text-centered mb-6">Our Cards</h2><div class="columns is-multiline"><div class="column is-one-third-desktop is-half-tablet"><div class="card h-100"><div class="card-image"><figure class="image is-16by9"><img src="https://picsum.photos/600/340" alt="Card" style="object-fit:cover"></figure></div><div class="card-content"><p class="title is-5">Card Title</p><p class="has-text-grey">Card description text goes here.</p></div></div></div><div class="column is-one-third-desktop is-half-tablet"><div class="card h-100"><div class="card-image"><figure class="image is-16by9"><img src="https://picsum.photos/600/341" alt="Card" style="object-fit:cover"></figure></div><div class="card-content"><p class="title is-5">Card Title Two</p><p class="has-text-grey">Another card description here.</p></div></div></div></div></div></section>' },
                        { id: 'cms-quote',        label: 'Quote',        category: 'CMS Sections', content: '<section class="section-sm reveal"><blockquote class="is-size-4" style="border-left:4px solid var(--color-primary);padding-left:1.5rem;max-width:48rem;margin:0 auto"><p class="has-text-grey-dark is-italic">"The best way to predict the future is to create it."</p><footer class="mt-4"><cite class="has-text-weight-bold">Abraham Lincoln <span class="has-text-grey">— President</span></cite></footer></blockquote></section>' },
                        { id: 'cms-video',        label: 'Video',        category: 'CMS Sections', content: '<section class="section reveal"><div class="container"><h3 class="title is-3 has-text-centered mb-5">Watch Our Video</h3><figure class="image is-16by9" style="max-width:900px;margin:0 auto;border-radius:.75rem;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.12)"><iframe class="has-ratio" src="https://www.youtube.com/embed/dQw4w9WgXcQ" allowfullscreen loading="lazy"></iframe></figure></div></section>' },

                        { id: 'cms-checklist',    label: 'Checklist',    category: 'CMS Content',  content: '<section class="section-sm reveal"><div style="max-width:40rem;margin:0 auto"><h3 class="title is-3 mb-5">Why choose us</h3><ul style="list-style:none"><li style="display:flex;align-items:flex-start;gap:.75rem;margin-bottom:.75rem"><svg style="flex-shrink:0;color:green;width:1.25rem;height:1.25rem;margin-top:.15rem" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg><span class="has-text-grey-dark">Benefit one</span></li><li style="display:flex;align-items:flex-start;gap:.75rem;margin-bottom:.75rem"><svg style="flex-shrink:0;color:green;width:1.25rem;height:1.25rem;margin-top:.15rem" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg><span class="has-text-grey-dark">Benefit two</span></li><li style="display:flex;align-items:flex-start;gap:.75rem;margin-bottom:.75rem"><svg style="flex-shrink:0;color:green;width:1.25rem;height:1.25rem;margin-top:.15rem" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg><span class="has-text-grey-dark">Benefit three</span></li></ul></div></section>' },
                        { id: 'cms-progress',     label: 'Progress',     category: 'CMS Content',  content: '<section class="section-sm reveal"><div style="max-width:40rem;margin:0 auto"><h3 class="title is-3 mb-5">Skills & Progress</h3><div class="mb-4"><div style="display:flex;justify-content:space-between;margin-bottom:.25rem"><span class="has-text-grey-dark">Skill One</span><span class="has-text-grey">90%</span></div><progress class="progress is-primary" value="90" max="100" data-value="90">90%</progress></div><div class="mb-4"><div style="display:flex;justify-content:space-between;margin-bottom:.25rem"><span class="has-text-grey-dark">Skill Two</span><span class="has-text-grey">75%</span></div><progress class="progress is-primary" value="75" max="100" data-value="75">75%</progress></div><div class="mb-4"><div style="display:flex;justify-content:space-between;margin-bottom:.25rem"><span class="has-text-grey-dark">Skill Three</span><span class="has-text-grey">60%</span></div><progress class="progress is-primary" value="60" max="100" data-value="60">60%</progress></div></div></section>' },
                        { id: 'cms-steps',        label: 'Steps',        category: 'CMS Content',  content: '<section class="section reveal"><div class="container"><h3 class="title is-2 has-text-centered mb-8">How it Works</h3><div class="columns is-multiline"><div class="column is-one-third-desktop is-half-tablet"><div class="card h-100"><div class="card-content has-text-centered"><span class="tag is-primary is-large" style="border-radius:50%;width:3rem;height:3rem;font-size:1.25rem;font-weight:700;margin-bottom:1rem">1</span><p class="title is-5 mt-3">Step One</p><p class="has-text-grey">Description of the first step.</p></div></div></div><div class="column is-one-third-desktop is-half-tablet"><div class="card h-100"><div class="card-content has-text-centered"><span class="tag is-primary is-large" style="border-radius:50%;width:3rem;height:3rem;font-size:1.25rem;font-weight:700;margin-bottom:1rem">2</span><p class="title is-5 mt-3">Step Two</p><p class="has-text-grey">Description of the second step.</p></div></div></div><div class="column is-one-third-desktop is-half-tablet"><div class="card h-100"><div class="card-content has-text-centered"><span class="tag is-primary is-large" style="border-radius:50%;width:3rem;height:3rem;font-size:1.25rem;font-weight:700;margin-bottom:1rem">3</span><p class="title is-5 mt-3">Step Three</p><p class="has-text-grey">Description of the third step.</p></div></div></div></div></div></section>' },
                        { id: 'cms-timeline',     label: 'Timeline',     category: 'CMS Content',  content: '<section class="section reveal"><div class="container" style="max-width:48rem"><div style="display:flex;gap:1.5rem;margin-bottom:2rem"><div style="display:flex;flex-direction:column;align-items:center"><span style="width:1rem;height:1rem;border-radius:50%;background:var(--color-primary);flex-shrink:0;margin-top:.3rem"></span><span style="flex:1;width:2px;background:#e0e0e0;margin-top:.5rem"></span></div><div class="box" style="flex:1;margin-bottom:0"><p class="has-text-grey is-size-7 mb-1">2024</p><p class="has-text-weight-bold">Event Title</p><p class="has-text-grey">Description of what happened.</p></div></div><div style="display:flex;gap:1.5rem;margin-bottom:2rem"><div style="display:flex;flex-direction:column;align-items:center"><span style="width:1rem;height:1rem;border-radius:50%;background:var(--color-primary);flex-shrink:0;margin-top:.3rem"></span><span style="flex:1;width:2px;background:#e0e0e0;margin-top:.5rem"></span></div><div class="box" style="flex:1;margin-bottom:0"><p class="has-text-grey is-size-7 mb-1">2025</p><p class="has-text-weight-bold">Another Event</p><p class="has-text-grey">Something important happened here.</p></div></div></div></section>' },
                        { id: 'cms-accordion',    label: 'Accordion',    category: 'CMS Content',  content: '<section class="section reveal"><div class="container" style="max-width:48rem"><h3 class="title is-3 has-text-centered mb-6">Accordion</h3><details class="box mb-3" open><summary style="font-weight:600;font-size:1.1rem;cursor:pointer;list-style:none;display:flex;justify-content:space-between;align-items:center;gap:1rem"><span>Section One</span><span>&#9660;</span></summary><div class="content mt-3"><p>Content for section one goes here.</p></div></details><details class="box mb-3"><summary style="font-weight:600;font-size:1.1rem;cursor:pointer;list-style:none;display:flex;justify-content:space-between;align-items:center;gap:1rem"><span>Section Two</span><span>&#9660;</span></summary><div class="content mt-3"><p>Content for section two goes here.</p></div></details></div></section>' },
                        { id: 'cms-tabs',         label: 'Tabs',         category: 'CMS Content',  content: '<section class="section reveal"><div class="container"><div class="tabs is-boxed"><ul><li class="is-active"><a>Tab One</a></li><li><a>Tab Two</a></li><li><a>Tab Three</a></li></ul></div><div class="content"><p>Tab content goes here. Click the tabs above to switch between sections.</p></div></div></section>' },
                        { id: 'cms-gallery',      label: 'Gallery',      category: 'CMS Content',  content: '<section class="section-sm reveal"><div class="columns is-multiline"><div class="column is-one-third"><figure class="image is-1by1"><img src="https://picsum.photos/600/600?random=1" alt="" style="object-fit:cover;height:100%" loading="lazy"></figure></div><div class="column is-one-third"><figure class="image is-1by1"><img src="https://picsum.photos/600/600?random=2" alt="" style="object-fit:cover;height:100%" loading="lazy"></figure></div><div class="column is-one-third"><figure class="image is-1by1"><img src="https://picsum.photos/600/600?random=3" alt="" style="object-fit:cover;height:100%" loading="lazy"></figure></div></div></section>' },
                        { id: 'cms-carousel',     label: 'Carousel',     category: 'CMS Content',  content: '<section class="section reveal"><div class="container"><figure class="image is-16by9" style="max-width:900px;margin:0 auto;border-radius:.75rem;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.12)"><img src="https://picsum.photos/900/506?random=10" alt="Slide" style="object-fit:cover;width:100%;height:100%"></figure></div></section>' },
                        { id: 'cms-table',        label: 'Table',        category: 'CMS Content',  content: '<section class="section-sm reveal"><div class="table-container"><h3 class="title is-3 has-text-centered mb-5">Data Table</h3><table class="table is-striped is-fullwidth"><thead><tr><th>Name</th><th>Description</th><th>Status</th></tr></thead><tbody><tr><td>Row One</td><td>Description here</td><td>Active</td></tr><tr><td>Row Two</td><td>Another description</td><td>Pending</td></tr><tr><td>Row Three</td><td>Third description</td><td>Active</td></tr></tbody></table></div></section>' },
                        { id: 'cms-list',         label: 'List',         category: 'CMS Content',  content: '<section class="section-sm reveal"><div style="max-width:40rem;margin:0 auto"><h3 class="title is-3 mb-5">List</h3><ul class="content" style="margin-left:1.5rem"><li style="margin-bottom:.375rem">List item one</li><li style="margin-bottom:.375rem">List item two</li><li style="margin-bottom:.375rem">List item three</li></ul></div></section>' },

                        { id: 'cms-newsletter',   label: 'Newsletter',   category: 'CMS Widgets',  content: '<section class="section has-background-light reveal"><div class="container" style="max-width:36rem"><div class="has-text-centered"><h3 class="title is-3">Stay Updated</h3><p class="has-text-grey mb-5">Get the latest news and updates delivered to your inbox.</p><div class="field has-addons" style="justify-content:center"><div class="control is-expanded"><input class="input" type="email" placeholder="Enter your email"></div><div class="control"><button class="button is-primary">Subscribe</button></div></div></div></div></section>' },
                        { id: 'cms-alert',        label: 'Alert',        category: 'CMS Widgets',  content: '<section class="section-sm reveal"><div class="notification is-info is-light"><button class="delete" aria-label="Dismiss"></button><strong>Information</strong> This is an important message for your visitors.</div></section>' },
                        { id: 'cms-download',     label: 'Download',     category: 'CMS Widgets',  content: '<section class="section-sm reveal"><div class="box" style="max-width:32rem;margin:0 auto;display:flex;align-items:center;gap:1.5rem"><span style="font-size:2.5rem">📄</span><div style="flex:1"><p class="has-text-weight-bold">Resource Name.pdf</p><p class="has-text-grey">PDF Document — 2.4 MB</p></div><a href="#" class="button is-primary" download>Download</a></div></section>' },
                        { id: 'cms-comparison',   label: 'Comparison',   category: 'CMS Widgets',  content: '<section class="section reveal"><div class="container"><h3 class="title is-3 has-text-centered mb-6">How we compare</h3><div class="table-container"><table class="table is-striped is-fullwidth"><thead><tr><th style="background-color:var(--color-primary);color:#fff">Feature</th><th style="background-color:var(--color-primary);color:#fff">Us</th><th style="background-color:var(--color-primary);color:#fff">Competitor</th></tr></thead><tbody><tr><td class="has-text-weight-semibold">Feature one</td><td class="has-text-centered">✓</td><td class="has-text-centered">✗</td></tr><tr><td class="has-text-weight-semibold">Feature two</td><td class="has-text-centered">✓</td><td class="has-text-centered">✓</td></tr></tbody></table></div></div></section>' },
                        { id: 'cms-logo_cloud',   label: 'Logo Cloud',   category: 'CMS Widgets',  content: '<section class="section-sm has-background-light reveal"><div class="container"><p class="has-text-grey has-text-centered mb-6 is-size-6 has-text-weight-semibold" style="text-transform:uppercase;letter-spacing:.1em">Trusted by leading companies</p><div style="display:flex;flex-wrap:wrap;justify-content:center;align-items:center"><div style="padding:1rem"><img src="https://picsum.photos/200/80?random=11" alt="Logo" style="max-height:3rem;filter:grayscale(1);opacity:.6" loading="lazy"></div><div style="padding:1rem"><img src="https://picsum.photos/200/80?random=12" alt="Logo" style="max-height:3rem;filter:grayscale(1);opacity:.6" loading="lazy"></div><div style="padding:1rem"><img src="https://picsum.photos/200/80?random=13" alt="Logo" style="max-height:3rem;filter:grayscale(1);opacity:.6" loading="lazy"></div></div></div></section>' },
                        { id: 'cms-contact_info', label: 'Contact Info',  category: 'CMS Widgets',  content: '<section class="section reveal"><div class="container"><h3 class="title is-3 has-text-centered mb-6">Get in touch</h3><div class="columns is-multiline is-centered"><div class="column is-one-third-desktop is-half-tablet"><div class="box has-text-centered"><p style="font-size:2rem;margin-bottom:.5rem">📍</p><p class="heading">Address</p><p class="has-text-weight-medium">123 Main St, City</p></div></div><div class="column is-one-third-desktop is-half-tablet"><div class="box has-text-centered"><p style="font-size:2rem;margin-bottom:.5rem">📧</p><p class="heading">Email</p><p class="has-text-weight-medium">hello@example.com</p></div></div><div class="column is-one-third-desktop is-half-tablet"><div class="box has-text-centered"><p style="font-size:2rem;margin-bottom:.5rem">📞</p><p class="heading">Phone</p><p class="has-text-weight-medium">+1 (555) 000-0000</p></div></div></div></div></section>' },
                        { id: 'cms-form',         label: 'Contact Form',  category: 'CMS Widgets',  content: '<section class="section reveal"><div class="container" style="max-width:40rem"><div class="card"><div class="card-content"><h2 class="title is-3 mb-5">Contact Us</h2><div class="field"><label class="label">Name</label><div class="control"><input class="input" type="text" placeholder="Your name"></div></div><div class="field"><label class="label">Email</label><div class="control"><input class="input" type="email" placeholder="your@email.com"></div></div><div class="field"><label class="label">Message</label><div class="control"><textarea class="textarea" placeholder="Your message..."></textarea></div></div><div class="field"><div class="control"><button class="button is-primary is-fullwidth">Send Message</button></div></div></div></div></div></section>' },
                        { id: 'cms-map',          label: 'Map',          category: 'CMS Widgets',  content: '<section class="section-sm reveal"><div class="container"><h3 class="title is-3 has-text-centered mb-5">Find us</h3><div class="image is-16by9"><iframe class="has-ratio" src="https://www.openstreetmap.org/export/embed.html?bbox=-0.1%2C51.4%2C0.0%2C51.6&layer=mapnik" allowfullscreen loading="lazy" title="Map"></iframe></div></div></section>' },
                        { id: 'cms-social',       label: 'Social Links', category: 'CMS Widgets',  content: '<div class="section-sm has-text-centered reveal"><p class="has-text-weight-semibold mb-3">Follow us</p><div class="buttons is-centered"><a href="#" class="button is-light" style="font-size:1.25rem" target="_blank" rel="noopener">f</a><a href="#" class="button is-light" style="font-size:1.25rem" target="_blank" rel="noopener">X</a><a href="#" class="button is-light" style="font-size:1.25rem" target="_blank" rel="noopener">in</a><a href="#" class="button is-light" style="font-size:1.25rem" target="_blank" rel="noopener">▶</a></div></div>' },
                        { id: 'cms-link_tree',    label: 'Link Tree',    category: 'CMS Widgets',  content: '<section class="section-sm reveal"><div style="max-width:24rem;margin:0 auto;text-align:center"><figure class="image is-96x96" style="margin:0 auto 1rem"><img style="border-radius:50%" src="https://picsum.photos/96/96" alt="Avatar"></figure><h2 class="title is-4 mb-1">Your Name</h2><p class="has-text-grey mb-4">Bio or tagline here</p><a href="#" class="button is-fullwidth is-outlined mb-3" target="_blank" rel="noopener">🌐 Website</a><a href="#" class="button is-fullwidth is-outlined mb-3" target="_blank" rel="noopener">🐦 Twitter</a><a href="#" class="button is-fullwidth is-outlined mb-3" target="_blank" rel="noopener">📸 Instagram</a></div></section>' }
                    ]
                },
                // Style manager — mounted in our right panel
                styleManager: {
                    appendTo: '#gjsStyles',
                    sectors: [
                        {
                            name: 'Typography', open: true,
                            properties: [
                                { property: 'color',          name: 'Color',    type: 'color' },
                                { property: 'font-size',      name: 'Size',     type: 'integer', units: ['px','em','rem','%'] },
                                { property: 'font-weight',    name: 'Weight',   type: 'select', options: [
                                    {value:'',name:'—'},{value:'300',name:'Light'},{value:'400',name:'Regular'},
                                    {value:'500',name:'Medium'},{value:'600',name:'Semibold'},{value:'700',name:'Bold'}
                                ]},
                                { property: 'text-align',     name: 'Align',    type: 'radio', options: [
                                    {value:'left',name:'L'},{value:'center',name:'C'},{value:'right',name:'R'},{value:'justify',name:'J'}
                                ]},
                                { property: 'line-height',    name: 'Line H',   type: 'integer', units: ['','px','em'] },
                                { property: 'letter-spacing', name: 'Spacing',  type: 'integer', units: ['px','em'] }
                            ]
                        },
                        {
                            name: 'Dimension', open: false,
                            properties: [
                                { property: 'width',      type: 'integer', units: ['px','%','vw','em','rem'] },
                                { property: 'height',     type: 'integer', units: ['px','%','vh','em'] },
                                { property: 'max-width',  name: 'Max W',   type: 'integer', units: ['px','%'] },
                                { property: 'min-height', name: 'Min H',   type: 'integer', units: ['px','%','vh'] },
                                { property: 'padding',    type: 'integer', units: ['px','em','rem','%'] },
                                { property: 'margin',     type: 'integer', units: ['px','em','rem','%'] }
                            ]
                        },
                        {
                            name: 'Decorations', open: false,
                            properties: [
                                { property: 'background-color', name: 'BG Color',     type: 'color' },
                                { property: 'border-radius',    name: 'Radius',        type: 'integer', units: ['px','%'] },
                                { property: 'border-width',     name: 'Border',        type: 'integer', units: ['px'] },
                                { property: 'border-style',     name: 'Border Style',  type: 'select', options: [
                                    {value:'none',name:'None'},{value:'solid',name:'Solid'},{value:'dashed',name:'Dashed'},{value:'dotted',name:'Dotted'}
                                ]},
                                { property: 'border-color',     name: 'Border Color',  type: 'color' },
                                { property: 'opacity',          type: 'integer',       units: [''] }
                            ]
                        },
                        {
                            name: 'Flex', open: false,
                            properties: [
                                { property: 'display', type: 'select', options: [
                                    {value:'',name:'—'},{value:'flex',name:'Flex'},{value:'grid',name:'Grid'},
                                    {value:'block',name:'Block'},{value:'inline-block',name:'Inline Block'},{value:'none',name:'None'}
                                ]},
                                { property: 'flex-direction', name: 'Direction', type: 'radio', options: [
                                    {value:'row',name:'→'},{value:'column',name:'↓'},{value:'row-reverse',name:'←'},{value:'column-reverse',name:'↑'}
                                ]},
                                { property: 'justify-content', name: 'Justify', type: 'select', options: [
                                    {value:'',name:'—'},{value:'flex-start',name:'Start'},{value:'center',name:'Center'},
                                    {value:'flex-end',name:'End'},{value:'space-between',name:'Space Between'},{value:'space-around',name:'Space Around'}
                                ]},
                                { property: 'align-items', name: 'Align', type: 'select', options: [
                                    {value:'',name:'—'},{value:'stretch',name:'Stretch'},{value:'flex-start',name:'Start'},
                                    {value:'center',name:'Center'},{value:'flex-end',name:'End'}
                                ]},
                                { property: 'gap', type: 'integer', units: ['px','em','rem'] }
                            ]
                        }
                    ]
                },
                layerManager:  { appendTo: '#gjsLayers' },
                traitManager:  { appendTo: '#gjsSettings' },
                selectorManager: { componentFirst: true }
            });

            editor.setComponents(currentData.html || '');

            // Definitive removal of any GrapesJS chrome elements that survive the CSS. CSS display:none can be overridden by GrapesJS's own !important rules; DOM removal is final. Re-append gjsStyle so it sits AFTER GrapesJS's own injected <style> tag — when both use !important, the last stylesheet in the head wins.
            editor.on('load', () => {
                ['.gjs-editor-sp', '.gjs-pn-panels', '.gjs-canvas-top', '.gjs-frame-wrapper__top']
                    .forEach(sel => document.querySelectorAll(sel).forEach(el => el.remove()));
                document.head.removeChild(gjsStyle);
                document.head.appendChild(gjsStyle);

                // Populate the GrapesJS asset manager with existing media from the server
                fetch('/api/media')
                    .then(r => r.json())
                    .then(data => {
                        const images = (data.assets || []).filter(a => a.is_image);
                        editor.AssetManager.add(images.map(a => ({
                            src: '/assets/' + a.hash,
                            name: a.filename,
                            type: 'image'
                        })));
                    })
                    .catch(() => {});
            });

            // ── Device buttons ────────────────────────────────────────────────
            overlay.querySelectorAll('#gjsDevs button').forEach(btn => {
                btn.addEventListener('click', () => {
                    overlay.querySelectorAll('#gjsDevs button').forEach(b => {
                        b.style.background = 'transparent';
                        b.style.color      = '#94a3b8';
                        b.style.borderColor = '#334155';
                    });
                    btn.style.background   = '#2563eb';
                    btn.style.color        = '#fff';
                    btn.style.borderColor  = '#2563eb';
                    editor.setDevice(btn.dataset.dev || 'Full Width');
                });
            });

            // ── Right panel tabs ──────────────────────────────────────────────
            overlay.querySelectorAll('.gjsrtab').forEach(tab => {
                tab.addEventListener('click', () => {
                    overlay.querySelectorAll('.gjsrtab').forEach(t => {
                        t.style.background   = 'transparent';
                        t.style.color        = '#64748b';
                        t.style.borderBottomColor = 'transparent';
                    });
                    tab.style.background   = '#2563eb';
                    tab.style.color        = '#fff';
                    tab.style.borderBottomColor = '#2563eb';
                    ['gjsStyles','gjsSettings','gjsLayers'].forEach(id =>
                        overlay.querySelector('#'+id).style.display = 'none'
                    );
                    overlay.querySelector('#'+tab.dataset.panel).style.display = 'block';
                });
            });

            // ── Cancel ────────────────────────────────────────────────────────
            overlay.querySelector('#gjsCancel').addEventListener('click', () => {
                editor.destroy();
                overlay.remove();
            });

            // ── Save ──────────────────────────────────────────────────────────
            // Use editor.getHtml() so inline styles survive the round-trip, then strip ephemeral GrapesJS artefacts before storing.
            overlay.querySelector('#gjsSave').addEventListener('click', async () => {
                let html = editor.getHtml();

                html = html.replace(/\s+id="([^"]+)"/gi, (match, id) =>
                    originalIds.has(id) ? match : ''
                );
                html = html
                    .replace(/\s+draggable="[^"]*"/gi, '')
                    .replace(/\s+contenteditable="[^"]*"/gi, '')
                    .replace(/\s+data-gjs-[a-z-]+="[^"]*"/gi, '');
                html = html.replace(/\bclass="([^"]*)"/gi, (match, cls) => {
                    const cleaned = cls.split(/\s+/).filter(c => !c.startsWith('gjs-')).join(' ');
                    return cleaned ? `class="${cleaned}"` : '';
                });

                // Normalize ARIA tab panel state: GrapesJS runs the block's <script> inside
                // its iframe, so whichever tab the user last clicked becomes active in the DOM.
                // Reset to first-panel-visible before storing so the saved HTML always has a
                // deterministic initial state (tab 0 open, rest display:none).
                (function() {
                    const _d = document.createElement('div');
                    _d.innerHTML = html;
                    _d.querySelectorAll('[role="tabpanel"]').forEach(function(panel, i) {
                        if (i === 0) {
                            panel.style.removeProperty('display');
                            if (!panel.getAttribute('style')) panel.removeAttribute('style');
                        } else {
                            panel.style.display = 'none';
                        }
                    });
                    _d.querySelectorAll('[role="tab"]').forEach(function(tab, i) {
                        tab.setAttribute('aria-selected', i === 0 ? 'true' : 'false');
                    });
                    html = _d.innerHTML;
                })();

                const anchorVal = overlay.querySelector('#gjsAnchor').value.trim();
                const newData = { html };
                if (anchorVal) newData.anchor = anchorVal;

                try {
                    const formData = new FormData();
                    formData.append('_csrf', getCsrfToken());
                    formData.append('block_json', JSON.stringify(newData));

                    const res = await fetch(`/api/blocks/${blockId}/update`, { method: 'POST', body: formData });
                    const data = await res.json();

                    if (!res.ok) { toast(data.error || 'Save failed', 'error'); return; }

                    editor.destroy();
                    overlay.remove();

                    if (data.rendered_html) {
                        swapBlockContent(blockId, data.rendered_html, newData);
                        toast('Block saved!');
                    } else {
                        toast('Block saved! Refreshing...');
                        setTimeout(() => location.reload(), 500);
                    }
                } catch (e) {
                    toast('Error: ' + e.message, 'error');
                }
            });
        });
    }

    async function editBlock(wrapper) {
        const blockId = wrapper.dataset.blockId;
        const blockType = wrapper.dataset.blockType;
        const dataScript = wrapper.querySelector('.block-data');
        const currentData = dataScript ? JSON.parse(dataScript.textContent) : {};

        // HTML blocks → GrapesJS visual editor
        if (currentData.html !== undefined) {
            openGrapesEditor(wrapper, blockId, currentData);
            return;
        }

        // Structured blocks → field-based modal editor
        const blockName = blockType.charAt(0).toUpperCase() + blockType.slice(1);
        const content = getEditorForm(blockType, currentData);

        createModal(`Edit ${blockName} Block`, content, async () => {
            const modal = document.querySelector('.monolithcms-modal');
            const newData = collectFormData(modal, blockType);

            try {
                const formData = new FormData();
                formData.append('_csrf', getCsrfToken());
                formData.append('block_json', JSON.stringify(newData));

                const res = await fetch(`/api/blocks/${blockId}/update`, {
                    method: 'POST',
                    body: formData
                });

                if (!res.ok) {
                    const err = await res.json().catch(() => ({}));
                    toast(err.error || 'Failed to update block', 'error');
                    return;
                }

                const data = await res.json();
                if (data.rendered_html) {
                    swapBlockContent(blockId, data.rendered_html, newData);
                    toast('Block updated!');
                } else {
                    toast('Block updated! Refreshing...');
                    setTimeout(() => location.reload(), 500);
                }
            } catch (e) {
                toast('Error: ' + e.message, 'error');
            }
        });
    }

    // Move block
    async function moveBlock(wrapper, direction) {
        const blockId = wrapper.dataset.blockId;

        try {
            const formData = new FormData();
            formData.append('_csrf', getCsrfToken());
            formData.append('direction', direction);

            const res = await fetch(`/api/blocks/${blockId}/move`, {
                method: 'POST',
                body: formData
            });

            if (res.ok) {
                toast('Block moved!');
                setTimeout(() => location.reload(), 300);
            } else {
                toast('Cannot move block', 'error');
            }
        } catch (e) {
            toast('Error: ' + e.message, 'error');
        }
    }

    // Delete block
    async function deleteBlock(wrapper) {
        if (!confirm('Delete this block? This cannot be undone.')) return;

        const blockId = wrapper.dataset.blockId;

        try {
            const formData = new FormData();
            formData.append('_csrf', getCsrfToken());

            const res = await fetch(`/api/blocks/${blockId}/delete`, {
                method: 'POST',
                body: formData
            });

            if (res.ok) {
                wrapper.remove();
                toast('Block deleted!');
            } else {
                toast('Failed to delete block', 'error');
            }
        } catch (e) {
            toast('Error: ' + e.message, 'error');
        }
    }

    // Block types for Add Block modal
    const blockTypeList = [
        { type: 'hero', icon: 'view_carousel', name: 'Hero' },
        { type: 'text', icon: 'article', name: 'Text' },
        { type: 'features', icon: 'auto_awesome', name: 'Features' },
        { type: 'stats', icon: 'analytics', name: 'Stats' },
        { type: 'testimonials', icon: 'format_quote', name: 'Testimonials' },
        { type: 'team', icon: 'groups', name: 'Team' },
        { type: 'pricing', icon: 'payments', name: 'Pricing' },
        { type: 'faq', icon: 'help', name: 'FAQ' },
        { type: 'cta', icon: 'campaign', name: 'Call to Action' },
        { type: 'form', icon: 'mail', name: 'Contact Form' },
        { type: 'image', icon: 'image', name: 'Image' },
        { type: 'gallery', icon: 'photo_library', name: 'Gallery' },
        { type: 'quote', icon: 'format_quote', name: 'Quote' },
        { type: 'newsletter', icon: 'newspaper', name: 'Newsletter' },
        { type: 'cards',      icon: 'grid_view',        name: 'Cards' },
        { type: 'video',      icon: 'play_circle',      name: 'Video' },
        { type: 'divider',    icon: 'horizontal_rule',  name: 'Divider' },
        { type: 'carousel',   icon: 'slideshow',        name: 'Carousel' },
        { type: 'checklist',  icon: 'checklist',        name: 'Checklist' },
        { type: 'logo_cloud', icon: 'business_center',  name: 'Logo Cloud' },
        { type: 'comparison', icon: 'compare',          name: 'Comparison' },
        { type: 'tabs',       icon: 'tab',              name: 'Tabs' },
        { type: 'accordion',  icon: 'expand_all',       name: 'Accordion' },
        { type: 'timeline',   icon: 'timeline',         name: 'Timeline' },
        { type: 'steps',       icon: 'stairs',                name: 'Steps' },
        { type: 'social',      icon: 'share',                 name: 'Social Links' },
        { type: 'map',         icon: 'map',                   name: 'Map' },
        { type: 'contact_info',icon: 'contact_page',          name: 'Contact Info' },
        { type: 'list',        icon: 'format_list_bulleted',  name: 'List' },
        { type: 'table',       icon: 'table_chart',           name: 'Table' },
        { type: 'columns',     icon: 'view_column',           name: 'Columns' },
        { type: 'progress',    icon: 'donut_large',           name: 'Progress Bars' },
        { type: 'download',    icon: 'download',              name: 'Download' },
        { type: 'alert',       icon: 'warning',               name: 'Alert' },
        { type: 'spacer',      icon: 'height',                name: 'Spacer' },
    ];

    // Add new block
    function showAddBlockModal() {
        const typeOptions = blockTypeList.map(b => `
            <div class="block-type-option" data-type="${b.type}">
                <span class="block-type-icon">${b.icon}</span>
                <div class="block-type-name">${b.name}</div>
            </div>
        `).join('');

        const content = `
            <p style="margin-bottom:16px;color:var(--mx);">Choose a block type to add:</p>
            <div class="block-type-grid">${typeOptions}</div>
        `;

        const modal = createModal('Add Block', content, async () => {
            const selected = document.querySelector('.block-type-option.selected');
            if (!selected) {
                toast('Please select a block type', 'error');
                return;
            }

            const blockType = selected.dataset.type;

            try {
                const formData = new FormData();
                formData.append('_csrf', getCsrfToken());
                formData.append('page_id', pageId);
                formData.append('type', blockType);
                formData.append('block_json', JSON.stringify({}));

                const res = await fetch('/api/blocks/create', {
                    method: 'POST',
                    body: formData
                });

                if (res.ok) {
                    toast('Block added!');
                    setTimeout(() => location.reload(), 500);
                } else {
                    toast('Failed to add block', 'error');
                }
            } catch (e) {
                toast('Error: ' + e.message, 'error');
            }
        });

        // Handle selection
        modal.querySelectorAll('.block-type-option').forEach(opt => {
            opt.onclick = () => {
                modal.querySelectorAll('.block-type-option').forEach(o => o.classList.remove('selected'));
                opt.classList.add('selected');
            };
        });
    }

    // Insert block above or below a reference block
    function insertBlock(refWrapper, position) {
        const refBlockId = refWrapper.dataset.blockId;
        const label = position === 'above' ? 'above this section' : 'below this section';
        const typeOptions = blockTypeList.map(b => `
            <div class="block-type-option" data-type="${b.type}">
                <span class="block-type-icon">${b.icon}</span>
                <div class="block-type-name">${b.name}</div>
            </div>
        `).join('');

        const content = `
            <p style="margin-bottom:16px;color:var(--mx);">Choose a block type to insert ${label}:</p>
            <div class="block-type-grid">${typeOptions}</div>
        `;

        const modal = createModal('Insert Block', content, async () => {
            const selected = document.querySelector('.block-type-option.selected');
            if (!selected) {
                toast('Please select a block type', 'error');
                return;
            }

            const blockType = selected.dataset.type;

            try {
                const formData = new FormData();
                formData.append('_csrf', getCsrfToken());
                formData.append('page_id', pageId);
                formData.append('type', blockType);
                formData.append('block_json', JSON.stringify({}));
                if (position === 'below') {
                    formData.append('after_block_id', refBlockId);
                } else {
                    formData.append('before_block_id', refBlockId);
                }

                const res = await fetch('/api/blocks/create', {
                    method: 'POST',
                    body: formData
                });

                if (res.ok) {
                    toast('Block inserted!');
                    setTimeout(() => location.reload(), 500);
                } else {
                    toast('Failed to insert block', 'error');
                }
            } catch (e) {
                toast('Error: ' + e.message, 'error');
            }
        });

        modal.querySelectorAll('.block-type-option').forEach(opt => {
            opt.onclick = () => {
                modal.querySelectorAll('.block-type-option').forEach(o => o.classList.remove('selected'));
                opt.classList.add('selected');
            };
        });
    }

    // Edit Header
    async function editHeader() {
        // Fetch current settings
        const res = await fetch('/api/theme/header');
        const data = await res.json();

        const content = `
            <div class="editor-field">
                <label class="editor-label">Site Name</label>
                <input type="text" class="editor-input" name="site_name" value="${data.site_name || ''}">
            </div>
            <div class="editor-field">
                <label class="editor-label">Tagline</label>
                <input type="text" class="editor-input" name="tagline" value="${data.tagline || ''}">
            </div>
            <div class="editor-field">
                <label class="editor-label">Logo URL</label>
                <input type="text" class="editor-input" name="logo_url" value="${data.logo_url || ''}" placeholder="/assets/logo.png">
            </div>
            <div class="editor-field">
                <label class="editor-label">Background Color</label>
                <input type="color" class="editor-color" name="header_bg" value="${data.header_bg || '#1e293b'}">
            </div>
            <div class="editor-field">
                <label class="editor-label">Text Color</label>
                <input type="color" class="editor-color" name="header_text" value="${data.header_text || '#ffffff'}">
            </div>
        `;

        createModal('Edit Header', content, async () => {
            const modal = document.querySelector('.monolithcms-modal');
            const formData = new FormData();
            formData.append('_csrf', getCsrfToken());
            formData.append('site_name', modal.querySelector('[name="site_name"]').value);
            formData.append('tagline', modal.querySelector('[name="tagline"]').value);
            formData.append('logo_url', modal.querySelector('[name="logo_url"]').value);
            formData.append('header_bg', modal.querySelector('[name="header_bg"]').value);
            formData.append('header_text', modal.querySelector('[name="header_text"]').value);

            const res = await fetch('/api/theme/header', { method: 'POST', body: formData });
            if (res.ok) {
                toast('Header updated!');
                setTimeout(() => location.reload(), 500);
            } else {
                toast('Failed to update header', 'error');
            }
        });
    }

    // Edit Navigation
    async function editNav() {
        const res = await fetch('/api/theme/nav');
        const data = await res.json();

        let navItemsHtml = '';
        (data.items || []).forEach((item, i) => {
            navItemsHtml += `
                <div class="nav-item-row" style="display:flex;gap:0.5rem;margin-bottom:0.5rem;align-items:center;">
                    <input type="text" class="editor-input" name="nav_label_${i}" value="${item.label || ''}" placeholder="Label" style="flex:1">
                    <input type="text" class="editor-input" name="nav_url_${i}" value="${item.url || ''}" placeholder="/page" style="flex:1">
                    <button type="button" class="editor-btn editor-btn-danger remove-nav" style="padding:0.5rem;">×</button>
                </div>
            `;
        });

        const content = `
            <div class="editor-field">
                <label class="editor-label">Navigation Background</label>
                <input type="color" class="editor-color" name="nav_bg" value="${data.nav_bg || '#1e40af'}">
            </div>
            <div class="editor-field">
                <label class="editor-label">Navigation Items</label>
                <div id="nav-items-list">${navItemsHtml}</div>
                <button type="button" class="editor-btn editor-btn-secondary" id="add-nav-item" style="margin-top:0.5rem;">+ Add Item</button>
            </div>
        `;

        const modal = createModal('Edit Navigation', content, async () => {
            const modalEl = document.querySelector('.monolithcms-modal');
            const items = [];
            modalEl.querySelectorAll('.nav-item-row').forEach((row, i) => {
                const label = row.querySelector('input[name^="nav_label"]').value;
                const url = row.querySelector('input[name^="nav_url"]').value;
                if (label && url) items.push({ label, url });
            });

            const formData = new FormData();
            formData.append('_csrf', getCsrfToken());
            formData.append('nav_bg', modalEl.querySelector('[name="nav_bg"]').value);
            formData.append('items', JSON.stringify(items));

            const res = await fetch('/api/theme/nav', { method: 'POST', body: formData });
            if (res.ok) {
                toast('Navigation updated!');
                setTimeout(() => location.reload(), 500);
            } else {
                toast('Failed to update navigation', 'error');
            }
        });

        // Add nav item button
        let navIndex = data.items?.length || 0;
        modal.querySelector('#add-nav-item').onclick = () => {
            const list = modal.querySelector('#nav-items-list');
            const row = document.createElement('div');
            row.className = 'nav-item-row';
            row.style.cssText = 'display:flex;gap:0.5rem;margin-bottom:0.5rem;align-items:center;';
            row.innerHTML = `
                <input type="text" class="editor-input" name="nav_label_${navIndex}" placeholder="Label" style="flex:1">
                <input type="text" class="editor-input" name="nav_url_${navIndex}" placeholder="/page" style="flex:1">
                <button type="button" class="editor-btn editor-btn-danger remove-nav" style="padding:0.5rem;">×</button>
            `;
            list.appendChild(row);
            navIndex++;
        };

        // Remove nav item
        modal.addEventListener('click', (e) => {
            if (e.target.classList.contains('remove-nav')) {
                e.target.closest('.nav-item-row').remove();
            }
        });
    }

    // Edit Footer
    async function editFooter() {
        const res = await fetch('/api/theme/footer');
        const data = await res.json();

        const content = `
            <div class="editor-field">
                <label class="editor-label">Footer Text</label>
                <textarea class="editor-textarea" name="footer_text" rows="3">${data.footer_text || ''}</textarea>
            </div>
            <div class="editor-field">
                <label class="editor-label">Background Color</label>
                <input type="color" class="editor-color" name="footer_bg" value="${data.footer_bg || '#1e293b'}">
            </div>
            <div class="editor-field">
                <label class="editor-label">Text Color</label>
                <input type="color" class="editor-color" name="footer_text_color" value="${data.footer_text_color || '#ffffff'}">
            </div>
        `;

        createModal('Edit Footer', content, async () => {
            const modal = document.querySelector('.monolithcms-modal');
            const formData = new FormData();
            formData.append('_csrf', getCsrfToken());
            formData.append('footer_text', modal.querySelector('[name="footer_text"]').value);
            formData.append('footer_bg', modal.querySelector('[name="footer_bg"]').value);
            formData.append('footer_text_color', modal.querySelector('[name="footer_text_color"]').value);

            const res = await fetch('/api/theme/footer', { method: 'POST', body: formData });
            if (res.ok) {
                toast('Footer updated!');
                setTimeout(() => location.reload(), 500);
            } else {
                toast('Failed to update footer', 'error');
            }
        });
    }

    // Initialize
    function init() {
        // ── Toolbar: event-based show/hide (more reliable than CSS :hover alone) ──
        document.querySelectorAll('.monolithcms-block-wrapper').forEach(wrapper => {
            const toolbar = wrapper.querySelector('.monolithcms-block-toolbar');
            let hideTimer = null;

            function showToolbar() {
                clearTimeout(hideTimer);
                if (toolbar) { toolbar.style.opacity = '1'; toolbar.style.transform = 'translateY(0)'; }
            }
            function scheduleHide() {
                hideTimer = setTimeout(() => {
                    if (toolbar) { toolbar.style.opacity = ''; toolbar.style.transform = ''; }
                }, 120);
            }

            wrapper.addEventListener('mouseenter', showToolbar);
            wrapper.addEventListener('mouseleave', scheduleHide);
            if (toolbar) {
                toolbar.addEventListener('mouseenter', showToolbar);
                toolbar.addEventListener('mouseleave', scheduleHide);
            }
        });

        // ── Block action buttons ──
        document.querySelectorAll('.monolithcms-block-wrapper').forEach(wrapper => {
            wrapper.querySelectorAll('.block-action').forEach(btn => {
                btn.onclick = (e) => {
                    e.preventDefault();
                    e.stopPropagation();

                    const action = btn.dataset.action;
                    switch (action) {
                        case 'insert-above': insertBlock(wrapper, 'above'); break;
                        case 'insert-below': insertBlock(wrapper, 'below'); break;
                        case 'edit': editBlock(wrapper); break;
                        case 'move-up': moveBlock(wrapper, 'up'); break;
                        case 'move-down': moveBlock(wrapper, 'down'); break;
                        case 'delete': deleteBlock(wrapper); break;
                    }
                };
            });
        });

        // Handle section edit buttons (Header, Nav, Footer)
        document.querySelectorAll('.section-edit-btn').forEach(btn => {
            btn.onclick = (e) => {
                e.preventDefault();
                e.stopPropagation();
                const action = btn.dataset.action;
                switch (action) {
                    case 'edit-header': editHeader(); break;
                    case 'edit-nav': editNav(); break;
                    case 'edit-footer': editFooter(); break;
                }
            };
        });

        // Add "Add Block" button
        const main = document.querySelector('main[data-page-id]');
        if (main && pageId) {
            const addBtn = document.createElement('div');
            addBtn.className = 'monolithcms-add-block';
            addBtn.innerHTML = '+ Add Block';
            addBtn.onclick = showAddBlockModal;
            main.appendChild(addBtn);
        }
    }

    // Run when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
JS;
    }

    public static function approvalJs(): void {
        header('Content-Type: application/javascript');
        header('Cache-Control: no-cache');
        echo <<<'JS'
    const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').content;
    const QUEUE_ID = parseInt(document.body.dataset.queueId || "0", 10);
    let planData;
    try {
        planData = JSON.parse(document.getElementById('planData').textContent);
        console.log('Plan data loaded:', planData.pages?.length, 'pages');
    } catch(e) {
        console.error('Failed to parse plan data:', e);
        planData = { pages: [] };
    }
    let currentEdit = { pageIndex: 0, blockIndex: 0 };

    // Common links for CTA mapping
    const commonLinks = [
        {url: '/', label: 'Home'},
        {url: '/contact', label: 'Contact'},
        {url: '/about', label: 'About'},
        {url: '/services', label: 'Services'},
        {url: '/pricing', label: 'Pricing'},
        {url: '#signup', label: 'Sign Up Form'},
        {url: '#newsletter', label: 'Newsletter'}
    ];

    // Initialize page tabs and content
    function init() {
        const tabsContainer = document.getElementById('pageTabs');
        const contentsContainer = document.getElementById('pageContents');

        planData.pages.forEach((page, pageIndex) => {
            // Create tab
            const tab = document.createElement('button');
            tab.className = 'page-tab' + (pageIndex === 0 ? ' active' : '');
            tab.textContent = page.title;
            tab.onclick = () => switchPage(pageIndex);
            tabsContainer.appendChild(tab);

            // Create page content
            const content = document.createElement('div');
            content.className = 'page-content' + (pageIndex === 0 ? ' active' : '');
            content.id = 'page-' + pageIndex;
            content.innerHTML = renderPageBlocks(page, pageIndex);
            reattachEventListeners(content);
            contentsContainer.appendChild(content);
        });

        // Hover CSS for edit-block-ai-btn
        const aiEditStyle = document.createElement('style');
        aiEditStyle.textContent = '.editable-block:hover .edit-block-ai-btn{display:inline-flex!important}';
        document.head.appendChild(aiEditStyle);

        // Edit with AI modal
        const aiEditModal = document.createElement('div');
        aiEditModal.id = 'ai-edit-block-modal';
        aiEditModal.style.cssText = 'display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:999990;align-items:center;justify-content:center;padding:1rem';
        aiEditModal.innerHTML = `
            <div style="background:#fff;border-radius:.75rem;box-shadow:0 20px 60px rgba(0,0,0,.3);max-width:36rem;width:100%;padding:1.5rem">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem">
                    <div>
                        <h3 style="font-size:1.125rem;font-weight:600;color:#111827;margin:0">Edit with AI</h3>
                        <p id="ai-edit-block-type" style="font-size:.8125rem;color:#6b7280;margin:.125rem 0 0"></p>
                    </div>
                    <button id="ai-edit-block-close" style="padding:.25rem;color:#6b7280;background:none;border:none;cursor:pointer;font-size:1.25rem;line-height:1">&#x2715;</button>
                </div>
                <label style="display:block;font-size:.8125rem;font-weight:500;color:#374151;margin-bottom:.375rem">Describe what to fix or improve</label>
                <textarea id="ai-edit-block-instruction" rows="4" placeholder="e.g. The steps are not displaying correctly — each step should have a number, title and description..." style="width:100%;padding:.625rem .75rem;border:1px solid #d1d5db;border-radius:.375rem;font-size:.875rem;resize:vertical;box-sizing:border-box"></textarea>
                <div style="display:flex;justify-content:flex-end;gap:.5rem;margin-top:1rem">
                    <button id="ai-edit-block-cancel" style="padding:.5rem 1rem;background:#f9fafb;border:1px solid #d1d5db;border-radius:.375rem;cursor:pointer;font-size:.875rem;color:#374151">Cancel</button>
                    <button id="ai-edit-block-submit" style="padding:.5rem 1rem;background:#135bec;color:#fff;border:none;border-radius:.375rem;cursor:pointer;font-size:.875rem;font-weight:600">Apply AI Fix</button>
                </div>
            </div>`;
        document.body.appendChild(aiEditModal);

        const closeAiEditModal = () => (aiEditModal.style.display = 'none');
        aiEditModal.querySelector('#ai-edit-block-close').addEventListener('click', closeAiEditModal);
        aiEditModal.querySelector('#ai-edit-block-cancel').addEventListener('click', closeAiEditModal);
        aiEditModal.addEventListener('click', e => { if (e.target === aiEditModal) closeAiEditModal(); });

        aiEditModal.querySelector('#ai-edit-block-submit').addEventListener('click', async function() {
            if (!window._aiEditTarget) return;
            const instruction = aiEditModal.querySelector('#ai-edit-block-instruction').value.trim();
            if (!instruction) { alert('Please describe what to fix.'); return; }

            const submitBtn = this;
            submitBtn.disabled = true;
            submitBtn.textContent = 'Fixing\u2026';

            try {
                const fd = new FormData();
                fd.append('_csrf', CSRF_TOKEN);
                fd.append('page_slug', window._aiEditTarget.pageSlug);
                fd.append('block_index', window._aiEditTarget.blockIndex);
                fd.append('instruction', instruction);

                const resp = await fetch(`/admin/approvals/${QUEUE_ID}/edit-block`, { method: 'POST', body: fd });
                const result = await resp.json();

                if (result.success) {
                    planData.pages[window._aiEditTarget.pageIndex].blocks[window._aiEditTarget.blockIndex] = result.block;
                    const pageEl = document.getElementById('page-' + window._aiEditTarget.pageIndex);
                    pageEl.innerHTML = renderPageBlocks(planData.pages[window._aiEditTarget.pageIndex], window._aiEditTarget.pageIndex);
                    reattachEventListeners(pageEl);
                    closeAiEditModal();
                } else {
                    alert('Edit failed: ' + (result.error || 'Unknown error'));
                }
            } catch (err) {
                alert('Request failed: ' + err.message);
            } finally {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Apply AI Fix';
            }
        });
    }

    function switchPage(index) {
        document.querySelectorAll('.page-tab').forEach((t, i) => t.classList.toggle('active', i === index));
        document.querySelectorAll('.page-content').forEach((c, i) => c.classList.toggle('active', i === index));
    }

    // Material Icons list for dropdown
    const materialIcons = [
        { name: 'star', label: 'Star' },
        { name: 'check_circle', label: 'Check Circle' },
        { name: 'verified', label: 'Verified' },
        { name: 'favorite', label: 'Favorite' },
        { name: 'thumb_up', label: 'Thumb Up' },
        { name: 'lightbulb', label: 'Lightbulb' },
        { name: 'rocket_launch', label: 'Rocket' },
        { name: 'shield', label: 'Shield' },
        { name: 'security', label: 'Security' },
        { name: 'speed', label: 'Speed' },
        { name: 'trending_up', label: 'Trending Up' },
        { name: 'insights', label: 'Insights' },
        { name: 'analytics', label: 'Analytics' },
        { name: 'settings', label: 'Settings' },
        { name: 'support', label: 'Support' },
        { name: 'help', label: 'Help' },
        { name: 'info', label: 'Info' },
        { name: 'schedule', label: 'Schedule' },
        { name: 'payments', label: 'Payments' },
        { name: 'handshake', label: 'Handshake' },
        { name: 'eco', label: 'Eco' },
        { name: 'public', label: 'Globe' },
        { name: 'groups', label: 'Groups' },
        { name: 'person', label: 'Person' },
        { name: 'home', label: 'Home' },
        { name: 'bolt', label: 'Bolt' },
        { name: 'auto_awesome', label: 'Auto Awesome' },
        { name: 'workspace_premium', label: 'Premium' },
        { name: 'diamond', label: 'Diamond' },
        { name: 'smartphone', label: 'Smartphone' },
        { name: 'computer', label: 'Computer' },
        { name: 'cloud', label: 'Cloud' },
        { name: 'code', label: 'Code' },
        { name: 'extension', label: 'Extension' },
        { name: 'lock', label: 'Lock' },
        { name: 'visibility', label: 'Visibility' },
        { name: 'edit', label: 'Edit' },
        { name: 'build', label: 'Build' },
        { name: 'palette', label: 'Palette' }
    ];

    function getIconOptions(selectedValue) {
        return materialIcons.map(icon =>
            `<option value="${icon.name}" ${selectedValue === icon.name ? 'selected' : ''}>${icon.label}</option>`
        ).join('');
    }

    // Render icon - supports Material Icons (lowercase names) or emojis/images
    function renderIcon(icon) {
        if (!icon) return '<span class="material-symbols-outlined text-primary">star</span>';

        // If it starts with / it's an image
        if (icon.startsWith('/')) {
            return `<img src="${escapeHtml(icon)}" alt="" class="w-10 h-10 object-contain inline">`;
        }

        // If it's a simple lowercase word (or underscore-separated), treat as Material Icon
        if (/^[a-z_]+$/.test(icon)) {
            return `<span class="material-symbols-outlined text-primary">${escapeHtml(icon)}</span>`;
        }

        // Otherwise render as-is (emoji)
        return escapeHtml(icon);
    }

    function renderPageBlocks(page, pageIndex) {
        const slug = page.slug || '';
        const toolbar = `<div style="display:flex;align-items:center;justify-content:flex-end;padding:.5rem 1rem;background:#f8fafc;border-bottom:1px solid #e2e8f0;">
            <button class="regen-page-btn" data-page-index="${pageIndex}" data-page-slug="${escapeHtml(slug)}"
                style="display:inline-flex;align-items:center;gap:.375rem;padding:.375rem .875rem;background:#fff;border:1px solid #e2e8f0;border-radius:.375rem;cursor:pointer;font-size:.8125rem;color:#475569;font-weight:500;">
                <span class="material-symbols-outlined" style="font-size:1rem;line-height:1;">refresh</span>Regenerate page
            </button>
        </div>`;
        if (!page.blocks) return toolbar + '<p style="text-align:center;padding:2rem;color:#94a3b8">No blocks generated</p>';
        return toolbar + page.blocks.map((block, blockIndex) => {
            const blockHtml = renderBlock(block);
            return `<div class="editable-block" style="position:relative" data-page="${pageIndex}" data-block="${blockIndex}" data-type="${block.type}">
                <button class="edit-block-ai-btn" data-page-index="${pageIndex}" data-block-index="${blockIndex}" data-page-slug="${escapeHtml(slug)}" data-block-type="${escapeHtml(block.type||'block')}"
                    style="display:none;position:absolute;top:.5rem;right:.5rem;z-index:100;align-items:center;gap:.25rem;padding:.3rem .65rem;background:rgba(19,91,236,.9);color:#fff;border:none;border-radius:.375rem;cursor:pointer;font-size:.75rem;font-weight:600;backdrop-filter:blur(4px);">
                    <span class="material-symbols-outlined" style="font-size:.875rem;line-height:1;">auto_fix_high</span>&nbsp;Edit with AI
                </button>
                ${blockHtml}
            </div>`;
        }).join('');
    }

    // Prepare AI-generated HTML for safe DOM insertion: - onclick preserved as data-cms-click (restored as real listener after insertion) - other on* attributes (onerror, onload, …) stripped — no legitimate use in content
    function stripEventHandlers(html) {
        const tmpl = document.createElement('template');
        tmpl.innerHTML = html;
        tmpl.content.querySelectorAll('*').forEach(el => {
            Array.from(el.attributes).forEach(a => {
                if (a.name === 'onclick') {
                    el.setAttribute('data-cms-click', a.value);
                    el.removeAttribute('onclick');
                } else if (/^on[a-z]/i.test(a.name)) {
                    el.removeAttribute(a.name);
                }
            });
        });
        const div = document.createElement('div');
        div.appendChild(tmpl.content);
        return div.innerHTML;
    }

    // After setting innerHTML, convert data-cms-click back to real event listeners
    function reattachEventListeners(container) {
        container.querySelectorAll('[data-cms-click]').forEach(el => {
            const handler = el.getAttribute('data-cms-click');
            el.removeAttribute('data-cms-click');
            el.addEventListener('click', function(event) {
                try {
                    new Function('event', 'el', handler.replace(/\bthis\b/g, 'el'))(event, el);
                } catch(e) {}
            });
        });
    }

    function renderBlock(block) {
        if (block.html) return stripEventHandlers(block.html);
        const data = block.content || block;
        const type = block.type;

        switch(type) {
            case 'hero':
                const heroStyle = data.image ? `background-image: url('${data.image}'); background-size: cover; background-position: center;` : '';
                return `<div class="hero min-h-[40vh] bg-base-200" style="${heroStyle}">
                    <div class="hero-overlay bg-opacity-60"></div>
                    <div class="hero-content text-center">
                        <div class="max-w-2xl">
                            <h1 class="text-5xl font-bold">${data.title || ''}</h1>
                            <p class="py-6 text-xl opacity-90">${data.subtitle || ''}</p>
                            ${data.button ? `<a href="${data.url || '#'}" class="btn btn-primary btn-lg">${data.button}</a>` : ''}
                        </div>
                    </div>
                </div>`;

            case 'cta':
                return `<div class="bg-primary text-primary-content py-8 px-4 my-4 rounded-box">
                    <div class="max-w-4xl mx-auto text-center">
                        <h2 class="text-3xl font-bold mb-4">${data.title || ''}</h2>
                        <p class="text-lg opacity-90 mb-8">${data.text || ''}</p>
                        <a href="${data.url || '#'}" class="btn btn-secondary btn-lg">${data.button || 'Learn More'}</a>
                    </div>
                </div>`;

            case 'features':
                const featuresItems = (data.items || []).map(item => {
                    const iconHtml = renderIcon(item.icon || 'star');
                    return `<div class="card bg-base-100 shadow-xl">
                        <div class="card-body items-center text-center">
                            <div class="text-4xl mb-4">${iconHtml}</div>
                            <h3 class="card-title">${item.title || ''}</h3>
                            <p class="text-base-content/70">${item.description || ''}</p>
                        </div>
                    </div>`;
                }).join('');
                return `<div class="py-16">
                    ${data.title ? `<h2 class="text-3xl font-bold text-center mb-4">${data.title}</h2>` : ''}
                    ${data.subtitle ? `<p class="text-center text-base-content/70 mb-12 max-w-2xl mx-auto">${data.subtitle}</p>` : ''}
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">${featuresItems}</div>
                </div>`;

            case 'team':
                const teamItems = (data.items || []).map(item => {
                    const initials = (item.name || 'TM').split(' ').map(n => n[0]).join('').toUpperCase();
                    return `<div class="card bg-base-100 shadow-lg">
                        <div class="card-body items-center text-center">
                            <div class="avatar placeholder mb-4">
                                <div class="bg-primary text-primary-content rounded-full w-24">
                                    ${item.photo ? `<img src="${item.photo}" alt="${item.name}">` : `<span class="text-2xl">${initials}</span>`}
                                </div>
                            </div>
                            <h3 class="card-title">${item.name || ''}</h3>
                            <div class="badge badge-ghost">${item.role || ''}</div>
                            <p class="text-base-content/70 mt-2">${item.bio || ''}</p>
                            ${item.url ? `<a href="${item.url}" class="btn btn-primary btn-sm mt-2">View Profile</a>` : ''}
                        </div>
                    </div>`;
                }).join('');
                return `<div class="py-16">
                    ${data.title ? `<h2 class="text-3xl font-bold text-center mb-12">${data.title}</h2>` : ''}
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">${teamItems}</div>
                    <div class="text-center mt-8">
                        <button class="btn btn-outline btn-primary" data-action="generate-team-pages">
                            <span class="material-symbols-outlined">person_add</span>
                            Generate Team Member Pages
                        </button>
                    </div>
                </div>`;

            case 'testimonials':
                const testimonialsItems = (data.items || []).map(item => {
                    const initials = (item.name || item.author || 'A').split(' ').map(n => n[0]).join('').toUpperCase();
                    const stars = '⭐'.repeat(item.rating || 5);
                    return `<div class="card bg-base-100 shadow-lg">
                        <div class="card-body">
                            <div class="text-warning mb-2">${stars}</div>
                            <p class="italic text-base-content/80">"${item.quote || ''}"</p>
                            <div class="flex items-center gap-4 mt-4">
                                <div class="avatar placeholder">
                                    <div class="bg-primary text-primary-content rounded-full w-12"><span>${initials}</span></div>
                                </div>
                                <div>
                                    <div class="font-bold">${item.name || item.author || ''}</div>
                                    <div class="text-sm text-base-content/60">${item.role || ''}</div>
                                </div>
                            </div>
                        </div>
                    </div>`;
                }).join('');
                return `<div class="py-16 bg-base-200 -mx-4 px-4">
                    ${data.title ? `<h2 class="text-3xl font-bold text-center mb-12">${data.title}</h2>` : ''}
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 max-w-6xl mx-auto">${testimonialsItems}</div>
                </div>`;

            case 'pricing':
                const plans = (data.plans || data.items || []).map(item => {
                    const features = (item.features || []).map(f => `<li class="flex items-center gap-2"><svg class="w-5 h-5 text-success" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>${f}</li>`).join('');
                    const featured = item.featured ? 'border-primary border-2 scale-105' : '';
                    const badge = item.featured ? '<div class="badge badge-primary absolute top-4 right-4">Popular</div>' : '';
                    return `<div class="card bg-base-100 shadow-xl relative ${featured}">
                        ${badge}
                        <div class="card-body">
                            <h3 class="card-title text-xl">${item.name || ''}</h3>
                            <div class="my-4"><span class="text-4xl font-bold">${item.price || ''}</span><span class="text-base-content/60">${item.period || '/month'}</span></div>
                            <ul class="space-y-2 mb-6 flex-grow">${features}</ul>
                            <a href="${item.url || '#'}" class="btn ${item.featured ? 'btn-primary' : 'btn-outline btn-primary'} w-full">${item.button || 'Get Started'}</a>
                        </div>
                    </div>`;
                }).join('');
                return `<div class="py-16">
                    ${data.title ? `<h2 class="text-3xl font-bold text-center mb-4">${data.title}</h2>` : ''}
                    ${data.subtitle ? `<p class="text-center text-base-content/70 mb-12">${data.subtitle}</p>` : ''}
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8 items-start max-w-6xl mx-auto">${plans}</div>
                </div>`;

            case 'stats':
                const statsItems = (data.items || []).map(item => `
                    <div class="stat place-items-center">
                        ${item.icon ? `<div class="text-3xl mb-2">${renderIcon(item.icon)}</div>` : ''}
                        <div class="stat-value text-primary">${item.value || ''}</div>
                        <div class="stat-desc">${item.label || ''}</div>
                    </div>
                `).join('');
                return `<div class="py-12">
                    ${data.title ? `<h2 class="text-3xl font-bold text-center mb-8">${data.title}</h2>` : ''}
                    <div class="stats stats-vertical lg:stats-horizontal shadow w-full bg-base-100">${statsItems}</div>
                </div>`;

            case 'faq':
                const faqItems = (data.items || []).map((item, i) => `
                    <div class="collapse collapse-arrow bg-base-100 mb-2 shadow">
                        <input type="radio" name="faq-accordion" ${i === 0 ? 'checked' : ''}>
                        <div class="collapse-title text-lg font-medium">${item.question || ''}</div>
                        <div class="collapse-content"><p class="text-base-content/70">${item.answer || ''}</p></div>
                    </div>
                `).join('');
                return `<div class="py-16 max-w-3xl mx-auto">
                    ${data.title ? `<h2 class="text-3xl font-bold text-center mb-8">${data.title}</h2>` : ''}
                    ${faqItems}
                </div>`;

            case 'text':
                return `<div class="prose prose-lg max-w-none py-6">${data.content || ''}</div>`;

            case 'form':
            case 'contact':
                return `<div class="py-16">
                    <div class="card bg-base-100 shadow-xl max-w-2xl mx-auto">
                        <div class="card-body">
                            <h2 class="card-title text-2xl mb-6">${data.title || 'Contact Us'}</h2>
                            <div class="space-y-4">
                                <div class="form-control"><label class="label"><span class="label-text">Name</span></label><input type="text" class="input input-bordered w-full" placeholder="Your name"></div>
                                <div class="form-control"><label class="label"><span class="label-text">Email</span></label><input type="email" class="input input-bordered w-full" placeholder="your@email.com"></div>
                                <div class="form-control"><label class="label"><span class="label-text">Message</span></label><textarea class="textarea textarea-bordered h-32" placeholder="Your message..."></textarea></div>
                                <button class="btn btn-primary w-full">${data.button || 'Send Message'}</button>
                            </div>
                        </div>
                    </div>
                </div>`;

            case 'quote':
                return `<div class="py-12 px-4">
                    <figure class="max-w-3xl mx-auto bg-base-200 rounded-2xl p-8 shadow">
                        <blockquote class="text-xl italic font-medium text-base-content leading-relaxed">"${data.text || ''}"</blockquote>
                        ${data.author ? `<figcaption class="mt-6 flex items-center gap-3">
                            <div class="avatar placeholder"><div class="bg-primary text-primary-content rounded-full w-10"><span>${(data.author||'A')[0]}</span></div></div>
                            <div><div class="font-semibold">${data.author}</div>${data.role ? `<div class="text-sm text-base-content/60">${data.role}</div>` : ''}</div>
                        </figcaption>` : ''}
                    </figure>
                </div>`;

            case 'steps':
                const stepsItems = (data.items || []).map((item, i) => `
                    <li class="step step-primary">
                        <div class="text-left">
                            <div class="font-bold">${item.title || ''}</div>
                            <div class="text-sm text-base-content/70 mt-1">${item.description || ''}</div>
                        </div>
                    </li>`).join('');
                return `<div class="py-16">
                    ${data.title ? `<h2 class="text-3xl font-bold text-center mb-10">${data.title}</h2>` : ''}
                    <ul class="steps steps-vertical lg:steps-horizontal w-full gap-4">${stepsItems}</ul>
                </div>`;

            case 'checklist':
                const checkItems = (data.items || []).map(item => `
                    <li class="flex items-start gap-3 py-2">
                        <svg class="w-5 h-5 text-success flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                        <span>${typeof item === 'object' ? (item.text || item.title || '') : item}</span>
                    </li>`).join('');
                return `<div class="py-12 max-w-2xl mx-auto">
                    ${data.title ? `<h2 class="text-3xl font-bold mb-8">${data.title}</h2>` : ''}
                    <ul class="divide-y divide-base-200">${checkItems}</ul>
                </div>`;

            case 'timeline':
                const timelineItems = (data.items || []).map((item, i) => `
                    <li>
                        <div class="timeline-middle"><div class="bg-primary rounded-full w-4 h-4"></div></div>
                        <div class="${i % 2 === 0 ? 'timeline-start md:text-end' : 'timeline-end'} mb-10">
                            ${item.date ? `<time class="font-mono text-sm text-base-content/60">${item.date}</time>` : ''}
                            <div class="text-lg font-black">${item.title || ''}</div>
                            <p class="text-base-content/70">${item.description || ''}</p>
                        </div>
                        <hr class="bg-primary"/>
                    </li>`).join('');
                return `<div class="py-16">
                    ${data.title ? `<h2 class="text-3xl font-bold text-center mb-10">${data.title}</h2>` : ''}
                    <ul class="timeline timeline-snap-icon max-md:timeline-compact timeline-vertical max-w-3xl mx-auto">${timelineItems}</ul>
                </div>`;

            case 'cards':
                const cardsItems = (data.items || []).map(item => {
                    const iconHtml = item.icon ? renderIcon(item.icon) : '';
                    return `<div class="card bg-base-100 shadow-lg hover:shadow-xl transition-shadow">
                        <div class="card-body">
                            ${iconHtml ? `<div class="text-3xl text-primary mb-3">${iconHtml}</div>` : ''}
                            <h3 class="card-title">${item.title || ''}</h3>
                            <p class="text-base-content/70">${item.description || ''}</p>
                            ${item.button ? `<div class="card-actions mt-4"><a href="${item.url || '#'}" class="btn btn-primary btn-sm">${item.button}</a></div>` : ''}
                        </div>
                    </div>`;
                }).join('');
                return `<div class="py-16">
                    ${data.title ? `<h2 class="text-3xl font-bold text-center mb-4">${data.title}</h2>` : ''}
                    ${data.subtitle ? `<p class="text-center text-base-content/70 mb-12 max-w-2xl mx-auto">${data.subtitle}</p>` : ''}
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">${cardsItems}</div>
                </div>`;

            case 'gallery':
                const cols = data.columns || 3;
                const galleryItems = (data.images || data.items || []).map(img => `
                    <div class="rounded-lg overflow-hidden shadow">
                        <img src="${img.url || ''}" alt="${img.alt || ''}" class="w-full h-48 object-cover hover:scale-105 transition-transform">
                    </div>`).join('');
                return `<div class="py-12">
                    ${data.title ? `<h2 class="text-3xl font-bold text-center mb-8">${data.title}</h2>` : ''}
                    <div class="grid grid-cols-2 md:grid-cols-${cols} gap-4">${galleryItems}</div>
                </div>`;

            case 'newsletter':
                return `<div class="py-16 bg-base-200 -mx-4 px-4">
                    <div class="max-w-xl mx-auto text-center">
                        ${data.title ? `<h2 class="text-3xl font-bold mb-4">${data.title}</h2>` : ''}
                        ${data.text ? `<p class="text-base-content/70 mb-6">${data.text}</p>` : ''}
                        <div class="flex gap-2 max-w-md mx-auto">
                            <input type="email" class="input input-bordered flex-1" placeholder="${data.placeholder || 'your@email.com'}">
                            <button class="btn btn-primary">${data.button || 'Subscribe'}</button>
                        </div>
                    </div>
                </div>`;

            case 'accordion':
                const accordionItems = (data.items || []).map((item, i) => `
                    <div class="collapse collapse-arrow bg-base-100 mb-2 shadow">
                        <input type="radio" name="accordion-preview" ${i === 0 ? 'checked' : ''}>
                        <div class="collapse-title text-lg font-medium">${item.title || ''}</div>
                        <div class="collapse-content"><div class="prose">${item.content || ''}</div></div>
                    </div>`).join('');
                return `<div class="py-16 max-w-3xl mx-auto">
                    ${data.title ? `<h2 class="text-3xl font-bold text-center mb-8">${data.title}</h2>` : ''}
                    ${accordionItems}
                </div>`;

            case 'logo_cloud':
                const logoItems = (data.logos || data.items || []).map(logo => `
                    <div class="flex items-center justify-center p-4 grayscale hover:grayscale-0 transition-all">
                        ${logo.url ? `<img src="${logo.url}" alt="${logo.name || ''}" class="h-12 object-contain">` : `<span class="text-lg font-bold text-base-content/40">${logo.name || ''}</span>`}
                    </div>`).join('');
                return `<div class="py-12">
                    ${data.title ? `<p class="text-center text-base-content/50 mb-8 uppercase tracking-widest text-sm">${data.title}</p>` : ''}
                    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">${logoItems}</div>
                </div>`;

            case 'image':
                return `<div class="py-8">
                    ${data.url ? `<figure><img src="${data.url}" alt="${data.alt || ''}" class="rounded-lg shadow-lg mx-auto max-w-full">
                    ${data.caption ? `<figcaption class="text-center text-sm text-base-content/60 mt-2">${data.caption}</figcaption>` : ''}</figure>` : '<div class="bg-base-200 rounded-lg h-48 flex items-center justify-center text-base-content/40">Image</div>'}
                </div>`;

            case 'divider':
                return `<div class="py-4"><hr class="border-base-300"></div>`;

            case 'spacer':
                return `<div style="height:${data.height || '60'}px"></div>`;

            case 'tabs':
                const tabItems = (data.items || data.tabs || []);
                const tabHeaders = tabItems.map((tab, i) => `<a role="tab" class="tab${i === 0 ? ' tab-active' : ''}">${tab.title || tab.label || ''}</a>`).join('');
                const tabContent = tabItems[0] ? `<div class="tab-content bg-base-100 border-base-300 rounded-box p-6"><div class="prose">${tabItems[0].content || ''}</div></div>` : '';
                return `<div class="py-12">
                    ${data.title ? `<h2 class="text-3xl font-bold text-center mb-8">${data.title}</h2>` : ''}
                    <div class="tabs tabs-bordered">${tabHeaders}</div>
                    ${tabContent}
                </div>`;

            case 'comparison':
                const compItems = (data.items || data.columns || []).map(col => {
                    const feats = (col.features || []).map(f => `<li class="flex items-center gap-2 py-1"><svg class="w-4 h-4 text-success flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>${f}</li>`).join('');
                    return `<div class="card bg-base-100 shadow-lg">
                        <div class="card-body">
                            <h3 class="card-title text-xl">${col.title || col.name || ''}</h3>
                            ${col.price ? `<div class="text-3xl font-bold text-primary my-2">${col.price}</div>` : ''}
                            <ul class="space-y-1 mt-4">${feats}</ul>
                        </div>
                    </div>`;
                }).join('');
                return `<div class="py-16">
                    ${data.title ? `<h2 class="text-3xl font-bold text-center mb-10">${data.title}</h2>` : ''}
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 max-w-4xl mx-auto">${compItems}</div>
                </div>`;

            case 'table':
                const headers = (data.headers || data.columns || []).map(h => `<th>${h}</th>`).join('');
                const rows = (data.rows || []).map(row => `<tr>${(Array.isArray(row) ? row : Object.values(row)).map(cell => `<td>${cell}</td>`).join('')}</tr>`).join('');
                return `<div class="py-12 overflow-x-auto">
                    ${data.title ? `<h2 class="text-2xl font-bold mb-6">${data.title}</h2>` : ''}
                    <table class="table table-zebra w-full">
                        ${headers ? `<thead><tr>${headers}</tr></thead>` : ''}
                        <tbody>${rows}</tbody>
                    </table>
                </div>`;

            case 'social':
                const socialLinks = (data.items || data.links || []).map(link => `
                    <a href="${link.url || '#'}" class="btn btn-circle btn-outline btn-primary" title="${link.platform || link.label || ''}">
                        ${renderIcon(link.icon || link.platform || 'link')}
                    </a>`).join('');
                return `<div class="py-12 text-center">
                    ${data.title ? `<h2 class="text-2xl font-bold mb-6">${data.title}</h2>` : ''}
                    ${data.text ? `<p class="text-base-content/70 mb-6">${data.text}</p>` : ''}
                    <div class="flex flex-wrap gap-4 justify-center">${socialLinks}</div>
                </div>`;

            case 'html':
                return data.html || data.content || '';

            default:
                return `<div class="py-8 text-center text-base-content/50">Block type: ${type}</div>`;
        }
    }

    function openEditor(pageIndex, blockIndex) {
        currentEdit = { pageIndex, blockIndex };
        const block = planData.pages[pageIndex].blocks[blockIndex];
        const data = block.content || block;
        const type = block.type;

        document.getElementById('modalTitle').textContent = `Edit ${type.charAt(0).toUpperCase() + type.slice(1)} Block`;
        const editor = document.getElementById('editorContent');

        // AI-generated blocks store their output as raw HTML — use the HTML editor directly
        if (block.html) {
            editor.innerHTML = getHtmlBlockEditor({ html: block.html, anchor: block.anchor || '' });
        } else {
            editor.innerHTML = getEditorForm(type, data);
        }
        document.getElementById('blockEditorModal').classList.add('is-active');
    }

    function getEditorForm(type, data) {
        const linkOptions = commonLinks.map(l => `<option value="${l.url}">${l.label}</option>`).join('');
        const pageOptions = planData.pages.map(p => `<option value="/${p.slug === 'home' ? '' : p.slug}">${p.title}</option>`).join('');

        switch(type) {
            case 'hero':
                return `
                    <div class="form-control"><label class="label"><span class="label-text">Title</span></label>
                        <input type="text" name="title" class="input input-bordered" value="${escapeHtml(data.title || '')}"></div>
                    <div class="form-control"><label class="label"><span class="label-text">Subtitle</span></label>
                        <textarea name="subtitle" class="textarea textarea-bordered">${escapeHtml(data.subtitle || '')}</textarea></div>
                    <div class="form-control"><label class="label"><span class="label-text">Background Image</span></label>
                        <div class="flex gap-2">
                            <input type="text" name="image" class="input input-bordered flex-1" value="${escapeHtml(data.image || '')}" readonly>
                            <button type="button" class="btn btn-primary" data-action="open-gallery" data-target="image">
                                <span class="material-symbols-outlined">image</span>
                                Browse
                            </button>
                            ${data.image ? `<button type="button" class="btn btn-ghost btn-error" data-action="clear-image" data-target="image">✕</button>` : ''}
                        </div>
                        ${data.image ? `<img src="${escapeHtml(data.image)}" class="mt-2 max-h-32 rounded-lg" id="image-preview">` : '<div id="image-preview"></div>'}
                    </div>
                    <div class="form-control"><label class="label"><span class="label-text">Button Text</span></label>
                        <input type="text" name="button" class="input input-bordered" value="${escapeHtml(data.button || '')}"></div>
                    <div class="form-control"><label class="label"><span class="label-text">Button Link</span></label>
                        <select name="url" class="select select-bordered"><option value="">Custom URL</option>${linkOptions}${pageOptions}</select>
                        <input type="text" name="url_custom" class="input input-bordered mt-2" value="${escapeHtml(data.url || '')}" placeholder="Or enter custom URL"></div>`;

            case 'cta':
                return `
                    <div class="form-control"><label class="label"><span class="label-text">Title</span></label>
                        <input type="text" name="title" class="input input-bordered" value="${escapeHtml(data.title || '')}"></div>
                    <div class="form-control"><label class="label"><span class="label-text">Text</span></label>
                        <textarea name="text" class="textarea textarea-bordered">${escapeHtml(data.text || '')}</textarea></div>
                    <div class="form-control"><label class="label"><span class="label-text">Button Text</span></label>
                        <input type="text" name="button" class="input input-bordered" value="${escapeHtml(data.button || 'Learn More')}"></div>
                    <div class="form-control"><label class="label"><span class="label-text">Button Link</span></label>
                        <select name="url" class="select select-bordered" data-action="url-preset">
                            <option value="">Select destination...</option>${linkOptions}${pageOptions}</select>
                        <p class="text-xs text-base-content/60 mt-1">Or enter custom:</p>
                        <input type="text" name="url_custom" class="input input-bordered mt-1" value="${escapeHtml(data.url || '')}" placeholder="Custom URL"></div>`;

            case 'team':
                const teamItemsHtml = (data.items || []).map((item, i) => `
                    <div class="card bg-base-200 p-4 mb-4" data-item-index="${i}">
                        <div class="flex justify-between items-center mb-2">
                            <span class="font-bold">Team Member ${i+1}</span>
                            <button type="button" class="btn btn-ghost btn-xs text-error" data-action="remove-item" data-index="${i}">Remove</button>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div class="form-control"><label class="label label-text text-xs">Name</label>
                                <input type="text" name="items[${i}][name]" class="input input-bordered input-sm" value="${escapeHtml(item.name || '')}"></div>
                            <div class="form-control"><label class="label label-text text-xs">Role</label>
                                <input type="text" name="items[${i}][role]" class="input input-bordered input-sm" value="${escapeHtml(item.role || '')}"></div>
                        </div>
                        <div class="form-control mt-2"><label class="label label-text text-xs">Bio</label>
                            <textarea name="items[${i}][bio]" class="textarea textarea-bordered textarea-sm">${escapeHtml(item.bio || '')}</textarea></div>
                        <div class="form-control mt-2"><label class="label label-text text-xs">Photo</label>
                            <div class="flex gap-2">
                                <input type="text" name="items[${i}][photo]" class="input input-bordered input-sm flex-1" value="${escapeHtml(item.photo || '')}" readonly>
                                <button type="button" class="btn btn-primary btn-sm" data-action="open-gallery" data-target="items[${i}][photo]">
                                    <span class="material-symbols-outlined text-sm">image</span>
                                </button>
                                ${item.photo ? `<button type="button" class="btn btn-ghost btn-sm text-error" data-action="clear-image" data-target="items[${i}][photo]">✕</button>` : ''}
                            </div></div>
                        <div class="form-control mt-2"><label class="label label-text text-xs">Profile Page Link</label>
                            <input type="text" name="items[${i}][url]" class="input input-bordered input-sm" value="${escapeHtml(item.url || '')}" placeholder="/team/name"></div>
                    </div>
                `).join('');
                return `
                    <div class="form-control"><label class="label"><span class="label-text">Section Title</span></label>
                        <input type="text" name="title" class="input input-bordered" value="${escapeHtml(data.title || '')}"></div>
                    <div class="divider">Team Members</div>
                    <div id="itemsContainer">${teamItemsHtml}</div>
                    <button type="button" class="btn btn-outline btn-sm" data-action="add-team-member">+ Add Team Member</button>`;

            case 'pricing':
                const plansHtml = (data.plans || data.items || []).map((item, i) => `
                    <div class="card bg-base-200 p-4 mb-4" data-item-index="${i}">
                        <div class="flex justify-between items-center mb-2">
                            <span class="font-bold">Plan ${i+1}</span>
                            <button type="button" class="btn btn-ghost btn-xs text-error" data-action="remove-item" data-index="${i}">Remove</button>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div class="form-control"><label class="label label-text text-xs">Name</label>
                                <input type="text" name="items[${i}][name]" class="input input-bordered input-sm" value="${escapeHtml(item.name || '')}"></div>
                            <div class="form-control"><label class="label label-text text-xs">Price</label>
                                <input type="text" name="items[${i}][price]" class="input input-bordered input-sm" value="${escapeHtml(item.price || '')}"></div>
                        </div>
                        <div class="form-control mt-2"><label class="label label-text text-xs">Period</label>
                            <input type="text" name="items[${i}][period]" class="input input-bordered input-sm" value="${escapeHtml(item.period || '/month')}"></div>
                        <div class="form-control mt-2"><label class="label label-text text-xs">Features (one per line)</label>
                            <textarea name="items[${i}][features]" class="textarea textarea-bordered textarea-sm">${(item.features || []).join('\n')}</textarea></div>
                        <div class="form-control mt-2"><label class="label label-text text-xs">Button Text</label>
                            <input type="text" name="items[${i}][button]" class="input input-bordered input-sm" value="${escapeHtml(item.button || 'Get Started')}"></div>
                        <div class="form-control mt-2"><label class="label label-text text-xs">Button Link</label>
                            <input type="text" name="items[${i}][url]" class="input input-bordered input-sm" value="${escapeHtml(item.url || '#')}"></div>
                        <div class="form-control mt-2">
                            <label class="label cursor-pointer justify-start gap-2">
                                <input type="checkbox" name="items[${i}][featured]" class="checkbox checkbox-primary" ${item.featured ? 'checked' : ''}>
                                <span class="label-text">Featured/Popular</span>
                            </label>
                        </div>
                    </div>
                `).join('');
                return `
                    <div class="form-control"><label class="label"><span class="label-text">Section Title</span></label>
                        <input type="text" name="title" class="input input-bordered" value="${escapeHtml(data.title || '')}"></div>
                    <div class="form-control"><label class="label"><span class="label-text">Subtitle</span></label>
                        <input type="text" name="subtitle" class="input input-bordered" value="${escapeHtml(data.subtitle || '')}"></div>
                    <div class="divider">Plans</div>
                    <div id="itemsContainer">${plansHtml}</div>
                    <button type="button" class="btn btn-outline btn-sm" data-action="add-pricing-plan">+ Add Plan</button>`;

            case 'features':
            case 'stats':
            case 'testimonials':
            case 'faq':
            case 'cards':
            case 'carousel':
            case 'accordion':
            case 'tabs':
            case 'checklist':
            case 'timeline':
            case 'steps':
            case 'social':
            case 'logo_cloud':
                return getItemsEditor(type, data);

            case 'gallery': {
                const imgs = Array.isArray(data.images)
                    ? data.images.map(i => (typeof i === 'object' ? (i.url || '') : i)).filter(Boolean).join('\n')
                    : (data.images || '');
                const cols = parseInt(data.columns) || 3;
                return `
                    <div class="form-control"><label class="label"><span class="label-text">Title</span></label>
                        <input type="text" name="title" class="input input-bordered" value="${escapeHtml(data.title || '')}"></div>
                    <div class="form-control">
                        <div class="label" style="padding-bottom:4px;justify-content:space-between;">
                            <span class="label-text">Images (one URL per line)</span>
                            <button type="button" class="btn btn-xs btn-outline gallery-add-image-btn">Add Image</button>
                        </div>
                        <div class="gallery-images-field">
                            <textarea name="images" class="textarea textarea-bordered w-full" rows="5" placeholder="/assets/...">${escapeHtml(imgs)}</textarea>
                        </div>
                    </div>
                    <div class="form-control"><label class="label"><span class="label-text">Columns</span></label>
                        <select name="columns" class="select select-bordered">
                            <option value="2" ${cols===2?'selected':''}>2</option>
                            <option value="3" ${cols===3?'selected':''}>3</option>
                            <option value="4" ${cols===4?'selected':''}>4</option>
                        </select></div>`;
            }

            case 'map':
                return `
                    <div class="form-control"><label class="label"><span class="label-text">Title</span></label>
                        <input type="text" name="title" class="input input-bordered" value="${escapeHtml(data.title || '')}" placeholder="Find Us"></div>
                    <div class="form-control"><label class="label"><span class="label-text">Address</span></label>
                        <input type="text" name="address" class="input input-bordered" value="${escapeHtml(data.address || '')}" placeholder="123 Main St, City, Country"></div>
                    <div class="form-control"><label class="label"><span class="label-text">Google Maps Embed URL</span></label>
                        <input type="text" name="embed" class="input input-bordered" value="${escapeHtml(data.embed || '')}" placeholder="https://maps.google.com/maps?q=...&output=embed">
                        <label class="label"><span class="label-text-alt text-gray-400">Go to Google Maps → Share → Embed a map → copy the src URL</span></label></div>`;

            case 'text':
                return `<div class="form-control"><label class="label"><span class="label-text">Content (HTML)</span></label>
                    <textarea name="content" class="textarea textarea-bordered h-48">${escapeHtml(data.content || '')}</textarea></div>`;

            default:
                if (data.html !== undefined) return getHtmlBlockEditor(data);
                return `<div class="alert alert-info">Editor for ${type} blocks coming soon. You can edit the raw JSON:</div>
                    <textarea name="raw_json" class="textarea textarea-bordered w-full h-48 font-mono text-sm">${escapeHtml(JSON.stringify(data, null, 2))}</textarea>`;
        }
    }

    function getHtmlBlockEditor(data) {
        const html = data.html || '';
        const anchor = data.anchor || '';
        return `
            <div class="form-control" style="margin-bottom:12px;">
                <label class="label"><span class="label-text">Section Anchor ID <span class="text-xs text-gray-400">(for nav links like /#features)</span></span></label>
                <input type="text" name="anchor" class="input input-bordered" placeholder="e.g. features" value="${escapeHtml(anchor)}">
            </div>
            <div style="display:flex;align-items:center;margin-bottom:8px;">
                <label class="label" style="margin:0;"><span class="label-text">HTML Source</span></label>
                <button type="button" data-action="toggle-html-preview" class="btn btn-xs btn-ghost" style="margin-left:auto;">&#128065; Preview</button>
            </div>
            <textarea name="html_source" class="textarea textarea-bordered w-full font-mono text-sm" style="height:360px;font-size:12px;line-height:1.5;tab-size:2;white-space:pre;resize:vertical;">${escapeHtml(html)}</textarea>
            <iframe id="htmlPreviewFrame" style="display:none;width:100%;height:360px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;"></iframe>`;
    }

    function toggleHtmlPreview(btn) {
        const modal = btn.closest('.modal-box') || btn.closest('[id$="Modal"]') || document.querySelector('.modal-box');
        const textarea = modal ? modal.querySelector('[name="html_source"]') : null;
        const frame = modal ? modal.querySelector('#htmlPreviewFrame') : null;
        if (!textarea || !frame) return;
        if (frame.style.display === 'none') {
            frame.style.display = 'block';
            textarea.style.display = 'none';
            frame.srcdoc = `<!DOCTYPE html><html><head><link rel="stylesheet" href="/cdn/bulma.min.css"><link rel="stylesheet" href="/cdn/material-icons.css"><link rel="stylesheet" href="/css?v=app.dev"><style>body{margin:0;overflow:auto;}</style></head><body>${textarea.value}</body></html>`;
            btn.textContent = '\u270F\uFE0F Edit HTML';
        } else {
            frame.style.display = 'none';
            textarea.style.display = 'block';
            btn.innerHTML = '&#128065; Preview';
        }
    }

    // Event delegation for data-action="toggle-html-preview"
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('[data-action="toggle-html-preview"]');
        if (btn) toggleHtmlPreview(btn);
    });

    function getItemsEditor(type, data) {
        const items = data.items || [];
        const fieldMap = {
            features:     ['icon', 'title', 'description'],
            stats:        ['icon', 'value', 'label'],
            testimonials: ['name', 'role', 'quote', 'rating'],
            faq:          ['question', 'answer'],
            accordion:    ['title', 'content'],
            tabs:         ['title', 'content'],
            checklist:    ['text'],
            timeline:     ['date', 'title', 'text'],
            steps:        ['icon', 'title', 'description'],
            social:       ['platform', 'url'],
            logo_cloud:   ['name', 'url'],
            cards:        ['image', 'icon', 'title', 'description', 'button', 'url'],
            carousel:     ['image', 'title', 'text'],
        };
        const fields = fieldMap[type] || ['title', 'description'];

        const itemsHtml = items.map((item, i) => {
            const fieldsHtml = fields.map(f => {
                const isTextarea = ['description', 'quote', 'answer', 'bio'].includes(f);
                const isIcon = f === 'icon';
                const isImage = f === 'image' || (type === 'logo_cloud' && f === 'url');
                const val = escapeHtml(item[f] || '');

                if (isImage) {
                    const label = f === 'url' ? 'Logo Image' : 'Image';
                    return `<div class="form-control"><label class="label label-text text-xs">${label}</label>
                        <div class="flex gap-2">
                            <input type="text" name="items[${i}][${f}]" class="input input-bordered input-sm flex-1" value="${val}" readonly>
                            <button type="button" class="btn btn-primary btn-sm" data-action="open-gallery" data-target="items[${i}][${f}]">
                                <span class="material-symbols-outlined text-sm">image</span>
                            </button>
                        </div>
                        ${val ? `<img src="${val}" class="mt-2 max-h-20 rounded">` : ''}</div>`;
                }

                if (isIcon) {
                    // Icon field with Material Icons dropdown
                    return `<div class="form-control"><label class="label label-text text-xs">Icon</label>
                        <div class="flex gap-2 items-center">
                            <select name="items[${i}][${f}]" class="select select-bordered select-sm flex-1">
                                ${getIconOptions(val)}
                            </select>
                            <span class="material-symbols-outlined text-2xl text-primary icon-preview">${val || 'star'}</span>
                        </div>
                    </div>`;
                }

                return `<div class="form-control"><label class="label label-text text-xs">${f.charAt(0).toUpperCase() + f.slice(1)}</label>
                    ${isTextarea ? `<textarea name="items[${i}][${f}]" class="textarea textarea-bordered textarea-sm">${val}</textarea>`
                                 : `<input type="text" name="items[${i}][${f}]" class="input input-bordered input-sm" value="${val}">`}</div>`;
            }).join('');
            return `<div class="card bg-base-200 p-4 mb-4" data-item-index="${i}">
                <div class="flex justify-between items-center mb-2"><span class="font-bold">Item ${i+1}</span>
                    <button type="button" class="btn btn-ghost btn-xs text-error" data-action="remove-item" data-index="${i}">Remove</button></div>
                ${fieldsHtml}
            </div>`;
        }).join('');

        return `<div class="form-control"><label class="label"><span class="label-text">Section Title</span></label>
                <input type="text" name="title" class="input input-bordered" value="${escapeHtml(data.title || '')}"></div>
            <div class="divider">Items</div>
            <div id="itemsContainer">${itemsHtml}</div>
            <button type="button" class="btn btn-outline btn-sm" data-action="add-generic-item" data-type="${type}">+ Add Item</button>`;
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function removeItem(index) {
        document.querySelector(`[data-item-index="${index}"]`)?.remove();
        // Re-index remaining items
        document.querySelectorAll('#itemsContainer > div').forEach((el, i) => {
            el.dataset.itemIndex = i;
            el.querySelectorAll('[name*="items["]').forEach(input => {
                input.name = input.name.replace(/items\[\d+\]/, `items[${i}]`);
            });
        });
    }

    function addTeamMember() {
        const container = document.getElementById('itemsContainer');
        const i = container.children.length;
        container.insertAdjacentHTML('beforeend', `
            <div class="card bg-base-200 p-4 mb-4" data-item-index="${i}">
                <div class="flex justify-between items-center mb-2"><span class="font-bold">Team Member ${i+1}</span>
                    <button type="button" class="btn btn-ghost btn-xs text-error" data-action="remove-item" data-index="${i}">Remove</button></div>
                <div class="grid grid-cols-2 gap-2">
                    <div class="form-control"><label class="label label-text text-xs">Name</label><input type="text" name="items[${i}][name]" class="input input-bordered input-sm"></div>
                    <div class="form-control"><label class="label label-text text-xs">Role</label><input type="text" name="items[${i}][role]" class="input input-bordered input-sm"></div>
                </div>
                <div class="form-control mt-2"><label class="label label-text text-xs">Bio</label><textarea name="items[${i}][bio]" class="textarea textarea-bordered textarea-sm"></textarea></div>
                <div class="form-control mt-2"><label class="label label-text text-xs">Photo</label>
                    <div class="flex gap-2">
                        <input type="text" name="items[${i}][photo]" class="input input-bordered input-sm flex-1" readonly>
                        <button type="button" class="btn btn-primary btn-sm" data-action="open-gallery" data-target="items[${i}][photo]">
                            <span class="material-symbols-outlined text-sm">image</span>
                        </button>
                    </div></div>
            </div>
        `);
    }

    function addPricingPlan() {
        const container = document.getElementById('itemsContainer');
        const i = container.children.length;
        container.insertAdjacentHTML('beforeend', `
            <div class="card bg-base-200 p-4 mb-4" data-item-index="${i}">
                <div class="flex justify-between items-center mb-2"><span class="font-bold">Plan ${i+1}</span>
                    <button type="button" class="btn btn-ghost btn-xs text-error" data-action="remove-item" data-index="${i}">Remove</button></div>
                <div class="grid grid-cols-2 gap-2">
                    <div class="form-control"><label class="label label-text text-xs">Name</label><input type="text" name="items[${i}][name]" class="input input-bordered input-sm"></div>
                    <div class="form-control"><label class="label label-text text-xs">Price</label><input type="text" name="items[${i}][price]" class="input input-bordered input-sm"></div>
                </div>
                <div class="form-control mt-2"><label class="label label-text text-xs">Period</label><input type="text" name="items[${i}][period]" class="input input-bordered input-sm" value="/month"></div>
                <div class="form-control mt-2"><label class="label label-text text-xs">Features (one per line)</label><textarea name="items[${i}][features]" class="textarea textarea-bordered textarea-sm"></textarea></div>
                <div class="form-control mt-2"><label class="label label-text text-xs">Button</label><input type="text" name="items[${i}][button]" class="input input-bordered input-sm" value="Get Started"></div>
            </div>
        `);
    }

    function addGenericItem(type) {
        const fieldMap = { features: ['icon', 'title', 'description'], stats: ['icon', 'value', 'label'], testimonials: ['name', 'role', 'quote', 'rating'], faq: ['question', 'answer'], accordion: ['title', 'content'], tabs: ['title', 'content'], checklist: ['text'], timeline: ['date', 'title', 'text'], steps: ['icon', 'title', 'description'], social: ['platform', 'url'], logo_cloud: ['name', 'url'], cards: ['image', 'icon', 'title', 'description', 'button', 'url'], carousel: ['image', 'title', 'text'] };
        const fields = fieldMap[type] || ['title', 'description'];
        const container = document.getElementById('itemsContainer');
        const i = container.children.length;
        const fieldsHtml = fields.map(f => {
            const isTextarea = ['description', 'quote', 'answer', 'content', 'text'].includes(f);
            const isIcon = f === 'icon';
            const isImage = f === 'image' || (type === 'logo_cloud' && f === 'url');

            if (isImage) {
                const label = f === 'url' ? 'Logo Image' : 'Image';
                return `<div class="form-control"><label class="label label-text text-xs">${label}</label>
                    <div class="flex gap-2">
                        <input type="text" name="items[${i}][${f}]" class="input input-bordered input-sm flex-1" readonly>
                        <button type="button" class="btn btn-primary btn-sm" data-action="open-gallery" data-target="items[${i}][${f}]">
                            <span class="material-symbols-outlined text-sm">image</span>
                        </button>
                    </div></div>`;
            }

            if (isIcon) {
                return `<div class="form-control"><label class="label label-text text-xs">Icon</label>
                    <div class="flex gap-2 items-center">
                        <select name="items[${i}][${f}]" class="select select-bordered select-sm flex-1">
                            ${getIconOptions('star')}
                        </select>
                        <span class="material-symbols-outlined text-2xl text-primary icon-preview">star</span>
                    </div></div>`;
            }

            return `<div class="form-control"><label class="label label-text text-xs">${f.charAt(0).toUpperCase() + f.slice(1)}</label>
                ${isTextarea ? `<textarea name="items[${i}][${f}]" class="textarea textarea-bordered textarea-sm"></textarea>`
                             : `<input type="text" name="items[${i}][${f}]" class="input input-bordered input-sm">`}</div>`;
        }).join('');
        container.insertAdjacentHTML('beforeend', `<div class="card bg-base-200 p-4 mb-4" data-item-index="${i}">
            <div class="flex justify-between items-center mb-2"><span class="font-bold">Item ${i+1}</span>
                <button type="button" class="btn btn-ghost btn-xs text-error" data-action="remove-item" data-index="${i}">Remove</button></div>
            ${fieldsHtml}</div>`);
    }

    // Save block changes
    document.getElementById('saveBlockBtn').addEventListener('click', async () => {
        const block = planData.pages[currentEdit.pageIndex].blocks[currentEdit.blockIndex];
        const type = block.type;
        const form = document.getElementById('editorContent');
        const newData = {};

        // Collect form data
        form.querySelectorAll('input:not([type="checkbox"]), textarea, select').forEach(el => {
            if (el.name && !el.name.includes('[')) {
                if (el.name === 'url_custom') {
                    if (el.value && !newData.url) newData.url = el.value;
                } else if (el.name === 'url' && el.value) {
                    newData.url = el.value;
                } else if (el.name !== 'url') {
                    newData[el.name] = el.value;
                }
            }
        });

        // Handle URL custom field fallback
        const urlSelect = form.querySelector('[name="url"]');
        const urlCustom = form.querySelector('[name="url_custom"]');
        if (urlCustom && urlCustom.value) {
            newData.url = urlCustom.value;
        } else if (urlSelect && urlSelect.value) {
            newData.url = urlSelect.value;
        }

        // Collect items array
        const items = [];
        form.querySelectorAll('#itemsContainer > div').forEach((card, i) => {
            const item = {};
            card.querySelectorAll('input:not([type="checkbox"]), textarea').forEach(el => {
                const match = el.name.match(/items\[\d+\]\[(\w+)\]/);
                if (match) {
                    if (match[1] === 'features') {
                        item[match[1]] = el.value.split('\n').filter(l => l.trim());
                    } else {
                        item[match[1]] = el.value;
                    }
                }
            });
            card.querySelectorAll('input[type="checkbox"]').forEach(el => {
                const match = el.name.match(/items\[\d+\]\[(\w+)\]/);
                if (match) item[match[1]] = el.checked;
            });
            if (Object.keys(item).length > 0) items.push(item);
        });

        if (items.length > 0) {
            newData.items = items;
            if (type === 'pricing') newData.plans = items;
        }

        // HTML block editor — save as {html, anchor}
        const htmlSource = form.querySelector('[name="html_source"]');
        if (htmlSource) {
            const anchor = (form.querySelector('[name="anchor"]')?.value || '').trim();
            // Keep html/anchor at BOTH block.content (used by applyApproval) and top-level
            block.content = anchor ? { html: htmlSource.value, anchor } : { html: htmlSource.value };
            block.html = htmlSource.value;
            if (anchor) block.anchor = anchor; else delete block.anchor;
        } else {
            // Handle raw JSON
            const rawJson = form.querySelector('[name="raw_json"]');
            if (rawJson) {
                try { Object.assign(newData, JSON.parse(rawJson.value)); } catch(e) {}
            }
            // Update block in planData
            block.content = newData;
        }

        // Save to server
        const formData = new FormData();
        formData.append('_csrf', CSRF_TOKEN);
        formData.append('page_index', currentEdit.pageIndex);
        formData.append('block_index', currentEdit.blockIndex);
        formData.append('block_data', JSON.stringify(block));

        try {
            const resp = await fetch(`/admin/approvals/${QUEUE_ID}/update-block`, { method: 'POST', body: formData });
            const result = await resp.json();
            if (result.success) {
                // Re-render block
                const pageContent = document.getElementById('page-' + currentEdit.pageIndex);
                pageContent.innerHTML = renderPageBlocks(planData.pages[currentEdit.pageIndex], currentEdit.pageIndex);
                reattachEventListeners(pageContent);
                document.getElementById('blockEditorModal').classList.remove('is-active');
            } else {
                alert('Error: ' + (result.error || 'Unknown error'));
            }
        } catch(e) {
            alert('Failed to save: ' + e.message);
        }
    });

    // Generate team member pages
    async function generateTeamPages(event) {
        event.stopPropagation();
        const block = event.target.closest('.editable-block');
        const pageIndex = parseInt(block.dataset.page);
        const blockIndex = parseInt(block.dataset.block);

        if (!confirm('Generate individual pages for each team member? This will add new pages to the site plan.')) return;

        const formData = new FormData();
        formData.append('_csrf', CSRF_TOKEN);
        formData.append('page_index', pageIndex);
        formData.append('block_index', blockIndex);

        try {
            const resp = await fetch(`/admin/approvals/${QUEUE_ID}/generate-team-pages`, { method: 'POST', body: formData });
            const result = await resp.json();
            if (result.success) {
                alert(result.message);
                location.reload();
            } else {
                alert('Error: ' + (result.error || 'Unknown error'));
            }
        } catch(e) {
            alert('Failed: ' + e.message);
        }
    }

    // Event delegation for all click handlers
    document.addEventListener('click', function(e) {
        const target = e.target.closest('[data-action]');
        if (!target) return;

        const action = target.dataset.action;

        switch(action) {
            case 'close-modal':
                document.getElementById('blockEditorModal').classList.remove('is-active');
                break;
            case 'remove-item':
                removeItem(parseInt(target.dataset.index));
                break;
            case 'add-team-member':
                addTeamMember();
                break;
            case 'add-pricing-plan':
                addPricingPlan();
                break;
            case 'add-generic-item':
                addGenericItem(target.dataset.type);
                break;
            case 'generate-team-pages':
                e.stopPropagation();
                generateTeamPages();
                break;
            case 'open-gallery':
                e.stopPropagation();
                openGallery(target.dataset.target);
                break;
            case 'clear-image':
                e.stopPropagation();
                clearImage(target.dataset.target);
                break;
        }
    });

    // Change event delegation (url-preset select syncs value to custom URL input)
    document.addEventListener('change', function(e) {
        if (e.target.dataset.action === 'url-preset') {
            const container = e.target.closest('.form-control');
            const customInput = container && container.querySelector('input[name="url_custom"]');
            if (customInput) customInput.value = e.target.value || '';
        }
    });

    // Event delegation for icon select changes - update preview
    document.addEventListener('change', function(e) {
        if (e.target.matches('select[name*="[icon]"]')) {
            const preview = e.target.closest('.flex').querySelector('.icon-preview');
            if (preview) {
                preview.textContent = e.target.value;
            }
        }
    });

    // Click handler for editable blocks
    document.getElementById('pageContents').addEventListener('click', function(e) {
        const block = e.target.closest('.editable-block');
        if (block && !e.target.closest('[data-action]')) {
            const pageIndex = parseInt(block.dataset.page);
            const blockIndex = parseInt(block.dataset.block);
            openEditor(pageIndex, blockIndex);
        }
    });

    // Click handler for page tabs
    document.getElementById('pageTabs').addEventListener('click', function(e) {
        const tab = e.target.closest('.page-tab');
        if (tab) {
            const index = Array.from(tab.parentNode.children).indexOf(tab);
            switchPage(index);
        }
    });

    // Regenerate a single page
    document.getElementById('pageContents').addEventListener('click', async function(e) {
        const btn = e.target.closest('.regen-page-btn');
        if (!btn) return;

        const pageIndex = parseInt(btn.dataset.pageIndex, 10);
        const pageSlug  = btn.dataset.pageSlug;
        const label     = planData.pages[pageIndex]?.title || pageSlug;

        if (!confirm(`Regenerate the "${label}" page? The current blocks will be replaced.`)) return;

        btn.disabled = true;
        btn.innerHTML = '<span class="material-symbols-outlined" style="font-size:1rem;line-height:1;">hourglass_top</span> Regenerating…';

        try {
            const fd = new FormData();
            fd.append('_csrf', CSRF_TOKEN);
            fd.append('page_slug', pageSlug);

            const resp = await fetch(`/admin/approvals/${QUEUE_ID}/regenerate-page`, { method: 'POST', body: fd });
            const result = await resp.json();

            if (result.success) {
                planData.pages[result.page_index].blocks = result.blocks;
                if (result.meta_description) planData.pages[result.page_index].meta_description = result.meta_description;
                const pageContent = document.getElementById('page-' + pageIndex);
                pageContent.innerHTML = renderPageBlocks(planData.pages[pageIndex], pageIndex);
                reattachEventListeners(pageContent);
            } else {
                alert('Regeneration failed: ' + (result.error || 'Unknown error'));
                btn.disabled = false;
                btn.innerHTML = '<span class="material-symbols-outlined" style="font-size:1rem;line-height:1;">refresh</span> Regenerate page';
            }
        } catch (err) {
            alert('Request failed: ' + err.message);
            btn.disabled = false;
            btn.innerHTML = '<span class="material-symbols-outlined" style="font-size:1rem;line-height:1;">refresh</span> Regenerate page';
        }
    });

    // Open Edit with AI modal when clicking a block's edit button
    window._aiEditTarget = null;
    document.getElementById('pageContents').addEventListener('click', function(e) {
        const btn = e.target.closest('.edit-block-ai-btn');
        if (!btn) return;
        e.stopPropagation();
        window._aiEditTarget = {
            pageIndex:  parseInt(btn.dataset.pageIndex, 10),
            blockIndex: parseInt(btn.dataset.blockIndex, 10),
            pageSlug:   btn.dataset.pageSlug,
            blockType:  btn.dataset.blockType,
        };
        const modal = document.getElementById('ai-edit-block-modal');
        if (!modal) return;
        modal.querySelector('#ai-edit-block-type').textContent = 'Block type: ' + window._aiEditTarget.blockType;
        modal.querySelector('#ai-edit-block-instruction').value = '';
        modal.style.display = 'flex';
        setTimeout(() => modal.querySelector('#ai-edit-block-instruction').focus(), 50);
    });

    // Gallery state

    function openGallery(targetName) {
        openMediaPicker(url => {
            if (!targetName) return;
            const input = document.querySelector(`[name="${targetName}"]`);
            if (input) {
                input.value = url;
                const preview = document.getElementById(targetName.replace(/[\[\]]/g, '') + '-preview') || document.getElementById('image-preview');
                if (preview) preview.innerHTML = `<img src="${escapeHtml(url)}" class="mt-2 max-h-32 rounded-lg">`;
            }
        });
    }

    function clearImage(targetName) {
        const input = document.querySelector(`[name="${targetName}"]`);
        if (input) {
            input.value = '';
            const preview = document.getElementById('image-preview');
            if (preview) preview.innerHTML = '';
        }
    }

    // Initialize
    init();
JS;
        exit;
    }

    public static function mediaPickerHtml(): string {
        return '<div id="media-picker" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:999999;align-items:center;justify-content:center;padding:1rem">'
            . '<div style="background:#fff;border-radius:.75rem;box-shadow:0 20px 60px rgba(0,0,0,.3);max-width:56rem;width:100%;max-height:80vh;display:flex;flex-direction:column">'
            . '<div style="display:flex;align-items:center;justify-content:space-between;padding:1rem;border-bottom:1px solid #e5e7eb">'
            . '<h3 style="font-size:1.125rem;font-weight:600;color:#111827;margin:0">Select Image</h3>'
            . '<button type="button" id="close-media-picker" style="padding:.25rem;color:#6b7280;background:none;border:none;cursor:pointer;border-radius:.25rem">'
            . '<span class="material-symbols-outlined">close</span></button></div>'
            . '<div id="media-upload-zone" style="margin:1rem 1rem 0;border:2px dashed #d1d5db;border-radius:.5rem;padding:1rem;text-align:center;background:#f9fafb;cursor:pointer">'
            . '<p style="color:#374151;font-size:.875rem;margin:0"><span style="color:#135bec;font-weight:500">Click to upload</span> or drag and drop</p>'
            . '<input type="file" id="media-file-input" accept="image/*" style="display:none"></div>'
            . '<div style="flex:1;overflow-y:auto;padding:1rem">'
            . '<div id="media-grid" style="display:grid;grid-template-columns:repeat(5,1fr);gap:.75rem"></div>'
            . '<div id="media-loading" style="text-align:center;color:#6b7280;padding:2rem 0">Loading media...</div>'
            . '<div id="media-empty" style="display:none;text-align:center;color:#6b7280;padding:2rem 0">No images found. Upload one above.</div>'
            . '</div></div></div>';
    }

    public static function mediaPickerBulmaHtml(): string {
        return '<div id="media-picker" class="modal" style="display:none;z-index:999999">'
            . '<div class="modal-background" id="media-picker-bg"></div>'
            . '<div class="modal-card">'
            . '<header class="modal-card-head">'
            . '<p class="modal-card-title">Select Image</p>'
            . '<button type="button" class="delete" id="close-media-picker" aria-label="close"></button>'
            . '</header>'
            . '<section class="modal-card-body">'
            . '<div id="media-upload-zone" style="border:2px dashed #d1d5db;border-radius:.5rem;padding:1rem;text-align:center;background:#f9fafb;cursor:pointer;margin-bottom:1rem">'
            . '<p class="is-size-7"><span style="color:#135bec;font-weight:500">Click to upload</span> or drag and drop</p>'
            . '<input type="file" id="media-file-input" accept="image/*" style="display:none"></div>'
            . '<div id="media-grid" style="display:grid;grid-template-columns:repeat(5,1fr);gap:.75rem"></div>'
            . '<div id="media-loading" style="text-align:center;color:#6b7280;padding:2rem 0">Loading media...</div>'
            . '<div id="media-empty" style="display:none;text-align:center;color:#6b7280;padding:2rem 0">No images found. Upload one above.</div>'
            . '</section>'
            . '</div>'
            . '</div>';
    }

    public static function adminGlobalJs(): void {
        header('Content-Type: application/javascript');
        header('Cache-Control: no-cache');
        echo <<<'JS'
// Event delegation for all admin interactions
document.addEventListener('DOMContentLoaded', function() {
    // ── Notifications dropdown ────────────────────────────────────────────
    const notifBtn      = document.getElementById('notif-btn');
    const notifDropdown = document.getElementById('notif-dropdown');
    if (notifBtn && notifDropdown) {
        notifBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            const open = !notifDropdown.classList.contains('hidden');
            notifDropdown.classList.toggle('hidden', open);
            notifBtn.setAttribute('aria-expanded', String(!open));
        });
        document.addEventListener('click', function(e) {
            if (!notifDropdown.classList.contains('hidden') && !notifDropdown.contains(e.target)) {
                notifDropdown.classList.add('hidden');
                notifBtn.setAttribute('aria-expanded', 'false');
            }
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && !notifDropdown.classList.contains('hidden')) {
                notifDropdown.classList.add('hidden');
                notifBtn.setAttribute('aria-expanded', 'false');
                notifBtn.focus();
            }
        });
    }

    // Handle form confirmations
    document.querySelectorAll('form[data-confirm]').forEach(form => {
        form.addEventListener('submit', function(e) {
            if (!confirm(this.dataset.confirm)) {
                e.preventDefault();
            }
        });
    });

    // Handle color input synchronization
    document.querySelectorAll('.color-input').forEach(container => {
        const colorInput = container.querySelector('input[type="color"]');
        const textInput = container.querySelector('input[type="text"]');
        if (colorInput && textInput) {
            colorInput.addEventListener('input', () => textInput.value = colorInput.value);
            textInput.addEventListener('input', () => colorInput.value = textInput.value);
        }
    });

    // Handle nav and generic actions (block editor actions are handled by /js/editor)
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('[data-action]');
        if (!btn) return;

        const action = btn.dataset.action;

        switch(action) {
            case 'add-nav-item':
                if (typeof addNavItem === 'function') addNavItem();
                break;
            case 'remove-nav-item':
                if (typeof removeNavItem === 'function') removeNavItem(btn);
                break;
            case 'copy-url':
                copyUrl(btn.dataset.url);
                break;
        }
    });
});

window.copyUrl = function(url) {
    navigator.clipboard.writeText(window.location.origin + url).then(() => {
        alert('URL copied to clipboard!');
    });
};

// ─── OG Image (page edit sidebar — parent frame) ──────────────────────────────
document.getElementById('select-og-image')?.addEventListener('click', () => {
    const ogInput   = document.getElementById('og-image-input');
    const ogPreview = document.getElementById('og-image-preview');
    openMediaPicker(url => {
        if (ogInput)   ogInput.value = url;
        if (ogPreview) ogPreview.innerHTML = `<img src="${url}" alt="OG Image" class="max-h-[100px] object-contain">`;
    });
});

document.getElementById('clear-og-image')?.addEventListener('click', () => {
    const ogInput   = document.getElementById('og-image-input');
    const ogPreview = document.getElementById('og-image-preview');
    if (ogInput)   ogInput.value = '';
    if (ogPreview) ogPreview.innerHTML = '<span class="text-gray-400 text-sm">No image selected</span>';
});

// ─── Universal Media Picker ───────────────────────────────────────────────────
(function() {
    var mediaPicker, mediaGrid, mediaLoading, mediaEmpty, mediaUploadZone, mediaFileInput;
    var mediaPickerCallback = null;

    document.addEventListener('DOMContentLoaded', function() {
        mediaPicker     = document.getElementById('media-picker');
        mediaGrid       = document.getElementById('media-grid');
        mediaLoading    = document.getElementById('media-loading');
        mediaEmpty      = document.getElementById('media-empty');
        mediaUploadZone = document.getElementById('media-upload-zone');
        mediaFileInput  = document.getElementById('media-file-input');
        if (!mediaPicker) return;

        document.getElementById('close-media-picker').addEventListener('click', closeMediaPicker);
        mediaPicker.addEventListener('click', function(e) {
            if (e.target === mediaPicker || e.target.id === 'media-picker-bg') closeMediaPicker();
        });

        mediaUploadZone.addEventListener('click', function() { mediaFileInput.click(); });
        mediaUploadZone.addEventListener('dragover', function(e) { e.preventDefault(); mediaUploadZone.style.borderColor = '#135bec'; });
        mediaUploadZone.addEventListener('dragleave', function() { mediaUploadZone.style.borderColor = '#d1d5db'; });
        mediaUploadZone.addEventListener('drop', function(e) {
            e.preventDefault();
            mediaUploadZone.style.borderColor = '#d1d5db';
            uploadMediaFile(e.dataTransfer.files[0]);
        });
        // Stop file input clicks from bubbling to the upload zone (prevents double file dialog)
        mediaFileInput.addEventListener('click', function(e) { e.stopPropagation(); });
        mediaFileInput.addEventListener('change', function() { uploadMediaFile(mediaFileInput.files[0]); });

        // Delegated: .pick-image-btn
        document.addEventListener('click', function(e) {
            var pickBtn = e.target.closest('.pick-image-btn');
            if (pickBtn) {
                var widget = pickBtn.closest('.image-picker-widget');
                var input  = widget && widget.querySelector('input[type="text"]');
                var thumb  = widget && widget.querySelector('.image-thumb');
                openMediaPicker(function(url) {
                    if (input) input.value = url;
                    if (thumb) thumb.innerHTML = '<img src="' + url + '" alt="" class="w-full h-full object-cover">';
                });
                return;
            }
            var galleryBtn = e.target.closest('.gallery-add-image-btn');
            if (galleryBtn) {
                var field = galleryBtn.closest('.editor-field') || galleryBtn.closest('.form-control');
                var ta = field && (field.querySelector('.gallery-images-field textarea') || field.querySelector('textarea[name="images"]'));
                openMediaPicker(function(url) {
                    if (ta) ta.value = (ta.value.trim() ? ta.value.trim() + '\n' : '') + url;
                });
            }
        });
    });

    window.openMediaPicker = function(callback) {
        mediaPickerCallback = callback;
        mediaPicker.classList.remove('hidden');
        mediaPicker.classList.add('is-active');
        mediaPicker.style.display = 'flex';
        loadMediaItems();
    };

    window.closeMediaPicker = function() {
        mediaPicker.classList.add('hidden');
        mediaPicker.classList.remove('is-active');
        mediaPicker.style.display = 'none';
        mediaPickerCallback = null;
    };

    function loadMediaItems() {
        mediaLoading.style.display = 'block';
        mediaEmpty.style.display = 'none';
        mediaGrid.innerHTML = '';
        fetch('/api/media').then(function(r) { return r.json(); }).then(function(data) {
            mediaLoading.style.display = 'none';
            var images = (data.assets || []).filter(function(a) { return a.is_image; });
            if (!images.length) { mediaEmpty.style.display = 'block'; return; }
            images.forEach(function(asset) {
                var div = document.createElement('div');
                div.style.cssText = 'aspect-ratio:1/1;border-radius:.5rem;border:2px solid transparent;cursor:pointer;overflow:hidden;background:#f3f4f6';
                div.innerHTML = '<img src="/assets/' + asset.hash + '" alt="' + asset.filename + '" style="width:100%;height:100%;object-fit:cover">';
                div.addEventListener('mouseenter', function() { this.style.borderColor = '#135bec'; });
                div.addEventListener('mouseleave', function() { this.style.borderColor = 'transparent'; });
                div.addEventListener('click', function() {
                    if (mediaPickerCallback) mediaPickerCallback('/assets/' + asset.hash, asset.id);
                    closeMediaPicker();
                });
                mediaGrid.appendChild(div);
            });
        }).catch(function() {
            mediaLoading.textContent = 'Error loading media';
        });
    }

    function uploadMediaFile(file) {
        if (!file) return;
        var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
        var fd = new FormData();
        fd.append('file', file);
        fd.append('_csrf', csrf);
        mediaUploadZone.innerHTML = '<p style="font-size:.875rem;color:#6b7280;margin:0">Uploading...</p>';
        fetch('/admin/media/upload', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                mediaUploadZone.innerHTML = '<p style="color:#374151;font-size:.875rem;margin:0"><span style="color:#135bec;font-weight:500">Click to upload</span> or drag and drop</p>';
                if (data.success && data.url) {
                    if (mediaPickerCallback) mediaPickerCallback(data.url, data.id);
                    closeMediaPicker();
                } else {
                    loadMediaItems();
                }
            })
            .catch(function() {
                mediaUploadZone.innerHTML = '<p style="color:#ef4444;font-size:.875rem;margin:0">Upload failed</p>';
            });
    }
})();

JS;
        exit;
    }

    public static function navJs(): void {
        header('Content-Type: application/javascript');
        header('Cache-Control: no-cache');
        echo <<<'JS'
let navCounter = parseInt(document.getElementById('nav-items').dataset.navCount || '0', 10);

function addNavItem() {
    navCounter++;
    const id = 'new_' + navCounter;
    const container = document.getElementById('nav-items');
    const order = container.children.length;

    const div = document.createElement('div');
    div.className = 'nav-item flex items-center gap-4 p-4 bg-gray-50 rounded-lg border border-gray-200';
    div.dataset.id = id;
    div.draggable = true;
    div.innerHTML = `
        <span class="handle cursor-move text-gray-400 hover:text-gray-600">
            <span class="material-symbols-outlined">drag_indicator</span>
        </span>
        <div class="flex-1 grid grid-cols-1 md:grid-cols-3 gap-3 items-center">
            <input type="text" name="nav[${id}][label]" placeholder="Label" required
                   class="px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none">
            <input type="text" name="nav[${id}][url]" placeholder="/page-slug" required
                   class="px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none">
            <label class="flex items-center gap-2 text-sm text-gray-600">
                <input type="checkbox" name="nav[${id}][visible]" value="1" checked
                       class="size-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                Visible
            </label>
            <input type="hidden" name="nav[${id}][id]" value="">
            <input type="hidden" name="nav[${id}][order]" class="nav-order" value="${order}">
        </div>
        <button type="button" data-action="remove-nav-item" class="p-2 text-red-500 hover:text-red-700 hover:bg-red-50 rounded-lg transition-colors" title="Delete">
            <span class="material-symbols-outlined">delete</span>
        </button>
    `;
    container.appendChild(div);
}

function removeNavItem(btn) {
    if (confirm('Remove this menu item?')) {
        btn.closest('.nav-item').remove();
        updateNavOrder();
    }
}

function updateNavOrder() {
    document.querySelectorAll('.nav-item').forEach((item, i) => {
        item.querySelector('.nav-order').value = i;
    });
}

// Simple drag-and-drop reordering
const navItems = document.getElementById('nav-items');
let draggedItem = null;

navItems.addEventListener('dragstart', e => {
    if (e.target.classList.contains('nav-item')) {
        draggedItem = e.target;
        e.target.style.opacity = '0.5';
    }
});

navItems.addEventListener('dragend', e => {
    if (draggedItem) {
        draggedItem.style.opacity = '1';
        draggedItem = null;
        updateNavOrder();
    }
});

navItems.addEventListener('dragover', e => {
    e.preventDefault();
    const afterElement = getDragAfterElement(navItems, e.clientY);
    if (draggedItem) {
        if (afterElement == null) {
            navItems.appendChild(draggedItem);
        } else {
            navItems.insertBefore(draggedItem, afterElement);
        }
    }
});

function getDragAfterElement(container, y) {
    const draggableElements = [...container.querySelectorAll('.nav-item:not([style*="opacity: 0.5"])')];
    return draggableElements.reduce((closest, child) => {
        const box = child.getBoundingClientRect();
        const offset = y - box.top - box.height / 2;
        if (offset < 0 && offset > closest.offset) {
            return { offset: offset, element: child };
        } else {
            return closest;
        }
    }, { offset: Number.NEGATIVE_INFINITY }).element;
}

// Make items draggable
document.querySelectorAll('.nav-item').forEach(item => item.draggable = true);
JS;
        exit;
    }

    public static function mediaJs(): void {
        header('Content-Type: application/javascript');
        header('Cache-Control: no-cache');
        echo <<<'JS'
const uploadZone = document.getElementById('upload-zone');
const fileInput = document.getElementById('file-input');
const progressContainer = document.getElementById('upload-progress');
const progressBar = document.getElementById('progress-bar');
const uploadStatus = document.getElementById('upload-status');

// Click to upload — skip if click came from the <label> (it already activates the input natively)
uploadZone.addEventListener('click', (e) => {
    if (e.target.closest('label')) return;
    fileInput.click();
});

// Drag and drop
uploadZone.addEventListener('dragover', e => {
    e.preventDefault();
    uploadZone.classList.add('border-[#135bec]', 'bg-blue-50');
});
uploadZone.addEventListener('dragleave', () => {
    uploadZone.classList.remove('border-[#135bec]', 'bg-blue-50');
});
uploadZone.addEventListener('drop', e => {
    e.preventDefault();
    uploadZone.classList.remove('border-[#135bec]', 'bg-blue-50');
    handleFiles(e.dataTransfer.files);
});

// File input
fileInput.addEventListener('change', () => handleFiles(fileInput.files));

function handleFiles(files) {
    if (files.length === 0) return;

    progressContainer.classList.remove('hidden');
    let uploaded = 0;

    Array.from(files).forEach(file => {
        const formData = new FormData();
        formData.append('file', file);
        formData.append('_csrf', document.querySelector('meta[name="csrf-token"]').content);

        fetch('/admin/media/upload', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            uploaded++;
            progressBar.style.width = (uploaded / files.length * 100) + '%';
            uploadStatus.textContent = `Uploaded ${uploaded} of ${files.length} files...`;

            if (uploaded === files.length) {
                setTimeout(() => location.reload(), 500);
            }
        })
        .catch(err => {
            uploaded++;
            uploadStatus.textContent = 'Error uploading ' + file.name;
        });
    });
}

function copyUrl(url) {
    navigator.clipboard.writeText(window.location.origin + url).then(() => {
        alert('URL copied to clipboard!');
    });
}
JS;
        exit;
    }

    public static function aiGenerateJs(): void {
        header('Content-Type: application/javascript');
        header('Cache-Control: no-cache');
        echo <<<'JS'
(function() {
    const form    = document.getElementById('ai-generate-form');
    const modal   = document.getElementById('ai-loading-modal');
    const btn     = document.getElementById('ai-generate-btn');
    const statusEl = document.getElementById('ai-status-text');
    const errorBox = document.getElementById('ai-error-box');
    const planForm = document.getElementById('ai-plan-form');
    const planJson = document.getElementById('ai-plan-json');

    function setStatus(msg) {
        if (statusEl) statusEl.innerHTML = '<span class="w-1.5 h-1.5 bg-[#135bec] rounded-full animate-pulse inline-block"></span> ' + msg;
    }

    function setStageActive(n) {
        const el = document.getElementById('stage-' + n);
        const icon = document.getElementById('stage-' + n + '-icon');
        if (el) el.classList.replace('bg-gray-50', 'bg-blue-50');
        if (icon) {
            icon.textContent = 'pending';
            icon.classList.replace('text-gray-400', 'text-[#135bec]');
            icon.classList.add('animate-pulse');
        }
    }

    function setStageComplete(n) {
        const el = document.getElementById('stage-' + n);
        const icon = document.getElementById('stage-' + n + '-icon');
        if (el) { el.classList.replace('bg-blue-50', 'bg-green-50'); }
        if (icon) {
            icon.textContent = 'check_circle';
            icon.classList.replace('text-[#135bec]', 'text-green-500');
            icon.classList.remove('animate-pulse');
        }
    }

    function showError(msg) {
        if (errorBox) { errorBox.textContent = msg; errorBox.classList.remove('hidden'); }
        setStatus('Generation failed.');
        btn.disabled = false;
        btn.innerHTML = '<span class="material-symbols-outlined text-xl">auto_awesome</span> Generate Site Plan';
    }

    function parseSseChunk(text) {
        // Returns array of {event, data} objects
        const results = [];
        const lines = text.split('\n');
        let event = 'message', data = '';
        for (const line of lines) {
            if (line.startsWith('event:')) { event = line.slice(6).trim(); }
            else if (line.startsWith('data:')) { data = line.slice(5).trim(); }
            else if (line === '' && data) {
                results.push({ event, data });
                event = 'message'; data = '';
            }
        }
        return results;
    }

    form?.addEventListener('submit', async function(e) {
        e.preventDefault();

        modal?.classList.remove('hidden');
        btn.disabled = true;
        btn.innerHTML = '<span class="material-symbols-outlined text-xl animate-spin">progress_activity</span> Generating...';
        if (errorBox) errorBox.classList.add('hidden');

        // Collect form data as JSON
        const fd = new FormData(form);
        const payload = {};
        fd.forEach((v, k) => {
            if (k.endsWith('[]')) {
                const key = k.slice(0, -2);
                if (!payload[key]) payload[key] = [];
                payload[key].push(v);
            } else {
                payload[k] = v;
            }
        });

        let buffer = '';
        try {
            const res = await fetch('/api/ai/stream', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify(payload)
            });

            if (!res.ok) {
                const err = await res.text();
                showError('Server error: ' + res.status + ' ' + err.slice(0, 200));
                return;
            }

            const reader = res.body.getReader();
            const decoder = new TextDecoder();
            let redirected = false;

            while (true) {
                const { value, done } = await reader.read();
                // Process data even on the final chunk (done=true with last bytes)
                if (value) {
                    buffer += decoder.decode(value, { stream: true });
                    const events = parseSseChunk(buffer);
                    // Keep only the incomplete last chunk in buffer
                    const lastNewline = buffer.lastIndexOf('\n\n');
                    if (lastNewline >= 0) buffer = buffer.slice(lastNewline + 2);

                    for (const { event, data } of events) {
                        // Yield to browser between events so DOM paints stage updates
                        await new Promise(resolve => setTimeout(resolve, 0));
                        let payload;
                        try { payload = JSON.parse(data); } catch { payload = { message: data }; }

                        if (event === 'stage') {
                            const n = payload.stage;
                            setStageActive(n);
                            setStatus(payload.message || payload.label || 'Stage ' + n + '...');
                            if (n === 2 && payload.page) {
                                const d = document.getElementById('stage-2-detail');
                                if (d) d.textContent = 'Writing: ' + payload.page;
                            }
                        } else if (event === 'stage_complete') {
                            setStageComplete(payload.stage);
                        } else if (event === 'complete') {
                            redirected = true;
                            setStageComplete(1);
                            setStageComplete(2);
                            setStageComplete(3);
                            setStatus('Done! Redirecting to approval queue...');
                            setTimeout(() => {
                                window.location.href = payload.queue_id
                                    ? '/admin/approvals/' + payload.queue_id
                                    : '/admin/approvals';
                            }, 600);
                        } else if (event === 'error') {
                            showError(payload.message || 'Unknown error during generation.');
                            return;
                        }
                    }
                }
                if (done) break;
            }
            // Stream closed — if complete event was missed (e.g. last-mile buffering
            // dropped the final SSE frame), redirect to approvals where the plan is saved.
            if (!redirected) {
                setStatus('Generation finished — redirecting to approval queue…');
                setTimeout(() => { window.location.href = '/admin/approvals'; }, 800);
            }
        } catch (err) {
            // Nginx may cut the SSE connection while PHP is still running.
            // The plan is saved server-side before the complete event — redirect to approvals.
            setStatus('Connection interrupted — checking for saved plan…');
            setTimeout(() => { window.location.href = '/admin/approvals'; }, 1500);
        }
    });
})();
JS;
        exit;
    }

    public static function aiChatJs(): void {
        header('Content-Type: application/javascript');
        header('Cache-Control: no-cache');
        echo <<<'JS'
(function() {
    const CSRF = document.querySelector('meta[name="csrf-token"]').content;
    const messagesEl = document.getElementById('chat-messages');
    const inputEl    = document.getElementById('message-input');
    const sendBtn    = document.getElementById('send-btn');
    const typing     = document.getElementById('typing-indicator');
    const ctaEl      = document.getElementById('generate-cta');
    const genBtn     = document.getElementById('generate-btn');
    const resetBtn   = document.getElementById('reset-btn');
    const modal      = document.getElementById('ai-loading-modal');
    const statusEl   = document.getElementById('ai-status-text');
    const errorBox   = document.getElementById('ai-error-box');
    const planForm   = document.getElementById('ai-plan-form');
    const planJson   = document.getElementById('ai-plan-json');

    let currentBrief = null;

    function scrollBottom() { if (messagesEl) messagesEl.scrollTop = messagesEl.scrollHeight; }
    scrollBottom();

    function appendMessage(role, content) {
        if (typing) typing.classList.add('hidden');
        // Strip __READY__ JSON blob (and any preceding --- separator) in case server didn't catch it
        if (role === 'assistant') {
            content = content.replace(/\s*-{3,}\s*\{"[\s\S]*__READY__[\s\S]*/g, '').trim();
            content = content.replace(/\{"\s*__READY__[\s\S]*/g, '').trim();
        }
        const wrap = document.createElement('div');
        wrap.className = 'flex ' + (role === 'user' ? 'justify-end' : 'justify-start');
        wrap.innerHTML = `<div class="max-w-[80%] px-4 py-2.5 rounded-2xl text-sm leading-relaxed whitespace-pre-wrap ${
            role === 'user'
                ? 'bg-[#135bec] text-white rounded-br-sm'
                : 'bg-gray-100 text-gray-800 rounded-bl-sm'
        }">${escapeHtml(content)}</div>`;
        messagesEl.appendChild(wrap);
        scrollBottom();
    }

    function escapeHtml(s) {
        return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    async function sendMessage() {
        const msg = inputEl.value.trim();
        if (!msg) return;
        inputEl.value = '';
        appendMessage('user', msg);
        sendBtn.disabled = true;
        if (typing) { messagesEl.appendChild(typing); typing.classList.remove('hidden'); }
        scrollBottom();

        try {
            const res = await fetch('/api/ai/chat', {
                method: 'POST',
                headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
                body: JSON.stringify({message: msg, _csrf: CSRF})
            });
            const data = await res.json();
            if (data.error) { appendMessage('assistant', '⚠️ ' + data.error); return; }
            appendMessage('assistant', data.reply);
            if (data.ready && data.brief) {
                currentBrief = data.brief;
                ctaEl?.classList.remove('hidden');
            }
        } catch(e) {
            appendMessage('assistant', '⚠️ Connection error: ' + e.message);
        } finally {
            sendBtn.disabled = false;
        }
    }

    sendBtn?.addEventListener('click', sendMessage);
    inputEl?.addEventListener('keydown', e => {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
    });

    resetBtn?.addEventListener('click', async () => {
        await fetch('/api/ai/chat/reset', {method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}});
        location.reload();
    });

    // SSE generation helpers
    function setStatus(msg) { if (statusEl) statusEl.textContent = msg; }
    function setStageActive(n) {
        const el = document.getElementById('stage-' + n);
        const ic = document.getElementById('stage-' + n + '-icon');
        el?.classList.replace('bg-gray-50','bg-blue-50');
        if (ic) { ic.textContent='pending'; ic.classList.replace('text-gray-400','text-[#135bec]'); ic.classList.add('animate-pulse'); }
    }
    function setStageComplete(n) {
        const el = document.getElementById('stage-' + n);
        const ic = document.getElementById('stage-' + n + '-icon');
        el?.classList.replace('bg-blue-50','bg-green-50');
        if (ic) { ic.textContent='check_circle'; ic.classList.replace('text-[#135bec]','text-green-500'); ic.classList.remove('animate-pulse'); }
    }
    function parseSse(text) {
        const out=[]; const lines=text.split('\n'); let ev='message',da='';
        for (const l of lines) {
            if (l.startsWith('event:')) ev=l.slice(6).trim();
            else if (l.startsWith('data:')) da=l.slice(5).trim();
            else if (l===''&&da) { out.push({event:ev,data:da}); ev='message';da=''; }
        }
        return out;
    }

    genBtn?.addEventListener('click', async () => {
        if (!currentBrief) return;
        modal?.classList.remove('hidden');
        ctaEl?.classList.add('hidden');
        if (errorBox) errorBox.classList.add('hidden');

        let buffer='';
        try {
            const res = await fetch('/api/ai/stream', {
                method:'POST',
                headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
                body: JSON.stringify(currentBrief)
            });
            if (!res.ok) { if(errorBox){errorBox.textContent='Server error '+res.status; errorBox.classList.remove('hidden');} return; }
            const reader = res.body.getReader();
            const dec = new TextDecoder();
            let redirected = false;
            while (true) {
                const {value, done} = await reader.read();
                if (value) {
                    buffer += dec.decode(value, {stream:true});
                    const events = parseSse(buffer);
                    const last = buffer.lastIndexOf('\n\n');
                    if (last>=0) buffer=buffer.slice(last+2);
                    for (const {event, data} of events) {
                        await new Promise(resolve => setTimeout(resolve, 0));
                        let p; try { p=JSON.parse(data); } catch { p={message:data}; }
                        if (event==='stage') { setStageActive(p.stage); setStatus(p.message||p.label||''); if (p.stage===2&&p.page) { const d=document.getElementById('stage-2-detail'); if(d) d.textContent='Writing: '+p.page; } }
                        else if (event==='stage_complete') setStageComplete(p.stage);
                        else if (event==='complete') { redirected=true; setStageComplete(1);setStageComplete(2);setStageComplete(3); setStatus('Done! Redirecting...'); setTimeout(()=>{ window.location.href = p.queue_id ? '/admin/approvals/'+p.queue_id : '/admin/approvals'; }, 600); }
                        else if (event==='error') { if(errorBox){errorBox.textContent=p.message||'Error';errorBox.classList.remove('hidden');} return; }
                    }
                }
                if (done) break;
            }
            if (!redirected) { setStatus('Generation finished — redirecting to approval queue…'); setTimeout(()=>{ window.location.href='/admin/approvals'; }, 800); }
        } catch(e) { setStatus('Connection interrupted — redirecting to approvals…'); setTimeout(()=>{ window.location.href='/admin/approvals'; }, 1500); }
    });
})();
JS;
        exit;
    }

    public static function blogEditorJs(): void {
        header('Content-Type: application/javascript');
        header('Cache-Control: no-cache');
        echo <<<'JS'
(function() {
    // ── Init markdown from base64 ──────────────────────────────────────────
    const mdInput = document.getElementById('md-input');
    if (mdInput && window._mdB64) {
        try { mdInput.value = atob(window._mdB64); } catch(e) {}
    }

    // ── Auto-slug from title ───────────────────────────────────────────────
    const titleInput = document.querySelector('input[name="title"]');
    const slugInput  = document.getElementById('slug-input');
    if (titleInput && slugInput && !slugInput.value) {
        titleInput.addEventListener('input', () => {
            slugInput.value = titleInput.value.toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'');
        });
    }

    // ── Tab switching ──────────────────────────────────────────────────────
    const tabVisual = document.getElementById('tab-visual');
    const tabMd     = document.getElementById('tab-md');
    const paneVisual = document.getElementById('pane-visual');
    const paneMd     = document.getElementById('pane-md');

    let activeTab = (window._mdB64 && window._mdB64.length > 0) ? 'md' : 'visual';

    function activateTab(tab) {
        activeTab = tab;
        if (tab === 'visual') {
            tabVisual.classList.add('tab-editor-active');    tabVisual.classList.remove('tab-editor-inactive');
            tabMd.classList.remove('tab-editor-active');     tabMd.classList.add('tab-editor-inactive');
            paneVisual.classList.remove('hidden');
            paneMd.classList.add('hidden');
        } else {
            tabMd.classList.add('tab-editor-active');        tabMd.classList.remove('tab-editor-inactive');
            tabVisual.classList.remove('tab-editor-active'); tabVisual.classList.add('tab-editor-inactive');
            paneMd.classList.remove('hidden');
            paneVisual.classList.add('hidden');
            refreshMdPreview();
        }
    }

    tabVisual?.addEventListener('click', () => activateTab('visual'));
    tabMd?.addEventListener('click', () => activateTab('md'));
    activateTab(activeTab);

    // ── Quill editor ───────────────────────────────────────────────────────
    const quill = new Quill('#quill-editor', {
        theme: 'snow',
        modules: { toolbar: [
            [{ header: [1,2,3,false] }],
            ['bold','italic','underline','strike'],
            ['blockquote','code-block'],
            [{ list:'ordered'},{list:'bullet'}],
            ['link','image'],
            ['clean']
        ]}
    });

    // ── Markdown live preview ──────────────────────────────────────────────
    const mdPreview = document.getElementById('md-preview');
    let previewTimer = null;

    function refreshMdPreview() {
        const md = mdInput?.value || '';
        if (!md.trim()) {
            if (mdPreview) mdPreview.innerHTML = '<p style="color:var(--mx);font-style:italic;font-size:.85rem;">Preview will appear here as you type\u2026</p>';
            return;
        }
        fetch('/api/markdown/preview', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({markdown: md})
        }).then(r => r.json()).then(d => {
            if (mdPreview && d.html) mdPreview.innerHTML = d.html;
        }).catch(() => {});
    }

    mdInput?.addEventListener('input', () => {
        clearTimeout(previewTimer);
        previewTimer = setTimeout(refreshMdPreview, 400);
    });

    // ── Form submission ────────────────────────────────────────────────────
    document.getElementById('post-form')?.addEventListener('submit', () => {
        if (activeTab === 'visual') {
            document.getElementById('body-html-input').value = quill.root.innerHTML;
            if (mdInput) mdInput.removeAttribute('name'); // don't submit body_markdown
        } else {
            // Markdown mode — server renders HTML; clear body_html
            document.getElementById('body-html-input').value = '';
        }
    });

    // ── Tag management ─────────────────────────────────────────────────────
    const tagSelect    = document.getElementById('tag-select');
    const selectedTags = document.getElementById('selected-tags');
    const newTagInput  = document.getElementById('new-tag-input');
    const addTagBtn    = document.getElementById('add-tag-btn');
    const existingIds  = new Set([...document.querySelectorAll('input[name="tags[]"]')].map(i=>i.value));

    function addTagEl(id, name) {
        if (existingIds.has(String(id))) return;
        existingIds.add(String(id));
        const span = document.createElement('span');
        span.className = 'inline-flex items-center gap-1 px-2 py-0.5 bg-blue-50 text-blue-700 rounded-full text-xs';
        span.innerHTML = name + '<button type="button" class="hover:text-red-500" data-remove-tag="' + id + '">\xd7</button><input type="hidden" name="tags[]" value="' + id + '">';
        selectedTags?.appendChild(span);
    }

    tagSelect?.addEventListener('change', () => {
        const opt = tagSelect.options[tagSelect.selectedIndex];
        if (opt.value) { addTagEl(opt.value, opt.dataset.name); tagSelect.selectedIndex=0; }
    });

    addTagBtn?.addEventListener('click', async () => {
        const name = newTagInput.value.trim();
        if (!name) return;
        const res = await fetch('/api/blog/tags', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({name})});
        const d = await res.json();
        if (d.id) { addTagEl(d.id, d.name); newTagInput.value=''; }
    });

    selectedTags?.addEventListener('click', e => {
        const btn = e.target.closest('[data-remove-tag]');
        if (btn) { const id=btn.dataset.removeTag; existingIds.delete(id); btn.closest('span')?.remove(); }
    });

    // ── Cover media picker ─────────────────────────────────────────────────
    document.getElementById('pick-cover-btn')?.addEventListener('click', () => {
        openMediaPicker((url, id) => {
            document.getElementById('cover-asset-id').value = id || '';
            const prev = document.getElementById('cover-preview');
            if (prev) { prev.src = url; prev.style.display = 'block'; prev.classList.remove('hidden'); }
        });
    });

    // ── AI Write Modal ─────────────────────────────────────────────────────
    const aiModal      = document.getElementById('ai-modal');
    const aiHistory    = document.getElementById('ai-chat-history');
    const aiInsertBar  = document.getElementById('ai-insert-bar');
    const aiInsertBtn  = document.getElementById('ai-insert-btn');
    const aiDiscardBtn = document.getElementById('ai-discard-btn');
    const aiInputEl    = document.getElementById('ai-input');
    const aiSendBtn    = document.getElementById('ai-send-btn');
    const csrfToken    = document.querySelector('meta[name="csrf-token"]').content;

    let chatMessages  = []; // [{role, content}]
    let lastReply     = '';

    function openAiModal() {
        if (!aiModal) return;
        aiModal.style.display = 'flex';
        aiInputEl?.focus();
    }
    function closeAiModal() { if (aiModal) aiModal.style.display = 'none'; }

    document.getElementById('ai-write-btn')?.addEventListener('click', openAiModal);
    document.getElementById('ai-modal-close')?.addEventListener('click', closeAiModal);

    if (window._autoAi) setTimeout(openAiModal, 400);

    function addBubble(role, text) {
        const div = document.createElement('div');
        div.className = role === 'user' ? 'ai-bubble-user' : (role === 'error' ? 'ai-bubble-error' : 'ai-bubble-ai');
        div.textContent = text;
        aiHistory?.appendChild(div);
        aiHistory.scrollTop = aiHistory.scrollHeight;
        return div;
    }

    async function sendAiMessage() {
        const msg = aiInputEl?.value.trim();
        if (!msg) return;
        chatMessages.push({role:'user', content: msg});
        addBubble('user', msg);
        aiInputEl.value = '';
        aiSendBtn.disabled = true;
        const loadingEl = addBubble('ai', '\u2026');
        try {
            const res = await fetch('/api/blog/ai/generate', {
                method: 'POST',
                headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
                body: JSON.stringify({messages: chatMessages, _csrf: csrfToken})
            });
            const data = await res.json();
            if (!res.ok || data.error) throw new Error(data.error || 'Request failed');
            loadingEl.remove();
            lastReply = data.reply;
            chatMessages.push({role:'assistant', content: lastReply});
            addBubble('ai', lastReply);
            if (aiInsertBar) aiInsertBar.style.display = 'flex';
        } catch(err) {
            loadingEl.remove();
            addBubble('error', 'Error: ' + err.message);
        }
        aiSendBtn.disabled = false;
    }

    aiSendBtn?.addEventListener('click', sendAiMessage);
    aiInputEl?.addEventListener('keydown', e => {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendAiMessage(); }
    });

    aiInsertBtn?.addEventListener('click', () => {
        if (!lastReply || !mdInput) return;
        mdInput.value = lastReply;
        activateTab('md');
        refreshMdPreview();
        closeAiModal();
    });

    aiDiscardBtn?.addEventListener('click', () => {
        chatMessages = []; lastReply = '';
        if (aiHistory) aiHistory.innerHTML = '';
        if (aiInsertBar) aiInsertBar.style.display = 'none';
    });
})();
JS;
        exit;
    }
}
// ─────────────────────────────────────────────────────────────────────────────
// SECTION 21: BLOCK API
// ─────────────────────────────────────────────────────────────────────────────

class BlockAPI {
    public static function update(int $id): void {
        Auth::require('content.edit');
        CSRF::require();

        $block = DB::fetch("SELECT * FROM content_blocks WHERE id = ?", [$id]);
        if (!$block) {
            Response::json(['error' => 'Block not found'], 404);
        }

        $blockJson = $_POST['block_json'] ?? '{}';

        // Validate JSON
        $data = json_decode($blockJson, true);
        if ($data === null && $blockJson !== '{}') {
            Response::json(['error' => 'Invalid JSON'], 400);
        }

        // Snapshot full page state before updating this block
        Revision::snapshotPage((int)$block['page_id']);

        DB::execute(
            "UPDATE content_blocks SET block_json = ?, updated_at = datetime('now') WHERE id = ?",
            [$blockJson, $id]
        );

        // Get page and invalidate cache
        $page = DB::fetch("SELECT slug FROM pages WHERE id = ?", [$block['page_id']]);
        if ($page) {
            Cache::invalidatePage($page['slug']);
        }

        // Return rendered HTML so the editor can swap in-place without a reload
        $updatedBlock = DB::fetch("SELECT * FROM content_blocks WHERE id = ?", [$id]);
        $renderedHtml = $updatedBlock ? PageController::renderBlockForCache($updatedBlock) : '';

        Response::json(['success' => true, 'rendered_html' => $renderedHtml]);
    }

    public static function move(int $id): void {
        Auth::require('content.edit');
        CSRF::require();

        $block = DB::fetch("SELECT * FROM content_blocks WHERE id = ?", [$id]);
        if (!$block) {
            Response::json(['error' => 'Block not found'], 404);
        }

        $direction = $_POST['direction'] ?? 'up';
        $currentOrder = (int) $block['sort_order'];

        if ($direction === 'up') {
            // Find block above
            $above = DB::fetch(
                "SELECT * FROM content_blocks WHERE page_id = ? AND sort_order < ? ORDER BY sort_order DESC LIMIT 1",
                [$block['page_id'], $currentOrder]
            );
            if ($above) {
                DB::execute("UPDATE content_blocks SET sort_order = ? WHERE id = ?", [$above['sort_order'], $id]);
                DB::execute("UPDATE content_blocks SET sort_order = ? WHERE id = ?", [$currentOrder, $above['id']]);
            }
        } else {
            // Find block below
            $below = DB::fetch(
                "SELECT * FROM content_blocks WHERE page_id = ? AND sort_order > ? ORDER BY sort_order ASC LIMIT 1",
                [$block['page_id'], $currentOrder]
            );
            if ($below) {
                DB::execute("UPDATE content_blocks SET sort_order = ? WHERE id = ?", [$below['sort_order'], $id]);
                DB::execute("UPDATE content_blocks SET sort_order = ? WHERE id = ?", [$currentOrder, $below['id']]);
            }
        }

        // Invalidate cache
        $page = DB::fetch("SELECT slug FROM pages WHERE id = ?", [$block['page_id']]);
        if ($page) {
            Cache::invalidatePage($page['slug']);
        }

        Response::json(['success' => true]);
    }

    public static function delete(int $id): void {
        Auth::require('content.edit');
        CSRF::require();

        $block = DB::fetch("SELECT * FROM content_blocks WHERE id = ?", [$id]);
        if (!$block) {
            Response::json(['error' => 'Block not found'], 404);
        }

        // Snapshot full page state before deleting this block
        Revision::snapshotPage((int)$block['page_id']);

        DB::execute("DELETE FROM content_blocks WHERE id = ?", [$id]);

        // Invalidate cache
        $page = DB::fetch("SELECT slug FROM pages WHERE id = ?", [$block['page_id']]);
        if ($page) {
            Cache::invalidatePage($page['slug']);
        }

        Response::json(['success' => true]);
    }

    public static function create(): void {
        Auth::require('content.edit');
        CSRF::require();

        $pageId = (int) ($_POST['page_id'] ?? 0);
        $type = $_POST['type'] ?? 'text';
        $blockJson = $_POST['block_json'] ?? '{}';

        if (!$pageId) {
            Response::json(['error' => 'Page ID required'], 400);
        }

        // Snapshot full page state before adding a block
        Revision::snapshotPage($pageId);

        // Get max sort order (default: append at end)
        $max = DB::fetch("SELECT MAX(sort_order) as max_order FROM content_blocks WHERE page_id = ?", [$pageId]);
        $sortOrder = ($max['max_order'] ?? 0) + 1;

        // Optional positional insertion
        $afterBlockId = (int) ($_POST['after_block_id'] ?? 0);
        $beforeBlockId = (int) ($_POST['before_block_id'] ?? 0);

        if ($afterBlockId) {
            $ref = DB::fetch("SELECT sort_order FROM content_blocks WHERE id = ? AND page_id = ?", [$afterBlockId, $pageId]);
            if ($ref) {
                $sortOrder = (int) $ref['sort_order'] + 1;
                DB::execute("UPDATE content_blocks SET sort_order = sort_order + 1 WHERE page_id = ? AND sort_order >= ?", [$pageId, $sortOrder]);
            }
        } elseif ($beforeBlockId) {
            $ref = DB::fetch("SELECT sort_order FROM content_blocks WHERE id = ? AND page_id = ?", [$beforeBlockId, $pageId]);
            if ($ref) {
                $sortOrder = (int) $ref['sort_order'];
                DB::execute("UPDATE content_blocks SET sort_order = sort_order + 1 WHERE page_id = ? AND sort_order >= ?", [$pageId, $sortOrder]);
            }
        }

        // Default content for each block type
        $defaults = [
            'hero' => ['title' => 'New Hero', 'subtitle' => 'Add your subtitle here', 'button' => 'Learn More', 'url' => '#'],
            'text' => ['content' => '<p>Add your content here...</p>'],
            'cta' => ['title' => 'Call to Action', 'text' => 'Your message here', 'button' => 'Get Started', 'url' => '#'],
            'features' => ['items' => [['icon' => '⭐', 'title' => 'Feature 1', 'description' => 'Description here']]],
            'stats' => ['items' => [['value' => '100+', 'label' => 'Customers']]],
            'testimonials' => ['items' => [['quote' => 'Great product!', 'name' => 'John Doe', 'role' => 'CEO']]],
            'image' => ['url' => '', 'alt' => '', 'caption' => ''],
            'gallery' => ['images' => [], 'columns' => 3],
            'pricing' => ['items' => [['name' => 'Basic', 'price' => '$9', 'period' => '/mo', 'features' => ['Feature 1'], 'button' => 'Get Started', 'url' => '#']]],
            'team' => ['items' => [['name' => 'Team Member', 'role' => 'Position', 'bio' => 'Bio here']]],
            'faq' => ['items' => [['question' => 'Question?', 'answer' => 'Answer here.']]],
            'form' => [],
            'cards'      => ['title' => '', 'items' => [['title' => 'Card', 'description' => 'Description', 'url' => '#', 'button' => 'Learn More']]],
            'video'      => ['title' => '', 'url' => 'https://www.youtube.com/embed/...', 'caption' => ''],
            'divider'    => ['style' => 'line'],
            'carousel'   => ['items' => [['image' => '', 'title' => 'Slide 1', 'text' => '']]],
            'checklist'  => ['title' => '', 'items' => [['text' => 'First item']]],
            'logo_cloud' => ['title' => 'Our Partners', 'items' => [['name' => 'Company', 'url' => '']]],
            'comparison' => ['title' => 'Compare', 'plans' => [['name' => 'Basic', 'features' => [true]], ['name' => 'Pro', 'features' => [true]]], 'features' => ['Feature 1']],
            'tabs'       => ['items' => [['title' => 'Tab 1', 'content' => 'Tab content here.']]],
            'accordion'  => ['title' => '', 'items' => [['title' => 'Item 1', 'content' => 'Content here.']]],
            'timeline'   => ['title' => '', 'items' => [['date' => '2024', 'title' => 'Event', 'text' => 'Description']]],
            'steps'      => ['title' => '', 'items' => [['icon' => 'check', 'title' => 'Step 1', 'description' => 'Description']]],
            'social'     => ['title' => '', 'items' => [['platform' => 'Twitter', 'url' => 'https://twitter.com/...'], ['platform' => 'LinkedIn', 'url' => 'https://linkedin.com/in/...']]],
            'map'        => ['title' => 'Find Us', 'address' => '', 'embed' => ''],
        ];

        $data = json_decode($blockJson, true) ?: [];
        if (empty($data) && isset($defaults[$type])) {
            $data = $defaults[$type];
        }

        DB::execute(
            "INSERT INTO content_blocks (page_id, type, block_json, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, datetime('now'), datetime('now'))",
            [$pageId, $type, json_encode($data), $sortOrder]
        );

        // Invalidate cache
        $page = DB::fetch("SELECT slug FROM pages WHERE id = ?", [$pageId]);
        if ($page) {
            Cache::invalidatePage($page['slug']);
        }

        Response::json(['success' => true, 'id' => DB::connect()->lastInsertId()]);
    }

    public static function schema(): void {
        Auth::require('content.edit');
        $type = $_GET['type'] ?? '';
        // Return the default JSON schema / template for a given block type
        $schemas = [
            'hero'         => ['title' => 'Main Headline', 'subtitle' => 'Supporting text', 'button' => 'Get Started', 'url' => '/contact'],
            'text'         => ['content' => '<p>Your text here...</p>'],
            'cta'          => ['title' => 'Call to Action', 'text' => 'Short description', 'button' => 'Learn More', 'url' => '/contact'],
            'features'     => ['title' => 'Features', 'subtitle' => '', 'items' => [['icon' => 'bolt', 'title' => 'Feature', 'description' => 'Description']], 'columns' => 3],
            'stats'        => ['title' => 'By the Numbers', 'items' => [['value' => '100+', 'label' => 'Clients', 'icon' => 'groups']]],
            'testimonials' => ['title' => 'What Clients Say', 'items' => [['quote' => 'Great work!', 'author' => 'Name', 'role' => 'Title, Company', 'rating' => 5]]],
            'pricing'      => ['title' => 'Pricing', 'subtitle' => '', 'plans' => [['name' => 'Basic', 'price' => '$9', 'period' => '/mo', 'features' => ['Feature 1'], 'button' => 'Start', 'url' => '#', 'featured' => false]]],
            'team'         => ['title' => 'Our Team', 'members' => [['name' => 'Name', 'role' => 'Title', 'bio' => 'Bio', 'photo' => '']]],
            'faq'          => ['title' => 'FAQ', 'items' => [['question' => 'Question?', 'answer' => 'Answer.']]],
            'cards'        => ['title' => '', 'items' => [['title' => 'Card', 'description' => 'Description', 'url' => '#', 'button' => 'Learn More']]],
            'gallery'      => ['title' => '', 'images' => [['url' => '/image.jpg', 'alt' => 'Alt text']], 'columns' => 3],
            'image'        => ['url' => '/image.jpg', 'alt' => 'Description', 'caption' => ''],
            'video'        => ['title' => '', 'url' => 'https://www.youtube.com/embed/...', 'caption' => ''],
            'quote'        => ['text' => 'Notable quote here.', 'author' => 'Author', 'role' => 'Title'],
            'form'         => [],
            'newsletter'   => ['title' => 'Stay Updated', 'text' => 'Subscribe for updates.', 'button' => 'Subscribe', 'placeholder' => 'your@email.com'],
            'divider'      => ['style' => 'line'],
            'carousel'     => ['title' => '', 'items' => [['image' => '', 'title' => 'Slide 1', 'text' => '']]],
            'checklist'    => ['title' => '', 'items' => [['text' => 'First item']]],
            'logo_cloud'   => ['title' => 'Our Partners', 'items' => [['name' => 'Company', 'url' => '']]],
            'comparison'   => ['title' => 'Compare', 'plans' => [['name' => 'Basic', 'features' => [true]], ['name' => 'Pro', 'features' => [true]]], 'features' => ['Feature 1']],
            'tabs'         => ['items' => [['title' => 'Tab 1', 'content' => 'Tab content here.']]],
            'accordion'    => ['title' => '', 'items' => [['title' => 'Item 1', 'content' => 'Content here.']]],
            'timeline'     => ['title' => '', 'items' => [['date' => '2024', 'title' => 'Event', 'text' => 'Description']]],
            'steps'        => ['title' => '', 'items' => [['icon' => 'check', 'title' => 'Step 1', 'description' => 'Description']]],
            'social'       => ['title' => '', 'items' => [['platform' => 'Twitter', 'url' => 'https://twitter.com/...'], ['platform' => 'LinkedIn', 'url' => 'https://linkedin.com/in/...']]],
            'map'          => ['title' => 'Find Us', 'address' => '123 Main St, City, Country', 'embed' => 'https://maps.google.com/maps?q=...&output=embed'],
            'raw_html'     => ['html' => '<div>Custom HTML</div>'],
            'raw_js'       => ['title' => 'Widget', 'js' => '// Your JavaScript here'],
            'game'         => ['title' => 'Play', 'url' => 'https://example.com/game', 'height' => 600],
            'link_tree'    => ['title' => 'Your Name', 'bio' => 'Short bio', 'avatar' => '', 'links' => [['label' => 'Website', 'url' => 'https://example.com', 'icon' => '🌐']]],
            'embed'        => ['title' => 'Embedded Content', 'url' => 'https://example.com/embed', 'height' => 400],
        ];
        $schema = $schemas[$type] ?? new stdClass();
        Response::json(['type' => $type, 'schema' => $schema]);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 22: THEME API
// ─────────────────────────────────────────────────────────────────────────────

class ThemeAPI {
    public static function getHeader(): void {
        Auth::require('content.edit');

        $header = DB::fetch("SELECT * FROM theme_header LIMIT 1") ?? [];

        // Get logo URL if asset exists
        $logoUrl = '';
        if (!empty($header['logo_asset_id'])) {
            $asset = DB::fetch("SELECT hash FROM assets WHERE id = ?", [$header['logo_asset_id']]);
            $logoUrl = $asset ? '/assets/' . $asset['hash'] : '';
        }

        Response::json([
            'site_name' => Settings::get('site_name', ''),
            'tagline' => $header['tagline'] ?? '',
            'logo_url' => $logoUrl,
            'header_bg' => $header['bg_color'] ?? '#1e293b',
            'header_text' => $header['text_color'] ?? '#ffffff'
        ]);
    }

    public static function updateHeader(): void {
        Auth::require('content.edit');
        CSRF::require();

        Settings::set('site_name', $_POST['site_name'] ?? '');

        // Update theme_header table
        $exists = DB::fetch("SELECT id FROM theme_header LIMIT 1");
        if ($exists) {
            DB::execute(
                "UPDATE theme_header SET tagline = ?, bg_color = ?, text_color = ? WHERE id = ?",
                [$_POST['tagline'] ?? '', $_POST['header_bg'] ?? '#1e293b', $_POST['header_text'] ?? '#ffffff', $exists['id']]
            );
        } else {
            DB::execute(
                "INSERT INTO theme_header (tagline, bg_color, text_color) VALUES (?, ?, ?)",
                [$_POST['tagline'] ?? '', $_POST['header_bg'] ?? '#1e293b', $_POST['header_text'] ?? '#ffffff']
            );
        }

        // Handle logo URL - if it's an uploaded asset path, we need to find or store it For now, we store the logo URL in settings if it's not an asset
        $logoUrl = $_POST['logo_url'] ?? '';
        if (!empty($logoUrl)) {
            Settings::set('logo_url', $logoUrl);
        }

        // Regenerate header partial and all pages
        Cache::onContentChange('theme_header');

        Response::json(['success' => true]);
    }

    public static function getNav(): void {
        Auth::require('content.edit');

        $navItems = DB::fetchAll("SELECT label, url FROM nav WHERE visible = 1 ORDER BY sort_order ASC");
        $header = DB::fetch("SELECT * FROM theme_header LIMIT 1") ?? [];

        Response::json([
            'items' => $navItems,
            'nav_bg' => Settings::get('nav_bg', $header['bg_color'] ?? '#1e40af')
        ]);
    }

    public static function updateNav(): void {
        Auth::require('content.edit');
        CSRF::require();

        $items = json_decode($_POST['items'] ?? '[]', true);
        $navBg = $_POST['nav_bg'] ?? '#1e40af';

        // Update nav background
        Settings::set('nav_bg', $navBg);

        // Clear and rebuild nav items
        DB::execute("DELETE FROM nav");

        foreach ($items as $i => $item) {
            if (!empty($item['label']) && !empty($item['url'])) {
                DB::execute(
                    "INSERT INTO nav (label, url, sort_order, visible) VALUES (?, ?, ?, 1)",
                    [$item['label'], $item['url'], $i]
                );
            }
        }

        // Regenerate nav partial and all pages
        Cache::onContentChange('nav');

        Response::json(['success' => true]);
    }

    public static function getFooter(): void {
        Auth::require('content.edit');

        $footer = DB::fetch("SELECT * FROM theme_footer LIMIT 1") ?? [];

        Response::json([
            'footer_text' => $footer['text'] ?? '',
            'footer_bg' => $footer['bg_color'] ?? '#1e293b',
            'footer_text_color' => $footer['text_color'] ?? '#ffffff'
        ]);
    }

    public static function updateFooter(): void {
        Auth::require('content.edit');
        CSRF::require();

        $exists = DB::fetch("SELECT id FROM theme_footer LIMIT 1");
        if ($exists) {
            DB::execute(
                "UPDATE theme_footer SET text = ?, bg_color = ?, text_color = ? WHERE id = ?",
                [$_POST['footer_text'] ?? '', $_POST['footer_bg'] ?? '#1e293b', $_POST['footer_text_color'] ?? '#ffffff', $exists['id']]
            );
        } else {
            DB::execute(
                "INSERT INTO theme_footer (text, bg_color, text_color) VALUES (?, ?, ?)",
                [$_POST['footer_text'] ?? '', $_POST['footer_bg'] ?? '#1e293b', $_POST['footer_text_color'] ?? '#ffffff']
            );
        }

        // Regenerate footer partial and all pages
        Cache::onContentChange('theme_footer');

        Response::json(['success' => true]);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 23: MEDIA API
// ─────────────────────────────────────────────────────────────────────────────

class MediaAPI {
    public static function list(): void {
        Auth::require('media.view');

        $assets = DB::fetchAll("SELECT id, filename, mime_type, hash, file_size FROM assets ORDER BY created_at DESC");

        $result = [];
        foreach ($assets as $asset) {
            $result[] = [
                'id' => (int)$asset['id'],
                'filename' => $asset['filename'],
                'hash' => $asset['hash'],
                'mime_type' => $asset['mime_type'],
                'size' => $asset['file_size'],
                'is_image' => str_starts_with($asset['mime_type'], 'image/'),
                'url' => '/assets/' . $asset['hash']
            ];
        }

        Response::json(['success' => true, 'assets' => $result]);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 24: AI PAGE API
// ─────────────────────────────────────────────────────────────────────────────

class AIPageAPI {
    // Block recommendations per page type
    private static array $pageTypeBlocks = [
        'landing' => ['hero', 'features', 'stats', 'testimonials', 'logo_cloud', 'pricing', 'faq', 'cta'],
        'about' => ['hero', 'text', 'timeline', 'team', 'stats', 'quote', 'gallery', 'cta'],
        'services' => ['hero', 'cards', 'features', 'steps', 'pricing', 'testimonials', 'faq', 'cta', 'form'],
        'pricing' => ['hero', 'pricing', 'comparison', 'features', 'faq', 'testimonials', 'cta'],
        'contact' => ['hero', 'form', 'map', 'contact_info', 'faq'],
        'portfolio' => ['hero', 'gallery', 'cards', 'testimonials', 'stats', 'cta'],
        'blog' => ['hero', 'text', 'image', 'quote', 'list', 'cta', 'newsletter'],
        'faq' => ['hero', 'faq', 'accordion', 'contact_info', 'cta'],
        'team' => ['hero', 'team', 'text', 'stats', 'testimonials', 'cta'],
        'product' => ['hero', 'features', 'gallery', 'stats', 'testimonials', 'comparison', 'pricing', 'faq', 'cta'],
        'game' => ['hero', 'game', 'text', 'features', 'cta'],
        'linktree' => ['link_tree'],
        'tool' => ['hero', 'embed', 'text', 'faq', 'cta'],
        'social' => ['hero', 'link_tree', 'gallery', 'cta'],
        'custom' => ['hero', 'text', 'features', 'cards', 'testimonials', 'cta', 'form']
    ];

    // Block type definitions for focused prompts (icons use Material Symbols names)
    private static array $blockDefinitions = [
        'hero' => '{"type": "hero", "content": {"title": "Main Headline", "subtitle": "Supporting text", "button": "CTA Text", "url": "/page"}}',
        'text' => '{"type": "text", "content": {"content": "<p>Rich HTML content with <strong>formatting</strong>, paragraphs, and detailed information.</p>"}}',
        'features' => '{"type": "features", "content": {"title": "Section Title", "subtitle": "Brief description", "items": [{"icon": "bolt", "title": "Feature Name", "description": "Feature description"}], "columns": 3}}',
        'cards' => '{"type": "cards", "content": {"title": "Section Title", "items": [{"icon": "rocket_launch", "title": "Card Title", "description": "Card description", "url": "#", "button": "Learn More"}]}}',
        'stats' => '{"type": "stats", "content": {"title": "By The Numbers", "items": [{"value": "100+", "label": "Customers", "icon": "groups"}]}}',
        'testimonials' => '{"type": "testimonials", "content": {"title": "What Our Clients Say", "items": [{"quote": "Detailed testimonial text...", "author": "Full Name", "role": "Title, Company", "rating": 5}]}}',
        'pricing' => '{"type": "pricing", "content": {"title": "Pricing Plans", "subtitle": "Choose your plan", "plans": [{"name": "Basic", "price": "$9", "period": "/month", "features": ["Feature 1", "Feature 2"], "button": "Get Started", "url": "#", "featured": false}]}}',
        'team' => '{"type": "team", "content": {"title": "Our Team", "subtitle": "Meet the experts", "members": [{"name": "Full Name", "role": "Job Title", "bio": "Brief bio", "photo": "/photo.jpg"}]}}',
        'faq' => '{"type": "faq", "content": {"title": "Frequently Asked Questions", "items": [{"question": "Common question?", "answer": "Detailed answer..."}]}}',
        'cta' => '{"type": "cta", "content": {"title": "Ready to Get Started?", "text": "Call to action description", "button": "Start Now", "url": "/contact"}}',
        'form' => '{"type": "form", "content": {"title": "Contact Us", "fields": [{"type": "text", "label": "Name", "required": true}, {"type": "email", "label": "Email"}, {"type": "textarea", "label": "Message"}], "button": "Send"}}',
        'quote' => '{"type": "quote", "content": {"text": "Inspirational or notable quote", "author": "Author Name", "role": "Title"}}',
        'gallery' => '{"type": "gallery", "content": {"title": "Gallery", "images": [{"url": "/img1.jpg", "alt": "Description"}], "columns": 3}}',
        'timeline' => '{"type": "timeline", "content": {"title": "Our Journey", "items": [{"date": "2020", "title": "Milestone", "description": "What happened"}]}}',
        'steps' => '{"type": "steps", "content": {"title": "How It Works", "items": [{"number": 1, "title": "Step Name", "description": "Step description"}]}}',
        'checklist' => '{"type": "checklist", "content": {"title": "Whats Included", "items": ["Feature one", "Feature two", "Feature three"]}}',
        'logo_cloud' => '{"type": "logo_cloud", "content": {"title": "Trusted By", "logos": [{"name": "Company Name", "url": "/logo.png"}]}}',
        'comparison' => '{"type": "comparison", "content": {"title": "Compare Options", "headers": ["Feature", "Basic", "Pro"], "rows": [["Storage", "10GB", "100GB"]]}}',
        'accordion' => '{"type": "accordion", "content": {"title": "More Information", "items": [{"title": "Section Title", "content": "<p>Expandable content</p>"}]}}',
        'tabs' => '{"type": "tabs", "content": {"items": [{"label": "Tab 1", "content": "<p>Tab content</p>"}]}}',
        'list' => '{"type": "list", "content": {"title": "Key Points", "style": "icon", "items": [{"icon": "check_circle", "text": "List item"}]}}',
        'table' => '{"type": "table", "content": {"title": "Data Table", "headers": ["Name", "Value"], "rows": [["Item", "Data"]]}}',
        'newsletter' => '{"type": "newsletter", "content": {"title": "Stay Updated", "text": "Subscribe to our newsletter", "button": "Subscribe", "placeholder": "Enter your email"}}',
        'download' => '{"type": "download", "content": {"title": "Download Resource", "description": "Description of the downloadable", "file": "/file.pdf", "button": "Download Now"}}',
        'alert' => '{"type": "alert", "content": {"type": "info", "title": "Notice", "text": "Important information"}}',
        'progress' => '{"type": "progress", "content": {"title": "Progress", "items": [{"label": "Step 1", "value": 100}]}}',
        'map' => '{"type": "map", "content": {"title": "Find Us", "address": "123 Main St, City, State"}}',
        'contact_info' => '{"type": "contact_info", "content": {"items": [{"icon": "mail", "label": "Email", "value": "hello@example.com", "url": "mailto:hello@example.com"}]}}',
        'social' => '{"type": "social", "content": {"title": "Follow Us", "links": [{"platform": "twitter", "url": "#", "icon": "share"}]}}',
        'video' => '{"type": "video", "content": {"title": "Watch", "url": "https://youtube.com/embed/...", "caption": "Video description"}}',
        'carousel' => '{"type": "carousel", "content": {"items": [{"image": "/slide.jpg", "title": "Slide Title", "text": "Slide text"}]}}',
        'image' => '{"type": "image", "content": {"url": "/image.jpg", "alt": "Image description", "caption": "Optional caption"}}',
        'divider' => '{"type": "divider", "content": {"style": "line"}}',
        'spacer' => '{"type": "spacer", "content": {"size": "medium"}}',
        'columns' => '{"type": "columns", "content": {"columns": [{"content": "<p>Column 1</p>"}, {"content": "<p>Column 2</p>"}]}}',
        'raw_html' => '{"type": "raw_html", "content": {"html": "<div>Custom HTML block</div>"}}',
        'raw_js' => '{"type": "raw_js", "content": {"title": "Interactive Widget", "js": "// your JavaScript here"}}',
        'game' => '{"type": "game", "content": {"title": "Play Now", "url": "https://example.com/game", "height": 600}}',
        'link_tree' => '{"type": "link_tree", "content": {"title": "Your Name", "bio": "Short bio", "avatar": "/photo.jpg", "links": [{"label": "Website", "url": "https://example.com", "icon": "🌐"}, {"label": "Twitter", "url": "https://twitter.com/", "icon": "🐦"}]}}',
        'embed' => '{"type": "embed", "content": {"title": "Embedded Content", "url": "https://example.com/embed", "height": 400}}'
    ];

    public static function generate(): void {
        // Suppress HTML error output for JSON API
        ini_set('html_errors', '0');

        try {
            Auth::require('content.edit');

            $input = json_decode(file_get_contents('php://input'), true);
            $prompt = trim($input['prompt'] ?? '');
            $pageType = $input['pageType'] ?? 'custom';
            $recommendedBlocks = $input['recommendedBlocks'] ?? [];

            if (empty($prompt)) {
                Response::json(['success' => false, 'error' => 'Please provide a description for the page.'], 400);
            }

            if (!AI::isConfigured()) {
                Response::json(['success' => false, 'error' => 'AI is not configured. Please set up an API key in Settings.'], 400);
            }

            $siteContext = self::getSiteContext();
            $fullPrompt = self::buildPrompt($prompt, $pageType, $recommendedBlocks, $siteContext);
            $result = AI::generate($fullPrompt);

            if (!$result) {
                Response::json(['success' => false, 'error' => 'AI generation failed. Please try again.'], 500);
            }

            // Normalize the response
            $page = [
                'success' => true,
                'title' => $result['title'] ?? 'New Page',
                'slug' => $result['slug'] ?? self::generateSlug($result['title'] ?? 'new-page'),
                'meta_description' => $result['meta_description'] ?? '',
                'blocks' => $result['blocks'] ?? [],
                'add_to_nav' => $result['add_to_nav'] ?? true
            ];

            // Ensure slug is URL-safe
            $page['slug'] = preg_replace('/[^a-z0-9\-]/', '', strtolower(str_replace(' ', '-', $page['slug'])));

            Response::json($page);
        } catch (Exception $e) {
            Response::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    private static function generateSlug(string $title): string {
        $slug = strtolower(trim($title));
        $slug = preg_replace('/[^a-z0-9\s\-]/', '', $slug);
        $slug = preg_replace('/[\s\-]+/', '-', $slug);
        return trim($slug, '-');
    }

    private static function getSiteContext(): array {
        $siteName = Settings::get('site_name', '');
        $tagline  = Settings::get('tagline', '');

        $colorRows = DB::fetchAll("SELECT key, value FROM theme_styles WHERE key LIKE 'color_%'");
        $colors = [];
        foreach ($colorRows as $row) {
            // Strip "color_" prefix to match the structure used in buildPagePrompt()
            $colors[substr($row['key'], 6)] = $row['value'];
        }

        return [
            'site_name' => $siteName,
            'tagline'   => $tagline,
            'colors'    => $colors,
        ];
    }

    private static function buildPrompt(string $userPrompt, string $pageType = 'custom', array $recommendedBlocks = [], array $siteContext = []): string {
        // Get blocks for this page type
        $blocks = !empty($recommendedBlocks) ? $recommendedBlocks : (self::$pageTypeBlocks[$pageType] ?? self::$pageTypeBlocks['custom']);

        // Build focused block definitions for recommended blocks only
        $blockDefs = [];
        foreach ($blocks as $blockType) {
            if (isset(self::$blockDefinitions[$blockType])) {
                $blockDefs[] = "- {$blockType}: " . self::$blockDefinitions[$blockType];
            }
        }
        $blockDefsStr = implode("\n", $blockDefs);

        // Build site context section from existing theme/brand
        $siteName  = $siteContext['site_name'] ?? '';
        $tagline   = $siteContext['tagline'] ?? '';
        $colors    = $siteContext['colors'] ?? [];
        $primaryColor   = $colors['primary'] ?? '';
        $secondaryColor = $colors['secondary'] ?? '';
        $accentColor    = $colors['accent'] ?? '';

        $siteContextBlock = '';
        if ($siteName || $primaryColor) {
            $colorParts = array_filter([
                $primaryColor   ? "primary={$primaryColor}"     : '',
                $secondaryColor ? "secondary={$secondaryColor}" : '',
                $accentColor    ? "accent={$accentColor}"       : '',
            ]);
            $siteContextBlock = "\nSITE CONTEXT (this page must match the existing site):\n";
            if ($siteName) $siteContextBlock .= "- Site name: {$siteName}\n";
            if ($tagline)  $siteContextBlock .= "- Tagline: {$tagline}\n";
            if (!empty($colorParts)) {
                $siteContextBlock .= "- Color palette: " . implode(', ', $colorParts) . "\n";
            }
            $siteContextBlock .= "Keep tone, style, and content consistent with this established site identity.\n";
        }

        // Page type guidance
        $typeGuidance = match($pageType) {
            'landing'   => "This is a LANDING PAGE. Start with an impactful hero, showcase key features/benefits, include social proof (testimonials, stats, logos), and end with a strong CTA.",
            'about'     => "This is an ABOUT PAGE. Tell the company story, introduce the team, highlight values/mission, show milestones/timeline, and include trust-building elements.",
            'services'  => "This is a SERVICES PAGE. Clearly present service offerings with cards, explain the process/steps, show pricing if applicable, include testimonials, and add a contact form.",
            'pricing'   => "This is a PRICING PAGE. Present pricing plans clearly with comparison, highlight the recommended plan, address common questions in FAQ, and include testimonials for trust.",
            'contact'   => "This is a CONTACT PAGE. Include a user-friendly form, show contact information (email, phone, address), embed a map if applicable, and add FAQ for common questions.",
            'portfolio' => "This is a PORTFOLIO PAGE. Showcase work with a gallery, highlight key projects with cards, include client testimonials and stats, end with a CTA.",
            'blog'      => "This is a BLOG/ARTICLE PAGE. Focus on rich text content, use quotes for emphasis, include relevant images, and end with newsletter signup or related content CTA.",
            'faq'       => "This is an FAQ PAGE. Organize questions logically, provide detailed answers, include contact info for unanswered questions.",
            'team'      => "This is a TEAM PAGE. Present team members with photos and bios, show company culture, include stats about the team, add testimonials.",
            'product'   => "This is a PRODUCT PAGE. Highlight key features, show the product with gallery/images, include specs in a table, add testimonials and comparison with competitors, show pricing.",
            default     => "Create a well-structured page that serves the user's needs with appropriate content blocks."
        };

        return <<<PROMPT
You are generating a {$pageType} page for a website.
{$siteContextBlock}
USER REQUEST: "{$userPrompt}"

PAGE TYPE GUIDANCE: {$typeGuidance}

RESPOND WITH JSON:
{
  "title": "Page Title",
  "slug": "url-friendly-slug",
  "meta_description": "SEO description (150-160 chars)",
  "add_to_nav": true,
  "blocks": [/* array of content blocks */]
}

RECOMMENDED BLOCKS FOR THIS PAGE TYPE (use these in logical order):
{$blockDefsStr}

CONTENT GUIDELINES:
1. Generate REAL, detailed content - not placeholder text
2. Use specific numbers, names, and examples relevant to the user's description
3. Each block should have complete, meaningful content
4. For items arrays, include 3-6 items with varied, realistic content
5. Write in a professional but engaging tone that matches the site identity above
6. Ensure content flows logically from one block to the next
7. For icons, use Material Symbols names (lowercase with underscores). Examples: star, check_circle, rocket_launch, shield, speed, lightbulb, favorite, trending_up, verified, bolt, groups, person, settings, support, payments, handshake, eco, security, insights, analytics

BLOCK ORDER BEST PRACTICES:
- Start with hero to grab attention
- Follow with key value propositions (features, stats)
- Add social proof in the middle (testimonials, logos)
- Include detailed content (text, cards, tabs)
- End with CTA or contact form

RESPOND ONLY WITH VALID JSON - no markdown, no explanation, no code blocks.
PROMPT;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 25: AI INTEGRATION
// ─────────────────────────────────────────────────────────────────────────────

class AI {
    /** @var callable|null  Heartbeat callback invoked every ~10 s during blocking HTTP calls (SSE context only). */
    private static $heartbeatFn = null;
    public static function setHeartbeat(?callable $fn): void { self::$heartbeatFn = $fn; }

    // Get the configured AI provider settings
    private static function getProvider(): array {
        return [
            'provider' => Settings::get('ai_provider', 'openai'),
            'api_key' => Settings::get('ai_api_key', ''),
            'model' => Settings::get('ai_model', 'gpt-5.4-pro')
        ];
    }

    // Check if AI is configured
    public static function isConfigured(): bool {
        $config = self::getProvider();
        return !empty($config['api_key']);
    }

    // Multi-turn chat: accepts an array of {role, content} messages and returns plain text. Used by AIConversation for the interactive design wizard.
    public static function generateChat(array $messages, string $systemPrompt = '', int $maxTokens = 1024): string {
        $config = self::getProvider();
        if (empty($config['api_key'])) return 'AI is not configured yet.';

        $text = match($config['provider']) {
            'anthropic' => self::anthropicChatRequest($messages, $systemPrompt, $config, $maxTokens),
            'google'    => self::googleChatRequest($messages, $systemPrompt, $config, $maxTokens),
            default     => self::openaiChatRequest($messages, $systemPrompt, $config, $maxTokens),
        };

        return $text ?? 'Sorry, I could not get a response. Please try again.';
    }

    // Retry wrapper around httpPost — backs off on rate-limit / transient server errors. Only retries on 429, 500, 502, 503, 504; passes all other codes through immediately.
    private static function httpPostWithRetry(
        string $url, array $headers, array $payload, int $timeout = 120, int $maxRetries = 2
    ): array {
        $retryable = [429, 500, 502, 503, 504];
        $attempt   = 0;
        do {
            $result = self::httpPost($url, $headers, $payload, $timeout);
            if (!in_array($result['code'], $retryable, true)) {
                return $result;
            }
            $attempt++;
            if ($attempt <= $maxRetries) {
                sleep(min(2 ** ($attempt - 1), 8)); // 1 s, 2 s, 4 s …
            }
        } while ($attempt <= $maxRetries);
        return $result;
    }

    /** Shared HTTP POST helper — works without the curl extension */
    private static function httpPost(string $url, array $headers, array $payload, int $timeout = 120): array {
        // In SSE context a heartbeat is registered; use socket transport so we can send
        // keepalive pings every 10 s and stay under nginx fastcgi_read_timeout.
        if (self::$heartbeatFn !== null) {
            return self::httpPostWithHeartbeat($url, $headers, $payload, $timeout, self::$heartbeatFn);
        }
        $body = json_encode($payload);
        $headerLines = array_map(fn($k, $v) => "$k: $v", array_keys($headers), $headers);
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", $headerLines),
            'content'       => $body,
            'timeout'       => $timeout,
            'ignore_errors' => true,   // get body even on 4xx/5xx
        ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $response = @file_get_contents($url, false, $ctx);
        // Parse HTTP status from $http_response_header
        $code = 200;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) { $code = (int)$m[1]; }
        }
        return ['code' => $code, 'body' => $response];
    }

    /** HTTP POST via raw socket — calls $heartbeat() every ~10 s while waiting for the AI response.
     *  Handles HTTPS, HTTP/1.1 chunked transfer-encoding, and TLS certificate validation. */
    private static function httpPostWithHeartbeat(
        string $url, array $headers, array $payload, int $timeout, callable $heartbeat
    ): array {
        $body   = json_encode($payload);
        $parsed = parse_url($url);
        $scheme = strtolower($parsed['scheme'] ?? 'https');
        $host   = $parsed['host'] ?? '';
        $port   = (int)($parsed['port'] ?? ($scheme === 'https' ? 443 : 80));
        $path   = $parsed['path'] ?? '/';
        if (isset($parsed['query'])) $path .= '?' . $parsed['query'];

        $remote = ($scheme === 'https' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $sslCtx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $fp     = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $sslCtx);
        if (!$fp) {
            error_log("AI: socket connect failed to {$remote}: {$errstr} ({$errno})");
            return ['code' => 0, 'body' => ''];
        }

        // Connection: close so the server signals EOF when the response is complete
        $reqLines = ["Host: {$host}", 'Content-Type: application/json',
                     'Content-Length: ' . strlen($body), 'Connection: close'];
        foreach ($headers as $k => $v) {
            if (strtolower($k) === 'content-type') continue; // already included
            $reqLines[] = "{$k}: {$v}";
        }
        $request = "POST {$path} HTTP/1.1\r\n" . implode("\r\n", $reqLines) . "\r\n\r\n" . $body;
        if (@fwrite($fp, $request) === false) { fclose($fp); return ['code' => 0, 'body' => '']; }

        // Read in 1-second ticks; emit a heartbeat comment every 10 s
        stream_set_blocking($fp, false);
        $raw           = '';
        $deadline      = time() + $timeout;
        $lastHeartbeat = time();
        while (!feof($fp) && time() <= $deadline) {
            $r = [$fp]; $w = null; $e = null;
            if (stream_select($r, $w, $e, 1, 0) > 0) {
                $chunk = @fread($fp, 65536);
                if ($chunk !== false) $raw .= $chunk;
            }
            if ((time() - $lastHeartbeat) >= 10) {
                $heartbeat();
                $lastHeartbeat = time();
            }
        }
        fclose($fp);

        $sep = strpos($raw, "\r\n\r\n");
        if ($sep === false) return ['code' => 0, 'body' => ''];
        $rawHead = substr($raw, 0, $sep);
        $rawBody = substr($raw, $sep + 4);
        if (stripos($rawHead, 'transfer-encoding: chunked') !== false) {
            $rawBody = self::unchunk($rawBody);
        }
        $code = 200;
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $rawHead, $m)) $code = (int)$m[1];
        return ['code' => $code, 'body' => $rawBody];
    }

    /** Decode HTTP/1.1 chunked transfer-encoding */
    private static function unchunk(string $data): string {
        $out = ''; $pos = 0; $len = strlen($data);
        while ($pos < $len) {
            $eol = strpos($data, "\r\n", $pos);
            if ($eol === false) break;
            $size = hexdec(trim(substr($data, $pos, $eol - $pos)));
            if ($size === 0) break;
            $pos  = $eol + 2;
            $out .= substr($data, $pos, $size);
            $pos += $size + 2;
        }
        return $out;
    }

    private static function openaiChatRequest(array $messages, string $system, array $config, int $maxTokens = 1024): ?string {
        $payload = ['model' => $config['model'], 'temperature' => 0.8, 'max_completion_tokens' => $maxTokens,
            'messages' => array_merge($system ? [['role' => 'system', 'content' => $system]] : [], $messages)];
        $r = self::httpPost('https://api.openai.com/v1/chat/completions',
            ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $config['api_key']], $payload);
        if ($r['code'] !== 200) {
            error_log('OpenAI chat error HTTP ' . $r['code'] . ': ' . $r['body']);
            return null;
        }
        $data = json_decode($r['body'], true);
        return $data['choices'][0]['message']['content'] ?? null;
    }

    private static function anthropicChatRequest(array $messages, string $system, array $config, int $maxTokens = 1024): ?string {
        $model = $config['model'];
        if (!str_starts_with($model, 'claude')) $model = 'claude-sonnet-4-6';
        $payload = ['model' => $model, 'max_tokens' => $maxTokens, 'messages' => $messages];
        if ($system) $payload['system'] = $system;
        $r = self::httpPost('https://api.anthropic.com/v1/messages',
            ['Content-Type' => 'application/json', 'X-API-Key' => $config['api_key'], 'anthropic-version' => '2023-06-01'], $payload);
        $data = json_decode($r['body'], true);
        return $data['content'][0]['text'] ?? null;
    }

    private static function googleChatRequest(array $messages, string $system, array $config, int $maxTokens = 1024): ?string {
        $model = $config['model'];
        if (!str_contains($model, 'gemini')) $model = 'gemini-3-flash';
        $parts = [];
        if ($system) $parts[] = ['text' => $system . "\n\n"];
        foreach ($messages as $m) $parts[] = ['text' => ($m['role'] === 'user' ? 'User: ' : 'Assistant: ') . $m['content'] . "\n"];
        $payload = ['contents' => [['parts' => $parts]], 'generationConfig' => ['maxOutputTokens' => $maxTokens, 'temperature' => 0.8]];
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . $config['api_key'];
        $r = self::httpPost($url, ['Content-Type' => 'application/json'], $payload);
        if ($r['code'] !== 200) {
            error_log('Google Gemini chat error HTTP ' . $r['code'] . ': ' . $r['body']);
            return null;
        }
        $data = json_decode($r['body'], true);
        return $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
    }

    // Generate content using the configured AI provider
    public static function generate(string $prompt, array $ctx = []): ?array {
        $config = self::getProvider();

        if (empty($config['api_key'])) {
            error_log('AI::generate — no API key configured');
            return null;
        }

        $start    = microtime(true);
        $response = match($config['provider']) {
            'anthropic' => self::anthropicRequest($prompt, $config),
            'google'    => self::googleRequest($prompt, $config),
            default     => self::openaiRequest($prompt, $config),
        };
        $durationMs = (int)round((microtime(true) - $start) * 1000);

        if (!$response) {
            error_log('AI::generate — no response from provider (' . $config['provider'] . ')');
            self::writeLog($ctx, $config, strlen($prompt), null, false, $durationMs);
            return null;
        }

        $json = self::extractJson($response);

        if (!$json) {
            error_log('AI::generate — failed to parse JSON from response');
        }

        self::writeLog($ctx, $config, strlen($prompt), $response, $json !== null, $durationMs);

        return $json;
    }

    // Write an AI call record to ai_logs for debugging and analysis
    private static function writeLog(array $ctx, array $config, int $promptLen, ?string $raw, bool $parsedOk, int $durationMs): void {
        try {
            DB::execute(
                "INSERT INTO ai_logs (stage, page_slug, model, provider, prompt_length, raw_response, parsed_ok, duration_ms)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $ctx['stage']     ?? null,
                    $ctx['page_slug'] ?? null,
                    $config['model']    ?? null,
                    $config['provider'] ?? null,
                    $promptLen,
                    $raw,
                    $parsedOk ? 1 : 0,
                    $durationMs,
                ]
            );
        } catch (\Throwable $e) {
            error_log('ai_logs write failed: ' . $e->getMessage());
        }
    }

    // Make a request to OpenAI API (with retry on transient errors)
    private static function openaiRequest(string $prompt, array $config): ?string {
        $payload = [
            'model'       => $config['model'],
            'messages'    => [
                ['role' => 'system', 'content' => self::getSystemPrompt()],
                ['role' => 'user',   'content' => $prompt],
            ],
            'temperature'            => 0.7,
            'max_completion_tokens'  => 32000,
        ];
        $r = self::httpPostWithRetry(
            'https://api.openai.com/v1/chat/completions',
            ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $config['api_key']],
            $payload, 240
        );
        if ($r['code'] !== 200) {
            error_log('OpenAI API error: HTTP ' . $r['code'] . ': ' . $r['body']);
            return null;
        }
        $data = json_decode($r['body'], true);
        return $data['choices'][0]['message']['content'] ?? null;
    }

    // Make a request to Anthropic API (with retry on transient errors)
    private static function anthropicRequest(string $prompt, array $config): ?string {
        $model = $config['model'];
        if (str_starts_with($model, 'gpt') || str_starts_with($model, 'gemini')) {
            $model = 'claude-sonnet-4-6';
        }
        $payload = [
            'model'      => $model,
            'max_tokens' => 32000,
            'system'     => self::getSystemPrompt(),
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ];
        $r = self::httpPostWithRetry(
            'https://api.anthropic.com/v1/messages',
            ['Content-Type' => 'application/json', 'x-api-key' => $config['api_key'], 'anthropic-version' => '2023-06-01'],
            $payload, 240
        );
        if ($r['code'] !== 200) {
            error_log('Anthropic API error: HTTP ' . $r['code']);
            return null;
        }
        $data = json_decode($r['body'], true);
        return $data['content'][0]['text'] ?? null;
    }

    // Make a request to Google Gemini API (with retry on transient errors)
    private static function googleRequest(string $prompt, array $config): ?string {
        $model = $config['model'];
        if (str_starts_with($model, 'gpt') || str_starts_with($model, 'claude')) {
            $model = 'gemini-3-flash';
        }
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . $config['api_key'];
        $payload = [
            'contents'         => [['parts' => [['text' => self::getSystemPrompt() . "\n\n" . $prompt]]]],
            'generationConfig' => ['temperature' => 0.7, 'maxOutputTokens' => 60000],
        ];
        $r = self::httpPostWithRetry($url, ['Content-Type' => 'application/json'], $payload, 300);
        if ($r['code'] !== 200) {
            error_log('Google Gemini API error: HTTP ' . $r['code']);
            return null;
        }
        $data = json_decode($r['body'], true);
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (!$text) { error_log('Google Gemini API: no text in response'); }
        return $text;
    }

    // Extract JSON from AI response (handles markdown code blocks)
    private static function extractJson(string $response): ?array {
        // Try direct JSON parse first
        $json = json_decode($response, true);
        if ($json !== null) {
            return $json;
        }

        // Try to extract from markdown code block
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $response, $matches)) {
            $json = json_decode(trim($matches[1]), true);
            if ($json !== null) {
                return $json;
            }
        }

        // Try to find JSON object in the response
        if (preg_match('/\{[\s\S]*\}/', $response, $matches)) {
            $json = json_decode($matches[0], true);
            if ($json !== null) {
                return $json;
            }
        }

        // Repair pass: AI sometimes emits unescaped straight double-quotes for display-text
        // e.g. HTML >"Some quote"< — those bare " break JSON string parsing.
        // Use a targeted regex: only replace " that appear immediately after > and before <
        // (i.e. quoted text sitting between HTML tags). This avoids breaking "html": "<section..."
        // JSON string boundaries that also start with a bare quote followed by <.
        $repaired = preg_replace('/(?<=>)"([^"<>\n]*)"(?=<)/u', '&ldquo;$1&rdquo;', $response);
        if ($repaired !== null && $repaired !== $response) {
            $json = json_decode($repaired, true);
            if ($json !== null) {
                return $json;
            }
            // Also try extracting the JSON object from the repaired text
            if (preg_match('/\{[\s\S]*\}/', $repaired, $matches)) {
                $json = json_decode($matches[0], true);
                if ($json !== null) {
                    return $json;
                }
            }
        }

        return null;
    }

    // Get the system prompt for site generation
    private static function getSystemPrompt(): string {
        $prompt = <<<'PROMPT'
You are a creative web design AI. You build fully custom, unique websites tailored to each client's specific vision — no templates, no presets. Every design decision is driven by what's right for this particular site.

IMPORTANT: Respond ONLY with valid JSON. No explanation, no markdown.

Output structure:
{
  "site_name": "...",
  "tagline": "...",
  "colors": {
    "primary": "#hex",
    "secondary": "#hex",
    "accent": "#hex",
    "background": "#hex",
    "text": "#hex",
    "header_bg": "#hex",
    "header_text": "#hex",
    "footer_bg": "#hex",
    "footer_text": "#hex",
    "cta_bg": "#hex",
    "cta_text": "#hex"
  },
  "pages": [
    {
      "slug": "home",
      "title": "Home",
      "meta_description": "...",
      "layout": {
        "show_nav": true,
        "show_footer": true,
        "show_page_title": false,
        "nav_style": "sticky"
      },
      "blocks": []
    }
  ],
  "nav": [{"label": "Home", "url": "/"}],
  "footer": {
    "text": "© 2025 Site Name.",
    "links": [],
    "social": []
  }
}

DESIGN PHILOSOPHY:
- Design each website as a unique creative work — not a template fill-in
- Let the client's vision drive all decisions: color, structure, tone, content order
- Choose block types and layouts based on what serves this specific site
- Every page should feel like part of one intentional, cohesive design system
- Content must be specific and real, not placeholder text
- For icons use Material Symbols names (lowercase with underscores): bolt, check_circle, star, rocket_launch, shield, speed, lightbulb, favorite, trending_up, verified, groups, person, settings, support, payments, handshake, eco, security, insights, analytics, mail, phone, location_on, schedule, palette, brush, camera, restaurant, fitness_center, school, home, work, explore

LAYOUT RULES — set per page in the pages array:
- show_page_title: false when the first block (hero, etc.) already presents the page title visually
- nav_style: "transparent" for pages where the hero has a background image and the nav should float above it; "sticky" (default) for all other pages
- show_nav: false only for deliberately nav-free pages (e.g. a standalone splash or landing page)
- show_footer: false only for stripped-down conversion pages that should have no distractions
PROMPT;
        return str_replace('© 2025', '© ' . date('Y'), $prompt);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// AIOrchestrator: Coordinates multiple AI calls to build a full site. Exposes an SSE endpoint that streams progress updates to the client.
class AIOrchestrator {
    /** Send one SSE event. Flushes immediately. */
    private static function sseEvent(string $event, array $data): void {
        echo "event: {$event}\n";
        echo 'data: ' . json_encode($data) . "\n\n";
        if (ob_get_level()) ob_flush();
        flush();
    }

    // SSE endpoint: POST /api/ai/stream Accepts the same parameters as the regular AI form, but streams the multi-stage generation process back to the client in real time.
    public static function stream(): void {
        Auth::require('content.edit');

        // SSE headers
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');      // Nginx proxy_pass: disables proxy buffering
        header('X-Nginx-No-Buffering: yes');  // some Nginx configs check this header
        header('Content-Encoding: identity'); // prevent gzip buffering the stream
        @ini_set('zlib.output_compression', 'Off');
        @ini_set('output_buffering', 'Off');
        set_time_limit(0);
        ignore_user_abort(true);
        ob_implicit_flush(true);
        while (ob_get_level() > 0) ob_end_clean(); // clear all OB levels

        // Force Nginx/FastCGI proxy buffers to flush immediately. Most proxies buffer until 4-8 KB of data arrives; SSE comments (lines starting with :) are silently ignored by the browser's EventSource — safe to send as padding.
        echo str_repeat(": buffer-flush\n", 300) . "\n"; // ~4.5 KB
        flush();

        $input       = json_decode(file_get_contents('php://input'), true) ?? [];
        $bizName     = trim($input['business_name'] ?? '');
        $description = trim($input['description'] ?? '');

        if (empty(trim($bizName . $description))) {
            self::sseEvent('error', ['message' => 'Project name and description are required.']);
            return;
        }

        if (!AI::isConfigured()) {
            self::sseEvent('error', ['message' => 'AI is not configured. Add an API key in Settings.']);
            return;
        }

        // Build a rich creative brief from all provided fields
        $briefParts = [];
        if (!empty($input['user_content']))    $briefParts[] = "Verified facts (use verbatim):\n{$input['user_content']}";
        if ($bizName)                          $briefParts[] = "Project/business name: {$bizName}";
        if ($description)                      $briefParts[] = "Description: {$description}";
        if (!empty($input['target_audience'])) $briefParts[] = "Target audience: {$input['target_audience']}";
        if (!empty($input['visual_style']))    $briefParts[] = "Visual style: {$input['visual_style']}";
        if (!empty($input['color_preference'])) $briefParts[] = "Color direction: {$input['color_preference']}";
        if (!empty($input['pages_needed']))    $briefParts[] = "Pages needed: {$input['pages_needed']}";
        if (!empty($input['features']))        $briefParts[] = "Features/functionality: " . (is_array($input['features']) ? implode(', ', $input['features']) : $input['features']);
        if (!empty($input['design_inspiration'])) $briefParts[] = "Design inspiration: {$input['design_inspiration']}";
        $brief = implode("\n", $briefParts);

        // Send SSE keepalive comments every 10 s so nginx fastcgi_read_timeout (default 60 s)
        // doesn't close the connection while PHP blocks waiting for a slow AI API response.
        AI::setHeartbeat(static function () {
            echo ": heartbeat\n\n";
            if (ob_get_level()) ob_flush();
            flush();
        });

        try {
            // ── Stage 1: Site Structure ──────────────────────────────────
            self::sseEvent('stage', ['stage' => 1, 'label' => 'Designing site structure…', 'total' => 3]);

            $structurePrompt = <<<EOT
You are designing the architecture for a website. Based on the creative brief below, design the complete site structure — freely and intentionally. No templates, no presets.

CREATIVE BRIEF:
{$brief}

Choose everything: how many pages (typically 3-7), what each page accomplishes, a color palette that matches the personality, navigation that serves this site's needs.

Respond with ONLY valid JSON:
{
  "site_name": "...",
  "tagline": "A tagline that captures the brand essence",
  "colors": {
    "primary": "#hex", "secondary": "#hex", "accent": "#hex",
    "background": "#hex", "text": "#hex",
    "header_bg": "#hex", "header_text": "#hex",
    "footer_bg": "#hex", "footer_text": "#hex",
    "cta_bg": "#hex", "cta_text": "#hex"
  },
  "nav": [{"label": "Home", "url": "/"}, {"label": "About", "url": "/about"}],
  "pages": [
    {"slug": "home", "title": "Home", "meta_description": "...", "purpose": "What this page accomplishes", "layout": {"show_nav": true, "show_footer": true, "show_page_title": false, "nav_style": "sticky"}},
    {"slug": "about", "title": "About", "meta_description": "...", "purpose": "...", "layout": {"show_nav": true, "show_footer": true, "show_page_title": true, "nav_style": "sticky"}}
  ],
  "fonts": {
    "heading": "Google Font name for headings — choose something that fits the brand (e.g. Playfair Display, Raleway, Oswald, Montserrat, Cormorant Garamond, Space Grotesk, DM Serif Display)",
    "body": "Google Font name for body text — choose a complement to the heading font (e.g. Lato, Source Sans 3, Nunito, Inter, DM Sans, Figtree)"
  },
  "footer": {"text": "© 2026 Site Name.", "links": [], "social": []},
  "facts": {
    "tagline": "exact tagline — same as above",
    "key_stats": ["stat 1", "stat 2", "stat 3"],
    "core_features": ["feature 1", "feature 2", "feature 3"],
    "value_props": ["what makes this unique", "core benefit 2"]
  }
}

The "facts" object defines canonical content anchors. ALL page generators will receive these verbatim and MUST use them as-is — not paraphrase or contradict them. Choose stats and features that are genuinely specific to this project based on the brief.

SHOW_PAGE_TITLE RULE: Set show_page_title: false for EVERY page without exception. Every page's first block must introduce the page visually (hero, banner, section heading). Never mix true/false across pages — inconsistency breaks the design system.
HASH ANCHORS: When a nav item links to a section within a page use the format "/#slug" (e.g. "/#about", "/#stack", "/#contact"). Use short, lowercase, hyphenated slugs. These will be used as "anchor" values on the matching block so users can scroll directly to that section.
NAV/PAGE PARITY RULE: Every nav item that links to a standalone page (e.g. "/about", "/services") MUST have a corresponding entry in the "pages" array with a matching "slug". Do not add a nav link to a page that is not in your pages list. Hash anchors (e.g. "/#contact") and "/blog" are exempt — they do not require a pages entry.
RESPOND ONLY WITH VALID JSON.
EOT;

            $structure = AI::generate($structurePrompt, ['stage' => 1]);
            if (!$structure) {
                self::sseEvent('error', ['message' => 'Stage 1 failed — AI did not return a structure.']);
                return;
            }

            // Reconcile: add a page entry for every standalone nav link the AI included but forgot to put in the pages array.
            $existingPageSlugs = array_flip(array_column($structure['pages'] ?? [], 'slug'));
            foreach ($structure['nav'] ?? [] as $navItem) {
                $url = $navItem['url'] ?? '';
                if (strpos($url, '#') !== false) continue;
                if (preg_match('/^https?:\/\//', $url)) continue;
                $slug = ltrim(parse_url($url, PHP_URL_PATH) ?? '', '/') ?: 'home';
                if ($slug === 'blog') continue;
                if (!isset($existingPageSlugs[$slug])) {
                    $label = $navItem['label'] ?? ucfirst(str_replace('-', ' ', $slug));
                    $structure['pages'][] = [
                        'slug'             => $slug,
                        'title'            => $label,
                        'meta_description' => '',
                        'purpose'          => "Page for the '{$label}' navigation item",
                        'layout'           => ['show_nav' => true, 'show_footer' => true, 'show_page_title' => true, 'nav_style' => 'sticky'],
                    ];
                    $existingPageSlugs[$slug] = true;
                }
            }

            self::sseEvent('stage_complete', ['stage' => 1, 'data' => [
                'site_name' => $structure['site_name'] ?? '',
                'pages'     => array_column($structure['pages'] ?? [], 'title'),
            ]]);

            // ── Stage 2: Generate Content Per Page ───────────────────────
            $pages            = $structure['pages'] ?? [];
            $builtPages       = [];
            $generatedSamples = []; // first 2 blocks HTML from each completed page — fed into subsequent pages so the LLM sees what was already built and can match the visual fingerprint exactly
            $totalPages       = count($pages);
            $colors      = $structure['colors'] ?? [];
            $primaryColor   = $colors['primary']    ?? '#2563eb';
            $secondaryColor = $colors['secondary']  ?? '#1e40af';
            $accentColor    = $colors['accent']     ?? '#f59e0b';
            $bgColor        = $colors['background'] ?? '#ffffff';
            $textColor      = $colors['text']       ?? '#1e293b';
            $ctaBgColor     = $colors['cta_bg']     ?? $primaryColor;
            $ctaTextColor   = $colors['cta_text']   ?? '#ffffff';

            // Fonts chosen by Stage 1
            $headingFont = !empty($structure['fonts']['heading']) ? $structure['fonts']['heading'] : 'Playfair Display';
            $bodyFont    = !empty($structure['fonts']['body'])    ? $structure['fonts']['body']    : 'Inter';

            // Extract facts bible from Stage 1 for cross-page consistency
            $facts = $structure['facts'] ?? [];
            $factsLines = [];
            if (!empty($facts['tagline']))      $factsLines[] = '- Tagline: "' . $facts['tagline'] . '"';
            if (!empty($facts['key_stats']))    $factsLines[] = '- Key stats: ' . implode(', ', (array)$facts['key_stats']);
            if (!empty($facts['core_features'])) $factsLines[] = '- Core features: ' . implode(', ', (array)$facts['core_features']);
            if (!empty($facts['value_props']))  $factsLines[] = '- Value propositions: ' . implode(' | ', (array)$facts['value_props']);
            $factsBlock = $factsLines
                ? "ESTABLISHED FACTS — use these verbatim across all pages, never contradict or alter them:\n" . implode("\n", $factsLines) . "\n"
                : '';

            foreach ($pages as $i => $pageMeta) {
                $label   = $pageMeta['title'] ?? ('Page ' . ($i + 1));
                $pageNum = $i + 1;
                self::sseEvent('stage', [
                    'stage'        => 2,
                    'label'        => "Generating content for \"{$label}\" ({$pageNum}/{$totalPages})\u{2026}",
                    'message'      => "Writing content for \"{$label}\" ({$pageNum}/{$totalPages})\u{2026}",
                    'page'         => $label,
                    'total'        => 3,
                    'sub_progress' => ['current' => $pageNum, 'total' => $totalPages],
                ]);

                $pagePurpose = $pageMeta['purpose'] ?? "A page titled \"{$label}\"";
                $pageSlug = $pageMeta['slug'] ?? '';

                // Extract nav hash anchors targeting this page
                $navItems = $structure['nav'] ?? [];
                $hashAnchors = [];
                foreach ($navItems as $nav) {
                    $url = $nav['url'] ?? '';
                    if (preg_match('/^\/?(?:' . preg_quote($pageSlug === 'home' ? '' : $pageSlug, '/') . ')#([a-z0-9\-_]+)$/i', $url, $m)) {
                        $hashAnchors[] = '"' . $m[1] . '" (label: ' . ($nav['label'] ?? '') . ')';
                    }
                }
                $anchorHints = '';
                if ($hashAnchors) {
                    $anchorList  = implode(', ', $hashAnchors);
                    $anchorHints = "\nNAV ANCHORS: The site nav deep-links to these anchors on this page: {$anchorList}.\nFor each anchor, add id=\"<anchor>\" directly on the outer <section> element of that block.";
                }

                // Build cross-page consistency block
                $allPagesLines = [];
                foreach ($pages as $j => $p) {
                    $pTitle   = $p['title']   ?? ('Page ' . ($j + 1));
                    $pPurpose = $p['purpose'] ?? '';
                    $pSlug    = $p['slug']    ?? '';
                    $pPath    = $pSlug === 'home' ? '/' : ('/' . $pSlug);
                    $marker   = ($j === $i) ? ' ← YOU ARE WRITING THIS PAGE' : '';
                    $allPagesLines[] = "  - {$pTitle} ({$pPath}): {$pPurpose}{$marker}";
                }
                $allPagesBlock = implode("\n", $allPagesLines);

                // Build prior-pages visual reference — real HTML the LLM has already output so it can match CSS values exactly
                $priorPagesBlock = '';
                if (!empty($generatedSamples)) {
                    $refLines = ["PREVIOUSLY GENERATED PAGES — your output MUST visually match these exactly. Extract and reuse: the same hero background color, same card border-radius, same button padding/font-size, same section padding. Do NOT copy the content — DO replicate every CSS value you see here:"];
                    foreach ($generatedSamples as $sample) {
                        $truncated = mb_substr($sample['html'], 0, 2000);
                        if (mb_strlen($sample['html']) > 2000) $truncated .= "\n\u2026[truncated]";
                        $refLines[] = "\n=== " . $sample['title'] . " (first 2 blocks) ===\n" . $truncated;
                    }
                    $priorPagesBlock = implode("\n", $refLines) . "\n";
                }

                // Build valid internal links from site nav (ground-truth hrefs)
                $navLinkLines = [];
                foreach ($structure['nav'] ?? [] as $nav) {
                    $navUrl   = $nav['url']   ?? '';
                    $navLabel = $nav['label'] ?? '';
                    if ($navUrl) $navLinkLines[] = "  {$navLabel}: {$navUrl}";
                }
                $navLinksBlock = $navLinkLines
                    ? "\nVALID INTERNAL LINKS (use ONLY these exact href paths for all in-site links):\n" . implode("\n", $navLinkLines) . "\n"
                    : '';

                $pagePrompt = <<<EOT
You are generating the HTML sections for one page of a website. Write production-quality, visually stunning HTML that looked like it came from a world-class design agency.

SITE CONTEXT:
- Name: {$structure['site_name']}
- Brief: {$brief}
- Exact color palette (use these — do not invent others):
    primary:    {$primaryColor}
    secondary:  {$secondaryColor}
    accent:     {$accentColor}
    background: {$bgColor}
    text:       {$textColor}
    CTA button: background {$ctaBgColor}, text {$ctaTextColor}

{$factsBlock}
ALL PAGES IN THIS SITE — page {$pageNum} of {$totalPages} (maintain consistency; do not duplicate content from other pages):
{$allPagesBlock}
{$priorPagesBlock}
{$navLinksBlock}
THIS PAGE:
- Title: {$label}
- Slug: {$pageMeta['slug']}
- Purpose: {$pagePurpose}
{$anchorHints}

TYPOGRAPHY — fonts loaded for this site:
- Heading font: {$headingFont} — use style="font-family:'{$headingFont}',serif" on all h1–h4 elements
- Body font:    {$bodyFont}    — use style="font-family:'{$bodyFont}',sans-serif" on body copy and paragraphs
  (These fonts are already loaded via Google Fonts — do not add any <link> tags.)

TECHNICAL ENVIRONMENT (already loaded on every page):
- Bulma CSS — use classes freely: hero, section, container, columns, column, card, box, button, tag, media, notification, title, subtitle, content, has-text-*, is-*, etc.
- Material Icons — <span class="material-symbols-outlined">icon_name</span>
- picsum.photos for images: https://picsum.photos/seed/<word>/<w>/<h>

INSTRUCTIONS:
- Write 4-8 sections. Each section is a complete <section> element.
- Use Bulma layout classes for structure, inline styles for brand colors.
- Every CTA button: style="background:{$ctaBgColor};color:{$ctaTextColor};border:none;padding:.75rem 2rem;border-radius:.5rem;font-weight:600;cursor:pointer;font-size:1rem;display:inline-block;text-decoration:none"
- Vary backgrounds: alternate {$bgColor}, light-tinted, and richly colored sections using the palette.
- Write real, specific, compelling copy — zero placeholder text.
- Add id=\"<anchor>\" on the outer <section> of any block that a nav anchor targets.
- TECHNICAL ACCURACY: For filenames, commands, URLs, and version numbers — use ONLY what was stated in the brief. If a specific detail was not provided, use a clear placeholder like [DOWNLOAD_URL] rather than inventing plausible-sounding specifics.
- QUOTATION MARKS IN HTML: For pull-quotes, blockquotes, or any quoted speech in HTML text content, use `&ldquo;` and `&rdquo;` or Unicode curly quotes — NEVER straight double quotes " — they break the JSON string encoding.
- RESPONSIVE DESIGN — all layouts must work on mobile, tablet, and desktop:
  - Multi-column grids: use `<div class="columns is-multiline">` with responsive column classes: `is-12-mobile is-6-tablet is-4-desktop` (never bare `is-4` alone)
  - Hero sections: avoid `is-fullheight` — use padding/min-height styles so mobile doesn't get a taller-than-viewport hero
  - Never set fixed pixel widths on layout wrappers — use Bulma containers or `max-width` + `width:100%`
  - Hide decorative / non-essential columns on small screens with `is-hidden-mobile`
  - Stack feature cards, testimonials, and pricing tiers vertically on mobile using `is-12-mobile`
- INTERNAL LINKS: Only use paths from VALID INTERNAL LINKS listed above. If the nav says "/#contact", the href must be "/#contact" — never "/contact". For page sections not in the nav, use "/{slug}#anchor" (matching slugs from ALL PAGES).

DESIGN CONSTRAINTS — hard rules, not guidelines:

COLOR CONTRAST (WCAG AA — 4.5:1 minimum):
- Contrast is determined by each element's OWN background — not its parent section's background.
- Dark element background (primary {$primaryColor}, secondary, or any dark color): text MUST be white (#fff). NEVER use {$textColor} on a dark background — it will be unreadable.
- Light element background ({$bgColor}, cream, white, or any light color): text MUST be {$textColor} (dark). NEVER use white or near-white text on a light background.
- CARD INSIDE DARK SECTION: If a card/box has a light background (e.g. white or cream), its text MUST be dark even though the surrounding section is dark. The card's own background is what matters.
- NO REDUCED OPACITY TEXT: Never use rgba(255,255,255,0.4) or similar low-alpha text. If text must be lighter, use #aaa or similar — never drop opacity below 0.85 on any text.
- Rule of thumb: white on dark, dark on light — apply this rule to every single element individually, not just at the section level.

TYPOGRAPHY SCALE — use these exact sizes on every page (deviating breaks cross-page consistency):
- Hero H1:    font-size:clamp(2.5rem,5vw,4rem); line-height:1.1; font-weight:800; max 8 words
- Section H2: font-size:clamp(1.75rem,3vw,2.25rem); line-height:1.2; font-weight:700
- Card H3:    font-size:1.125rem; font-weight:600
- Body:       font-size:1rem; line-height:1.7
- Hero H1 must be a punchy phrase — NOT a full sentence. Wrong: "Homes and places designed with quiet performance." Right: "Architecture That Endures."

VISUAL RHYTHM — cross-page UI consistency (violations create a fragmented, unpolished site):
- HERO BACKGROUND: Every page's hero section must use the same darkest palette color as its background. Never use a lighter shade on one page's hero while other pages use dark — all heroes must feel like one coherent site.
- HERO PADDING: Every hero section must use padding:5rem 1.5rem 4rem — consistent vertical breathing room across all pages.
- SECTION PADDING: Every non-hero body section must use padding:5rem 1.5rem — exactly. Never use 4rem, 4.5rem, 6rem, or any other variation for body sections. The only allowed exception is the hero (uses 4rem bottom) and the footer.
- CARD SURFACE: All cards, feature tiles, and article boxes across the entire site must share ONE consistent surface style: background:#ffffff (or the page's lightest palette color); border:1px solid rgba(R,G,B,0.1) using the darkest-palette-color's RGB values; box-shadow:0 4px 20px rgba(R,G,B,0.05). NEVER use flat-gray backgrounds (#F7F9F8, #f5f5f5) with generic gray borders (#e0e0e0, #ddd) on cards — this creates a mismatched style family that breaks visual cohesion across pages.
- CARD BORDER-RADIUS: All bordered feature cards, article elements, and icon+text boxes must use border-radius:.75rem sitewide. Never mix .5rem and .75rem radius values across or within pages.
- BUTTON SIZE: Primary CTA buttons must always use the full spec: padding:.75rem 2rem; font-size:1rem. Do NOT shrink buttons in secondary contexts (e.g. padding:.6rem 1.5rem or font-size:.9rem) — size consistency builds visual trust across every page.
- GALLERY/PORTFOLIO CARD BACKGROUND: Cards in gallery, cards, featured, and team blocks must ALWAYS use background:#ffffff with the standard border+shadow. The dark-palette background treatment is permitted ONLY for the single "Most Popular" or highlighted tier card inside a pricing block — it must never appear in gallery, portfolio, or article card grids. Mixing dark-bg and white-bg cards in the same grid breaks cohesion across pages.
- H3 FONT SIZE UNIFORMITY: All card/item h3 titles must use font-size:1.125rem across every block type and every page without exception — unless the block is a pricing block, where tier names may use font-size:1.5rem. Never use ad-hoc sizes (1.35rem, 1.2rem, 1.3rem) for individual cards: this creates single-card outliers that look broken.
- TAB/FILTER CONTROLS: Tab or filter toggle buttons (role="tab", portfolio filters, category selectors) may use padding:.75rem 1.25rem to distinguish them visually from full CTA buttons. They must still use border-radius:.5rem and font-weight:600. Never use ad-hoc padding values — keep it consistently .75rem 1.25rem.
- PALETTE COLOR LOCK: Every hex color value used in page blocks MUST be copied verbatim from the palette defined in the structure stage (primary, secondary, accent, background, text, header_bg, etc.). Do NOT re-derive or approximate palette colors on a per-page basis — this causes hex drift (e.g. #eef1ea vs #eef1eb) that breaks cross-page color consistency. Always pick the exact hex from the structure palette; never compute or guess a tint independently per page.
- TAB PANEL CONTAINER: When generating tabbed content blocks, ALL tabpanel divs (role="tabpanel") must be siblings INSIDE the same .container div — not just the first one. A common mistake is closing the .container div after the first panel, leaving all subsequent panels outside it at full page width. Structure must be: <div class="container"> [tablist buttons] [ALL tabpanels] </div>. Verify div nesting before finalizing.
- H2 MARGIN-BOTTOM: All section h2 headings must use margin-bottom:1rem consistently across every page and block. Never use 1.25rem, 1.5rem, or other variations — even in CTA blocks. Uniform heading rhythm prevents visual inconsistency when pages are viewed side by side.

PAGE WIDTH — every section without exception:
- Wrap all content in <div class="container"> inside the section.
- Full-bleed colored backgrounds are fine — but inner content MUST sit inside .container.
- NEVER use style="max-width:Npx" or style="width:Npx" on sections or direct child wrappers.
- NEVER use negative margins (e.g. margin:0 -1rem) on sections or wrappers — the page shell enforces a consistent horizontal gap from screen edges; sections must stay within it.

CARD PADDING:
- Every card, box, or feature tile: minimum padding 1.5rem on all sides.
- Use class="card" with <div class="card-content"> OR class="box" — both have built-in padding.
- Card text, captions, and titles must never sit flush against the card border edge.

INTERACTIVE ELEMENTS — functional JS required or use static layout:
- TABS: If you render tab buttons + panels, MUST include a <script nonce="{{csp_nonce}}"> that: hides all panels except the first on load; on tab click: removes active from all tabs, adds active to clicked tab, hides all panels, shows the matching panel.
- ACCORDION: Must include JS open/close toggle per item.
- CAROUSEL: Must include prev/next navigation JS.
- If you cannot write the full working JS — use a static layout instead. Non-functional interactive UI is always worse than no interaction at all.

IMAGES (1.1.1): Every <img> MUST have a descriptive alt attribute. Never alt="" or alt="photo" or alt="image". Describe what is shown: alt="Exterior view of the library with timber cladding".

HEADING HIERARCHY (1.3.2): One H1 per page only (the hero). Section headings use H2. Card/item titles use H3. Never skip levels (no H1→H3). Heading text must describe the section — not just be decorative.

SEMANTIC HTML (1.3.1): Wrap the primary page content in <main>. Use <article> for self-contained content blocks. Use <header> and <footer> for structural landmarks. Never use <div> where a semantic element exists.

COLOR NOT SOLE INDICATOR (1.4.1): Never use color as the only way to show state. Active tabs/selected items: add font-weight:700 or a border-bottom in addition to any color change. Do not convey meaning through color alone.

NON-TEXT CONTRAST (1.4.11): Button borders and input field borders must achieve 3:1 contrast ratio against their adjacent background. Use sufficient border opacity or lightness difference — do not use near-invisible borders on light backgrounds.

LINK TEXT (2.4.4): All link text must be self-describing. NEVER use "click here", "read more", "learn more", or "here" as complete link text. Write "View our portfolio", "Read the full case study", "Get a free consultation" instead.

ACCENT COLOR USAGE: The accent color {$accentColor} is for decorative elements ONLY — icon fills, border-left highlights, divider lines, badge backgrounds. NEVER use the accent color as text color for labels, headings, or body copy. Accent on white/cream typically fails WCAG contrast. All text labels, metadata, and subheadings must use a properly contrasted dark color, not the accent.

CARD BODY TEXT: Inside any card, box, or panel with a light background — body text, labels, metadata, and subheadings MUST use {$textColor} or a dark shade of it. Never use primary, secondary, or accent as body text color inside a light-background card.

FOCUS STYLES (2.4.7): Every interactive element must show a visible keyboard focus ring. Include once per page inside a <style> or <script nonce="{{csp_nonce}}"> block: *:focus-visible{outline:2px solid {$accentColor};outline-offset:3px;border-radius:3px;}

ARIA ROLES (4.1.2): Tabs: add role="tablist" on the container, role="tab" + aria-selected + aria-controls on each trigger, role="tabpanel" + matching id on each panel. Tab JS must also support left/right arrow key navigation. Accordions: add aria-expanded on each trigger button, aria-controls pointing to the panel id.

FORM LABELS (3.3.2): Every <input>, <textarea>, and <select> MUST have a paired <label for="id">. Placeholder text alone is not sufficient — it disappears on focus. Always write: <label for="email">Email address</label><input id="email" type="email">.

OVERFLOW ON TEXT (1.4.12): Never apply a fixed height with overflow:hidden to a container holding text. Use min-height instead of height so text reflows when users increase font size.

Respond with ONLY valid JSON:
{
  "meta_description": "SEO description 150-160 chars",
  "blocks": [
    {"type": "hero",     "html": "<section class=\"hero\" style=\"background:{$primaryColor}\">...</section>"},
    {"type": "features", "html": "<section class=\"section\">...</section>"}
  ]
}

Block type is just an editor label. Use the most descriptive name: hero, features, stats, testimonials, pricing, team, faq, cta, cards, gallery, timeline, steps, checklist, contact, text, quote, newsletter, social, tabs, accordion.

RESPOND ONLY WITH VALID JSON. No markdown, no commentary.
EOT;

                $pageResult   = AI::generate($pagePrompt, ['stage' => 2, 'page_slug' => $pageSlug]);
                $pageBlocks   = null;
                $pageMetaDesc = '';
                if ($pageResult) {
                    if (!empty($pageResult['blocks'])) {
                        $pageBlocks   = $pageResult['blocks'];
                        $pageMetaDesc = $pageResult['meta_description'] ?? '';
                    } elseif (isset($pageResult['pages'][0]['blocks'])) {
                        // AI returned full site structure — extract first page's blocks
                        $pageBlocks   = $pageResult['pages'][0]['blocks'];
                        $pageMetaDesc = $pageResult['pages'][0]['meta_description'] ?? '';
                    } elseif (isset($pageResult['pages'])) {
                        // Find matching page by slug
                        foreach ($pageResult['pages'] as $p) {
                            if (($p['slug'] ?? '') === $pageSlug && !empty($p['blocks'])) {
                                $pageBlocks   = $p['blocks'];
                                $pageMetaDesc = $p['meta_description'] ?? '';
                                break;
                            }
                        }
                    }
                }
                if (!$pageBlocks) {
                    // Retry once before giving up
                    self::sseEvent('warning', ['message' => "Retrying \"{$label}\"…"]);
                    $retry = AI::generate($pagePrompt, ['stage' => 2, 'page_slug' => $pageSlug]);
                    if ($retry && !empty($retry['blocks'])) {
                        $pageBlocks   = $retry['blocks'];
                        $pageMetaDesc = $retry['meta_description'] ?? '';
                    }
                }
                if ($pageBlocks && is_array($pageBlocks) && count($pageBlocks) > 0) {
                    $builtPages[] = array_merge($pageMeta, [
                        'blocks'           => $pageBlocks,
                        'meta_description' => $pageMetaDesc ?: ($pageMeta['meta_description'] ?? ''),
                    ]);
                    // Accumulate first 2 blocks as visual fingerprint for subsequent page prompts
                    $sampleHtml = implode("\n", array_map(fn($b) => $b['html'] ?? '', array_slice($pageBlocks, 0, 2)));
                    $generatedSamples[] = ['title' => $label, 'html' => $sampleHtml];
                } else {
                    self::sseEvent('warning', ['message' => "Content generation failed for \"{$label}\" — using placeholder."]);
                    $builtPages[] = array_merge($pageMeta, [
                        'blocks' => [['type' => 'hero', 'content' => ['title' => $label, 'subtitle' => '', 'button' => 'Learn More', 'url' => '/contact']]],
                    ]);
                }
            }

            // ── Stage 3: Save & Notify ───────────────────────────────────
            self::sseEvent('stage', ['stage' => 3, 'label' => 'Saving plan…', 'total' => 3]);

            $fullPlan  = array_merge($structure, ['pages' => $builtPages]);
            $briefJson = json_encode(array_diff_key($input, ['_csrf' => true]));
            DB::execute(
                "INSERT INTO build_queue (plan_json, brief_json, status, created_at) VALUES (?, ?, 'pending', datetime('now'))",
                [json_encode($fullPlan), $briefJson]
            );
            $queueId = (int)DB::pdo()->lastInsertId();

            self::sseEvent('complete', ['queue_id' => $queueId]);
        } catch (Exception $e) {
            self::sseEvent('error', ['message' => $e->getMessage()]);
        } finally {
            AI::setHeartbeat(null);
        }
    }
}

class AIController {
    private static function renderAdmin(string $template, array $data = [], array $flags = []): void {
        $user         = Auth::user();
        $pendingCount = DB::fetch("SELECT COUNT(*) as c FROM build_queue WHERE status = 'pending'")['c'] ?? 0;
        $flash        = Session::getFlash();
        $flashError   = ($flash && $flash['type'] === 'error')   ? $flash['message'] : null;
        $flashSuccess = ($flash && $flash['type'] === 'success') ? $flash['message'] : null;
        $content      = Template::render($template, $data);
        Response::html(Template::render('admin_layout', array_merge([
            'title'         => $data['title'] ?? 'AI Generate',
            'content'       => $content,
            'user_email'    => $user['email'] ?? '',
            'user_role'     => ucfirst($user['role'] ?? 'User'),
            'pending_count' => $pendingCount,
            'flash_error'   => $flashError,
            'flash_success' => $flashSuccess,
            'csp_nonce'     => defined('CSP_NONCE') ? CSP_NONCE : '',
            'csrf_field'    => '<input type="hidden" name="_csrf" value="' . CSRF::token() . '">',
            'blog_enabled'  => Settings::get('blog_enabled', '0') === '1',
            'is_dashboard'  => false,
            'is_pages'      => false,
            'is_nav'        => false,
            'is_media'      => false,
            'is_theme'      => false,
            'is_users'      => false,
            'is_ai'         => true,
            'is_ai_chat'    => false,
            'is_approvals'  => false,
            'is_blog'       => false,
            'is_blog_cats'  => false,
        ], $flags)));
    }

    // Display the AI generation form
    public static function form(): void {
        Auth::require('*');

        $isConfigured    = AI::isConfigured();
        $currentProvider = Settings::get('ai_provider', '');
        $currentModel    = Settings::get('ai_model', '');

        $providerNames = [
            'openai'    => 'OpenAI',
            'anthropic' => 'Anthropic',
            'google'    => 'Google Gemini'
        ];

        // Pre-fill brief if ?from=<id> is provided
        $brief = [];
        $fromId = (int)(Request::query('from', '0'));
        if ($fromId > 0) {
            $item = DB::fetch("SELECT brief_json FROM build_queue WHERE id = ?", [$fromId]);
            if ($item && !empty($item['brief_json'])) {
                $brief = json_decode($item['brief_json'], true) ?? [];
            }
        }

        self::renderAdmin('admin_ai', [
            'is_configured'             => $isConfigured,
            'current_provider_name'     => $providerNames[$currentProvider] ?? 'Unknown',
            'current_model'             => $currentModel,
            'brief_business_name'       => Sanitize::html($brief['business_name'] ?? ''),
            'brief_description'         => Sanitize::html($brief['description'] ?? ''),
            'brief_target_audience'     => Sanitize::html($brief['target_audience'] ?? ''),
            'brief_visual_style'        => Sanitize::html($brief['visual_style'] ?? ''),
            'brief_color_preference'    => Sanitize::html($brief['color_preference'] ?? ''),
            'brief_design_inspiration'  => Sanitize::html($brief['design_inspiration'] ?? ''),
            'brief_pages_needed'        => Sanitize::html(is_array($brief['pages_needed'] ?? '') ? implode("\n", $brief['pages_needed']) : ($brief['pages_needed'] ?? '')),
            'brief_features'            => Sanitize::html(is_array($brief['features'] ?? '') ? implode("\n", $brief['features']) : ($brief['features'] ?? '')),
            'brief_user_content'        => Sanitize::html(is_array($brief['user_content'] ?? '') ? implode("\n", $brief['user_content']) : ($brief['user_content'] ?? '')),
        ]);
    }

    // Save AI configuration
    public static function configure(): void {
        Auth::require('*');

        if (!CSRF::verify($_POST['_csrf'] ?? '')) {
            Session::flash('error', 'Invalid security token. Please try again.');
            Response::redirect('/admin/ai');
        }

        $provider = trim(Request::input('ai_provider', ''));
        $apiKey = trim(Request::input('ai_api_key', ''));
        $model = trim(Request::input('ai_model', ''));

        // Validate provider
        if (!in_array($provider, ['openai', 'anthropic', 'google'])) {
            Session::flash('error', 'Please select a valid AI provider.');
            Response::redirect('/admin/ai');
        }

        // Validate API key (must be provided)
        if (empty($apiKey)) {
            Session::flash('error', 'API key is required.');
            Response::redirect('/admin/ai');
        }

        // Save settings
        Settings::set('ai_provider', $provider);
        Settings::setEncrypted('ai_api_key', $apiKey);

        if (!empty($model)) {
            Settings::set('ai_model', $model);
        } else {
            // Set default model based on provider
            $defaultModel = match($provider) {
                'anthropic' => 'claude-sonnet-4-6',
                'google' => 'gemini-3-flash-preview',
                default => 'gpt-5.4-pro'
            };
            Settings::set('ai_model', $defaultModel);
        }

        Session::flash('success', 'AI configuration saved successfully!');
        Response::redirect('/admin/ai');
    }

    // Generate site plan using AI - Multi-Stage Approach
    public static function generate(): void {
        Auth::require('*');

        if (!CSRF::verify($_POST['_csrf'] ?? '')) {
            Session::flash('error', 'Invalid security token. Please try again.');
            Response::redirect('/admin/ai');
        }

        if (!AI::isConfigured()) {
            Session::flash('error', 'AI is not configured. Please add your API key in Settings.');
            Response::redirect('/admin/ai');
        }

        // Collect user input
        $input = [
            'business_name'      => trim(Request::input('business_name', '')),
            'description'        => trim(Request::input('description', '')),
            'target_audience'    => trim(Request::input('target_audience', '')),
            'visual_style'       => trim(Request::input('visual_style', '')),
            'color_preference'   => trim(Request::input('color_preference', '')),
            'pages_needed'       => trim(Request::input('pages_needed', '')),
            'features'           => trim(Request::input('features', '')),
            'design_inspiration' => trim(Request::input('design_inspiration', '')),
            'user_content'       => trim(Request::input('user_content', '')),
        ];

        // Validation
        if (empty($input['business_name'])) {
            Session::flash('error', 'Project name is required.');
            Response::redirect('/admin/ai');
        }

        if (empty($input['description'])) {
            Session::flash('error', 'Project description is required.');
            Response::redirect('/admin/ai');
        }

        error_log("=== MULTI-STAGE SITE GENERATION START ===");

        // STAGE 1: Generate site structure (pages list, colors, nav)
        error_log("STAGE 1: Generating site structure...");
        $structurePrompt = self::buildStructurePrompt($input);
        $structure = AI::generate($structurePrompt, ['stage' => 1]);

        if (!$structure || !isset($structure['pages'])) {
            error_log("STAGE 1 FAILED: No valid structure returned");
            Session::flash('error', 'Failed to generate site structure. Please try again.');
            Response::redirect('/admin/ai');
        }

        error_log("STAGE 1 SUCCESS: " . count($structure['pages']) . " pages planned");

        // STAGE 1 RECONCILE: Ensure every standalone nav link has a corresponding page. The AI occasionally generates a nav item pointing to a slug it forgot to include in the pages array. We catch that here before Stage 2 iterates the pages list.
        $existingPageSlugs = array_flip(array_column($structure['pages'], 'slug'));
        foreach ($structure['nav'] as $navItem) {
            $url = $navItem['url'] ?? '';
            // Skip hash-anchor links (/#foo, /slug#foo), external URLs, and /blog (own module)
            if (strpos($url, '#') !== false) continue;
            if (preg_match('/^https?:\/\//', $url)) continue;
            $slug = ltrim(parse_url($url, PHP_URL_PATH) ?? '', '/') ?: 'home';
            if ($slug === 'blog') continue;
            if (!isset($existingPageSlugs[$slug])) {
                $label = $navItem['label'] ?? ucfirst(str_replace('-', ' ', $slug));
                error_log("STAGE 1 RECONCILE: Adding missing page for nav link '{$url}' (slug: {$slug})");
                $structure['pages'][] = [
                    'slug'             => $slug,
                    'title'            => $label,
                    'meta_description' => '',
                    'purpose'          => "Page for the '{$label}' navigation item",
                    'layout'           => ['show_nav' => true, 'show_footer' => true, 'show_page_title' => true, 'nav_style' => 'sticky'],
                ];
                $existingPageSlugs[$slug] = true;
            }
        }
        error_log("STAGE 1 RECONCILE COMPLETE: " . count($structure['pages']) . " pages after nav check");

        // STAGE 2: Generate detailed content for each page
        error_log("STAGE 2: Generating page content...");
        $detailedPages    = [];
        $generatedSamples = []; // first 2 blocks HTML from each completed page — fed into subsequent pages so the LLM sees what was built and can match the visual fingerprint exactly

        foreach ($structure['pages'] as $index => $pagePlan) {
            $pageNum = $index + 1;
            $totalPages = count($structure['pages']);
            error_log("STAGE 2: Generating page {$pageNum}/{$totalPages}: {$pagePlan['title']}");

            $pagePrompt = self::buildPagePrompt($input, $pagePlan, $structure, $generatedSamples);
            $pageContent = AI::generate($pagePrompt, ['stage' => 2, 'page_slug' => $pagePlan['slug'] ?? '']);

            // Handle case where AI returns blocks directly or nested in a full structure
            $blocks = null;
            $metaDesc = '';

            if ($pageContent) {
                if (isset($pageContent['blocks'])) {
                    // Direct blocks response (expected format)
                    $blocks = $pageContent['blocks'];
                    $metaDesc = $pageContent['meta_description'] ?? '';
                } elseif (isset($pageContent['pages'][0]['blocks'])) {
                    // AI returned full structure instead of just blocks - extract first page
                    $blocks = $pageContent['pages'][0]['blocks'];
                    $metaDesc = $pageContent['pages'][0]['meta_description'] ?? '';
                    error_log("Page {$pageNum}: extracted blocks from nested structure");
                } elseif (isset($pageContent['pages'])) {
                    // Check if any page matches our slug
                    foreach ($pageContent['pages'] as $p) {
                        if (($p['slug'] ?? '') === $pagePlan['slug'] && isset($p['blocks'])) {
                            $blocks = $p['blocks'];
                            $metaDesc = $p['meta_description'] ?? '';
                            error_log("Page {$pageNum}: found matching page by slug in nested structure");
                            break;
                        }
                    }
                }
            }

            if ($blocks && is_array($blocks) && count($blocks) > 0) {
                $detailedPages[] = array_merge($pagePlan, [
                    'blocks' => $blocks,
                    'meta_description' => $metaDesc ?: ($pagePlan['meta_description'] ?? '')
                ]);
                error_log("Page {$pageNum} generated with " . count($blocks) . " blocks");
                // Accumulate first 2 blocks as visual fingerprint for subsequent page prompts
                $sampleHtml = implode("\n", array_map(fn($b) => $b['html'] ?? '', array_slice($blocks, 0, 2)));
                $generatedSamples[] = ['title' => $pagePlan['title'] ?? ('Page ' . $pageNum), 'html' => $sampleHtml];
            } else {
                // Fallback: use basic structure if page generation fails
                error_log("Page {$pageNum} generation failed, using basic structure");
                $detailedPages[] = $pagePlan;
            }
        }

        // Combine into final plan
        $plan = [
            'site_name' => $structure['site_name'] ?? $input['business_name'],
            'tagline'   => $structure['tagline'] ?? '',
            'colors'    => $structure['colors'] ?? self::getDefaultColors(),
            'pages'     => $detailedPages,
            'nav'       => $structure['nav'] ?? [],
            'footer'    => $structure['footer'] ?? ['text' => '© ' . date('Y') . ' ' . $input['business_name']]
        ];

        error_log("=== MULTI-STAGE GENERATION COMPLETE ===");
        error_log("Total pages: " . count($plan['pages']));

        // Validate the plan structure
        if (!isset($plan['pages']) || !is_array($plan['pages']) || count($plan['pages']) === 0) {
            Session::flash('error', 'AI response was not valid. Please try again.');
            Response::redirect('/admin/ai');
        }

        // Store in build queue for approval
        DB::execute(
            "INSERT INTO build_queue (plan_json, brief_json, status, created_at) VALUES (?, ?, 'pending', datetime('now'))",
            [json_encode($plan), json_encode($input)]
        );

        Session::flash('success', 'Site plan generated successfully! Review it in the Approvals queue.');
        Response::redirect('/admin/approvals');
    }

    // Get default color scheme
    private static function getDefaultColors(): array {
        return [
            'primary' => '#2563eb',
            'secondary' => '#1e40af',
            'accent' => '#3b82f6',
            'background' => '#ffffff',
            'text' => '#1e293b',
            'header_bg' => '#1e40af',
            'header_text' => '#ffffff',
            'footer_bg' => '#1e293b',
            'footer_text' => '#ffffff'
        ];
    }

    // Build prompt for STAGE 1: Site structure
    private static function buildStructurePrompt(array $input): string {
        $brief = self::buildBrief($input);

        return <<<PROMPT
You are designing the architecture for a website. Based on the creative brief below, design the complete site structure — freely and intentionally. There are no required templates or page types to follow.

CREATIVE BRIEF:
{$brief}

Design the structure that is right for THIS specific site. Choose:
- How many pages make sense (typically 3-7) and what each should accomplish
- A color palette that perfectly matches the personality and feel described
- Navigation that serves this site's unique needs
- A footer that fits the brand

Respond with ONLY valid JSON:
{
  "site_name": "...",
  "tagline": "A tagline that captures the brand essence",
  "colors": {
    "primary": "#hex",
    "secondary": "#hex",
    "accent": "#hex",
    "background": "#hex",
    "text": "#hex",
    "header_bg": "#hex",
    "header_text": "#hex",
    "footer_bg": "#hex",
    "footer_text": "#hex",
    "cta_bg": "#hex",
    "cta_text": "#hex"
  },
  "pages": [
    {"slug": "home", "title": "Home", "meta_description": "...", "purpose": "What this page accomplishes for visitors", "layout": {"show_nav": true, "show_footer": true, "show_page_title": false, "nav_style": "sticky"}},
    {"slug": "about", "title": "About", "meta_description": "...", "purpose": "...", "layout": {"show_nav": true, "show_footer": true, "show_page_title": true, "nav_style": "sticky"}}
  ],
  "nav": [{"label": "Home", "url": "/"}, {"label": "About", "url": "/about"}],
  "footer": {
    "text": "© 2026 Site Name.",
    "links": [],
    "social": []
  },
  "facts": {
    "tagline": "exact tagline — same as above",
    "key_stats": ["stat 1", "stat 2", "stat 3"],
    "core_features": ["feature 1", "feature 2", "feature 3"],
    "value_props": ["what makes this unique", "core benefit 2"]
  }
}

The "facts" object defines canonical content anchors. ALL page generators will receive these verbatim and MUST use them as-is — not paraphrase or contradict them. Choose stats and features that are genuinely specific to this project based on the brief.

SHOW_PAGE_TITLE RULE: Set show_page_title: false for EVERY page without exception. Every page's first block must introduce the page visually (hero, banner, section heading). Never mix true/false across pages — inconsistency breaks the design system. Use nav_style="transparent" when the hero has a background image and you want the nav to float above it. Only set show_nav/show_footer to false for deliberately stripped-down pages.

HASH ANCHORS: When a nav item links to a section within a page, use the format "/#slug" (e.g. "/#about", "/#stack", "/#contact"). Use short, lowercase, hyphenated slugs with no spaces. These slugs will be used as "anchor" values in the block content so users can scroll directly to that section. Keep them concise and descriptive (2-12 chars ideal).

NAV/PAGE PARITY RULE: Every nav item that links to a standalone page (e.g. "/about", "/services") MUST have a corresponding entry in the "pages" array with a matching "slug". Do not add a nav link to a page that is not in your pages list. Hash anchors (e.g. "/#contact") and "/blog" are exempt — they do not require a pages entry.

RESPOND ONLY WITH VALID JSON.
PROMPT;
    }

    // Build a rich creative brief string from user input
    private static function buildBrief(array $input): string {
        $parts = [];
        if (!empty($input['user_content'])) $parts[] = "Verified facts (use verbatim):\n{$input['user_content']}";
        if (!empty($input['business_name'])) $parts[] = "Project/business name: {$input['business_name']}";
        if (!empty($input['description']))   $parts[] = "Description: {$input['description']}";
        if (!empty($input['target_audience'])) $parts[] = "Target audience: {$input['target_audience']}";
        if (!empty($input['visual_style']))  $parts[] = "Visual style: {$input['visual_style']}";
        if (!empty($input['color_preference'])) $parts[] = "Color direction: {$input['color_preference']}";
        if (!empty($input['pages_needed'])) $parts[] = "Pages needed: {$input['pages_needed']}";
        if (!empty($input['features']))     $parts[] = "Functionality needed: " . (is_array($input['features']) ? implode(', ', $input['features']) : $input['features']);
        if (!empty($input['design_inspiration'])) $parts[] = "Design inspiration: {$input['design_inspiration']}";
        return implode("\n", $parts);
    }

    // Build prompt for STAGE 2: Individual page content
    public static function buildPagePrompt(array $input, array $pagePlan, array $structure, array $priorSamples = []): string {
        $brief       = self::buildBrief($input);
        $pagePurpose = $pagePlan['purpose'] ?? "A page titled \"{$pagePlan['title']}\"";
        $colors      = $structure['colors'] ?? [];
        $primary   = $colors['primary']    ?? '#2563eb';
        $secondary = $colors['secondary']  ?? '#1e40af';
        $accent    = $colors['accent']     ?? '#f59e0b';
        $bg        = $colors['background'] ?? '#ffffff';
        $text      = $colors['text']       ?? '#1e293b';
        $ctaBg     = $colors['cta_bg']     ?? $primary;
        $ctaText   = $colors['cta_text']   ?? '#ffffff';
        $headerBg  = $colors['header_bg']  ?? $primary;

        // Extract hash-anchor nav items targeting this page
        $anchorHints = '';
        $pageSlug    = $pagePlan['slug'] ?? '';
        $hashAnchors = [];
        foreach ($structure['nav'] ?? [] as $nav) {
            $url = $nav['url'] ?? '';
            if (preg_match('/^\/?' . preg_quote($pageSlug === 'home' ? '' : $pageSlug, '/') . '#([a-z0-9\-_]+)$/i', $url, $m)) {
                $hashAnchors[] = '"' . $m[1] . '" (nav label: ' . ($nav['label'] ?? '') . ')';
            }
        }
        if ($hashAnchors) {
            $anchorList  = implode(', ', $hashAnchors);
            $anchorHints = "\nNAV ANCHORS: The site nav deep-links to these anchors on this page: {$anchorList}.\nFor each anchor, add id=\"<anchor>\" directly on the outer <section> element of that block. E.g. for /#pricing, use <section id=\"pricing\" ...>.";
        }

        // Build cross-page consistency block
        $currentSlug   = $pagePlan['slug'] ?? '';
        $allPages      = $structure['pages'] ?? [];
        $totalPages    = count($allPages);
        $currentNum    = 1;
        $allPagesLines = [];
        foreach ($allPages as $j => $p) {
            $pTitle   = $p['title']   ?? ('Page ' . ($j + 1));
            $pPurpose = $p['purpose'] ?? '';
            $pSlug    = $p['slug']    ?? '';
            $pPath    = $pSlug === 'home' ? '/' : ('/' . $pSlug);
            if ($pSlug === $currentSlug) {
                $currentNum = $j + 1;
                $allPagesLines[] = "  - {$pTitle} ({$pPath}): {$pPurpose} ← YOU ARE WRITING THIS PAGE";
            } else {
                $allPagesLines[] = "  - {$pTitle} ({$pPath}): {$pPurpose}";
            }
        }
        $allPagesBlock = implode("\n", $allPagesLines);

        // Build valid internal links from site nav (ground-truth hrefs)
        $navLinkLines = [];
        foreach ($structure['nav'] ?? [] as $nav) {
            $navUrl   = $nav['url']   ?? '';
            $navLabel = $nav['label'] ?? '';
            if ($navUrl) $navLinkLines[] = "  {$navLabel}: {$navUrl}";
        }
        $navLinksBlock = $navLinkLines
            ? "\nVALID INTERNAL LINKS (use ONLY these exact href paths for all in-site links):\n" . implode("\n", $navLinkLines) . "\n"
            : '';

        // Extract facts bible from structure for cross-page consistency
        $facts = $structure['facts'] ?? [];
        $factsLines = [];
        if (!empty($facts['tagline']))       $factsLines[] = '- Tagline: "' . $facts['tagline'] . '"';
        if (!empty($facts['key_stats']))     $factsLines[] = '- Key stats: ' . implode(', ', (array)$facts['key_stats']);
        if (!empty($facts['core_features'])) $factsLines[] = '- Core features: ' . implode(', ', (array)$facts['core_features']);
        if (!empty($facts['value_props']))   $factsLines[] = '- Value propositions: ' . implode(' | ', (array)$facts['value_props']);
        $factsBlock = $factsLines
            ? "ESTABLISHED FACTS — use these verbatim across all pages, never contradict or alter them:\n" . implode("\n", $factsLines) . "\n"
            : '';

        // Build prior-pages visual reference — real HTML the LLM has already output so it can match CSS values exactly
        $priorPagesBlock = '';
        if (!empty($priorSamples)) {
            $refLines = ["PREVIOUSLY GENERATED PAGES — your output MUST visually match these exactly. Extract and reuse: the same hero background color, same card border-radius, same button padding/font-size, same section padding. Do NOT copy the content — DO replicate every CSS value you see here:"];
            foreach ($priorSamples as $sample) {
                $truncated = mb_substr($sample['html'], 0, 2000);
                if (mb_strlen($sample['html']) > 2000) $truncated .= "\n\u2026[truncated]";
                $refLines[] = "\n=== " . $sample['title'] . " (first 2 blocks) ===\n" . $truncated;
            }
            $priorPagesBlock = implode("\n", $refLines) . "\n";
        }

        return <<<PROMPT
You are generating the HTML sections for one page of a website. Write production-quality, visually stunning HTML that looks like it came from a world-class design agency.

SITE CONTEXT:
- Name: {$structure['site_name']}
- Brief: {$brief}
- Exact color palette (use these — do not invent others):
    primary:    {$primary}
    secondary:  {$secondary}
    accent:     {$accent}
    background: {$bg}
    text:       {$text}
    CTA button: background {$ctaBg}, text {$ctaText}

{$factsBlock}
ALL PAGES IN THIS SITE — page {$currentNum} of {$totalPages} (maintain consistency; do not duplicate content from other pages):
{$allPagesBlock}
{$priorPagesBlock}
{$navLinksBlock}
THIS PAGE:
- Title: {$pagePlan['title']}
- Slug: {$pagePlan['slug']}
- Purpose: {$pagePurpose}
{$anchorHints}

TECHNICAL ENVIRONMENT (already loaded on every page):
- Bulma CSS — use classes freely: hero, section, container, columns, column, card, box, button, tag, media, level, notification, title, subtitle, content, has-text-*, is-*, etc.
- Material Icons — <span class="material-symbols-outlined">icon_name</span> (icon names: bolt, check_circle, star, rocket_launch, shield, speed, lightbulb, favorite, trending_up, verified, groups, person, settings, support, payments, handshake, eco, security, insights, analytics, mail, phone, location_on, schedule, arrow_forward, close, menu, code, language, public, devices, cloud, storage, data_object)
- Inter font (sans-serif)
- picsum.photos for placeholder images: https://picsum.photos/seed/<word>/<w>/<h>

INSTRUCTIONS:
- Write 4-8 sections. Each section is a complete <section> element (or <div> for non-section blocks like a raw CTA strip).
- Use Bulma layout classes for structure, inline styles for brand colors and custom touches.
- Every CTA / primary button: style="background:{$ctaBg};color:{$ctaText};border:none;padding:.75rem 2rem;border-radius:.5rem;font-weight:600;cursor:pointer;font-size:1rem;display:inline-block;text-decoration:none"
- Vary section backgrounds to create visual rhythm: alternate between {$bg}, a tinted {$primary}10 (use the hex + "0d" opacity), and rich colored sections using {$primary} or {$secondary}.
- Write real, specific, compelling copy — match the brand voice from the brief. Zero placeholder text.
- Make it beautiful: consider padding, whitespace, subtle shadows (box-shadow:0 4px 24px rgba(0,0,0,.08)), border-radius, gradient backgrounds.
- Inline <script> blocks are allowed (e.g. for counters or small interactions). IMPORTANT: ALL <script> tags MUST include nonce="{{csp_nonce}}" for Content Security Policy compliance.
- NEVER use inline event handlers (onclick, onerror, onload) — they violate CSP. For interactivity, use this pattern:
  WRONG: <button onclick="doSomething()">Click</button>
  RIGHT: <button id="myBtn">Click</button><script nonce="{{csp_nonce}}">document.getElementById('myBtn').addEventListener('click',()=>{{doSomething();}});</script>
- TECHNICAL ACCURACY: For filenames, commands, URLs, and version numbers — use ONLY what was stated in the brief. If a specific detail was not provided, use a clear placeholder like [DOWNLOAD_URL] rather than inventing plausible-sounding specifics.
- NUMBERS IN CODE BLOCKS: Numbers inside <code>, <pre>, and ASCII-art terminal snippets must match the brief exactly — never abbreviate (e.g. do not write "18k" if the brief says "18,629").
- STAT FORMAT CONSISTENCY: When a stat figure appears on multiple pages, use the same format every time — never switch between "1,247", "~1.2k", and "1k+" for the same number across pages.
- QUOTATION MARKS IN HTML: For pull-quotes, blockquotes, or any quoted speech in HTML text content, use `&ldquo;` and `&rdquo;` or Unicode curly quotes — NEVER straight double quotes " — they break the JSON string encoding.
- RESPONSIVE DESIGN — all layouts must work on mobile, tablet, and desktop:
  - Multi-column grids: use `<div class="columns is-multiline">` with responsive column classes: `is-12-mobile is-6-tablet is-4-desktop` (never bare `is-4` alone)
  - Hero sections: avoid `is-fullheight` — use padding/min-height styles so mobile doesn't get a taller-than-viewport hero
  - Never set fixed pixel widths on layout wrappers — use Bulma containers or `max-width` + `width:100%`
  - Hide decorative / non-essential columns on small screens with `is-hidden-mobile`
  - Stack feature cards, testimonials, and pricing tiers vertically on mobile using `is-12-mobile`
- INTERNAL LINKS: Only use paths from VALID INTERNAL LINKS listed above. If the nav says "/#contact", the href must be "/#contact" — never "/contact". For page sections not in the nav, use "/{slug}#anchor" (matching slugs from ALL PAGES).

DESIGN CONSTRAINTS — hard rules, not guidelines:

COLOR CONTRAST (WCAG AA — 4.5:1 minimum):
- Contrast is determined by each element's OWN background — not its parent section's background.
- Dark element background (primary {$primary}, secondary, or any dark color): text MUST be white (#fff). NEVER use {$text} on a dark background — it will be unreadable.
- Light element background ({$bg}, cream, white, or any light color): text MUST be {$text} (dark). NEVER use white or near-white text on a light background.
- CARD INSIDE DARK SECTION: If a card/box has a light background (e.g. white or cream), its text MUST be dark even though the surrounding section is dark. The card's own background is what matters.
- NO REDUCED OPACITY TEXT: Never use rgba(255,255,255,0.4) or similar low-alpha text. If text must be lighter, use #aaa or similar — never drop opacity below 0.85 on any text.
- Rule of thumb: white on dark, dark on light — apply this rule to every single element individually, not just at the section level.

TYPOGRAPHY SCALE — use these exact sizes on every page (deviating breaks cross-page consistency):
- Hero H1:    font-size:clamp(2.5rem,5vw,4rem); line-height:1.1; font-weight:800; max 8 words
- Section H2: font-size:clamp(1.75rem,3vw,2.25rem); line-height:1.2; font-weight:700
- Card H3:    font-size:1.125rem; font-weight:600
- Body:       font-size:1rem; line-height:1.7
- Hero H1 must be a punchy phrase — NOT a full sentence. Wrong: "Homes and places designed with quiet performance." Right: "Architecture That Endures."

VISUAL RHYTHM — cross-page UI consistency (violations create a fragmented, unpolished site):
- HERO BACKGROUND: Every page's hero section must use the same darkest palette color as its background. Never use a lighter shade on one page's hero while other pages use dark — all heroes must feel like one coherent site.
- HERO PADDING: Every hero section must use padding:5rem 1.5rem 4rem — consistent vertical breathing room across all pages.
- SECTION PADDING: Every non-hero body section must use padding:5rem 1.5rem — exactly. Never use 4rem, 4.5rem, 6rem, or any other variation for body sections. The only allowed exception is the hero (uses 4rem bottom) and the footer.
- CARD SURFACE: All cards, feature tiles, and article boxes across the entire site must share ONE consistent surface style: background:#ffffff (or the page's lightest palette color); border:1px solid rgba(R,G,B,0.1) using the darkest-palette-color's RGB values; box-shadow:0 4px 20px rgba(R,G,B,0.05). NEVER use flat-gray backgrounds (#F7F9F8, #f5f5f5) with generic gray borders (#e0e0e0, #ddd) on cards — this creates a mismatched style family that breaks visual cohesion across pages.
- CARD BORDER-RADIUS: All bordered feature cards, article elements, and icon+text boxes must use border-radius:.75rem sitewide. Never mix .5rem and .75rem radius values across or within pages.
- BUTTON SIZE: Primary CTA buttons must always use the full spec: padding:.75rem 2rem; font-size:1rem. Do NOT shrink buttons in secondary contexts (e.g. padding:.6rem 1.5rem or font-size:.9rem) — size consistency builds visual trust across every page.
- GALLERY/PORTFOLIO CARD BACKGROUND: Cards in gallery, cards, featured, and team blocks must ALWAYS use background:#ffffff with the standard border+shadow. The dark-palette background treatment is permitted ONLY for the single "Most Popular" or highlighted tier card inside a pricing block — it must never appear in gallery, portfolio, or article card grids. Mixing dark-bg and white-bg cards in the same grid breaks cohesion across pages.
- H3 FONT SIZE UNIFORMITY: All card/item h3 titles must use font-size:1.125rem across every block type and every page without exception — unless the block is a pricing block, where tier names may use font-size:1.5rem. Never use ad-hoc sizes (1.35rem, 1.2rem, 1.3rem) for individual cards: this creates single-card outliers that look broken.
- TAB/FILTER CONTROLS: Tab or filter toggle buttons (role="tab", portfolio filters, category selectors) may use padding:.75rem 1.25rem to distinguish them visually from full CTA buttons. They must still use border-radius:.5rem and font-weight:600. Never use ad-hoc padding values — keep it consistently .75rem 1.25rem.
- PALETTE COLOR LOCK: Every hex color value used in page blocks MUST be copied verbatim from the palette defined in the structure stage (primary, secondary, accent, background, text, header_bg, etc.). Do NOT re-derive or approximate palette colors on a per-page basis — this causes hex drift (e.g. #eef1ea vs #eef1eb) that breaks cross-page color consistency. Always pick the exact hex from the structure palette; never compute or guess a tint independently per page.
- TAB PANEL CONTAINER: When generating tabbed content blocks, ALL tabpanel divs (role="tabpanel") must be siblings INSIDE the same .container div — not just the first one. A common mistake is closing the .container div after the first panel, leaving all subsequent panels outside it at full page width. Structure must be: <div class="container"> [tablist buttons] [ALL tabpanels] </div>. Verify div nesting before finalizing.
- H2 MARGIN-BOTTOM: All section h2 headings must use margin-bottom:1rem consistently across every page and block. Never use 1.25rem, 1.5rem, or other variations — even in CTA blocks. Uniform heading rhythm prevents visual inconsistency when pages are viewed side by side.

PAGE WIDTH — every section without exception:
- Wrap all content in <div class="container"> inside the section.
- Full-bleed colored backgrounds are fine — but inner content MUST sit inside .container.
- NEVER use style="max-width:Npx" or style="width:Npx" on sections or direct child wrappers.
- NEVER use negative margins (e.g. margin:0 -1rem) on sections or wrappers — the page shell enforces a consistent horizontal gap from screen edges; sections must stay within it.

CARD PADDING:
- Every card, box, or feature tile: minimum padding 1.5rem on all sides.
- Use class="card" with <div class="card-content"> OR class="box" — both have built-in padding.
- Card text, captions, and titles must never sit flush against the card border edge.

INTERACTIVE ELEMENTS — functional JS required or use static layout:
- TABS: If you render tab buttons + panels, MUST include a <script nonce="{{csp_nonce}}"> that: hides all panels except the first on load; on tab click: removes active from all tabs, adds active to clicked tab, hides all panels, shows the matching panel.
- ACCORDION: Must include JS open/close toggle per item.
- CAROUSEL: Must include prev/next navigation JS.
- If you cannot write the full working JS — use a static layout instead. Non-functional interactive UI is always worse than no interaction at all.

IMAGES (1.1.1): Every <img> MUST have a descriptive alt attribute. Never alt="" or alt="photo" or alt="image". Describe what is shown: alt="Exterior view of the library with timber cladding".

HEADING HIERARCHY (1.3.2): One H1 per page only (the hero). Section headings use H2. Card/item titles use H3. Never skip levels (no H1→H3). Heading text must describe the section — not just be decorative.

SEMANTIC HTML (1.3.1): Wrap the primary page content in <main>. Use <article> for self-contained content blocks. Use <header> and <footer> for structural landmarks. Never use <div> where a semantic element exists.

COLOR NOT SOLE INDICATOR (1.4.1): Never use color as the only way to show state. Active tabs/selected items: add font-weight:700 or a border-bottom in addition to any color change. Do not convey meaning through color alone.

NON-TEXT CONTRAST (1.4.11): Button borders and input field borders must achieve 3:1 contrast ratio against their adjacent background. Use sufficient border opacity or lightness difference — do not use near-invisible borders on light backgrounds.

LINK TEXT (2.4.4): All link text must be self-describing. NEVER use "click here", "read more", "learn more", or "here" as complete link text. Write "View our portfolio", "Read the full case study", "Get a free consultation" instead.

ACCENT COLOR USAGE: The accent color {$accent} is for decorative elements ONLY — icon fills, border-left highlights, divider lines, badge backgrounds. NEVER use the accent color as text color for labels, headings, or body copy. Accent on white/cream typically fails WCAG contrast. All text labels, metadata, and subheadings must use a properly contrasted dark color, not the accent.

CARD BODY TEXT: Inside any card, box, or panel with a light background — body text, labels, metadata, and subheadings MUST use {$text} or a dark shade of it. Never use primary, secondary, or accent as body text color inside a light-background card.

FOCUS STYLES (2.4.7): Every interactive element must show a visible keyboard focus ring. Include once per page inside a <style> or <script nonce="{{csp_nonce}}"> block: *:focus-visible{outline:2px solid {$accent};outline-offset:3px;border-radius:3px;}

ARIA ROLES (4.1.2): Tabs: add role="tablist" on the container, role="tab" + aria-selected + aria-controls on each trigger, role="tabpanel" + matching id on each panel. Tab JS must also support left/right arrow key navigation. Accordions: add aria-expanded on each trigger button, aria-controls pointing to the panel id.

FORM LABELS (3.3.2): Every <input>, <textarea>, and <select> MUST have a paired <label for="id">. Placeholder text alone is not sufficient — it disappears on focus. Always write: <label for="email">Email address</label><input id="email" type="email">.

OVERFLOW ON TEXT (1.4.12): Never apply a fixed height with overflow:hidden to a container holding text. Use min-height instead of height so text reflows when users increase font size.

Respond with ONLY valid JSON:
{
  "meta_description": "SEO description 150-160 chars",
  "blocks": [
    {"type": "hero",     "html": "<section class=\"hero\" style=\"padding:6rem 1.5rem;background:{$primary}\">...</section>"},
    {"type": "features", "html": "<section class=\"section\" style=\"padding:4rem 1.5rem\">...</section>"}
  ]
}

Block type values are just labels for the editor (use the most descriptive: hero, features, stats, testimonials, pricing, team, faq, cta, cards, gallery, timeline, steps, checklist, contact, text, quote, divider, newsletter, social, tabs, accordion, comparison, table).

RESPOND ONLY WITH VALID JSON. No markdown, no commentary.
PROMPT;
    }

    // ── AI Logs Viewer ───────────────────────────────────────────────────────

    public static function logs(): void {
        Auth::requireRole('admin');
        $logs = DB::query(
            "SELECT id, stage, page_slug, provider, model, prompt_length,
                    length(raw_response) AS response_length, parsed_ok, duration_ms, created_at
             FROM ai_logs ORDER BY id DESC LIMIT 200"
        )->fetchAll();
        self::renderAdmin('admin_ai_logs', ['title' => 'AI Request Logs', 'logs' => $logs]);
    }

    public static function logView(int $id): void {
        Auth::requireRole('admin');
        $log = DB::query("SELECT raw_response FROM ai_logs WHERE id = ?", [$id])->fetch();
        if (!$log) { http_response_code(404); exit('Not found'); }
        header('Content-Type: text/plain; charset=utf-8');
        echo $log['raw_response'];
    }

    public static function logsClear(): void {
        Auth::requireRole('admin');
        CSRF::validate();
        DB::execute("DELETE FROM ai_logs");
        Session::flash('success', 'AI logs cleared.');
        Response::redirect('/admin/ai/logs');
    }
}

// AIConversation: Session-backed multi-turn chat that acts as a web design consultant. Users describe their business conversationally; AI asks questions and eventually produces a JSON brief that can be piped into AIOrchestrator::stream().
class AIConversation {
    private const SESSION_KEY = 'monolithcms_ai_chat';

    /** Start (or return existing) conversation in session */
    public static function init(): void {
        Session::start(); // ensure session is open before reading/writing $_SESSION
        if (!isset($_SESSION[self::SESSION_KEY])) {
            self::reset();
        }
    }

    /** Return full message history array */
    public static function history(): array {
        return $_SESSION[self::SESSION_KEY]['messages'] ?? [];
    }

    /** Clear conversation */
    public static function reset(): void {
        $_SESSION[self::SESSION_KEY] = ['messages' => []];
    }

    // Send a user message, get an AI reply. Returns ['reply' => string, 'ready' => bool, 'brief' => array|null] ready=true when AI has enough info to generate the site.
    public static function chat(string $userMessage): array {
        self::init();

        $history   = self::history();
        $history[] = ['role' => 'user', 'content' => $userMessage];

        $systemPrompt = <<<'SYS'
You are a creative web design partner who builds genuinely unique, tailored websites. Your job is to deeply understand what the person envisions through natural, curious conversation — not to fill out a form.

Explore their vision with open, thoughtful questions. Ask about things like:
- The purpose and story behind their project or business
- Who their visitors are and what those people care about
- The feeling they want visitors to experience (inspired? calm? excited? informed?)
- Their visual personality — minimal, bold, warm, technical, playful, elegant?
- Any websites, brands, or aesthetics they admire or want to be different from
- What's the single most important action they want visitors to take
- What pages or sections they have in mind, and any specific features needed

Keep responses warm and conversational. Ask ONE clear question at a time. Build on their answers — make them feel understood, not interrogated.

After 4-7 exchanges (when you genuinely understand their vision), output EXACTLY this JSON on its own line:
{"__READY__": true, "brief": {"business_name": "...", "description": "A rich, detailed synthesis of everything you learned — personality, audience, visual style, goals, tone, specific needs and preferences. Make it detailed enough that an AI designer could build the perfect site from it alone.", "target_audience": "...", "visual_style": "...", "color_preference": "...", "pages_needed": "...", "design_inspiration": "...", "features": []}}
Do not include anything else on that line. Before that line you may write a warm summary message.
SYS;

        // Build messages for AI call
        $messages = [];
        foreach ($history as $msg) {
            $messages[] = $msg;
        }

        $reply  = AI::generateChat($messages, $systemPrompt);
        $ready  = false;
        $brief  = null;

        // Scan reply for __READY__ JSON marker — handles inline, mid-paragraph, or own-line placement
        if (preg_match('/(\{"\s*__READY__\s*".*)/s', $reply, $m)) {
            $jsonStr = $m[1];
            // Try to extract a valid JSON object from the match
            $decoded = json_decode($jsonStr, true);
            if (!$decoded) {
                // JSON may be truncated by trailing text — find the matching closing brace
                $depth = 0; $end = 0;
                for ($ci = 0, $clen = strlen($jsonStr); $ci < $clen; $ci++) {
                    if ($jsonStr[$ci] === '{') $depth++;
                    elseif ($jsonStr[$ci] === '}') { $depth--; if ($depth === 0) { $end = $ci; break; } }
                }
                if ($end > 0) $decoded = json_decode(substr($jsonStr, 0, $end + 1), true);
            }
            if ($decoded && !empty($decoded['brief'])) {
                $ready = true;
                $brief = $decoded['brief'];
            }
            // Strip the JSON blob (and any preceding --- separator) from the visible reply
            $reply = preg_replace('/\s*-{3,}\s*\{"\s*__READY__\s*".*/s', '', $reply);
            $reply = preg_replace('/\{"\s*__READY__\s*".*/s', '', $reply);
            $reply = trim($reply);
        }

        $history[] = ['role' => 'assistant', 'content' => $reply];
        $_SESSION[self::SESSION_KEY]['messages'] = $history;

        return ['reply' => $reply, 'ready' => $ready, 'brief' => $brief];
    }
}

// AIChatController: Admin controller for the conversational AI chat wizard.
class AIChatController {
    private static function renderAdmin(string $template, array $data = [], array $flags = []): void {
        $user         = Auth::user();
        $pendingCount = DB::fetch("SELECT COUNT(*) as c FROM build_queue WHERE status = 'pending'")['c'] ?? 0;
        $flash        = Session::getFlash();
        $flashError   = ($flash && $flash['type'] === 'error')   ? $flash['message'] : null;
        $flashSuccess = ($flash && $flash['type'] === 'success') ? $flash['message'] : null;
        $content      = Template::render($template, $data);
        Response::html(Template::render('admin_layout', array_merge([
            'title'         => $data['title'] ?? 'AI Chat',
            'content'       => $content,
            'user_email'    => $user['email'] ?? '',
            'user_role'     => ucfirst($user['role'] ?? 'User'),
            'pending_count' => $pendingCount,
            'flash_error'   => $flashError,
            'flash_success' => $flashSuccess,
            'csp_nonce'     => defined('CSP_NONCE') ? CSP_NONCE : '',
            'csrf_field'    => '<input type="hidden" name="_csrf" value="' . CSRF::token() . '">',
            'blog_enabled'  => Settings::get('blog_enabled', '0') === '1',
            'is_dashboard'  => false,
            'is_pages'      => false,
            'is_nav'        => false,
            'is_media'      => false,
            'is_theme'      => false,
            'is_users'      => false,
            'is_ai'         => false,
            'is_ai_chat'    => true,
            'is_approvals'  => false,
            'is_blog'       => false,
            'is_blog_cats'  => false,
        ], $flags)));
    }

    /** GET /admin/ai/chat */
    public static function chat(): void {
        Auth::require('*');
        AIConversation::init();
        $history = array_map(function($msg) {
            $msg['role_is_user'] = ($msg['role'] === 'user');
            return $msg;
        }, AIConversation::history());
        self::renderAdmin('admin_ai_chat', [
            'is_configured' => AI::isConfigured(),
            'history'       => $history,
            'csrf_token'    => CSRF::token(),
        ]);
    }

    /** POST /api/ai/chat  — JSON endpoint */
    public static function send(): void {
        Auth::require('content.edit');

        $input   = json_decode(file_get_contents('php://input'), true) ?? [];
        $message = trim($input['message'] ?? '');

        if (!$message) {
            Response::json(['error' => 'Empty message.'], 400);
            return;
        }
        if (!AI::isConfigured()) {
            Response::json(['error' => 'AI is not configured.'], 503);
            return;
        }

        $result = AIConversation::chat($message);
        Response::json([
            'reply'   => $result['reply'],
            'ready'   => $result['ready'],
            'brief'   => $result['brief'],
            'history' => AIConversation::history(),
        ]);
    }

    /** POST /api/ai/chat/reset  — clears session history */
    public static function resetChat(): void {
        Auth::require('*');
        AIConversation::reset();
        Response::json(['ok' => true]);
    }
}

class BuildQueue {
    // Get all pending build queue items
    public static function getPending(): array {
        return DB::fetchAll(
            "SELECT * FROM build_queue WHERE status = 'pending' ORDER BY created_at DESC"
        );
    }

    // Get a single build queue item
    public static function get(int $id): ?array {
        return DB::fetch("SELECT * FROM build_queue WHERE id = ?", [$id]);
    }

    // Approve a build queue item
    public static function approve(int $id): bool {
        $queue = self::get($id);
        if (!$queue || $queue['status'] !== 'pending') {
            return false;
        }

        $userId = Auth::user()['id'] ?? null;

        DB::execute(
            "UPDATE build_queue SET status = 'approved', approved_by = ?, approved_at = datetime('now') WHERE id = ?",
            [$userId, $id]
        );

        return true;
    }

    // Reject a build queue item
    public static function reject(int $id, string $reason = ''): bool {
        $queue = self::get($id);
        if (!$queue || $queue['status'] !== 'pending') {
            return false;
        }

        $userId = Auth::user()['id'] ?? null;

        DB::execute(
            "UPDATE build_queue SET status = 'rejected', approved_by = ?, approved_at = datetime('now'), rejection_reason = ? WHERE id = ?",
            [$userId, $reason, $id]
        );

        return true;
    }

    // Apply an approved build queue plan
    public static function apply(int $id): bool {
        $queue = self::get($id);
        if (!$queue || $queue['status'] !== 'approved') {
            return false;
        }

        $plan = json_decode($queue['plan_json'], true);
        if (!$plan) {
            return false;
        }

        // Backward-compat: if the plan IS a single page (has 'blocks' but no 'pages'), wrap it
        if (!isset($plan['pages']) && isset($plan['blocks'])) {
            $plan['pages'] = [$plan];
        }

        $db = DB::connect();
        $db->beginTransaction();

        try {
            // Apply theme colors
            if (isset($plan['colors']) && is_array($plan['colors'])) {
                foreach ($plan['colors'] as $key => $value) {
                    DB::execute(
                        "INSERT INTO theme_styles (key, value) VALUES (?, ?)
                         ON CONFLICT(key) DO UPDATE SET value = excluded.value",
                        ['color_' . $key, $value]
                    );
                }

                // Derive coherent secondary/border/muted from chosen background so the theme is consistent even when the AI only specifies primary colors.
                $bg = $plan['colors']['background'] ?? null;
                $txt = $plan['colors']['text'] ?? null;
                if ($bg && preg_match('/^#[0-9a-fA-F]{6}$/', $bg)) {
                    $bgR = hexdec(substr($bg, 1, 2));
                    $bgG = hexdec(substr($bg, 3, 2));
                    $bgB = hexdec(substr($bg, 5, 2));
                    $lum = (0.299 * $bgR + 0.587 * $bgG + 0.114 * $bgB) / 255;
                    if ($lum < 0.3) { // Dark background
                        $bgSec  = sprintf('#%02x%02x%02x', min(255,$bgR+25), min(255,$bgG+25), min(255,$bgB+25));
                        $border = sprintf('#%02x%02x%02x', min(255,$bgR+50), min(255,$bgG+50), min(255,$bgB+50));
                        $muted  = '#9ca3af';
                    } else { // Light background
                        $bgSec  = sprintf('#%02x%02x%02x', max(0,$bgR-8), max(0,$bgG-8), max(0,$bgB-8));
                        $border = sprintf('#%02x%02x%02x', max(0,$bgR-30), max(0,$bgG-30), max(0,$bgB-30));
                        $muted  = '#6b7280';
                    }
                    foreach (['color_background_secondary' => $bgSec, 'color_border' => $border, 'color_text_muted' => $muted] as $k => $v) {
                        DB::execute("INSERT INTO theme_styles (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value", [$k, $v]);
                    }
                }
            }

            // Apply fonts to theme_styles
            if (!empty($plan['fonts']['heading'])) {
                DB::execute(
                    "INSERT INTO theme_styles (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value",
                    ['font_heading', $plan['fonts']['heading']]
                );
            }
            if (!empty($plan['fonts']['body'])) {
                DB::execute(
                    "INSERT INTO theme_styles (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value",
                    ['font_body', $plan['fonts']['body']]
                );
            }

            // Apply site name and tagline to settings
            if (!empty($plan['site_name'])) {
                Settings::set('site_name', $plan['site_name']);
            }
            if (!empty($plan['tagline'])) {
                Settings::set('tagline', $plan['tagline']);
            }
            if (!empty($plan['ui_theme'])) {
                Settings::set('ui_theme', $plan['ui_theme']);
            }

            // Apply header with proper colors
            if (!empty($plan['tagline']) || !empty($plan['colors'])) {
                $headerBg = $plan['colors']['header_bg'] ?? $plan['colors']['primary'] ?? '#0f172a';
                $headerText = $plan['colors']['header_text'] ?? '#f8fafc';
                DB::execute("DELETE FROM theme_header");
                DB::execute(
                    "INSERT INTO theme_header (tagline, bg_color, text_color) VALUES (?, ?, ?)",
                    [$plan['tagline'] ?? '', $headerBg, $headerText]
                );
            }

            // Apply nav
            if (isset($plan['nav']) && is_array($plan['nav'])) {
                DB::execute("DELETE FROM nav");
                $order = 0;
                foreach ($plan['nav'] as $item) {
                    DB::execute(
                        "INSERT INTO nav (label, url, parent_id, sort_order, visible) VALUES (?, ?, NULL, ?, 1)",
                        [$item['label'], $item['url'], $order++]
                    );
                }
            }

            // Apply pages (clear existing or update)
            if (isset($plan['pages']) && is_array($plan['pages'])) {
                foreach ($plan['pages'] as $page) {
                    // Check if page exists
                    $existing = DB::fetch("SELECT id FROM pages WHERE slug = ?", [$page['slug']]);

                    // Extract layout config from AI plan
                    $pageLayout = (isset($page['layout']) && is_array($page['layout'])) ? $page['layout'] : [];

                    if ($existing) {
                        // Update existing page — merge layout into existing meta_json
                        $pageId = $existing['id'];
                        $existingRow = DB::fetch("SELECT meta_json FROM pages WHERE id = ?", [$pageId]);
                        $existingMeta = json_decode($existingRow['meta_json'] ?? '{}', true) ?? [];
                        if (!empty($pageLayout)) {
                            $existingMeta['layout'] = $pageLayout;
                        }
                        DB::execute(
                            "UPDATE pages SET title = ?, meta_description = ?, meta_json = ?, status = 'published', updated_at = datetime('now') WHERE id = ?",
                            [$page['title'], $page['meta_description'] ?? '', json_encode($existingMeta), $pageId]
                        );
                        // Clear existing blocks
                        DB::execute("DELETE FROM content_blocks WHERE page_id = ?", [$pageId]);
                    } else {
                        // Create new page with layout config
                        $newMeta = !empty($pageLayout) ? ['layout' => $pageLayout] : [];
                        DB::execute(
                            "INSERT INTO pages (slug, title, meta_description, meta_json, status, created_at) VALUES (?, ?, ?, ?, 'published', datetime('now'))",
                            [$page['slug'], $page['title'], $page['meta_description'] ?? '', json_encode($newMeta)]
                        );
                        $pageId = $db->lastInsertId();
                    }

                    // Add blocks
                    if (isset($page['blocks']) && is_array($page['blocks'])) {
                        $blockOrder = 0;
                        foreach ($page['blocks'] as $block) {
                            // New AI-HTML format: store {"html":"...","anchor":"..."}; legacy content format: store content object
                            if (isset($block['html'])) {
                                $blockData = ['html' => $block['html']];
                                if (!empty($block['anchor'])) $blockData['anchor'] = $block['anchor'];
                                elseif (!empty($block['content']['anchor'])) $blockData['anchor'] = $block['content']['anchor'];
                                $blockJson = json_encode($blockData);
                            } else {
                                $blockJson = json_encode($block['content'] ?? $block);
                            }
                            DB::execute(
                                "INSERT INTO content_blocks (page_id, type, block_json, sort_order) VALUES (?, ?, ?, ?)",
                                [$pageId, $block['type'], $blockJson, $blockOrder++]
                            );
                        }
                    }
                }
            }

            // Apply footer with proper colors
            if (isset($plan['footer']) || !empty($plan['colors'])) {
                $footerBg = $plan['colors']['footer_bg'] ?? '#1e293b';
                $footerText = $plan['colors']['footer_text'] ?? '#f1f5f9';
                DB::execute("DELETE FROM theme_footer");
                DB::execute(
                    "INSERT INTO theme_footer (text, links_json, social_json, bg_color, text_color) VALUES (?, ?, ?, ?, ?)",
                    [
                        $plan['footer']['text'] ?? '',
                        json_encode($plan['footer']['links'] ?? []),
                        json_encode($plan['footer']['social'] ?? []),
                        $footerBg,
                        $footerText
                    ]
                );
            }

            // Mark as applied
            DB::execute(
                "UPDATE build_queue SET status = 'applied', applied_at = datetime('now') WHERE id = ?",
                [$id]
            );

            $db->commit();

            // Regenerate all caches (pages, partials, CSS)
            Cache::regenerateAll();

            return true;
        } catch (Exception $e) {
            $db->rollBack();
            error_log("BuildQueue apply error: " . $e->getMessage());
            return false;
        }
    }
}

class ApprovalController {
    // Render an admin template with layout
    private static function renderAdmin(string $template, array $data = [], array $flags = []): void {
        $user         = Auth::user();
        $pendingCount = DB::fetch("SELECT COUNT(*) as c FROM build_queue WHERE status = 'pending'")['c'] ?? 0;
        $flash        = Session::getFlash();
        $flashError   = ($flash && $flash['type'] === 'error')   ? $flash['message'] : null;
        $flashSuccess = ($flash && $flash['type'] === 'success') ? $flash['message'] : null;
        $content      = Template::render($template, $data);
        Response::html(Template::render('admin_layout', array_merge([
            'title'         => $data['title'] ?? 'Approvals',
            'content'       => $content,
            'user_email'    => $user['email'] ?? '',
            'user_role'     => ucfirst($user['role'] ?? 'User'),
            'pending_count' => $pendingCount,
            'flash_error'   => $flashError,
            'flash_success' => $flashSuccess,
            'csp_nonce'     => defined('CSP_NONCE') ? CSP_NONCE : '',
            'csrf_field'    => '<input type="hidden" name="_csrf" value="' . CSRF::token() . '">',
            'blog_enabled'  => Settings::get('blog_enabled', '0') === '1',
            'is_dashboard'  => false,
            'is_pages'      => false,
            'is_nav'        => false,
            'is_media'      => false,
            'is_theme'      => false,
            'is_users'      => false,
            'is_ai'         => false,
            'is_ai_chat'    => false,
            'is_approvals'  => true,
            'is_blog'       => false,
            'is_blog_cats'  => false,
        ], $flags)));
    }

    // Display the approvals queue
    public static function queue(): void {
        Auth::require('*');

        $pending = BuildQueue::getPending();
        $approved = DB::fetchAll(
            "SELECT * FROM build_queue WHERE status = 'approved' ORDER BY approved_at DESC LIMIT 10"
        );
        $applied = DB::fetchAll(
            "SELECT * FROM build_queue WHERE status = 'applied' ORDER BY applied_at DESC LIMIT 10"
        );

        // Annotate each item with has_brief flag
        $addBriefFlag = function(array $items): array {
            return array_map(function($item) {
                $item['has_brief'] = !empty($item['brief_json']);
                return $item;
            }, $items);
        };
        $pending  = $addBriefFlag($pending);
        $approved = $addBriefFlag($approved);
        $applied  = $addBriefFlag($applied);

        // Mark the most recently applied plan as the active (live) one
        if (!empty($applied)) {
            $applied[0]['is_active'] = true;
        }

        self::renderAdmin('admin_approvals', [
            'pending' => $pending,
            'approved' => $approved,
            'applied' => $applied,
            'has_pending' => count($pending) > 0,
            'has_approved' => count($approved) > 0,
            'has_applied' => count($applied) > 0
        ]);
    }

    // Store a plan from the SSE orchestrator (POST /admin/approvals)
    public static function store(): void {
        Auth::require('content.edit');
        CSRF::require();

        $planJson = $_POST['plan_json'] ?? '';
        if (!$planJson) {
            Session::flash('error', 'No plan data received.');
            Response::redirect('/admin/approvals');
        }

        $plan = json_decode($planJson, true);
        if (!$plan || empty($plan['pages'])) {
            Session::flash('error', 'Plan data is invalid or missing pages.');
            Response::redirect('/admin/approvals');
        }

        $briefJson = $_POST['brief_json'] ?? null;

        DB::execute(
            "INSERT INTO build_queue (plan_json, brief_json, status, created_at) VALUES (?, ?, 'pending', datetime('now'))",
            [json_encode($plan), $briefJson ?: null]
        );

        Session::flash('success', 'Site plan queued for approval!');
        Response::redirect('/admin/approvals');
    }

    // View a single approval item
    public static function view(int $id): void {
        Auth::require('*');

        $item = BuildQueue::get($id);
        if (!$item) {
            Session::flash('error', 'Build plan not found.');
            Response::redirect('/admin/approvals');
        }

        $plan = json_decode($item['plan_json'], true);

        // Add status flags for template conditionals
        $item['is_pending'] = ($item['status'] === 'pending');
        $item['is_approved'] = ($item['status'] === 'approved');
        $item['is_applied'] = ($item['status'] === 'applied');

        // Stamp can_regenerate onto each page so {{#if can_regenerate}} works inside {{#each plan.pages}}
        $canRegenerate = !$item['is_applied'];
        foreach ($plan['pages'] as &$p) { $p['can_regenerate'] = $canRegenerate; }
        unset($p);

        self::renderAdmin('admin_approval_view', [
            'item' => $item,
            'plan' => $plan,
            'plan_json' => json_encode($plan, JSON_PRETTY_PRINT),
            'pages_count' => count($plan['pages'] ?? [])
        ]);
    }

    // Approve a build plan
    public static function approve(int $id): void {
        Auth::require('*');

        if (!CSRF::verify($_POST['_csrf'] ?? '')) {
            Session::flash('error', 'Invalid security token.');
            Response::redirect('/admin/approvals');
        }

        if (BuildQueue::approve($id)) {
            Session::flash('success', 'Build plan approved! You can now apply it.');
        } else {
            Session::flash('error', 'Failed to approve build plan.');
        }

        Response::redirect('/admin/approvals/' . $id);
    }

    // Apply an approved build plan
    public static function apply(int $id): void {
        Auth::require('*');

        if (!CSRF::verify($_POST['_csrf'] ?? '')) {
            Session::flash('error', 'Invalid security token.');
            Response::redirect('/admin/approvals');
        }

        if (BuildQueue::apply($id)) {
            Session::flash('success', 'Site plan applied successfully! Your new site is ready.');
        } else {
            Session::flash('error', 'Failed to apply build plan. It may not be approved yet.');
        }

        Response::redirect('/admin/approvals');
    }

    // Reject a build plan
    public static function reject(int $id): void {
        Auth::require('*');

        if (!CSRF::verify($_POST['_csrf'] ?? '')) {
            Session::flash('error', 'Invalid security token.');
            Response::redirect('/admin/approvals');
        }

        $reason = trim(Request::input('reason', ''));

        if (BuildQueue::reject($id, $reason)) {
            Session::flash('success', 'Build plan rejected.');
        } else {
            Session::flash('error', 'Failed to reject build plan.');
        }

        Response::redirect('/admin/approvals');
    }

    // Preview with editable blocks
    public static function preview(int $id): void {
        Auth::require('*');

        $item = BuildQueue::get($id);
        if (!$item) {
            Session::flash('error', 'Build plan not found.');
            Response::redirect('/admin/approvals');
        }

        // Add convenience flags for template
        $item['is_pending'] = $item['status'] === 'pending';
        $item['is_approved'] = $item['status'] === 'approved';

        $plan = json_decode($item['plan_json'], true);
        $uiTheme = $plan['ui_theme'] ?? 'emerald';

        // Escape JSON for safe embedding in HTML script tag
        $planJson = json_encode($plan, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        Response::html(Template::render('admin_approval_preview', [
            'item' => $item,
            'plan' => $plan,
            'plan_json' => $planJson,
            'ui_theme' => $uiTheme,
            'csrf_token' => CSRF::token(),
            'media_picker_html' => EditorAssets::mediaPickerBulmaHtml()
        ]));
    }

    // Update a specific block in the build queue
    public static function updateBlock(int $id): void {
        Auth::require('*');

        header('Content-Type: application/json');

        if (!CSRF::verify($_POST['_csrf'] ?? '')) {
            echo json_encode(['success' => false, 'error' => 'Invalid token']);
            exit;
        }

        $item = BuildQueue::get($id);
        if (!$item || $item['status'] === 'applied') {
            echo json_encode(['success' => false, 'error' => 'Cannot modify applied plan']);
            exit;
        }

        $plan = json_decode($item['plan_json'], true);
        $pageIndex = (int) Request::input('page_index', 0);
        $blockIndex = (int) Request::input('block_index', 0);
        $blockData = json_decode(Request::input('block_data', '{}'), true);

        if (!isset($plan['pages'][$pageIndex]['blocks'][$blockIndex])) {
            echo json_encode(['success' => false, 'error' => 'Block not found']);
            exit;
        }

        // Update the block
        $plan['pages'][$pageIndex]['blocks'][$blockIndex] = $blockData;

        // Save back to database
        DB::execute(
            "UPDATE build_queue SET plan_json = ? WHERE id = ?",
            [json_encode($plan), $id]
        );

        echo json_encode(['success' => true]);
        exit;
    }

    // Generate individual team member pages
    public static function generateTeamPages(int $id): void {
        Auth::require('*');

        header('Content-Type: application/json');

        if (!CSRF::verify($_POST['_csrf'] ?? '')) {
            echo json_encode(['success' => false, 'error' => 'Invalid token']);
            exit;
        }

        $item = BuildQueue::get($id);
        if (!$item || $item['status'] === 'applied') {
            echo json_encode(['success' => false, 'error' => 'Cannot modify applied plan']);
            exit;
        }

        $plan = json_decode($item['plan_json'], true);
        $pageIndex = (int) Request::input('page_index', 0);
        $blockIndex = (int) Request::input('block_index', 0);

        if (!isset($plan['pages'][$pageIndex]['blocks'][$blockIndex])) {
            echo json_encode(['success' => false, 'error' => 'Block not found']);
            exit;
        }

        $teamBlock = $plan['pages'][$pageIndex]['blocks'][$blockIndex];
        if ($teamBlock['type'] !== 'team') {
            echo json_encode(['success' => false, 'error' => 'Not a team block']);
            exit;
        }

        $items = $teamBlock['content']['items'] ?? $teamBlock['items'] ?? [];
        $newPages = [];
        $updatedItems = [];

        foreach ($items as $member) {
            $name = $member['name'] ?? 'Team Member';
            $slug = 'team/' . preg_replace('/[^a-z0-9]+/', '-', strtolower($name));
            $role = $member['role'] ?? '';
            $bio = $member['bio'] ?? '';
            $photo = $member['photo'] ?? '';

            // Create page for this member
            $memberPage = [
                'slug' => $slug,
                'title' => $name,
                'meta_description' => "$name - $role at " . ($plan['site_name'] ?? 'Our Company'),
                'blocks' => [
                    [
                        'type' => 'hero',
                        'content' => [
                            'title' => $name,
                            'subtitle' => $role,
                            'image' => $photo
                        ]
                    ],
                    [
                        'type' => 'text',
                        'content' => [
                            'content' => "<h2>About $name</h2><p>$bio</p>"
                        ]
                    ],
                    [
                        'type' => 'cta',
                        'content' => [
                            'title' => 'Get in Touch',
                            'text' => "Want to work with $name? Contact us today.",
                            'button' => 'Contact Us',
                            'url' => '/contact'
                        ]
                    ]
                ]
            ];

            $newPages[] = $memberPage;

            // Add link to team member item
            $member['url'] = '/' . $slug;
            $updatedItems[] = $member;
        }

        // Update team block with links
        if (isset($plan['pages'][$pageIndex]['blocks'][$blockIndex]['content']['items'])) {
            $plan['pages'][$pageIndex]['blocks'][$blockIndex]['content']['items'] = $updatedItems;
        } else {
            $plan['pages'][$pageIndex]['blocks'][$blockIndex]['items'] = $updatedItems;
        }

        // Add new pages to plan
        $plan['pages'] = array_merge($plan['pages'], $newPages);

        // Save back to database
        DB::execute(
            "UPDATE build_queue SET plan_json = ? WHERE id = ?",
            [json_encode($plan), $id]
        );

        echo json_encode([
            'success' => true,
            'message' => count($newPages) . ' team member pages created',
            'pages_created' => count($newPages)
        ]);
        exit;
    }

    // Regenerate a single page within an existing build, preserving all other pages and the original brief
    public static function regeneratePage(int $id): void {
        Auth::require('*');

        header('Content-Type: application/json');

        if (!CSRF::verify($_POST['_csrf'] ?? '')) {
            echo json_encode(['success' => false, 'error' => 'Invalid token']);
            exit;
        }

        $item = BuildQueue::get($id);
        if (!$item) {
            echo json_encode(['success' => false, 'error' => 'Plan not found']);
            exit;
        }

        $slug = trim(Request::input('page_slug', ''));
        if (!$slug) {
            echo json_encode(['success' => false, 'error' => 'page_slug is required']);
            exit;
        }

        $plan  = json_decode($item['plan_json'], true);
        $brief = json_decode($item['brief_json'] ?? '{}', true) ?? [];

        // Find the page by slug
        $pageIndex = null;
        foreach ($plan['pages'] as $i => $p) {
            if (($p['slug'] ?? '') === $slug) { $pageIndex = $i; break; }
        }
        if ($pageIndex === null) {
            echo json_encode(['success' => false, 'error' => "Page '{$slug}' not found in this plan"]);
            exit;
        }

        // Reconstruct $structure from plan_json so buildPagePrompt has full context
        $structure = [
            'site_name' => $plan['site_name'] ?? '',
            'tagline'   => $plan['tagline']   ?? '',
            'colors'    => $plan['colors']    ?? [],
            'nav'       => $plan['nav']       ?? [],
            'footer'    => $plan['footer']    ?? [],
            'facts'     => [],
            'pages'     => array_map(fn($p) => [
                'slug'    => $p['slug']    ?? '',
                'title'   => $p['title']   ?? '',
                'purpose' => $p['purpose'] ?? '',
                'layout'  => $p['layout']  ?? [],
            ], $plan['pages']),
        ];

        $pagePlan = $structure['pages'][$pageIndex];

        $prompt     = AIController::buildPagePrompt($brief, $pagePlan, $structure);
        $pageContent = AI::generate($prompt, ['stage' => 2, 'page_slug' => $slug]);

        // Extract blocks — mirror the same fallback logic used in the normal generation flow
        $blocks   = null;
        $metaDesc = $plan['pages'][$pageIndex]['meta_description'] ?? '';

        if ($pageContent) {
            if (isset($pageContent['blocks'])) {
                $blocks   = $pageContent['blocks'];
                $metaDesc = $pageContent['meta_description'] ?? $metaDesc;
            } elseif (isset($pageContent['pages'][0]['blocks'])) {
                $blocks   = $pageContent['pages'][0]['blocks'];
                $metaDesc = $pageContent['pages'][0]['meta_description'] ?? $metaDesc;
            } elseif (isset($pageContent['pages'])) {
                foreach ($pageContent['pages'] as $p) {
                    if (($p['slug'] ?? '') === $slug && isset($p['blocks'])) {
                        $blocks   = $p['blocks'];
                        $metaDesc = $p['meta_description'] ?? $metaDesc;
                        break;
                    }
                }
            }
        }

        if (empty($blocks)) {
            echo json_encode(['success' => false, 'error' => 'AI did not return valid blocks — try again.']);
            exit;
        }

        // Patch plan in-place and persist; reset applied plans to pending so they can be re-applied
        $plan['pages'][$pageIndex]['blocks']           = $blocks;
        $plan['pages'][$pageIndex]['meta_description'] = $metaDesc;

        $newStatus = ($item['status'] === 'applied') ? 'approved' : $item['status'];
        DB::execute("UPDATE build_queue SET plan_json = ?, status = ? WHERE id = ?", [json_encode($plan), $newStatus, $id]);

        echo json_encode([
            'success'          => true,
            'page_index'       => $pageIndex,
            'blocks'           => $blocks,
            'meta_description' => $metaDesc,
        ]);
        exit;
    }

    // ─── EDIT SINGLE BLOCK WITH AI ────────────────────────────────────────────
    public static function editBlockWithAI(int $id): void {
        Auth::require('*');
        header('Content-Type: application/json');
        if (!CSRF::verify($_POST['_csrf'] ?? '')) {
            echo json_encode(['success' => false, 'error' => 'Invalid token']); exit;
        }

        $item = BuildQueue::get($id);
        if (!$item) {
            echo json_encode(['success' => false, 'error' => 'Plan not found']); exit;
        }

        $slug        = trim(Request::input('page_slug', ''));
        $blockIndex  = (int)Request::input('block_index', -1);
        $instruction = trim(Request::input('instruction', ''));

        if (!$slug || $blockIndex < 0 || !$instruction) {
            echo json_encode(['success' => false, 'error' => 'Missing required fields']); exit;
        }

        $plan = json_decode($item['plan_json'], true);

        $pageIndex = null;
        foreach ($plan['pages'] as $i => $p) {
            if (($p['slug'] ?? '') === $slug) { $pageIndex = $i; break; }
        }
        if ($pageIndex === null) {
            echo json_encode(['success' => false, 'error' => "Page '{$slug}' not found in this plan"]); exit;
        }

        $blocks = $plan['pages'][$pageIndex]['blocks'] ?? [];
        if (!isset($blocks[$blockIndex])) {
            echo json_encode(['success' => false, 'error' => "Block index {$blockIndex} not found"]); exit;
        }

        $currentBlock = $blocks[$blockIndex];
        $siteContext  = [
            'site_name'  => $plan['site_name'] ?? '',
            'colors'     => $plan['colors']    ?? [],
            'page_title' => $plan['pages'][$pageIndex]['title'] ?? '',
            'page_slug'  => $slug,
        ];

        $prompt = self::buildEditBlockPrompt($instruction, $currentBlock, $siteContext);
        $result = AI::generate($prompt, ['stage' => 'edit_block', 'page_slug' => $slug]);

        // Extract updated block — accept {html:...} or {block:{html:...}}
        $newBlock = null;
        if (isset($result['html'])) {
            $newBlock = $result;
        } elseif (isset($result['block']['html'])) {
            $newBlock = $result['block'];
        } elseif (isset($result['blocks'][0]['html'])) {
            $newBlock = $result['blocks'][0];
        }

        if (empty($newBlock['html'])) {
            echo json_encode(['success' => false, 'error' => 'AI did not return valid HTML — try again.']); exit;
        }

        // Merge: preserve type/sort_order, replace html
        $plan['pages'][$pageIndex]['blocks'][$blockIndex] = array_merge($currentBlock, ['html' => $newBlock['html']]);

        $newStatus = ($item['status'] === 'applied') ? 'approved' : $item['status'];
        DB::execute("UPDATE build_queue SET plan_json = ?, status = ? WHERE id = ?", [json_encode($plan), $newStatus, $id]);

        echo json_encode([
            'success' => true,
            'block'   => $plan['pages'][$pageIndex]['blocks'][$blockIndex],
            'block_index' => $blockIndex,
        ]);
        exit;
    }

    private static function buildEditBlockPrompt(string $instruction, array $block, array $siteContext): string {
        $colors  = $siteContext['colors'] ?? [];
        $palette = implode(', ', array_filter([
            isset($colors['primary'])    ? "primary={$colors['primary']}"    : '',
            isset($colors['secondary'])  ? "secondary={$colors['secondary']}" : '',
            isset($colors['background']) ? "bg={$colors['background']}"      : '',
            isset($colors['text'])       ? "text={$colors['text']}"          : '',
        ]));

        $currentHtml = $block['html'] ?? json_encode($block);

        return <<<PROMPT
You are fixing a single HTML content block on a website. Return ONLY a valid JSON object with one key: "html".

Site: {$siteContext['site_name']}
Page: {$siteContext['page_title']} (/{$siteContext['page_slug']})
Color palette: {$palette}

User instruction: {$instruction}

Current block HTML:
{$currentHtml}

Rules:
- Return ONLY: {"html":"...complete fixed HTML..."}
- Keep the same visual style, color scheme, and overall layout — only fix what the instruction asks for
- Preserve any Bulma CSS classes, inline styles, and the general structure
- All inline <script> tags must include nonce="{{csp_nonce}}"
- Do not wrap in markdown fences
PROMPT;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 26: EMAIL
// ─────────────────────────────────────────────────────────────────────────────

class Mailer {
    public static function send(string $to, string $subject, string $body): bool {
        $driver = Settings::get('email_driver', 'smtp');

        return match ($driver) {
            'mailgun'  => self::sendViaMailgun($to, $subject, $body),
            'sendgrid' => self::sendViaSendGrid($to, $subject, $body),
            'postmark' => self::sendViaPostmark($to, $subject, $body),
            default    => self::sendViaSMTP($to, $subject, $body),
        };
    }

    private static function fromAddress(): string {
        $configured = Settings::get('smtp_from', '');
        if ($configured) return $configured;
        $host = strtok($_SERVER['HTTP_HOST'] ?? 'localhost', ':'); // strip port if present
        return 'no-reply@' . $host;
    }

    // ── SMTP ─────────────────────────────────────────────────────────────────

    private static function sendViaSMTP(string $to, string $subject, string $body): bool {
        $host = Settings::get('smtp_host');

        if (!$host) {
            // Fallback to PHP mail()
            $from = self::fromAddress();
            $headers = "From: $from\r\nContent-Type: text/html; charset=UTF-8";
            return @mail($to, $subject, $body, $headers);
        }

        $port = (int) Settings::get('smtp_port', 587);
        $user = Settings::get('smtp_user');
        $pass = Settings::get('smtp_pass');
        $from = Settings::get('smtp_from', $user);

        try {
            $socket = @fsockopen($host, $port, $errno, $errstr, 30);
            if (!$socket) {
                return false;
            }

            // Read greeting
            fgets($socket);

            // EHLO
            fwrite($socket, "EHLO " . gethostname() . "\r\n");
            self::readResponse($socket);

            // STARTTLS if port 587
            if ($port === 587) {
                fwrite($socket, "STARTTLS\r\n");
                self::readResponse($socket);
                stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                fwrite($socket, "EHLO " . gethostname() . "\r\n");
                self::readResponse($socket);
            }

            // AUTH LOGIN
            if ($user && $pass) {
                fwrite($socket, "AUTH LOGIN\r\n");
                self::readResponse($socket);
                fwrite($socket, base64_encode($user) . "\r\n");
                self::readResponse($socket);
                fwrite($socket, base64_encode($pass) . "\r\n");
                self::readResponse($socket);
            }

            // MAIL FROM
            fwrite($socket, "MAIL FROM:<$from>\r\n");
            self::readResponse($socket);

            // RCPT TO
            fwrite($socket, "RCPT TO:<$to>\r\n");
            self::readResponse($socket);

            // DATA
            fwrite($socket, "DATA\r\n");
            self::readResponse($socket);

            // Message
            $message  = "From: $from\r\n";
            $message .= "To: $to\r\n";
            $message .= "Subject: $subject\r\n";
            $message .= "MIME-Version: 1.0\r\n";
            $message .= "Content-Type: text/html; charset=UTF-8\r\n";
            $message .= "\r\n";
            $message .= $body;
            $message .= "\r\n.\r\n";

            fwrite($socket, $message);
            self::readResponse($socket);

            // QUIT
            fwrite($socket, "QUIT\r\n");
            fclose($socket);

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    private static function readResponse($socket): string {
        $response = '';
        while ($line = fgets($socket, 512)) {
            $response .= $line;
            if (substr($line, 3, 1) === ' ') {
                break;
            }
        }
        return $response;
    }

    // ── MAILGUN ──────────────────────────────────────────────────────────────

    private static function sendViaMailgun(string $to, string $subject, string $body): bool {
        $apiKey = Settings::get('mailgun_api_key');
        $domain = Settings::get('mailgun_domain');
        $from   = self::fromAddress();

        if (!$apiKey || !$domain) {
            return false;
        }

        $payload = http_build_query([
            'from'    => $from,
            'to'      => $to,
            'subject' => $subject,
            'html'    => $body,
        ]);

        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Authorization: Basic " . base64_encode("api:{$apiKey}") . "\r\n" .
                               "Content-Type: application/x-www-form-urlencoded\r\n",
            'content'       => $payload,
            'timeout'       => 15,
            'ignore_errors' => true,
        ]]);

        @file_get_contents("https://api.mailgun.net/v3/{$domain}/messages", false, $ctx);
        $code = (int)(explode(' ', $http_response_header[0] ?? 'HTTP/1.1 0')[1] ?? 0);
        return $code === 200;
    }

    // ── SENDGRID ─────────────────────────────────────────────────────────────

    private static function sendViaSendGrid(string $to, string $subject, string $body): bool {
        $apiKey = Settings::get('sendgrid_api_key');
        $from   = self::fromAddress();

        if (!$apiKey) {
            return false;
        }

        $payload = json_encode([
            'personalizations' => [['to' => [['email' => $to]]]],
            'from'    => ['email' => $from],
            'subject' => $subject,
            'content' => [['type' => 'text/html', 'value' => $body]],
        ]);

        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Authorization: Bearer {$apiKey}\r\n" .
                               "Content-Type: application/json\r\n",
            'content'       => $payload,
            'timeout'       => 15,
            'ignore_errors' => true,
        ]]);

        @file_get_contents('https://api.sendgrid.com/v3/mail/send', false, $ctx);
        $code = (int)(explode(' ', $http_response_header[0] ?? 'HTTP/1.1 0')[1] ?? 0);
        return $code === 202;
    }

    // ── POSTMARK ─────────────────────────────────────────────────────────────

    private static function sendViaPostmark(string $to, string $subject, string $body): bool {
        $apiKey = Settings::get('postmark_api_key');
        $from   = self::fromAddress();

        if (!$apiKey) {
            return false;
        }

        $payload = json_encode([
            'From'     => $from,
            'To'       => $to,
            'Subject'  => $subject,
            'HtmlBody' => $body,
        ]);

        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "X-Postmark-Server-Token: {$apiKey}\r\n" .
                               "Content-Type: application/json\r\n" .
                               "Accept: application/json\r\n",
            'content'       => $payload,
            'timeout'       => 15,
            'ignore_errors' => true,
        ]]);

        @file_get_contents('https://api.postmarkapp.com/email', false, $ctx);
        $code = (int)(explode(' ', $http_response_header[0] ?? 'HTTP/1.1 0')[1] ?? 0);
        return $code === 200;
    }

    // ── HELPERS ──────────────────────────────────────────────────────────────

    public static function sendMFACode(string $email, string $code): bool {
        $subject = 'Your Login Verification Code';
        $body = <<<HTML
<div style="font-family: sans-serif; max-width: 400px; margin: 0 auto; padding: 20px;">
    <h2 style="color: #1e293b;">Verification Code</h2>
    <p>Your verification code is:</p>
    <div style="font-size: 32px; font-weight: bold; letter-spacing: 8px; padding: 20px; background: #f1f5f9; text-align: center; border-radius: 8px;">
        $code
    </div>
    <p style="color: #64748b; font-size: 14px; margin-top: 20px;">This code expires in 10 minutes.</p>
</div>
HTML;

        return self::send($email, $subject, $body);
    }

    public static function sendContactForm(array $data): bool {
        $to = Settings::get('contact_email', Settings::get('smtp_from'));
        if (!$to) {
            return false;
        }

        $subject = 'New Contact Form Submission';
        $body  = '<h2>New Contact Form Submission</h2>';
        $body .= '<p><strong>Name:</strong> ' . Sanitize::html($data['name']) . '</p>';
        $body .= '<p><strong>Email:</strong> ' . Sanitize::html($data['email']) . '</p>';
        $body .= '<p><strong>Message:</strong><br>' . nl2br(Sanitize::html($data['message'])) . '</p>';

        return self::send($to, $subject, $body);
    }

    public static function sendWelcome(string $email, string $password, string $role): bool {
        $siteName = Settings::get('site_name', 'MonolithCMS');
        $host     = strtok($_SERVER['HTTP_HOST'] ?? 'localhost', ':');
        $scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $loginUrl = $scheme . '://' . $host . '/admin/login';
        $subject  = 'Welcome to ' . $siteName . ' — Your account is ready';

        $body = <<<HTML
<div style="font-family:sans-serif;max-width:480px;margin:0 auto;padding:24px;color:#1e293b;">
    <h2 style="margin:0 0 8px;font-size:22px;color:#1e293b;">Welcome to {$siteName}</h2>
    <p style="margin:0 0 24px;color:#475569;">Your account has been created. Here are your login details:</p>
    <table style="width:100%;border-collapse:collapse;margin-bottom:24px;font-size:14px;">
        <tr><td style="padding:8px 12px;background:#f8fafc;border:1px solid #e2e8f0;font-weight:600;width:120px;">Site</td><td style="padding:8px 12px;border:1px solid #e2e8f0;">{$siteName}</td></tr>
        <tr><td style="padding:8px 12px;background:#f8fafc;border:1px solid #e2e8f0;font-weight:600;">Email</td><td style="padding:8px 12px;border:1px solid #e2e8f0;">{$email}</td></tr>
        <tr><td style="padding:8px 12px;background:#f8fafc;border:1px solid #e2e8f0;font-weight:600;">Password</td><td style="padding:8px 12px;border:1px solid #e2e8f0;font-family:monospace;">{$password}</td></tr>
        <tr><td style="padding:8px 12px;background:#f8fafc;border:1px solid #e2e8f0;font-weight:600;">Role</td><td style="padding:8px 12px;border:1px solid #e2e8f0;">{$role}</td></tr>
    </table>
    <a href="{$loginUrl}" style="display:inline-block;background:#135bec;color:#fff;text-decoration:none;padding:12px 28px;border-radius:8px;font-weight:600;font-size:15px;">Log in now</a>
    <p style="margin:24px 0 0;font-size:12px;color:#94a3b8;">Please change your password after your first login.</p>
</div>
HTML;

        return self::send($email, $subject, $body);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 27: BLOG MODULE
// ─────────────────────────────────────────────────────────────────────────────

class BlogController {
    private static function getNavAndFooter(): array {
        return [
            'nav_html'    => Template::partial('nav'),
            'footer_html' => Template::partial('footer'),
            'ui_theme'    => Settings::get('ui_theme', 'light'),
            'site_name'   => Settings::get('site_name', 'Site'),
        ];
    }

    /** GET /blog */
    public static function index(): void {
        if (Settings::get('blog_enabled', '0') !== '1') { Response::notFound(); return; }
        $perPage  = (int)(Settings::get('blog_posts_per_page', '10'));
        $page     = max(1, (int)($_GET['page'] ?? 1));
        $status   = $_GET['status'] ?? null;

        $where  = "WHERE p.status = 'published'";
        $params = [];
        if ($status === 'category' && isset($_GET['cat'])) {
            // handled by category() below
        }

        $total   = (int)(DB::fetch("SELECT COUNT(*) as c FROM blog_posts p $where", $params)['c'] ?? 0);
        $offset  = ($page - 1) * $perPage;
        $posts   = DB::fetchAll(
            "SELECT p.*, COALESCE(NULLIF(TRIM(u.name),''), SUBSTR(u.email,1,INSTR(u.email,'@')-1)) as author_name, c.name as category_name, c.slug as category_slug,
                    CASE WHEN a.hash IS NOT NULL THEN '/assets/' || a.hash ELSE NULL END as cover_url
             FROM blog_posts p
             LEFT JOIN users u ON u.id = p.author_id
             LEFT JOIN blog_categories c ON c.id = p.category_id
             LEFT JOIN assets a ON a.id = p.cover_asset_id
             $where ORDER BY p.published_at DESC LIMIT ? OFFSET ?",
            array_merge($params, [$perPage, $offset])
        );

        foreach ($posts as &$post) {
            $post['published_at_fmt'] = $post['published_at']
                ? date('M j, Y', strtotime($post['published_at'])) : '';
        }

        $totalPages = (int)ceil($total / $perPage);

        Response::html(Template::render('blog_index', array_merge(self::getNavAndFooter(), [
            'posts'            => $posts,
            'blog_title'       => Settings::get('blog_title', 'Blog'),
            'blog_description' => Settings::get('blog_description', ''),
            'has_pagination'   => $totalPages > 1,
            'current_page'     => $page,
            'total_pages'      => $totalPages,
            'prev_page'        => $page > 1 ? $page - 1 : null,
            'next_page'        => $page < $totalPages ? $page + 1 : null,
        ])));
    }

    /** GET /blog/category/{slug} */
    public static function category(string $slug): void {
        if (Settings::get('blog_enabled', '0') !== '1') { Response::notFound(); return; }
        $cat = DB::fetch("SELECT * FROM blog_categories WHERE slug = ?", [$slug]);
        if (!$cat) { http_response_code(404); echo '404 Not Found'; return; }

        $perPage  = (int)(Settings::get('blog_posts_per_page', '10'));
        $page     = max(1, (int)($_GET['page'] ?? 1));

        $total  = (int)(DB::fetch("SELECT COUNT(*) as c FROM blog_posts WHERE status='published' AND category_id=?", [$cat['id']])['c'] ?? 0);
        $offset = ($page - 1) * $perPage;
        $posts  = DB::fetchAll(
            "SELECT p.*, COALESCE(NULLIF(TRIM(u.name),''), SUBSTR(u.email,1,INSTR(u.email,'@')-1)) as author_name, c.name as category_name, c.slug as category_slug,
                    CASE WHEN a.hash IS NOT NULL THEN '/assets/' || a.hash ELSE NULL END as cover_url
             FROM blog_posts p
             LEFT JOIN users u ON u.id=p.author_id
             LEFT JOIN blog_categories c ON c.id=p.category_id
             LEFT JOIN assets a ON a.id=p.cover_asset_id
             WHERE p.status='published' AND p.category_id=?
             ORDER BY p.published_at DESC LIMIT ? OFFSET ?",
            [$cat['id'], $perPage, $offset]
        );
        foreach ($posts as &$post) {
            $post['published_at_fmt'] = $post['published_at'] ? date('M j, Y', strtotime($post['published_at'])) : '';
        }
        $totalPages = (int)ceil($total / $perPage);

        Response::html(Template::render('blog_index', array_merge(self::getNavAndFooter(), [
            'posts'         => $posts,
            'blog_title'    => $cat['name'],
            'blog_description' => $cat['description'] ?? '',
            'has_pagination' => $totalPages > 1,
            'current_page'  => $page, 'total_pages' => $totalPages,
            'prev_page'     => $page > 1 ? $page - 1 : null,
            'next_page'     => $page < $totalPages ? $page + 1 : null,
        ])));
    }

    /** GET /blog/tag/{slug} */
    public static function tag(string $slug): void {
        if (Settings::get('blog_enabled', '0') !== '1') { Response::notFound(); return; }
        $tag = DB::fetch("SELECT * FROM blog_tags WHERE slug = ?", [$slug]);
        if (!$tag) { http_response_code(404); echo '404 Not Found'; return; }

        $perPage = (int)(Settings::get('blog_posts_per_page', '10'));
        $page    = max(1, (int)($_GET['page'] ?? 1));

        $total  = (int)(DB::fetch(
            "SELECT COUNT(*) as c FROM blog_posts p
             INNER JOIN blog_post_tags pt ON pt.post_id=p.id
             WHERE p.status='published' AND pt.tag_id=?",
            [$tag['id']]
        )['c'] ?? 0);
        $offset = ($page - 1) * $perPage;
        $posts  = DB::fetchAll(
            "SELECT p.*, COALESCE(NULLIF(TRIM(u.name),''), SUBSTR(u.email,1,INSTR(u.email,'@')-1)) as author_name, c.name as category_name, c.slug as category_slug,
                    CASE WHEN a.hash IS NOT NULL THEN '/assets/' || a.hash ELSE NULL END as cover_url
             FROM blog_posts p
             LEFT JOIN users u ON u.id=p.author_id
             LEFT JOIN blog_categories c ON c.id=p.category_id
             LEFT JOIN assets a ON a.id=p.cover_asset_id
             INNER JOIN blog_post_tags pt ON pt.post_id=p.id
             WHERE p.status='published' AND pt.tag_id=?
             ORDER BY p.published_at DESC LIMIT ? OFFSET ?",
            [$tag['id'], $perPage, $offset]
        );
        foreach ($posts as &$post) {
            $post['published_at_fmt'] = $post['published_at'] ? date('M j, Y', strtotime($post['published_at'])) : '';
        }
        $totalPages = (int)ceil($total / $perPage);

        Response::html(Template::render('blog_index', array_merge(self::getNavAndFooter(), [
            'posts'            => $posts,
            'blog_title'       => '#' . $tag['name'],
            'blog_description' => 'Posts tagged with ' . htmlspecialchars($tag['name']),
            'has_pagination'   => $totalPages > 1,
            'current_page'     => $page, 'total_pages' => $totalPages,
            'prev_page'        => $page > 1 ? $page - 1 : null,
            'next_page'        => $page < $totalPages ? $page + 1 : null,
        ])));
    }

    /** GET /blog/{slug} */
    public static function post(string $slug): void {
        if (Settings::get('blog_enabled', '0') !== '1') { Response::notFound(); return; }
        $post = DB::fetch(
            "SELECT p.*, COALESCE(NULLIF(TRIM(u.name),''), SUBSTR(u.email,1,INSTR(u.email,'@')-1)) as author_name, c.name as category_name, c.slug as category_slug,
                    CASE WHEN a.hash IS NOT NULL THEN '/assets/' || a.hash ELSE NULL END as cover_url,
                    CASE WHEN og.hash IS NOT NULL THEN '/assets/' || og.hash ELSE NULL END as og_image_url
             FROM blog_posts p
             LEFT JOIN users u ON u.id=p.author_id
             LEFT JOIN blog_categories c ON c.id=p.category_id
             LEFT JOIN assets a ON a.id=p.cover_asset_id
             LEFT JOIN assets og ON og.id=p.og_image_asset_id
             WHERE p.slug=? AND p.status='published'",
            [$slug]
        );
        if (!$post) { http_response_code(404); echo '404 Not Found'; return; }

        $tags = DB::fetchAll(
            "SELECT t.* FROM blog_tags t JOIN blog_post_tags pt ON pt.tag_id=t.id WHERE pt.post_id=?",
            [$post['id']]
        );

        $prev = DB::fetch(
            "SELECT slug, title FROM blog_posts WHERE status='published' AND published_at < ? ORDER BY published_at DESC LIMIT 1",
            [$post['published_at']]
        );
        $next = DB::fetch(
            "SELECT slug, title FROM blog_posts WHERE status='published' AND published_at > ? ORDER BY published_at ASC LIMIT 1",
            [$post['published_at']]
        );

        Response::html(Template::render('blog_post', array_merge(self::getNavAndFooter(), [
            'post_title'       => $post['title'],
            'meta_description' => $post['meta_description'] ?? $post['excerpt'] ?? '',
            'author_name'      => $post['author_name'] ?? '',
            'published_at_fmt' => $post['published_at'] ? date('M j, Y', strtotime($post['published_at'])) : '',
            'category_name'    => $post['category_name'] ?? '',
            'category_slug'    => $post['category_slug'] ?? '',
            'cover_url'        => $post['cover_url'] ?? '',
            'og_image_url'     => $post['og_image_url'] ?? $post['cover_url'] ?? '',
            'body_html'        => $post['body_html'] ?? '',
            'tags'      => $tags,
            'prev_post' => $prev ?: null,
            'next_post' => $next ?: null,
        ])));
    }
}

class BlogAdminController {
    private static function renderAdmin(string $template, array $data = [], array $flags = []): void {
        $user         = Auth::user();
        $pendingCount = DB::fetch("SELECT COUNT(*) as c FROM build_queue WHERE status = 'pending'")['c'] ?? 0;
        $flash        = Session::getFlash();
        $flashError   = ($flash && $flash['type'] === 'error')   ? $flash['message'] : null;
        $flashSuccess = ($flash && $flash['type'] === 'success') ? $flash['message'] : null;
        $content      = Template::render($template, $data);
        Response::html(Template::render('admin_layout', array_merge([
            'title'         => $data['title'] ?? 'Blog',
            'content'       => $content,
            'user_email'    => $user['email'] ?? '',
            'user_role'     => ucfirst($user['role'] ?? 'User'),
            'pending_count' => $pendingCount,
            'flash_error'   => $flashError,
            'flash_success' => $flashSuccess,
            'csp_nonce'     => defined('CSP_NONCE') ? CSP_NONCE : '',
            'csrf_field'    => '<input type="hidden" name="_csrf" value="' . CSRF::token() . '">',
            'blog_enabled'  => Settings::get('blog_enabled', '0') === '1',
            'is_dashboard'  => false,
            'is_pages'      => false,
            'is_nav'        => false,
            'is_media'      => false,
            'is_theme'      => false,
            'is_users'      => false,
            'is_ai'         => false,
            'is_ai_chat'    => false,
            'is_approvals'  => false,
            'is_blog'       => true,
            'is_blog_cats'  => false,
        ], $flags)));
    }

    /** GET /admin/blog/posts */
    public static function posts(): void {
        Auth::require('*');
        $status  = $_GET['status'] ?? null;
        $where   = $status ? "WHERE p.status = ?" : "";
        $params  = $status ? [$status] : [];

        $posts = DB::fetchAll(
            "SELECT p.*, c.name as category_name, u.email as author_name
             FROM blog_posts p
             LEFT JOIN blog_categories c ON c.id = p.category_id
             LEFT JOIN users u ON u.id = p.author_id
             $where ORDER BY p.created_at DESC",
            $params
        );
        foreach ($posts as &$p) {
            $p['published_at_fmt'] = $p['published_at'] ? date('M j, Y', strtotime($p['published_at'])) : '—';
            $p['status_published'] = $p['status'] === 'published';
            $p['status_draft']     = $p['status'] === 'draft';
            $p['status_archived']  = $p['status'] === 'archived';
        }

        self::renderAdmin('admin/blog_posts', [
            'posts'            => $posts,
            'total_posts'      => count($posts),
            'filter_all'       => !$status,
            'filter_published' => $status === 'published',
            'filter_draft'     => $status === 'draft',
        ]);
    }

    /** GET /admin/blog/posts/new */
    public static function newPost(): void {
        Auth::require('content.edit');
        $cats    = DB::fetchAll("SELECT * FROM blog_categories ORDER BY name");
        $allTags = DB::fetchAll("SELECT * FROM blog_tags ORDER BY name");
        self::renderAdmin('admin/blog_post_edit', [
            'categories'         => $cats,
            'all_tags'           => $allTags,
            'post_tags'          => [],
            'status_draft'       => true,
            'published_at_input' => date('Y-m-d\TH:i'),
            'post_markdown_b64'  => '',
            'autoopen_ai'        => isset($_GET['ai']) ? 'true' : 'false',
        ]);
    }

    /** GET /admin/blog/posts/{id}/edit */
    public static function editPost(int $id): void {
        Auth::require('content.edit');
        $post = DB::fetch("SELECT * FROM blog_posts WHERE id = ?", [$id]);
        if (!$post) { Response::redirect('/admin/blog/posts'); }

        $cats     = DB::fetchAll("SELECT * FROM blog_categories ORDER BY name");
        $allTags  = DB::fetchAll("SELECT * FROM blog_tags ORDER BY name");
        $postTags = DB::fetchAll("SELECT t.* FROM blog_tags t JOIN blog_post_tags pt ON pt.tag_id=t.id WHERE pt.post_id=?", [$id]);
        $cover    = $post['cover_asset_id'] ? DB::fetch("SELECT hash FROM assets WHERE id=?", [$post['cover_asset_id']]) : null;

        $categoriesWithSelected = array_map(function($c) use ($post) {
            $c['selected'] = ($c['id'] == $post['category_id']);
            return $c;
        }, $cats);

        self::renderAdmin('admin/blog_post_edit', [
            'post_id'            => $post['id'],
            'post_title'         => $post['title'],
            'post_slug'          => $post['slug'],
            'post_excerpt'       => $post['excerpt'] ?? '',
            'post_body'          => $post['body_html'] ?? '',
            'post_markdown_b64'  => base64_encode($post['body_markdown'] ?? ''),
            'meta_description'   => $post['meta_description'] ?? '',
            'cover_url'          => $cover['hash'] ? '/assets/' . $cover['hash'] : '',
            'cover_asset_id'     => $post['cover_asset_id'] ?? '',
            'published_at_input' => $post['published_at'] ? date('Y-m-d\TH:i', strtotime($post['published_at'])) : date('Y-m-d\TH:i'),
            'status_draft'       => $post['status'] === 'draft',
            'status_published'   => $post['status'] === 'published',
            'status_archived'    => $post['status'] === 'archived',
            'categories'         => $categoriesWithSelected,
            'all_tags'           => $allTags,
            'post_tags'          => $postTags,
            'autoopen_ai'        => 'false',
        ]);
    }

    /** POST /admin/blog/posts/save */
    public static function savePost(): void {
        Auth::require('content.edit');
        CSRF::require();

        $id           = (int)($_POST['post_id'] ?? 0);
        $title        = trim($_POST['title'] ?? '');
        $slug         = trim($_POST['slug'] ?? '');
        $excerpt      = trim($_POST['excerpt'] ?? '');
        $bodyHtml     = $_POST['body_html'] ?? '';
        $bodyMarkdown = trim($_POST['body_markdown'] ?? '');
        $metaDesc     = trim($_POST['meta_description'] ?? '');
        $status       = in_array($_POST['status'] ?? '', ['draft','published','archived']) ? $_POST['status'] : 'draft';
        $catId        = (int)($_POST['category_id'] ?? 0) ?: null;
        $coverId      = (int)($_POST['cover_asset_id'] ?? 0) ?: null;
        $pubAt        = $_POST['published_at'] ?? null;
        $tagIds       = array_filter(array_map('intval', (array)($_POST['tags'] ?? [])));

        // If markdown mode, render to HTML; clear markdown when saving from visual editor
        if ($bodyMarkdown !== '') {
            $bodyHtml = Markdown::render($bodyMarkdown);
        } else {
            $bodyMarkdown = null;
        }

        if (!$title) { Session::flash('error', 'Title is required.'); Response::redirect($id ? "/admin/blog/posts/{$id}/edit" : '/admin/blog/posts/new'); }

        if (!$slug) $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $title));
        $slug = strtolower(preg_replace('/[^a-z0-9-]/', '', $slug));

        $pubAtDb = ($pubAt && $status === 'published') ? date('Y-m-d H:i:s', strtotime($pubAt)) : null;
        if ($status === 'published' && !$pubAtDb) $pubAtDb = date('Y-m-d H:i:s');

        $user     = Auth::user();
        $authorId = (int)($user['id'] ?? 1);

        if ($id) {
            DB::execute(
                "UPDATE blog_posts SET title=?,slug=?,excerpt=?,body_html=?,body_markdown=?,meta_description=?,status=?,category_id=?,cover_asset_id=?,published_at=?,updated_at=datetime('now'),author_id=? WHERE id=?",
                [$title,$slug,$excerpt,$bodyHtml,$bodyMarkdown,$metaDesc,$status,$catId,$coverId,$pubAtDb,$authorId,$id]
            );
            DB::execute("DELETE FROM blog_post_tags WHERE post_id=?", [$id]);
        } else {
            DB::execute(
                "INSERT INTO blog_posts (title,slug,excerpt,body_html,body_markdown,meta_description,status,category_id,cover_asset_id,published_at,author_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)",
                [$title,$slug,$excerpt,$bodyHtml,$bodyMarkdown,$metaDesc,$status,$catId,$coverId,$pubAtDb,$authorId]
            );
            $id = (int)DB::lastInsertId();
        }

        foreach ($tagIds as $tagId) {
            DB::execute("INSERT OR IGNORE INTO blog_post_tags (post_id,tag_id) VALUES (?,?)", [$id, $tagId]);
        }

        Cache::invalidateAll();
        Session::flash('success', 'Post saved.');
        Response::redirect("/admin/blog/posts/{$id}/edit");
    }

    /** POST /admin/blog/posts/{id}/delete */
    public static function deletePost(int $id): void {
        Auth::require('content.edit');
        CSRF::require();
        DB::execute("DELETE FROM blog_posts WHERE id=?", [$id]);
        Cache::invalidateAll();
        Session::flash('success', 'Post deleted.');
        Response::redirect('/admin/blog/posts');
    }

    /** POST /admin/blog/posts/{id}/publish */
    public static function publishPost(int $id): void {
        Auth::require('content.edit');
        CSRF::require();
        DB::execute("UPDATE blog_posts SET status='published',published_at=COALESCE(published_at,datetime('now')) WHERE id=?", [$id]);
        Session::flash('success', 'Post published.');
        Response::redirect('/admin/blog/posts');
    }

    /** POST /admin/blog/posts/{id}/unpublish */
    public static function unpublishPost(int $id): void {
        Auth::require('content.edit');
        CSRF::require();
        DB::execute("UPDATE blog_posts SET status='draft' WHERE id=?", [$id]);
        Session::flash('success', 'Post set to draft.');
        Response::redirect('/admin/blog/posts');
    }

    /** GET /admin/blog/categories */
    public static function categories(): void {
        Auth::require('*');
        $cats = DB::fetchAll(
            "SELECT c.*, COUNT(p.id) as post_count
             FROM blog_categories c
             LEFT JOIN blog_posts p ON p.category_id=c.id
             GROUP BY c.id ORDER BY c.name"
        );
        self::renderAdmin('admin/blog_categories', ['categories' => $cats], ['is_blog_cats' => true]);
    }

    /** POST /admin/blog/categories/save */
    public static function saveCategory(): void {
        Auth::require('content.edit');
        CSRF::require();
        $id   = (int)($_POST['cat_id'] ?? 0) ?: null;
        $name = trim($_POST['cat_name'] ?? '');
        $desc = trim($_POST['cat_description'] ?? '');
        if (!$name) { Session::flash('error', 'Name required.'); Response::redirect('/admin/blog/categories'); }

        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
        if ($id) {
            DB::execute("UPDATE blog_categories SET name=?,slug=?,description=? WHERE id=?", [$name,$slug,$desc,$id]);
        } else {
            DB::execute("INSERT INTO blog_categories (name,slug,description) VALUES (?,?,?)", [$name,$slug,$desc]);
        }
        Session::flash('success', 'Category saved.');
        Response::redirect('/admin/blog/categories');
    }

    /** POST /admin/blog/categories/{id}/delete */
    public static function deleteCategory(int $id): void {
        Auth::require('content.edit');
        CSRF::require();
        DB::execute("DELETE FROM blog_categories WHERE id=?", [$id]);
        Session::flash('success', 'Category deleted.');
        Response::redirect('/admin/blog/categories');
    }
}

/** Simple tag API used by the post editor JS */
class BlogTagAPI {
    public static function create(): void {
        Auth::require('content.edit');
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $name  = trim($input['name'] ?? '');
        if (!$name) { Response::json(['error' => 'Name required'], 400); return; }
        $slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', $name));
        DB::execute("INSERT OR IGNORE INTO blog_tags (name,slug) VALUES (?,?)", [$name,$slug]);
        $tag = DB::fetch("SELECT * FROM blog_tags WHERE slug=?", [$slug]);
        Response::json($tag ?: ['error' => 'Failed'], $tag ? 200 : 500);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 28: CONTACT FORM
// ─────────────────────────────────────────────────────────────────────────────

class FormController {
    public static function contact(): void {
        CSRF::require();

        if (!RateLimit::check('contact_form', 5, 3600)) {
            if (Request::isAjax()) {
                Response::json(['error' => 'Too many submissions. Please wait.'], 429);
            }
            Session::flash('error', 'Too many submissions. Please wait an hour.');
            Response::redirect('/');
        }

        $name = trim(Request::input('name', ''));
        $email = filter_var(Request::input('email', ''), FILTER_VALIDATE_EMAIL);
        $message = trim(Request::input('message', ''));

        if (!$name || !$email || !$message) {
            if (Request::isAjax()) {
                Response::json(['error' => 'All fields are required.'], 400);
            }
            Session::flash('error', 'All fields are required.');
            Response::redirect('/');
        }

        $sent = Mailer::sendContactForm([
            'name' => $name,
            'email' => $email,
            'message' => $message
        ]);

        if ($sent) {
            if (Request::isAjax()) {
                Response::json(['success' => true, 'message' => 'Thank you! We\'ll be in touch.']);
            }
            Session::flash('success', 'Thank you! We\'ll be in touch.');
        } else {
            if (Request::isAjax()) {
                Response::json(['error' => 'Failed to send message. Please try again.'], 500);
            }
            Session::flash('error', 'Failed to send message. Please try again.');
        }

        Response::redirect('/');
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 29: ROUTE DEFINITIONS
// ─────────────────────────────────────────────────────────────────────────────

// Initialize database
DB::connect();

// Check if setup is needed
$needsSetup = !DB::fetch("SELECT COUNT(*) as c FROM users WHERE role = 'admin'")['c'];

// ─── SETUP ROUTES ────────────────────────────────────────────────────────────
Router::get('/setup', 'SetupController::index');
Router::post('/setup', 'SetupController::process');

// ─── AUTH ROUTES ─────────────────────────────────────────────────────────────
Router::get('/admin/login', 'AuthController::loginForm');
Router::post('/admin/login', 'AuthController::login');
Router::get('/admin/mfa', 'AuthController::mfaForm');
Router::post('/admin/mfa', 'AuthController::mfaVerify');
Router::post('/admin/logout', 'AuthController::logout');

// ─── ADMIN ROUTES ────────────────────────────────────────────────────────────
Router::get('/admin', 'AdminController::dashboard');

// Pages
Router::get('/admin/pages', 'AdminController::pages');
Router::get('/admin/pages/new', function () { AdminController::editPage('new'); });
Router::get('/admin/pages/{id}', function ($id) { AdminController::editPage($id); });
Router::post('/admin/pages/{id}', function ($id) { AdminController::savePage($id); });
Router::post('/admin/pages/{id}/delete', function ($id) { AdminController::deletePage($id); });
Router::get('/admin/pages/{id}/revisions', function ($id) { AdminController::listRevisions($id); });
Router::post('/admin/pages/{id}/revisions/{rev}/restore', function ($id, $rev) { AdminController::restoreRevision($id, $rev); });

// Navigation
Router::get('/admin/nav', 'AdminController::nav');
Router::post('/admin/nav', 'AdminController::saveNav');

// Cache Management
Router::post('/admin/cache/regenerate', 'AdminController::regenerateCache');

// Media Library
Router::get('/admin/media', 'AdminController::media');
Router::get('/admin/media/json', 'AdminController::mediaJson');
Router::post('/admin/media/upload', 'AdminController::uploadMedia');
Router::post('/admin/media/{id}/delete', function ($id) { AdminController::deleteMedia($id); });

// Theme Settings
Router::get('/admin/theme', 'AdminController::theme');
Router::post('/admin/theme', 'AdminController::saveTheme');

// Settings (credentials & integrations)
Router::get('/admin/settings', 'AdminController::settings');
Router::post('/admin/settings', 'AdminController::saveSettings');

// Users Management
Router::get('/admin/users', 'AdminController::users');
Router::get('/admin/users/new', function () { AdminController::editUser('new'); });
Router::get('/admin/users/{id}', function ($id) { AdminController::editUser($id); });
Router::post('/admin/users/{id}', function ($id) { AdminController::saveUser($id); });
Router::post('/admin/users/{id}/delete', function ($id) { AdminController::deleteUser($id); });

// AI Generation
Router::get('/admin/ai', 'AIController::form');
Router::post('/admin/ai/configure', 'AIController::configure');
Router::post('/admin/ai/generate', 'AIController::generate');
Router::get('/admin/ai/logs', 'AIController::logs');
Router::post('/admin/ai/logs/clear', 'AIController::logsClear');
Router::get('/admin/ai/logs/{id}', function ($id) { AIController::logView((int)$id); });
Router::get('/admin/ai/chat', 'AIChatController::chat');
Router::post('/api/ai/chat', 'AIChatController::send');
Router::post('/api/ai/chat/reset', 'AIChatController::resetChat');

// Approvals Queue
Router::get('/admin/approvals', 'ApprovalController::queue');
Router::post('/admin/approvals', 'ApprovalController::store');
Router::get('/admin/approvals/{id}', function ($id) { ApprovalController::view((int)$id); });
Router::get('/admin/approvals/{id}/preview', function ($id) { ApprovalController::preview((int)$id); });
Router::post('/admin/approvals/{id}/approve', function ($id) { ApprovalController::approve((int)$id); });
Router::post('/admin/approvals/{id}/apply', function ($id) { ApprovalController::apply((int)$id); });
Router::post('/admin/approvals/{id}/reject', function ($id) { ApprovalController::reject((int)$id); });
Router::post('/admin/approvals/{id}/update-block', function ($id) { ApprovalController::updateBlock((int)$id); });
Router::post('/admin/approvals/{id}/generate-team-pages', function ($id) { ApprovalController::generateTeamPages((int)$id); });
Router::post('/admin/approvals/{id}/regenerate-page', function ($id) { ApprovalController::regeneratePage((int)$id); });
Router::post('/admin/approvals/{id}/edit-block', function ($id) { ApprovalController::editBlockWithAI((int)$id); });

// ─── ASSET ROUTES ────────────────────────────────────────────────────────────
Router::get('/assets/{hash}', 'AssetController::serve');
Router::get('/assets/css/{hash}', 'AssetController::serveCSS');
Router::get('/css', function () {
    $hash = Cache::getCSSHash();
    AssetController::serveCSS($hash);
});

// ─── CDN CACHE ROUTES ────────────────────────────────────────────────────────
Router::get('/cdn/fonts/{filename}', function ($filename) {
    $filePath = MONOLITHCMS_CACHE . '/assets/cdn/fonts/' . basename($filename);
    if (!file_exists($filePath)) {
        http_response_code(404);
        exit('Not Found');
    }
    $ext = pathinfo($filename, PATHINFO_EXTENSION);
    $contentType = match($ext) {
        'woff2' => 'font/woff2',
        'woff' => 'font/woff',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
        default => 'application/octet-stream'
    };
    header('Content-Type: ' . $contentType);
    header('Cache-Control: public, max-age=31536000, immutable');
    readfile($filePath);
    exit;
});
Router::get('/cdn/{filename}', function ($filename) { CDNCache::serve($filename); });

// ─── VISUAL EDITOR ASSETS ────────────────────────────────────────────────────
Router::get('/css/admin', 'AssetController::serveAdminCSS');
Router::get('/css/editor', 'EditorAssets::css');
Router::get('/js/editor', 'EditorAssets::js');
Router::get('/js/reveal', 'EditorAssets::revealJs');
Router::get('/js/theme', 'EditorAssets::themeJs');
Router::get('/js/approval', 'EditorAssets::approvalJs');
Router::get('/js/admin-global',      'EditorAssets::adminGlobalJs');
Router::get('/js/admin-nav',         'EditorAssets::navJs');
Router::get('/js/admin-media',       'EditorAssets::mediaJs');
Router::get('/js/admin-ai',          'EditorAssets::aiGenerateJs');
Router::get('/js/admin-ai-chat',     'EditorAssets::aiChatJs');
Router::get('/js/admin-blog-editor', 'EditorAssets::blogEditorJs');

// ─── BLOCK EDITING API ───────────────────────────────────────────────────────
Router::post('/api/blocks/{id}/update', function ($id) { BlockAPI::update((int)$id); });
Router::post('/api/blocks/{id}/move', function ($id) { BlockAPI::move((int)$id); });
Router::post('/api/blocks/{id}/delete', function ($id) { BlockAPI::delete((int)$id); });
Router::post('/api/blocks/create', 'BlockAPI::create');
Router::get('/api/blocks/schema', 'BlockAPI::schema');

// ─── THEME EDITING API ───────────────────────────────────────────────────────
Router::get('/api/theme/header', 'ThemeAPI::getHeader');
Router::post('/api/theme/header', 'ThemeAPI::updateHeader');
Router::get('/api/theme/nav', 'ThemeAPI::getNav');
Router::post('/api/theme/nav', 'ThemeAPI::updateNav');
Router::get('/api/theme/footer', 'ThemeAPI::getFooter');
Router::post('/api/theme/footer', 'ThemeAPI::updateFooter');

// ─── MEDIA API ───────────────────────────────────────────────────────────────
Router::get('/api/media', 'MediaAPI::list');

// ─── AI PAGE GENERATION API ──────────────────────────────────────────────────
Router::post('/api/ai/generate-page', 'AIPageAPI::generate');
Router::post('/api/ai/stream', 'AIOrchestrator::stream');

// ─── BLOG (admin) ────────────────────────────────────────────────────────────
Router::get('/admin/blog/posts', 'BlogAdminController::posts');
Router::get('/admin/blog/posts/new', 'BlogAdminController::newPost');
Router::get('/admin/blog/posts/{id}/edit', function ($id) { BlogAdminController::editPost((int)$id); });
Router::post('/admin/blog/posts/save', 'BlogAdminController::savePost');
Router::post('/admin/blog/posts/{id}/delete', function ($id) { BlogAdminController::deletePost((int)$id); });
Router::post('/admin/blog/posts/{id}/publish', function ($id) { BlogAdminController::publishPost((int)$id); });
Router::post('/admin/blog/posts/{id}/unpublish', function ($id) { BlogAdminController::unpublishPost((int)$id); });
Router::get('/admin/blog/categories', 'BlogAdminController::categories');
Router::post('/admin/blog/categories/save', 'BlogAdminController::saveCategory');
Router::post('/admin/blog/categories/{id}/delete', function ($id) { BlogAdminController::deleteCategory((int)$id); });
Router::post('/api/blog/tags', 'BlogTagAPI::create');
Router::post('/api/blog/ai/generate', 'BlogAIAPI::generate');
Router::post('/api/markdown/preview', 'MarkdownAPI::preview');

// ─── BLOG (public) ───────────────────────────────────────────────────────────
Router::get('/blog', 'BlogController::index');
Router::get('/blog/category/{slug}', function ($slug) { BlogController::category($slug); });
Router::get('/blog/tag/{slug}', function ($slug) { BlogController::tag($slug); });
Router::get('/blog/{slug}', function ($slug) { BlogController::post($slug); });

// ─── CONTACT FORM ────────────────────────────────────────────────────────────
Router::post('/contact', 'FormController::contact');

// ─── SITEMAP ─────────────────────────────────────────────────────────────────
Router::get('/sitemap.xml', 'Sitemap::serve');
Router::get('/robots.txt', 'RobotsController::serve');
Router::get('/llms.txt', 'LLMsController::serve');

// ─── PUBLIC ROUTES ───────────────────────────────────────────────────────────
Router::get('/', function () use ($needsSetup) {
    if ($needsSetup) {
        Response::redirect('/setup');
    }

    $page = DB::fetch("SELECT * FROM pages WHERE slug = 'home' AND status = 'published'");
    if ($page) {
        PageController::show($page);
    } else {
        // Show a default welcome page
        Response::html(Template::render('page', [
            'title' => 'Welcome',
            'meta_description' => 'Welcome to MonolithCMS',
            'blocks_html' => '<div class="block block-hero"><h2>Welcome to MonolithCMS</h2><p>Your AI-powered website is ready. <a href="/admin">Go to Admin</a> to start building.</p></div>'
        ]));
    }
});

// Dynamic page routes - this should be last to catch all other slugs
Router::get('/{slug}', function ($slug) use ($needsSetup) {
    if ($needsSetup) {
        Response::redirect('/setup');
    }

    $page = DB::fetch("SELECT * FROM pages WHERE slug = ? AND status = 'published'", [$slug]);
    if ($page) {
        PageController::show($page);
    } else {
        // 404 page
        http_response_code(404);
        Response::html(Template::render('page', [
            'title' => 'Page Not Found',
            'meta_description' => 'The page you requested could not be found.',
            'blocks_html' => '<div class="block block-text" style="text-align: center; padding: 3rem;"><h2>404 - Page Not Found</h2><p>The page you\'re looking for doesn\'t exist.</p><p><a href="/">← Back to Home</a></p></div>'
        ]));
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// DISPATCH
// ─────────────────────────────────────────────────────────────────────────────

// Start session eagerly before dispatch so cookie params (Secure, SameSite, etc.) are applied consistently on every request, including the login redirect target.
Session::start();

// Allow CLI scripts (e.g. tmp/generate.php) to boot the app without dispatching.
if (defined('MONOLITHCMS_CLI_BOOT') && MONOLITHCMS_CLI_BOOT) {
    return;
}

// Pre-warm critical CDN assets on first request after migration or fresh install. Only downloads 4 essential files (tailwind, bulma, icons, inter-font); grapes/quill download lazily on first editor use.
if (!file_exists(MONOLITHCMS_CACHE . '/assets/cdn/tailwind-forms.min.js')) {
    CDNCache::initialize(true);
}

Router::dispatch();
// ─────────────────────────────────────────────────────────────────────────────
// SECTION 30: MARKDOWN RENDERING
// ─────────────────────────────────────────────────────────────────────────────

// Full GFM-compatible Markdown renderer. Supports: ATX/setext headings, paragraphs, fenced/indented code blocks, blockquotes, unordered/ordered/task lists, tables, thematic breaks, bold, italic, bold+italic, strikethrough, inline code, links, images, autolinks, backslash escapes, hard line-breaks, raw HTML pass-through.
class Markdown {
    /** Protected fenced-code block HTML, keyed by index */
    private static array $blockStore = [];

    // ─── Public API ───────────────────────────────────────────────────────

    public static function render(string $md): string {
        self::$blockStore = [];
        $md = str_replace(["\r\n", "\r"], "\n", $md);

        // 1. Protect fenced code blocks (``` or ~~~) before block splitting
        $md = preg_replace_callback(
            '/^(`{3,}|~{3,})[ \t]*([^\n]*)\n([\s\S]*?)^\1[ \t]*$/m',
            static function (array $m): string {
                $info = trim($m[2]);
                $lang = $info !== '' ? strtok($info, ' ') : '';
                $code = $m[3];
                // Trim trailing newline added by regex
                if (str_ends_with($code, "\n")) {
                    $code = substr($code, 0, -1);
                }
                $langAttr = $lang ? ' class="language-' . htmlspecialchars($lang, ENT_QUOTES) . '"' : '';
                $html = '<pre><code' . $langAttr . '>' . htmlspecialchars($code, ENT_NOQUOTES) . '</code></pre>';
                $key  = "\x02B" . count(self::$blockStore) . "\x03";
                self::$blockStore[] = $html;
                return "\n" . $key . "\n";
            },
            $md
        );

        // 2. Parse block elements
        $lines = explode("\n", $md);
        $html  = self::parseBlocks($lines);

        // 3. Restore fenced code placeholders
        foreach (self::$blockStore as $idx => $block) {
            $html = str_replace("\x02B{$idx}\x03", $block, $html);
        }

        return '<div class="md-content">' . "\n" . $html . "</div>\n";
    }

    // ─── Block Parsing ────────────────────────────────────────────────────

    private static function parseBlocks(array $lines): string {
        $html = '';
        $i    = 0;
        $n    = count($lines);

        while ($i < $n) {
            $line = $lines[$i];

            // Fenced code placeholder
            if (preg_match('/^\x02B(\d+)\x03$/', trim($line), $m)) {
                $html .= self::$blockStore[(int)$m[1]] . "\n";
                $i++;
                continue;
            }

            // Blank line
            if (trim($line) === '') {
                $i++;
                continue;
            }

            // ATX Heading  # to ######
            if (preg_match('/^(#{1,6})(?:[ \t]+|$)(.*?)(?:[ \t]+#+[ \t]*)?$/', $line, $m)) {
                $level = strlen($m[1]);
                $text  = rtrim($m[2]);
                $id    = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-');
                $html .= "<h{$level}" . ($id ? " id=\"{$id}\"" : '') . ">"
                      . self::inline($text)
                      . "</h{$level}>\n";
                $i++;
                continue;
            }

            // Setext H1: current line + next line all `=`
            if ($i + 1 < $n
                && trim($line) !== ''
                && !str_starts_with(ltrim($line), '>')
                && preg_match('/^=+[ \t]*$/', $lines[$i + 1])
            ) {
                $text = trim($line);
                $id   = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-');
                $html .= "<h1" . ($id ? " id=\"{$id}\"" : '') . ">"
                      . self::inline($text) . "</h1>\n";
                $i += 2;
                continue;
            }

            // Setext H2: current line + next line all `-` (and non-empty to avoid thematic break confusion)
            if ($i + 1 < $n
                && trim($line) !== ''
                && !str_starts_with(ltrim($line), '>')
                && preg_match('/^-{2,}[ \t]*$/', $lines[$i + 1])
                && !preg_match('/^[ \t]*([-*_])([ \t]*\1){2,}[ \t]*$/', trim($line))
            ) {
                $text = trim($line);
                $id   = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-');
                $html .= "<h2" . ($id ? " id=\"{$id}\"" : '') . ">"
                      . self::inline($text) . "</h2>\n";
                $i += 2;
                continue;
            }

            // Thematic break  --- or *** or ___
            if (preg_match('/^[ \t]*([-*_])([ \t]*\1){2,}[ \t]*$/', $line)) {
                $html .= "<hr>\n";
                $i++;
                continue;
            }

            // Blockquote
            if (str_starts_with(ltrim($line), '>')) {
                $qlines = [];
                while ($i < $n) {
                    $l = $lines[$i];
                    if (str_starts_with(ltrim($l), '>')) {
                        $stripped = ltrim($l);
                        // Strip `> ` or `>`
                        $qlines[] = strlen($stripped) > 1 && $stripped[1] === ' '
                            ? substr($stripped, 2)
                            : substr($stripped, 1);
                        $i++;
                    } elseif (trim($l) === '' && $i + 1 < $n && str_starts_with(ltrim($lines[$i + 1]), '>')) {
                        $qlines[] = '';
                        $i++;
                    } else {
                        break;
                    }
                }
                $html .= "<blockquote>\n" . self::parseBlocks($qlines) . "</blockquote>\n";
                continue;
            }

            // Unordered list
            if (preg_match('/^([ \t]*)[-*+][ \t]/', $line)) {
                [$listHtml, $i] = self::parseList($lines, $i, $n, 'ul');
                $html .= $listHtml;
                continue;
            }

            // Ordered list
            if (preg_match('/^([ \t]*)\d+[.)][ \t]/', $line)) {
                [$listHtml, $i] = self::parseList($lines, $i, $n, 'ol');
                $html .= $listHtml;
                continue;
            }

            // Table (header + align row)
            if (str_contains($line, '|')
                && $i + 1 < $n
                && preg_match('/^[ \t]*\|?[ \t]*:?-{1,}:?[ \t]*(?:\|[ \t]*:?-{1,}:?[ \t]*)+\|?[ \t]*$/', $lines[$i + 1])
            ) {
                [$tableHtml, $i] = self::parseTable($lines, $i, $n);
                $html .= $tableHtml;
                continue;
            }

            // Indented code block (4 spaces or 1 tab)
            if (str_starts_with($line, '    ') || ($line !== '' && $line[0] === "\t")) {
                $code = '';
                while ($i < $n
                    && (str_starts_with($lines[$i], '    ')
                        || ($lines[$i] !== '' && $lines[$i][0] === "\t")
                        || trim($lines[$i]) === '')
                ) {
                    if (trim($lines[$i]) === '') {
                        $code .= "\n";
                    } else {
                        $prefix = ($lines[$i][0] === "\t") ? 1 : 4;
                        $code  .= substr($lines[$i], $prefix) . "\n";
                    }
                    $i++;
                }
                $html .= '<pre><code>' . htmlspecialchars(rtrim($code), ENT_NOQUOTES) . "</code></pre>\n";
                continue;
            }

            // Paragraph — collect until blank line or block-level element
            $para = [];
            while ($i < $n) {
                $l = $lines[$i];
                if (trim($l) === '') break;
                if (preg_match('/^(#{1,6})(?:[ \t]|$)/', $l)) break;
                if (preg_match('/^[ \t]*([-*_])([ \t]*\1){2,}[ \t]*$/', $l)) break;
                if (preg_match('/^[ \t]*[-*+][ \t]/', $l)) break;
                if (preg_match('/^[ \t]*\d+[.)][ \t]/', $l)) break;
                if (str_starts_with(ltrim($l), '>')) break;
                if (str_starts_with($l, '    ') || ($l !== '' && $l[0] === "\t")) break;
                if (preg_match('/^\x02B\d+\x03$/', trim($l))) break;
                // Stop before a setext underline
                if ($i + 1 < $n
                    && (preg_match('/^=+[ \t]*$/', $lines[$i + 1])
                        || preg_match('/^-{2,}[ \t]*$/', $lines[$i + 1]))
                ) {
                    $para[] = $l;
                    $i++;
                    break;
                }
                $para[] = $l;
                $i++;
            }
            if ($para) {
                $text  = implode("\n", $para);
                $html .= '<p>' . self::inline($text) . "</p>\n";
            }
        }

        return $html;
    }

    private static function parseList(array $lines, int $i, int $n, string $tag): array {
        $html  = "<{$tag}>\n";
        $tight = true; // Tight list unless blank lines between items

        while ($i < $n) {
            $line = $lines[$i];
            $isUl = ($tag === 'ul');

            if ($isUl && !preg_match('/^([ \t]*)[-*+][ \t]+(.*)/', $line, $m)) break;
            if (!$isUl && !preg_match('/^([ \t]*)\d+[.)][ \t]+(.*)/', $line, $m)) break;

            $indent  = str_replace("\t", '    ', $m[1]);
            $content = $m[2];
            $i++;

            // Collect continuation/nested lines (indented by >= indent + 2)
            $minIndent = strlen($indent) + 2;
            $hasBlank  = false;

            while ($i < $n) {
                $l = $lines[$i];
                if (trim($l) === '') {
                    $hasBlank = true;
                    // Keep blank to signal loose list, but check if next is still list
                    if ($i + 1 < $n) {
                        $next = $lines[$i + 1];
                        $nextIsItem = ($isUl && preg_match('/^[ \t]*[-*+][ \t]/', $next))
                                   || (!$isUl && preg_match('/^[ \t]*\d+[.)][ \t]/', $next));
                        $nextIsCont = preg_match('/^[ \t]{' . $minIndent . ',}/', $next);
                        if ($nextIsItem || $nextIsCont) {
                            $i++;
                            continue;
                        }
                    }
                    break;
                }
                $stripped = str_replace("\t", '    ', $l);
                if (strlen($stripped) - strlen(ltrim($stripped)) >= $minIndent) {
                    $content .= "\n" . substr($stripped, $minIndent);
                    $i++;
                } else {
                    break;
                }
            }

            if ($hasBlank) $tight = false;

            // Task list item?
            if (preg_match('/^\[([xX ])\] (.*)/', $content, $tm)) {
                $checked = strtolower($tm[1]) === 'x' ? ' checked' : '';
                $inner   = '<input type="checkbox" disabled' . $checked . '> ' . self::inline($tm[2]);
                $html   .= "<li class=\"task-item\">{$inner}</li>\n";
            } elseif (str_contains($content, "\n")) {
                $inner = self::parseBlocks(explode("\n", $content));
                $html .= "<li>{$inner}</li>\n";
            } else {
                $html .= "<li>" . self::inline($content) . "</li>\n";
            }
        }

        $html .= "</{$tag}>\n";
        return [$html, $i];
    }

    private static function parseTable(array $lines, int $i, int $n): array {
        $headerRow = $lines[$i++];
        $alignRow  = $lines[$i++];

        // Parse alignment cells
        $alignRow   = trim($alignRow, '| ');
        $alignCells = preg_split('/\s*\|\s*/', $alignRow);
        $aligns     = array_map(static function (string $c): string {
            $c = trim($c, ' ');
            $l = str_starts_with($c, ':');
            $r = str_ends_with($c, ':');
            if ($l && $r) return 'center';
            if ($r)       return 'right';
            return 'left';
        }, $alignCells);

        // Header cells
        $headerRow = trim($headerRow, '| ');
        $headers   = preg_split('/\s*\|\s*/', $headerRow);

        $html = "<div class=\"table-wrap\">\n<table>\n<thead>\n<tr>";
        foreach ($headers as $j => $th) {
            $align = isset($aligns[$j]) ? " style=\"text-align:{$aligns[$j]}\"" : '';
            $html .= "<th{$align}>" . self::inline(trim($th)) . "</th>";
        }
        $html .= "</tr>\n</thead>\n<tbody>\n";

        // Body rows
        while ($i < $n && trim($lines[$i]) !== '' && str_contains($lines[$i], '|')) {
            $row   = trim($lines[$i], '| ');
            $cells = preg_split('/\s*\|\s*/', $row);
            $html .= "<tr>";
            foreach ($cells as $j => $td) {
                $align = isset($aligns[$j]) ? " style=\"text-align:{$aligns[$j]}\"" : '';
                $html .= "<td{$align}>" . self::inline(trim($td)) . "</td>";
            }
            $html .= "</tr>\n";
            $i++;
        }

        $html .= "</tbody>\n</table>\n</div>\n";
        return [$html, $i];
    }

    // ─── Inline Parsing ───────────────────────────────────────────────────

    private static function inline(string $text): string {
        $inlines = [];

        // 1. Backslash escapes
        $text = preg_replace_callback(
            '/\\\\([\\\\`*_{}\[\]()#+\-.!|~<>])/',
            static function (array $m) use (&$inlines): string {
                $key      = "\x02I" . count($inlines) . "\x03";
                $inlines[] = htmlspecialchars($m[1], ENT_QUOTES);
                return $key;
            },
            $text
        );

        // 2. Code spans  `code`  or  ``code``
        $text = preg_replace_callback(
            '/(`+)(.+?)\1/s',
            static function (array $m) use (&$inlines): string {
                $key      = "\x02I" . count($inlines) . "\x03";
                $inlines[] = '<code>' . htmlspecialchars(trim($m[2]), ENT_QUOTES) . '</code>';
                return $key;
            },
            $text
        );

        // 3. Images  ![alt](url "title")
        $text = preg_replace_callback(
            '/!\[([^\]]*)\]\(([^\s)]+)(?:\s+"([^"]*)")?\)/',
            static function (array $m) use (&$inlines): string {
                $key      = "\x02I" . count($inlines) . "\x03";
                $alt      = htmlspecialchars($m[1], ENT_QUOTES);
                $src      = htmlspecialchars($m[2], ENT_QUOTES);
                $title    = ($m[3] ?? '') !== '' ? ' title="' . htmlspecialchars($m[3], ENT_QUOTES) . '"' : '';
                $inlines[] = "<img src=\"{$src}\" alt=\"{$alt}\"{$title}>";
                return $key;
            },
            $text
        );

        // 4. Links  [text](url "title")
        $text = preg_replace_callback(
            '/\[([^\]]*)\]\(([^\s)]+)(?:\s+"([^"]*)")?\)/',
            static function (array $m) use (&$inlines): string {
                $key      = "\x02I" . count($inlines) . "\x03";
                $href     = htmlspecialchars($m[2], ENT_QUOTES);
                $title    = ($m[3] ?? '') !== '' ? ' title="' . htmlspecialchars($m[3], ENT_QUOTES) . '"' : '';
                $inner    = self::inline($m[1]); // recursive for nested inline
                $inlines[] = "<a href=\"{$href}\"{$title}>{$inner}</a>";
                return $key;
            },
            $text
        );

        // 5. Angle-bracket autolinks  <https://...> or <email@example.com>
        $text = preg_replace_callback(
            '/<(https?:\/\/[^\s>]+|[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})>/',
            static function (array $m) use (&$inlines): string {
                $key      = "\x02I" . count($inlines) . "\x03";
                $url      = htmlspecialchars($m[1], ENT_QUOTES);
                $prefix   = str_contains($m[1], '@') ? 'mailto:' : '';
                $inlines[] = "<a href=\"{$prefix}{$url}\">{$url}</a>";
                return $key;
            },
            $text
        );

        // 6. Bare URL autolinks  https://... (GFM extension)
        $text = preg_replace_callback(
            '/(?<!["\'\(])https?:\/\/[^\s<>\]"\']{2,}/',
            static function (array $m) use (&$inlines): string {
                $key      = "\x02I" . count($inlines) . "\x03";
                $url      = htmlspecialchars($m[0], ENT_QUOTES);
                $inlines[] = "<a href=\"{$url}\">{$url}</a>";
                return $key;
            },
            $text
        );

        // 7. HTML-escape plain text segments (split on placeholders, escape non-placeholder parts)
        $parts = preg_split('/(\x02I\d+\x03)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $text  = '';
        foreach ($parts as $part) {
            $text .= preg_match('/^\x02I\d+\x03$/', $part)
                ? $part
                : htmlspecialchars($part, ENT_NOQUOTES);
        }

        // 8. Bold + italic emphasis (order: bold+italic > bold > italic > strikethrough)
        $text = preg_replace('/\*\*\*(.+?)\*\*\*/s', '<strong><em>$1</em></strong>', $text);
        $text = preg_replace('/___(.+?)___/s',        '<strong><em>$1</em></strong>', $text);
        $text = preg_replace('/\*\*(.+?)\*\*/s',      '<strong>$1</strong>',          $text);
        $text = preg_replace('/__(.+?)__/s',           '<strong>$1</strong>',          $text);
        $text = preg_replace('/\*([^*\n]+?)\*/s',      '<em>$1</em>',                  $text);
        $text = preg_replace('/_([^_\n]+?)_/s',        '<em>$1</em>',                  $text);
        $text = preg_replace('/~~(.+?)~~/s',            '<del>$1</del>',                $text);

        // 9. Hard line-breaks  (two trailing spaces or \  before newline)
        $text = preg_replace('/  \n/', "<br>\n", $text);
        $text = preg_replace('/\\\\\n/', "<br>\n", $text);

        // 10. Restore inline placeholders
        foreach ($inlines as $j => $inline) {
            $text = str_replace("\x02I{$j}\x03", $inline, $text);
        }

        return $text;
    }
}

// ─────────────────────────────────────────────────────────────────────────────

/** POST /api/markdown/preview — returns rendered HTML for live preview */
class MarkdownAPI {
    public static function preview(): void {
        Auth::require('*');
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $md    = $input['markdown'] ?? '';
        if (!is_string($md)) {
            Response::json(['error' => 'Invalid input'], 400);
            return;
        }
        Response::json(['html' => Markdown::render($md)]);
    }
}

// ─────────────────────────────────────────────────────────────────────────────

/** POST /api/blog/ai/generate — AI blog writing assistant (blocking) */
class BlogAIAPI {
    public static function generate(): void {
        Auth::require('content.edit');

        if (!AI::isConfigured()) {
            Response::json(['error' => 'AI provider not configured.'], 503);
            return;
        }

        $input    = json_decode(file_get_contents('php://input'), true) ?? [];
        $messages = $input['messages'] ?? [];

        if (!is_array($messages) || empty($messages)) {
            Response::json(['error' => 'messages array required'], 400);
            return;
        }

        // Validate and sanitise messages
        $cleaned = [];
        foreach ($messages as $msg) {
            if (!is_array($msg)
                || !in_array($msg['role'] ?? '', ['user', 'assistant'], true)
                || !is_string($msg['content'] ?? null)
            ) {
                continue;
            }
            $cleaned[] = ['role' => $msg['role'], 'content' => $msg['content']];
        }

        if (empty($cleaned)) {
            Response::json(['error' => 'No valid messages'], 400);
            return;
        }

        $year   = date('Y');
        $system = <<<PROMPT
You are an expert blog writer. Write complete, high-quality blog posts in GitHub Flavored Markdown (GFM).

Rules:
- Start the post with a single H1 heading (# Title).
- Structure content with H2 and H3 subheadings.
- Write in a natural, engaging tone appropriate for the topic.
- Use markdown features where helpful: bold, italic, bullet lists, numbered lists, tables, code blocks for technical topics, blockquotes for highlights.
- Do NOT add YAML front-matter, HTML tags, or any preamble text outside the post itself.
- End with a meaningful conclusion or call-to-action paragraph.
- Current year: {$year}.
PROMPT;

        try {
            $reply = AI::generateChat($cleaned, $system, 8192);
        } catch (\Throwable $e) {
            error_log('BlogAIAPI::generate error: ' . $e->getMessage());
            Response::json(['error' => 'AI generation failed. Please try again.'], 500);
            return;
        }

        Response::json(['reply' => $reply]);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 31: SITEMAP GENERATOR
// ─────────────────────────────────────────────────────────────────────────────

class Sitemap {
    private static string $cacheFile = '';

    private static function cacheFile(): string {
        if (self::$cacheFile === '') {
            self::$cacheFile = MONOLITHCMS_CACHE . '/sitemap.xml';
        }
        return self::$cacheFile;
    }

    // ── Public entry-point ────────────────────────────────────────────────────
    public static function serve(): void {
        $file = self::cacheFile();

        // Regenerate if cache does not exist (it is deleted with every version
        // bump in the version-invalidation block at the top of index.php).
        if (!file_exists($file)) {
            self::regenerate();
        }

        header('Content-Type: application/xml; charset=UTF-8');
        header('Cache-Control: public, max-age=3600');
        readfile($file);
    }

    // ── Build and cache sitemap.xml ───────────────────────────────────────────
    public static function regenerate(): void {
        $baseUrl = self::baseUrl();
        $lines   = ['<?xml version="1.0" encoding="UTF-8"?>',
                    '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'];

        // ── Static public routes ──────────────────────────────────────────────
        $lines[] = self::url($baseUrl . '/', '1.0', 'daily');

        $blogEnabled = (bool)(int)Settings::get('blog_enabled', '1');
        if ($blogEnabled) {
            $lines[] = self::url($baseUrl . '/blog', '0.8', 'daily');
        }

        // ── Published pages ───────────────────────────────────────────────────
        $pages = DB::fetchAll(
            "SELECT slug, updated_at FROM pages WHERE status = 'published' AND slug != 'home' ORDER BY updated_at DESC"
        );
        foreach ($pages as $page) {
            $lines[] = self::url(
                $baseUrl . '/' . rawurlencode($page['slug']),
                '0.8',
                'weekly',
                $page['updated_at']
            );
        }

        // ── Published blog posts ──────────────────────────────────────────────
        if ($blogEnabled) {
            $posts = DB::fetchAll(
                "SELECT slug, published_at, updated_at FROM blog_posts WHERE status = 'published' ORDER BY published_at DESC"
            );
            foreach ($posts as $post) {
                $lastmod = $post['updated_at'] ?? $post['published_at'];
                $lines[] = self::url(
                    $baseUrl . '/blog/' . rawurlencode($post['slug']),
                    '0.6',
                    'monthly',
                    $lastmod
                );
            }

            // ── Blog category pages ───────────────────────────────────────────
            $cats = DB::fetchAll(
                "SELECT DISTINCT bc.slug FROM blog_categories bc
                 INNER JOIN blog_posts bp ON bp.category_id = bc.id
                 WHERE bp.status = 'published'"
            );
            foreach ($cats as $cat) {
                $lines[] = self::url(
                    $baseUrl . '/blog/category/' . rawurlencode($cat['slug']),
                    '0.5',
                    'weekly'
                );
            }
        }

        $lines[] = '</urlset>';
        $xml = implode("\n", $lines) . "\n";

        @file_put_contents(self::cacheFile(), $xml);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────
    private static function url(string $loc, string $priority, string $changefreq, ?string $lastmod = null): string {
        $entry  = '  <url>';
        $entry .= '<loc>' . htmlspecialchars($loc, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc>';
        if ($lastmod !== null) {
            $ts = strtotime($lastmod);
            if ($ts) {
                $entry .= '<lastmod>' . date('Y-m-d', $ts) . '</lastmod>';
            }
        }
        $entry .= '<changefreq>' . $changefreq . '</changefreq>';
        $entry .= '<priority>' . $priority . '</priority>';
        $entry .= '</url>';
        return $entry;
    }

    private static function baseUrl(): string {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return rtrim($scheme . '://' . $host, '/');
    }
}

// ─────────────────────────────────────────────────────────────────────────────

class RobotsController {
    public static function serve(): void {
        $baseUrl = self::baseUrl();
        $body    = "User-agent: *\nDisallow:\n\n";
        $body   .= "Sitemap: {$baseUrl}/sitemap.xml\n";
        $body   .= "LLMs: {$baseUrl}/llms.txt\n";

        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: public, max-age=86400');
        echo $body;
    }

    private static function baseUrl(): string {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return rtrim($scheme . '://' . $host, '/');
    }
}

// ─────────────────────────────────────────────────────────────────────────────

class LLMsController {
    public static function serve(): void {
        $baseUrl = self::baseUrl();
        $lines   = [];

        // ── Header ────────────────────────────────────────────────────────────
        $siteName = htmlspecialchars_decode(Settings::get('site_name', 'My Site'), ENT_QUOTES);
        $siteDesc = htmlspecialchars_decode(Settings::get('site_description', ''), ENT_QUOTES);

        $lines[] = "# {$siteName}";
        $lines[] = '';
        if ($siteDesc !== '') {
            $lines[] = "> {$siteDesc}";
            $lines[] = '';
        }

        // ── Core pages ────────────────────────────────────────────────────────
        $homePage = DB::fetch("SELECT title, meta_description FROM pages WHERE slug = 'home' AND status = 'published'");
        $pages    = DB::fetchAll(
            "SELECT title, slug, meta_description FROM pages WHERE status = 'published' AND slug != 'home' ORDER BY title ASC"
        );

        if ($homePage || !empty($pages)) {
            $lines[] = '## Core pages';
            $lines[] = '';
            if ($homePage) {
                $title = htmlspecialchars_decode($homePage['title'], ENT_QUOTES);
                $desc  = htmlspecialchars_decode($homePage['meta_description'] ?? '', ENT_QUOTES);
                $entry = "- [{$title}]({$baseUrl}/)";
                if ($desc !== '') {
                    $entry .= ": {$desc}";
                }
                $lines[] = $entry;
            }
            foreach ($pages as $page) {
                $title = htmlspecialchars_decode($page['title'], ENT_QUOTES);
                $slug  = rawurlencode($page['slug']);
                $desc  = htmlspecialchars_decode($page['meta_description'] ?? '', ENT_QUOTES);
                $entry = "- [{$title}]({$baseUrl}/{$slug})";
                if ($desc !== '') {
                    $entry .= ": {$desc}";
                }
                $lines[] = $entry;
            }
            $lines[] = '';
        }

        // ── Blog ──────────────────────────────────────────────────────────────
        $blogEnabled = (bool)(int)Settings::get('blog_enabled', '0');
        if ($blogEnabled) {
            $posts = DB::fetchAll(
                "SELECT title, slug, excerpt FROM blog_posts WHERE status = 'published' ORDER BY published_at DESC LIMIT 50"
            );
            if (!empty($posts)) {
                $blogTitle = htmlspecialchars_decode(Settings::get('blog_title', 'Blog'), ENT_QUOTES);
                $lines[] = "## {$blogTitle}";
                $lines[] = '';
                foreach ($posts as $post) {
                    $title   = htmlspecialchars_decode($post['title'], ENT_QUOTES);
                    $slug    = rawurlencode($post['slug']);
                    $excerpt = htmlspecialchars_decode($post['excerpt'] ?? '', ENT_QUOTES);
                    $entry   = "- [{$title}]({$baseUrl}/blog/{$slug})";
                    if ($excerpt !== '') {
                        $entry .= ": {$excerpt}";
                    }
                    $lines[] = $entry;
                }
                $lines[] = '';
            }
        }

        $output = implode("\n", $lines);

        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: public, max-age=3600');
        echo $output;
    }

    private static function baseUrl(): string {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return rtrim($scheme . '://' . $host, '/');
    }
}
