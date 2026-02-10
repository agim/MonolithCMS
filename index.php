<?php
/*==============================================================================
 * OneCMS - Single-File AI-Driven Website Builder
 * Version: 1.0.0
 * 
 * A portable, AI-powered CMS in a single PHP file with SQLite backend.
 * Deploy with just 2 files: index.php + site.sqlite
 *=============================================================================*/

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 1: CONFIGURATION & BOOT
// ─────────────────────────────────────────────────────────────────────────────

// Constants
define('ONECMS_VERSION', '1.0.0');
define('ONECMS_ROOT', __DIR__);
define('ONECMS_DB', ONECMS_ROOT . '/site.sqlite');
define('ONECMS_CACHE', ONECMS_ROOT . '/cache');

// Environment Detection
$isProduction = !in_array($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', ['127.0.0.1', '::1']);
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
           || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

// Error Handling
if ($isProduction) {
    error_reporting(0);
    ini_set('display_errors', '0');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
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
    "script-src 'self' 'nonce-{$cspNonce}'; " .
    "style-src 'self' 'unsafe-inline'; " .
    "font-src 'self'; " .
    "img-src 'self' data: https:; " .
    "connect-src 'self' https://api.openai.com https://api.anthropic.com https://generativelanguage.googleapis.com"
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
    ONECMS_CACHE,
    ONECMS_CACHE . '/pages',
    ONECMS_CACHE . '/partials',
    ONECMS_CACHE . '/assets'
];
foreach ($cacheDirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 2: DATABASE LAYER
// ─────────────────────────────────────────────────────────────────────────────

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
            
            // Migration 15: Insert default theme styles (light mode)
            15 => "INSERT OR IGNORE INTO theme_styles (key, value) VALUES 
                   ('color_primary', '#3b82f6'),
                   ('color_secondary', '#1e40af'),
                   ('color_accent', '#f59e0b'),
                   ('color_background', '#ffffff'),
                   ('color_background_secondary', '#f8fafc'),
                   ('color_text', '#1f2937'),
                   ('color_text_muted', '#6b7280'),
                   ('color_border', '#e5e7eb'),
                   ('font_family', 'system-ui, -apple-system, sans-serif'),
                   ('color_primary_dark', '#60a5fa'),
                   ('color_secondary_dark', '#3b82f6'),
                   ('color_accent_dark', '#fbbf24'),
                   ('color_background_dark', '#0f172a'),
                   ('color_background_secondary_dark', '#1e293b'),
                   ('color_text_dark', '#f1f5f9'),
                   ('color_text_muted_dark', '#94a3b8'),
                   ('color_border_dark', '#334155')",
            
            // Migration 16: Insert default header and footer
            16 => "INSERT OR IGNORE INTO theme_header (id, tagline, bg_color) VALUES (1, 'Welcome to OneCMS', '#3b82f6');
                   INSERT OR IGNORE INTO theme_footer (id, text) VALUES (1, '© ' || strftime('%Y', 'now') || ' OneCMS. All rights reserved.')",
            
            // Migration 17: Add applied_at and rejection_reason to build_queue
            17 => "ALTER TABLE build_queue ADD COLUMN applied_at TEXT;
                   ALTER TABLE build_queue ADD COLUMN rejection_reason TEXT",
            
            // Migration 18: Add last_login to users and meta_json to pages
            18 => "ALTER TABLE users ADD COLUMN last_login TEXT;
                   ALTER TABLE pages ADD COLUMN meta_json TEXT DEFAULT '{}'"
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

class Session {
    private static bool $started = false;
    
    public static function start(): void {
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        
        global $isHttps;
        
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', $isHttps ? '1' : '0');
        ini_set('session.cookie_samesite', 'Strict');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_maxlifetime', '7200'); // 2 hours
        
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
            session_regenerate_id(true);
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
    public static function token(): string {
        Session::start();
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf_token'];
    }
    
    public static function verify(?string $token): bool {
        Session::start();
        if (empty($token) || empty($_SESSION['_csrf_token'])) {
            return false;
        }
        return hash_equals($_SESSION['_csrf_token'], $token);
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
    public static function user(): ?array {
        $userId = Session::get('user_id');
        if (!$userId) {
            return null;
        }
        return DB::fetch("SELECT * FROM users WHERE id = ?", [$userId]);
    }
    
    public static function check(): bool {
        return self::user() !== null;
    }
    
    public static function id(): ?int {
        return Session::get('user_id');
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
        Session::regenerate();
        Session::set('user_id', $userId);
        Session::set('logged_in_at', time());
        RateLimit::reset('login');
        
        // Update last_login timestamp
        DB::execute("UPDATE users SET last_login = datetime('now') WHERE id = ?", [$userId]);
    }
    
    public static function logout(): void {
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
             ON CONFLICT(key) DO UPDATE SET value = excluded.value",
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
            // Use a combination of file path and a stored key for encryption
            $storedKey = DB::fetch("SELECT value FROM settings WHERE key = '_encryption_key'");
            if (!$storedKey) {
                $newKey = bin2hex(random_bytes(32));
                DB::execute(
                    "INSERT INTO settings (key, value, encrypted) VALUES ('_encryption_key', ?, 0)",
                    [$newKey]
                );
                self::$encryptionKey = $newKey;
            } else {
                self::$encryptionKey = $storedKey['value'];
            }
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
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
    
    public static function redirect(string $url, int $code = 302): void {
        http_response_code($code);
        header('Location: ' . $url);
        exit;
    }
    
    public static function html(string $content, int $code = 200): void {
        http_response_code($code);
        header('Content-Type: text/html; charset=UTF-8');
        echo $content;
        exit;
    }
    
    public static function notFound(string $message = 'Not Found'): void {
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
        global $cspNonce;
        
        $data['csrf_field'] = CSRF::field();
        $data['csrf_token'] = CSRF::token();
        $data['csp_nonce'] = $cspNonce;
        $data['site_name'] = Settings::get('site_name', 'OneCMS');
        $data['flash'] = Session::getFlash();
        $data['css_hash'] = Cache::getCSSHash();
        
        // CDN paths (local cached versions)
        $data['cdn'] = CDNCache::getPaths();
        
        // Check if user can edit (for visual editor)
        $data['can_edit'] = Auth::can('content.edit');
        $data['edit_mode'] = $data['can_edit'] && isset($_GET['edit']);
        
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
            return self::getValue($m[1]) ?? '';
        }, $html);
        
        // Process variables: {{variable}} (escaped) - supports dot notation like {{flash.message}}
        $html = preg_replace_callback('/\{\{\s*([\w.]+)\s*\}\}/', function ($m) {
            $value = self::getValue($m[1]);
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
    
    /**
     * Resolve a dot-notation key from a context array
     */
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
    
    /**
     * Process {{#each items}}...{{/each}} blocks with proper nesting support
     */
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
                    
                    // Substitute variables
                    foreach ($item as $key => $val) {
                        if (is_scalar($val)) {
                            $inner = str_replace('{{' . $key . '}}', Sanitize::html((string) $val), $inner);
                            $inner = str_replace('{{{' . $key . '}}}', (string) $val, $inner);
                        }
                    }
                } else {
                    $inner = str_replace('{{this}}', Sanitize::html((string) $item), $inner);
                    $inner = str_replace('{{{this}}}', (string) $item, $inner);
                }
                
                $inner = str_replace('{{@index}}', (string) $index, $inner);
                $inner = str_replace('{{@key}}', (string) $index, $inner);
                $output .= $inner;
            }
            
            $html = substr($html, 0, $startPos) . $output . substr($html, $contentEnd + 9);
        }
        
        return $html;
    }
    
    /**
     * Process {{#if var}}...{{else}}...{{/if}} blocks with proper nesting support
     */
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
        $cacheFile = ONECMS_CACHE . '/partials/' . $name . '.html';
        
        // Use cached version if fresh (1 hour TTL)
        if (file_exists($cacheFile) && filemtime($cacheFile) > time() - 3600) {
            return file_get_contents($cacheFile);
        }
        
        // Render partial
        $html = match ($name) {
            'header' => self::renderHeader(),
            'footer' => self::renderFooter(),
            'nav' => self::renderNav(),
            default => self::getTemplate('_' . $name)
        };
        
        // Cache it
        file_put_contents($cacheFile, $html);
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
        $navBg = Settings::get('nav_bg', '');
        $siteName = Settings::get('site_name', 'OneCMS');
        return self::renderPartialTemplate('_nav', [
            'items' => $items, 
            'nav_bg' => $navBg,
            'site_name' => $siteName
        ]);
    }
    
    private static function renderFooter(): string {
        $footer = DB::fetch("SELECT * FROM theme_footer LIMIT 1") ?? [];
        $footer['site_name'] = Settings::get('site_name', 'OneCMS');
        $footer['year'] = date('Y');
        return self::renderPartialTemplate('_footer', $footer);
    }
    
    /**
     * Render a partial template without overwriting parent data
     */
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
        $templates = [
            // ─── LAYOUT ──────────────────────────────────────────────────
            'layout' => <<<'HTML'
<!DOCTYPE html>
<html lang="en" data-theme="{{ui_theme}}" data-light-theme="{{ui_theme}}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{title}} | {{site_name}}</title>
    <meta name="description" content="{{meta_description}}">
    <meta property="og:title" content="{{title}}">
    <meta property="og:description" content="{{meta_description}}">
    {{#if og_image}}<meta property="og:image" content="{{og_image}}">{{/if}}
    <link rel="canonical" href="{{canonical_url}}">
    <script nonce="{{csp_nonce}}">
        // Auto dark mode detection
        (function() {
            const savedTheme = localStorage.getItem('theme');
            const lightTheme = document.documentElement.dataset.lightTheme || 'emerald';
            const darkThemes = {
                'light': 'dark', 'cupcake': 'dark', 'bumblebee': 'dark', 'emerald': 'forest',
                'corporate': 'business', 'garden': 'forest', 'lofi': 'black', 'pastel': 'night',
                'fantasy': 'dracula', 'wireframe': 'black', 'cmyk': 'dark', 'autumn': 'halloween',
                'acid': 'synthwave', 'lemonade': 'night', 'winter': 'night'
            };
            const darkTheme = darkThemes[lightTheme] || 'dark';
            
            if (savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.setAttribute('data-theme', darkTheme);
                document.documentElement.classList.add('dark');
            } else {
                document.documentElement.setAttribute('data-theme', lightTheme);
                document.documentElement.classList.remove('dark');
            }
        })();
    </script>
    <link href="/cdn/daisyui.min.css" rel="stylesheet" type="text/css" />
    <script src="/cdn/tailwind.min.js"></script>
    <link rel="stylesheet" href="/cdn/material-icons.css" />
    <link rel="stylesheet" href="/css?v={{css_hash}}">
    {{{head_scripts}}}
    <style>
        :root {
            --color-primary: {{color_primary}};
            --color-secondary: {{color_secondary}};
            --color-accent: {{color_accent}};
        }
        /* Button text centering fix */
        .btn, a.btn { 
            display: inline-flex !important; 
            align-items: center !important; 
            justify-content: center !important; 
            text-align: center !important;
            vertical-align: middle !important;
        }
        /* Smooth scroll */
        html { scroll-behavior: smooth; }
        /* Animation utilities */
        .animate-fade-in { animation: fadeIn 0.6s ease-out; }
        .animate-slide-up { animation: slideUp 0.6s ease-out; }
        .animate-scale-in { animation: scaleIn 0.4s ease-out; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes slideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes scaleIn { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }
        /* Intersection observer animations */
        .reveal { opacity: 0; transform: translateY(30px); transition: all 0.6s ease; }
        .reveal.visible { opacity: 1; transform: translateY(0); }
    </style>
</head>
<body class="min-h-screen bg-base-100 text-base-content">
    {{> header}}
    {{> nav}}
    <main class="container mx-auto px-4 py-8">
        {{{content}}}
    </main>
    {{> footer}}
    {{{footer_scripts}}}
    <script>
        // Reveal on scroll animation
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('visible');
                }
            });
        }, { threshold: 0.1 });
        document.querySelectorAll('.reveal').forEach(el => observer.observe(el));
    </script>
</body>
</html>
HTML,
            
            // ─── HEADER PARTIAL ──────────────────────────────────────────
            '_header' => <<<'HTML'
HTML,
            
            // ─── NAV PARTIAL ─────────────────────────────────────────────
            '_nav' => <<<'HTML'
<div class="navbar bg-base-200 shadow-lg sticky top-0 z-50">
    <div class="navbar-start">
        <div class="dropdown lg:hidden">
            <div tabindex="0" role="button" class="btn btn-ghost">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h8m-8 6h16" />
                </svg>
            </div>
            <ul tabindex="0" class="menu menu-sm dropdown-content mt-3 z-[100] p-2 shadow bg-base-100 rounded-box w-52">
                {{#each items}}
                <li><a href="{{url}}" class="text-base-content">{{label}}</a></li>
                {{/each}}
            </ul>
        </div>
        <a href="/" class="btn btn-ghost text-xl font-bold">{{site_name}}</a>
    </div>
    <div class="navbar-center hidden lg:flex">
        <ul class="menu menu-horizontal px-1">
            {{#each items}}
            <li><a href="{{url}}" class="text-base-content hover:text-primary">{{label}}</a></li>
            {{/each}}
        </ul>
    </div>
    <div class="navbar-end gap-2">
        <label class="swap swap-rotate btn btn-ghost btn-circle text-base-content" title="Toggle dark mode">
            <input type="checkbox" id="theme-toggle" class="theme-controller" />
            <!-- sun icon -->
            <svg class="swap-off fill-current w-6 h-6" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M5.64,17l-.71.71a1,1,0,0,0,0,1.41,1,1,0,0,0,1.41,0l.71-.71A1,1,0,0,0,5.64,17ZM5,12a1,1,0,0,0-1-1H3a1,1,0,0,0,0,2H4A1,1,0,0,0,5,12Zm7-7a1,1,0,0,0,1-1V3a1,1,0,0,0-2,0V4A1,1,0,0,0,12,5ZM5.64,7.05a1,1,0,0,0,.7.29,1,1,0,0,0,.71-.29,1,1,0,0,0,0-1.41l-.71-.71A1,1,0,0,0,4.93,6.34Zm12,.29a1,1,0,0,0,.7-.29l.71-.71a1,1,0,1,0-1.41-1.41L17,5.64a1,1,0,0,0,0,1.41A1,1,0,0,0,17.66,7.34ZM21,11H20a1,1,0,0,0,0,2h1a1,1,0,0,0,0-2Zm-9,8a1,1,0,0,0-1,1v1a1,1,0,0,0,2,0V20A1,1,0,0,0,12,19ZM18.36,17A1,1,0,0,0,17,18.36l.71.71a1,1,0,0,0,1.41,0,1,1,0,0,0,0-1.41ZM12,6.5A5.5,5.5,0,1,0,17.5,12,5.51,5.51,0,0,0,12,6.5Zm0,9A3.5,3.5,0,1,1,15.5,12,3.5,3.5,0,0,1,12,15.5Z"/></svg>
            <!-- moon icon -->
            <svg class="swap-on fill-current w-6 h-6" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M21.64,13a1,1,0,0,0-1.05-.14,8.05,8.05,0,0,1-3.37.73A8.15,8.15,0,0,1,9.08,5.49a8.59,8.59,0,0,1,.25-2A1,1,0,0,0,8,2.36,10.14,10.14,0,1,0,22,14.05,1,1,0,0,0,21.64,13Z"/></svg>
        </label>
        <a href="/contact" class="btn btn-primary btn-sm">Contact</a>
    </div>
</div>
HTML,
            
            // ─── FOOTER PARTIAL ──────────────────────────────────────────
            '_footer' => <<<'HTML'
<footer class="footer footer-center p-10 bg-base-200 text-base-content mt-16">
    <aside>
        <p class="font-bold text-lg">{{site_name}}</p>
        <p class="text-base-content/70">{{text}}</p>
        <!-- Copyright removed to avoid duplication -->
    </aside>
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
    <title>{{title}} | {{site_name}}</title>
    <meta name="description" content="{{meta_description}}">
    <meta property="og:title" content="{{title}}">
    <meta property="og:description" content="{{meta_description}}">
    {{#if og_image}}<meta property="og:image" content="{{og_image}}">{{/if}}
    <script nonce="{{csp_nonce}}">
        // Auto dark mode detection
        (function() {
            const savedTheme = localStorage.getItem('theme');
            const lightTheme = document.documentElement.dataset.lightTheme || 'emerald';
            const darkThemes = {
                'light': 'dark', 'cupcake': 'dark', 'bumblebee': 'dark', 'emerald': 'forest',
                'corporate': 'business', 'garden': 'forest', 'lofi': 'black', 'pastel': 'night',
                'fantasy': 'dracula', 'wireframe': 'black', 'cmyk': 'dark', 'autumn': 'halloween',
                'acid': 'synthwave', 'lemonade': 'night', 'winter': 'night'
            };
            const darkTheme = darkThemes[lightTheme] || 'dark';
            
            if (savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.setAttribute('data-theme', darkTheme);
                document.documentElement.classList.add('dark');
            } else {
                document.documentElement.setAttribute('data-theme', lightTheme);
                document.documentElement.classList.remove('dark');
            }
        })();
    </script>
    <link href="/cdn/daisyui.min.css" rel="stylesheet" type="text/css" />
    <script src="/cdn/tailwind.min.js"></script>
    <link rel="stylesheet" href="/cdn/material-icons.css" />
    <link rel="stylesheet" href="/css?v={{css_hash}}">
    {{#if edit_mode}}<link rel="stylesheet" href="/css/editor">{{/if}}
    <style>
        /* Button text centering fix */
        .btn, a.btn { 
            display: inline-flex !important; 
            align-items: center !important; 
            justify-content: center !important; 
            text-align: center !important;
            vertical-align: middle !important;
        }
        /* Animation utilities */
        .animate-fade-in { animation: fadeIn 0.6s ease-out; }
        .animate-slide-up { animation: slideUp 0.6s ease-out; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes slideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        /* Reveal animations */
        .reveal { opacity: 0; transform: translateY(30px); transition: all 0.6s ease; }
        .reveal.visible { opacity: 1; transform: translateY(0); }
    </style>
</head>
<body class="min-h-screen bg-base-100 text-base-content">
    <!--ONECMS_ADMIN_BAR-->
    {{#if edit_mode}}<input type="hidden" id="onecms-csrf" value="{{csrf_token}}">{{/if}}
    {{#if edit_mode}}<div class="onecms-section-wrapper" data-section="header"><button class="section-edit-btn" data-action="edit-header">✎ Edit Header</button>{{/if}}
    {{> header}}
    {{#if edit_mode}}</div>{{/if}}
    {{#if edit_mode}}<div class="onecms-section-wrapper" data-section="nav"><button class="section-edit-btn" data-action="edit-nav">✎ Edit Navigation</button>{{/if}}
    {{> nav}}
    {{#if edit_mode}}</div>{{/if}}
    <main class="container mx-auto px-4 py-8" data-page-id="{{id}}">
        {{#if flash}}<div class="alert alert-{{flash.type}} mb-6">{{flash.message}}</div>{{/if}}
        {{#if show_title}}<h1 class="text-4xl font-bold mb-8">{{title}}</h1>{{/if}}
        {{{blocks_html}}}
    </main>
    {{#if edit_mode}}<div class="onecms-section-wrapper" data-section="footer"><button class="section-edit-btn" data-action="edit-footer">✎ Edit Footer</button>{{/if}}
    {{> footer}}
    {{#if edit_mode}}</div>{{/if}}
    {{#if edit_mode}}<script src="/js/editor" nonce="{{csp_nonce}}"></script>{{/if}}
    <script src="/js/reveal"></script>
    <script nonce="{{csp_nonce}}">
        document.addEventListener('DOMContentLoaded', () => {
            const toggle = document.getElementById('theme-toggle');
            if (toggle) {
                if (document.documentElement.classList.contains('dark')) {
                    toggle.checked = true;
                }
                toggle.addEventListener('change', (e) => {
                    const lightTheme = document.documentElement.dataset.lightTheme || 'emerald';
                    const darkThemes = {'light':'dark','cupcake':'dark','bumblebee':'dark','emerald':'forest','corporate':'business','garden':'forest','lofi':'black','pastel':'night','fantasy':'dracula','wireframe':'black','cmyk':'dark','autumn':'halloween','acid':'synthwave','lemonade':'night','winter':'night'};
                    const darkTheme = darkThemes[lightTheme] || 'dark';
                    
                    if (e.target.checked) {
                        document.documentElement.setAttribute('data-theme', darkTheme);
                        document.documentElement.classList.add('dark');
                        localStorage.setItem('theme', 'dark');
                    } else {
                        document.documentElement.setAttribute('data-theme', lightTheme);
                        document.documentElement.classList.remove('dark');
                        localStorage.setItem('theme', 'light');
                    }
                });
            }
        });
    </script>
</body>
</html>
HTML,
            
            // ─── ADMIN LAYOUT ────────────────────────────────────────────
            'admin_layout' => <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{title}} | OneCMS Admin</title>
    <script>
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
</head>
<body class="bg-background-light dark:bg-background-dark font-display text-slate-900 dark:text-white transition-colors duration-200">
<div class="flex h-screen overflow-hidden">
    <!-- Sidebar -->
    <aside class="w-64 bg-white dark:bg-[#1e293b] border-r border-slate-200 dark:border-slate-700 flex flex-col transition-colors duration-200">
        <div class="p-6 flex items-center gap-3">
            <div class="bg-primary size-10 rounded-lg flex items-center justify-center text-white">
                <span class="material-symbols-outlined">mms</span>
            </div>
            <div class="flex flex-col">
                <h1 class="text-slate-900 text-base font-bold leading-none">OneCMS</h1>
                <p class="text-slate-500 text-xs mt-1">Admin Portal</p>
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
            <div class="pt-4 pb-2">
                <p class="px-3 text-[10px] font-bold text-slate-400 uppercase tracking-widest">AI Tools</p>
            </div>
            <a class="flex items-center gap-3 px-3 py-2.5 rounded-lg {{#if is_ai}}bg-primary/10 text-primary font-medium{{else}}text-slate-600 hover:bg-slate-50{{/if}} transition-colors" href="/admin/ai">
                <span class="material-symbols-outlined text-[22px]">auto_awesome</span>
                <span class="text-sm">AI Generate</span>
            </a>
            <a class="flex items-center gap-3 px-3 py-2.5 rounded-lg {{#if is_approvals}}bg-primary/10 text-primary font-medium{{else}}text-slate-600 hover:bg-slate-50{{/if}} transition-colors" href="/admin/approvals">
                <span class="material-symbols-outlined text-[22px]">pending_actions</span>
                <span class="text-sm">Approvals</span>
            </a>
        </nav>
        <div class="p-4 border-t border-slate-200 dark:border-slate-700">
            <button id="theme-toggle-btn" type="button" class="w-full flex items-center gap-3 px-3 py-2 mb-2 rounded-lg text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                <span class="material-symbols-outlined text-[22px] dark:hidden">dark_mode</span>
                <span class="material-symbols-outlined text-[22px] hidden dark:block">light_mode</span>
                <span class="text-sm font-medium">Toggle Theme</span>
            </button>
            <div class="flex items-center gap-3 p-2">
                <div class="size-8 rounded-full bg-primary/20 flex items-center justify-center">
                    <span class="material-symbols-outlined text-primary text-[18px]">person</span>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-semibold text-slate-900 truncate">{{user_email}}</p>
                    <p class="text-xs text-slate-500 truncate">{{user_role}}</p>
                </div>
            </div>
            <form method="post" action="/admin/logout" class="mt-2">
                {{{csrf_field}}}
                <button type="submit" class="w-full flex items-center justify-center gap-2 px-3 py-2 text-sm text-red-600 hover:bg-red-50 rounded-lg transition-colors">
                    <span class="material-symbols-outlined text-[18px]">logout</span>
                    <span>Logout</span>
                </button>
            </form>
        </div>
    </aside>
    <!-- Main Content -->
    <main class="flex-1 flex flex-col overflow-y-auto">
        <!-- Top Header -->
        <header class="h-16 bg-white border-b border-slate-200 flex items-center justify-between px-8 sticky top-0 z-10">
            <div class="flex items-center gap-4 flex-1 max-w-xl">
                <div class="relative w-full">
                    <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xl">search</span>
                    <input class="w-full bg-slate-50 border-none rounded-lg pl-10 pr-4 py-2 text-sm focus:ring-2 focus:ring-primary" placeholder="Search..." type="text">
                </div>
            </div>
            <div class="flex items-center gap-3">
                <a href="/" target="_blank" class="p-2 text-slate-500 hover:bg-slate-100 rounded-lg" title="View Site">
                    <span class="material-symbols-outlined">open_in_new</span>
                </a>
                <button class="p-2 text-slate-500 hover:bg-slate-100 rounded-lg relative">
                    <span class="material-symbols-outlined">notifications</span>
                    {{#if pending_count}}<span class="absolute top-2 right-2 size-2 bg-red-500 rounded-full border-2 border-white"></span>{{/if}}
                </button>
                <div class="h-8 w-px bg-slate-200 mx-2"></div>
                <a href="/admin/pages/new" class="flex items-center gap-2 bg-primary text-white px-4 py-2 rounded-lg text-sm font-semibold hover:bg-primary/90 transition-colors">
                    <span class="material-symbols-outlined text-sm">add</span>
                    Create New
                </a>
            </div>
        </header>
        <div class="p-8">
            {{#if flash_error}}<div class="mb-6 p-4 bg-red-50 border border-red-200 text-red-700 rounded-lg flex items-center gap-3"><span class="material-symbols-outlined">error</span>{{flash_error}}</div>{{/if}}
            {{#if flash_success}}<div class="mb-6 p-4 bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-lg flex items-center gap-3"><span class="material-symbols-outlined">check_circle</span>{{flash_success}}</div>{{/if}}
            {{{content}}}
        </div>
    </main>
</div>
<script nonce="{{csp_nonce}}">
// Event delegation for all admin interactions
document.addEventListener('DOMContentLoaded', function() {
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
    
    // Handle button actions via data attributes
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('[data-action]');
        if (!btn) return;
        
        const action = btn.dataset.action;
        
        switch(action) {
            case 'move-block-up':
                moveBlockUp(btn);
                break;
            case 'move-block-down':
                moveBlockDown(btn);
                break;
            case 'delete-block':
                deleteBlock(btn);
                break;
            case 'show-block-picker':
                showBlockPicker();
                break;
            case 'hide-block-picker':
                hideBlockPicker();
                break;
            case 'add-block':
                addBlock(btn.dataset.blockType);
                break;
            case 'add-nav-item':
                addNavItem();
                break;
            case 'remove-nav-item':
                removeNavItem(btn);
                break;
            case 'copy-url':
                copyUrl(btn.dataset.url);
                break;
        }
    });
});

// Admin helper functions (defined globally for templates that need them)
window.moveBlockUp = function(btn) {
    const block = btn.closest('.content-block');
    const prev = block.previousElementSibling;
    if (prev && prev.classList.contains('content-block')) {
        block.parentNode.insertBefore(block, prev);
        updateBlockOrder();
    }
};

window.moveBlockDown = function(btn) {
    const block = btn.closest('.content-block');
    const next = block.nextElementSibling;
    if (next && next.classList.contains('content-block')) {
        block.parentNode.insertBefore(next, block);
        updateBlockOrder();
    }
};

window.deleteBlock = function(btn) {
    if (confirm('Delete this block?')) {
        btn.closest('.content-block').remove();
        updateBlockOrder();
    }
};

window.showBlockPicker = function() {
    const picker = document.getElementById('block-picker');
    if (picker) picker.classList.remove('hidden');
};

window.hideBlockPicker = function() {
    const picker = document.getElementById('block-picker');
    if (picker) picker.classList.add('hidden');
};

window.addBlock = function(type) {
    const container = document.getElementById('blocks-container');
    if (!container) return;
    const blockId = 'new_' + Date.now();
    const html = document.getElementById('block-template-' + type);
    if (html) {
        const block = document.createElement('div');
        block.innerHTML = html.innerHTML.replace(/\{id\}/g, blockId);
        container.appendChild(block.firstElementChild);
    }
    hideBlockPicker();
    updateBlockOrder();
};

window.updateBlockOrder = function() {
    document.querySelectorAll('.content-block').forEach((block, index) => {
        const orderInput = block.querySelector('input[name$="[order]"]');
        if (orderInput) orderInput.value = index;
    });
};

window.addNavItem = function() {
    const container = document.getElementById('nav-items');
    if (!container) return;
    const template = document.getElementById('nav-item-template');
    if (!template) return;
    const newItem = document.createElement('div');
    newItem.innerHTML = template.innerHTML.replace(/\{index\}/g, Date.now());
    container.appendChild(newItem.firstElementChild);
};

window.removeNavItem = function(btn) {
    if (confirm('Remove this menu item?')) {
        btn.closest('.nav-item').remove();
    }
};

window.copyUrl = function(url) {
    navigator.clipboard.writeText(window.location.origin + url).then(() => {
        alert('URL copied to clipboard!');
    });
};

// Theme Toggle Logic
const themeBtn = document.getElementById('theme-toggle-btn');
if (themeBtn) {
    themeBtn.addEventListener('click', () => {
        // if set via local storage previously
        if (localStorage.getItem('color-theme')) {
            if (localStorage.getItem('color-theme') === 'light') {
                document.documentElement.classList.add('dark');
                localStorage.setItem('color-theme', 'dark');
            } else {
                document.documentElement.classList.remove('dark');
                localStorage.setItem('color-theme', 'light');
            }
        // if NOT set via local storage previously
        } else {
            if (document.documentElement.classList.contains('dark')) {
                document.documentElement.classList.remove('dark');
                localStorage.setItem('color-theme', 'light');
            } else {
                document.documentElement.classList.add('dark');
                localStorage.setItem('color-theme', 'dark');
            }
        }
    });
}
</script>
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
    <title>Login | OneCMS</title>
    <script>
        if (localStorage.getItem('color-theme') === 'dark' || (!('color-theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        }
    </script>
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
</head>
<body class="bg-slate-100 dark:bg-slate-950 font-display min-h-screen flex items-center justify-center p-4 transition-colors">
    <div class="w-full max-w-md">
        <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-xl p-8 transition-colors">
            <div class="flex justify-center mb-6">
                <div class="bg-primary size-14 rounded-xl flex items-center justify-center text-white">
                    <span class="material-symbols-outlined text-3xl">mms</span>
                </div>
            </div>
            <h1 class="text-2xl font-bold text-slate-900 dark:text-white text-center mb-2">Welcome back</h1>
            <p class="text-slate-500 dark:text-slate-400 text-center mb-8">Sign in to your OneCMS admin account</p>
            
            {{#if flash}}<div class="mb-6 p-4 {{#if flash.is_error}}bg-red-50 border border-red-200 text-red-700{{else}}bg-emerald-50 border border-emerald-200 text-emerald-700{{/if}} rounded-lg text-sm flex items-center gap-2">
                <span class="material-symbols-outlined text-lg">{{#if flash.is_error}}error{{else}}check_circle{{/if}}</span>
                {{flash.message}}
            </div>{{/if}}
            
            <form method="post" action="/admin/login" class="space-y-5">
                {{{csrf_field}}}
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Email</label>
                    <input type="email" name="email" required autofocus 
                        class="w-full px-4 py-3 bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-colors placeholder-slate-400"
                        placeholder="you@example.com">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Password</label>
                    <input type="password" name="password" required 
                        class="w-full px-4 py-3 bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-colors placeholder-slate-400"
                        placeholder="••••••••••••">
                </div>
                <button type="submit" 
                    class="w-full bg-primary text-white py-3 px-4 rounded-lg font-semibold hover:bg-primary/90 transition-colors flex items-center justify-center gap-2">
                    <span class="material-symbols-outlined text-xl">login</span>
                    Sign In
                </button>
            </form>
        </div>
        <p class="text-center text-slate-500 text-sm mt-6">Powered by OneCMS</p>
    </div>
</body>
</html>
HTML,
            
            // ─── MFA PAGE ────────────────────────────────────────────────
            'admin/mfa' => <<<'HTML'
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Code | OneCMS</title>
    <link rel="stylesheet" href="/cdn/pico.min.css">
    <style>
        :root { color-scheme: light; }
        body { display: flex; align-items: center; justify-content: center; min-height: 100vh; background: #f1f5f9; }
        .mfa-box { background: #fff; padding: 2rem; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); width: 100%; max-width: 400px; text-align: center; }
        .mfa-box h1 { margin-bottom: 0.5rem; color: #1e293b; }
        .mfa-box p { color: #6b7280; margin-bottom: 1.5rem; }
        .flash { padding: 0.75rem; margin-bottom: 1rem; border-radius: 4px; font-size: 0.875rem; }
        .flash-error { background: #fee2e2; color: #991b1b; }
        .flash-success { background: #d1fae5; color: #065f46; }
        /* Force light mode for form inputs */
        input {
            background: #fff !important;
            color: #1e293b !important;
            border: 1px solid #cbd5e1 !important;
        }
        input:focus {
            border-color: #3b82f6 !important;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1) !important;
        }
        input::placeholder { color: #94a3b8 !important; }
        input[name="code"] { text-align: center; font-size: 1.5rem; letter-spacing: 0.5rem; }
        button[type="submit"] { background: #3b82f6; border-color: #3b82f6; }
        button[type="submit"]:hover { background: #2563eb; border-color: #2563eb; }
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
            <div class="p-2 bg-violet-50 text-violet-600 rounded-lg">
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
        <a href="/admin/pages/new" class="flex items-center gap-2 bg-primary text-white px-4 py-2 rounded-lg text-sm font-semibold hover:bg-primary/90 transition-colors">
            <span class="material-symbols-outlined text-sm">add</span>
            New Page
        </a>
        <a href="/admin/ai" class="flex items-center gap-2 bg-slate-100 text-slate-700 px-4 py-2 rounded-lg text-sm font-semibold hover:bg-slate-200 transition-colors">
            <span class="material-symbols-outlined text-sm">auto_awesome</span>
            AI Generate
        </a>
        <a href="/admin/media" class="flex items-center gap-2 bg-slate-100 text-slate-700 px-4 py-2 rounded-lg text-sm font-semibold hover:bg-slate-200 transition-colors">
            <span class="material-symbols-outlined text-sm">upload</span>
            Upload Media
        </a>
        <form method="post" action="/admin/cache/regenerate" class="inline">
            {{{csrf_field}}}
            <button type="submit" class="flex items-center gap-2 bg-amber-100 text-amber-700 px-4 py-2 rounded-lg text-sm font-semibold hover:bg-amber-200 transition-colors">
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
                        <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-50 text-emerald-600 border border-emerald-100">Published</span>
                        {{else}}
                        <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-slate-100 text-slate-600 border border-slate-200">Draft</span>
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
            <a href="/admin/pages/new" class="flex items-center gap-2 bg-primary text-white px-4 py-2 rounded-lg text-sm font-semibold hover:bg-primary/90 transition-colors">
                <span class="material-symbols-outlined text-sm">add</span>
                Create Page
            </a>
            <a href="/admin/ai" class="flex items-center gap-2 bg-slate-100 text-slate-700 px-4 py-2 rounded-lg text-sm font-semibold hover:bg-slate-200 transition-colors">
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
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup | OneCMS</title>
    <link rel="stylesheet" href="/cdn/pico.min.css">
    <style>
        :root { color-scheme: light; }
        body { display: flex; align-items: center; justify-content: center; min-height: 100vh; background: #f1f5f9; }
        .setup-box { background: #fff; padding: 2.5rem; border-radius: 12px; box-shadow: 0 25px 50px rgba(0,0,0,0.15); width: 100%; max-width: 500px; }
        .setup-box h1 { text-align: center; margin-bottom: 0.5rem; color: #1e293b; }
        .setup-box h2 { color: #1e293b; }
        .setup-box .subtitle { text-align: center; color: #64748b; margin-bottom: 2rem; }
        .steps { display: flex; justify-content: center; gap: 0.5rem; margin-bottom: 2rem; }
        .step { width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 600; }
        .step.active { background: #3b82f6; color: #fff; }
        .step.done { background: #10b981; color: #fff; }
        .step.pending { background: #e2e8f0; color: #94a3b8; }
        .flash { padding: 0.75rem; margin-bottom: 1rem; border-radius: 4px; }
        .flash-error { background: #fee2e2; color: #991b1b; }
        /* Force light mode for form inputs */
        input, select, textarea { 
            background: #fff !important; 
            color: #1e293b !important; 
            border: 1px solid #cbd5e1 !important;
        }
        input:focus, select:focus, textarea:focus {
            border-color: #3b82f6 !important;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1) !important;
        }
        input::placeholder { color: #94a3b8 !important; }
        label { color: #475569; }
        button[type="submit"] { background: #3b82f6; border-color: #3b82f6; }
        button[type="submit"]:hover { background: #2563eb; border-color: #2563eb; }
        p { color: #64748b; }
    </style>
</head>
<body>
    <div class="setup-box">
        <h1>Welcome to OneCMS</h1>
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
<h2>AI Provider (Optional)</h2>
<p style="color: #64748b; font-size: 0.875rem;">Configure AI to auto-generate your website content. You can skip this and set it up later.</p>
<form method="post" action="/setup">
    {{{csrf_field}}}
    <input type="hidden" name="step" value="3">
    <label>
        AI Provider
        <select name="ai_provider">
            <option value="">-- Skip for now --</option>
            <option value="openai">OpenAI (GPT-4)</option>
            <option value="anthropic">Anthropic (Claude)</option>
        </select>
    </label>
    <label>
        API Key
        <input type="password" name="ai_api_key" placeholder="sk-...">
    </label>
    <button type="submit">Continue →</button>
</form>
HTML,
            
            'setup/step4' => <<<'HTML'
<h2>Email Configuration (Optional)</h2>
<p style="color: #64748b; font-size: 0.875rem;">Configure SMTP to enable contact forms and MFA. Skip to use PHP's mail() function.</p>
<form method="post" action="/setup">
    {{{csrf_field}}}
    <input type="hidden" name="step" value="4">
    <label>
        SMTP Host
        <input type="text" name="smtp_host" placeholder="smtp.example.com">
    </label>
    <label>
        SMTP Port
        <input type="number" name="smtp_port" value="587">
    </label>
    <label>
        SMTP Username
        <input type="text" name="smtp_user">
    </label>
    <label>
        SMTP Password
        <input type="password" name="smtp_pass">
    </label>
    <label>
        From Email
        <input type="email" name="smtp_from" placeholder="noreply@example.com">
    </label>
    <button type="submit">Complete Setup →</button>
</form>
HTML,
            
            'setup/complete' => <<<'HTML'
<div style="text-align: center;">
    <div style="font-size: 4rem; margin-bottom: 1rem;">🎉</div>
    <h2>Setup Complete!</h2>
    <p style="color: #64748b;">Your OneCMS installation is ready to use.</p>
    <a href="/admin/login" style="display: inline-block; background: #3b82f6; color: #fff; padding: 0.75rem 2rem; border-radius: 6px; text-decoration: none; margin-top: 1rem;">Go to Admin Panel →</a>
</div>
HTML,
            
            // ─── ADMIN: PAGES LIST ───────────────────────────────────────
            'admin/pages' => <<<'HTML'
<!-- Page Header -->
<div class="mb-8 flex items-center justify-between">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Pages</h1>
        <p class="text-gray-600 mt-1">Manage your website pages and content.</p>
    </div>
    <a href="/admin/pages/new" class="inline-flex items-center gap-2 px-4 py-2.5 bg-[#135bec] text-white font-medium rounded-lg hover:bg-[#0f4fd1] transition-colors">
        <span class="material-symbols-outlined text-xl">add</span>
        New Page
    </a>
</div>

{{#if flash_error}}
<div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg flex items-center gap-3">
    <span class="material-symbols-outlined text-red-600">error</span>
    <span class="text-red-800">{{flash_error}}</span>
</div>
{{/if}}

{{#if flash_success}}
<div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-lg flex items-center gap-3">
    <span class="material-symbols-outlined text-green-600">check_circle</span>
    <span class="text-green-800">{{flash_success}}</span>
</div>
{{/if}}

{{#if pages}}
<div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
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
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium bg-green-100 text-green-800 rounded-full">
                            <span class="w-1.5 h-1.5 bg-green-500 rounded-full"></span>
                            Published
                        </span>
                        {{else}}
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium bg-amber-100 text-amber-800 rounded-full">
                            <span class="w-1.5 h-1.5 bg-amber-500 rounded-full"></span>
                            Draft
                        </span>
                        {{/if}}
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-500">{{updated_at}}</td>
                    <td class="px-6 py-4">
                        <div class="flex items-center justify-end gap-2">
                            <a href="/admin/pages/{{id}}" class="inline-flex items-center gap-1 px-3 py-1.5 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors">
                                <span class="material-symbols-outlined text-lg">edit</span>
                                Edit
                            </a>
                            <a href="/{{slug}}" target="_blank" class="inline-flex items-center gap-1 px-3 py-1.5 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors">
                                <span class="material-symbols-outlined text-lg">open_in_new</span>
                                View
                            </a>
                            <form method="post" action="/admin/pages/{{id}}/delete" class="inline" data-confirm="Delete this page?">
                                {{{csrf_field}}}
                                <button type="submit" class="inline-flex items-center gap-1 px-3 py-1.5 text-sm font-medium text-red-600 bg-red-50 rounded-lg hover:bg-red-100 transition-colors">
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
        <a href="/admin/pages/new" class="inline-flex items-center gap-2 px-4 py-2.5 bg-[#135bec] text-white font-medium rounded-lg hover:bg-[#0f4fd1] transition-colors">
            <span class="material-symbols-outlined text-xl">add</span>
            Create Page
        </a>
        <a href="/admin/ai" class="inline-flex items-center gap-2 px-4 py-2.5 bg-gray-100 text-gray-700 font-medium rounded-lg hover:bg-gray-200 transition-colors">
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
<div class="mb-6 flex items-center justify-between">
    <div class="flex items-center gap-3">
        <a href="/admin/pages" class="text-gray-500 hover:text-gray-700">
            <span class="material-symbols-outlined">arrow_back</span>
        </a>
        <div>
            <h1 class="text-2xl font-bold text-gray-900">{{#if is_new}}Create Page{{else}}Edit Page{{/if}}</h1>
            <p class="text-gray-600 mt-1">{{#if is_new}}Add a new page to your site.{{else}}Update page content and settings.{{/if}}</p>
        </div>
    </div>
    {{#if is_new}}
    <button type="button" id="ai-generate-btn" class="inline-flex items-center gap-2 px-4 py-2.5 bg-gradient-to-r from-purple-600 to-indigo-600 text-white font-medium rounded-lg hover:from-purple-700 hover:to-indigo-700 transition-all shadow-md">
        <span class="material-symbols-outlined">auto_awesome</span>
        AI Generate
    </button>
    {{/if}}
</div>

{{#if flash_error}}
<div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg flex items-center gap-3">
    <span class="material-symbols-outlined text-red-600">error</span>
    <span class="text-red-800">{{flash_error}}</span>
</div>
{{/if}}

{{#if flash_success}}
<div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-lg flex items-center gap-3">
    <span class="material-symbols-outlined text-green-600">check_circle</span>
    <span class="text-green-800">{{flash_success}}</span>
</div>
{{/if}}

<form method="post" action="/admin/pages/{{id}}" id="page-form">
    {{{csrf_field}}}
    
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main Content Column -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Page Title -->
            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <label class="block text-sm font-medium text-gray-700 mb-2">Page Title *</label>
                <input type="text" name="title" value="{{title}}" required 
                       class="w-full px-4 py-3 text-xl font-semibold border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none">
            </div>
            
            <!-- Content Blocks -->
            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <h2 class="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-[#135bec]">widgets</span>
                    Content Blocks
                </h2>
                
                <div id="blocks-container" class="space-y-4">
                    {{#each blocks}}
                    <div class="block-item bg-gray-50 border border-gray-200 rounded-lg p-4" data-block-id="{{id}}" data-block-order="{{sort_order}}">
                        <div class="flex items-center justify-between mb-3 pb-3 border-b border-gray-200">
                            <span class="inline-flex items-center gap-2 px-3 py-1 bg-[#135bec]/10 text-[#135bec] rounded-full text-sm font-medium">
                                <span class="material-symbols-outlined text-lg">{{type_icon}}</span>
                                {{type_label}}
                            </span>
                            <div class="flex items-center gap-1">
                                <button type="button" data-action="move-block-up" class="p-1.5 text-gray-500 hover:text-gray-700 hover:bg-gray-200 rounded transition-colors" title="Move up">
                                    <span class="material-symbols-outlined text-lg">arrow_upward</span>
                                </button>
                                <button type="button" data-action="move-block-down" class="p-1.5 text-gray-500 hover:text-gray-700 hover:bg-gray-200 rounded transition-colors" title="Move down">
                                    <span class="material-symbols-outlined text-lg">arrow_downward</span>
                                </button>
                                <button type="button" data-action="delete-block" class="p-1.5 text-red-500 hover:text-red-700 hover:bg-red-50 rounded transition-colors" title="Delete">
                                    <span class="material-symbols-outlined text-lg">delete</span>
                                </button>
                            </div>
                        </div>
                        <input type="hidden" name="blocks[{{id}}][id]" value="{{id}}">
                        <input type="hidden" name="blocks[{{id}}][type]" value="{{type}}">
                        <input type="hidden" name="blocks[{{id}}][order]" class="block-order" value="{{sort_order}}">
                        <div class="block-fields space-y-3">
                            {{{block_fields}}}
                        </div>
                    </div>
                    {{/each}}
                </div>
                
                <button type="button" data-action="show-block-picker" 
                        class="mt-4 w-full flex items-center justify-center gap-2 p-4 border-2 border-dashed border-gray-300 rounded-lg text-gray-500 hover:border-[#135bec] hover:text-[#135bec] transition-colors">
                    <span class="material-symbols-outlined">add</span>
                    Add Content Block
                </button>
            </div>
        </div>
        
        <!-- Sidebar -->
        <div class="space-y-6">
            <!-- Page Settings -->
            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-[#135bec]">settings</span>
                    Page Settings
                </h3>
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">URL Slug *</label>
                        <div class="flex items-center">
                            <span class="px-3 py-2 bg-gray-100 border border-r-0 border-gray-300 rounded-l-lg text-gray-500 text-sm">/</span>
                            <input type="text" name="slug" id="page-slug" value="{{slug}}" required pattern="[a-z0-9\-]+"
                                   class="flex-1 px-3 py-2 border border-gray-300 rounded-r-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                        <select name="status"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none">
                            <option value="draft" {{#if is_draft}}selected{{/if}}>Draft</option>
                            <option value="published" {{#if is_published}}selected{{/if}}>Published</option>
                        </select>
                    </div>
                </div>
            </div>
            
            <!-- Navigation -->
            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-[#135bec]">menu</span>
                    Navigation
                </h3>
                <div class="space-y-4">
                    <label class="flex items-center gap-3 cursor-pointer">
                        <input type="checkbox" name="add_to_nav" id="add-to-nav" value="1" {{#if add_to_nav}}checked{{/if}}
                               class="w-5 h-5 rounded border-gray-300 text-[#135bec] focus:ring-[#135bec]">
                        <span class="text-sm font-medium text-gray-700">Add to navigation menu</span>
                    </label>
                    <div id="nav-position-group" class="{{#if add_to_nav}}{{else}}hidden{{/if}}">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Position</label>
                        <select name="nav_position" id="nav-position"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none">
                            <option value="0">At the beginning</option>
                            {{#each nav_items}}
                            <option value="{{sort_order_after}}">After "{{label}}"</option>
                            {{/each}}
                        </select>
                    </div>
                </div>
            </div>
            
            <!-- SEO Settings -->
            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-[#135bec]">search</span>
                    SEO
                </h3>
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Meta Description</label>
                        <textarea name="meta_description" rows="3"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none resize-y">{{meta_description}}</textarea>
                        <p class="text-xs text-gray-500 mt-1">Recommended: 150-160 characters</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">OG Image</label>
                        <div class="flex gap-2">
                            <input type="hidden" name="og_image" id="og-image-input" value="{{og_image}}">
                            <div id="og-image-preview" class="flex-1 min-h-[100px] border border-gray-300 rounded-lg bg-gray-50 flex items-center justify-center overflow-hidden">
                                {{#if og_image}}
                                <img src="{{og_image}}" alt="OG Image" class="max-h-[100px] object-contain">
                                {{else}}
                                <span class="text-gray-400 text-sm">No image selected</span>
                                {{/if}}
                            </div>
                            <div class="flex flex-col gap-2">
                                <button type="button" id="select-og-image" class="inline-flex items-center gap-1 px-3 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors">
                                    <span class="material-symbols-outlined text-lg">photo_library</span>
                                    Select
                                </button>
                                <button type="button" id="clear-og-image" class="inline-flex items-center gap-1 px-3 py-2 text-sm font-medium text-red-600 bg-red-50 rounded-lg hover:bg-red-100 transition-colors">
                                    <span class="material-symbols-outlined text-lg">close</span>
                                    Clear
                                </button>
                            </div>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">Recommended: 1200x630 pixels for social sharing</p>
                    </div>
                </div>
            </div>
            
            <!-- Actions -->
            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <button type="submit" class="w-full inline-flex items-center justify-center gap-2 px-4 py-3 bg-[#135bec] text-white font-medium rounded-lg hover:bg-[#0f4fd1] transition-colors">
                    <span class="material-symbols-outlined">save</span>
                    Save Page
                </button>
                {{#if id}}
                <a href="/{{slug}}" target="_blank" 
                   class="mt-3 w-full inline-flex items-center justify-center gap-2 px-4 py-3 bg-gray-100 text-gray-700 font-medium rounded-lg hover:bg-gray-200 transition-colors">
                    <span class="material-symbols-outlined">open_in_new</span>
                    Preview Page
                </a>
                {{/if}}
            </div>
        </div>
    </div>
</form>

<!-- Block Picker Modal -->
<div id="block-picker" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-xl max-w-2xl w-full max-h-[80vh] overflow-y-auto p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Add Content Block</h3>
        
        <!-- Hero & Headers -->
        <div class="mb-4">
            <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Hero & Headers</h4>
            <div class="grid grid-cols-4 gap-2">
                <button type="button" data-action="add-block" data-block-type="hero" class="flex flex-col items-center gap-1 p-3 border border-gray-200 rounded-lg hover:border-[#135bec] hover:bg-[#135bec]/5 transition-colors">
                    <span class="material-symbols-outlined text-xl text-[#135bec]">featured_video</span>
                    <span class="text-xs font-medium text-gray-700">Hero</span>
                </button>
                <button type="button" data-action="add-block" data-block-type="cta" class="flex flex-col items-center gap-1 p-3 border border-gray-200 rounded-lg hover:border-[#135bec] hover:bg-[#135bec]/5 transition-colors">
                    <span class="material-symbols-outlined text-xl text-[#135bec]">campaign</span>
                    <span class="text-xs font-medium text-gray-700">CTA</span>
                </button>
            </div>
        </div>
        
        <!-- Content -->
        <div class="mb-4">
            <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Content</h4>
            <div class="grid grid-cols-4 gap-2">
                <button type="button" data-action="add-block" data-block-type="text" class="flex flex-col items-center gap-1 p-3 border border-gray-200 rounded-lg hover:border-[#135bec] hover:bg-[#135bec]/5 transition-colors">
                    <span class="material-symbols-outlined text-xl text-[#135bec]">article</span>
                    <span class="text-xs font-medium text-gray-700">Text</span>
                </button>
                <button type="button" data-action="add-block" data-block-type="quote" class="flex flex-col items-center gap-1 p-3 border border-gray-200 rounded-lg hover:border-[#135bec] hover:bg-[#135bec]/5 transition-colors">
                    <span class="material-symbols-outlined text-xl text-[#135bec]">format_quote</span>
                    <span class="text-xs font-medium text-gray-700">Quote</span>
                </button>
                <button type="button" data-action="add-block" data-block-type="list" class="flex flex-col items-center gap-1 p-3 border border-gray-200 rounded-lg hover:border-[#135bec] hover:bg-[#135bec]/5 transition-colors">
                    <span class="material-symbols-outlined text-xl text-[#135bec]">format_list_bulleted</span>
                    <span class="text-xs font-medium text-gray-700">List</span>
                </button>
                <button type="button" data-action="add-block" data-block-type="columns" class="flex flex-col items-center gap-1 p-3 border border-gray-200 rounded-lg hover:border-[#135bec] hover:bg-[#135bec]/5 transition-colors">
                    <span class="material-symbols-outlined text-xl text-[#135bec]">view_column</span>
                    <span class="text-xs font-medium text-gray-700">Columns</span>
                </button>
            </div>
        </div>
        
        <!-- Media -->
        <div class="mb-4">
            <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Media</h4>
            <div class="grid grid-cols-4 gap-2">
                <button type="button" data-action="add-block" data-block-type="image" class="flex flex-col items-center gap-1 p-3 border border-gray-200 rounded-lg hover:border-[#135bec] hover:bg-[#135bec]/5 transition-colors">
                    <span class="material-symbols-outlined text-xl text-[#135bec]">image</span>
                    <span class="text-xs font-medium text-gray-700">Image</span>
                </button>
                <button type="button" data-action="add-block" data-block-type="gallery" class="flex flex-col items-center gap-1 p-3 border border-gray-200 rounded-lg hover:border-[#135bec] hover:bg-[#135bec]/5 transition-colors">
                    <span class="material-symbols-outlined text-xl text-[#135bec]">photo_library</span>
                    <span class="text-xs font-medium text-gray-700">Gallery</span>
                </button>
                <button type="button" data-action="add-block" data-block-type="video" class="flex flex-col items-center gap-1 p-3 border border-gray-200 rounded-lg hover:border-[#135bec] hover:bg-[#135bec]/5 transition-colors">
                    <span class="material-symbols-outlined text-xl text-[#135bec]">play_circle</span>
                    <span class="text-xs font-medium text-gray-700">Video</span>
                </button>
                <button type="button" data-action="add-block" data-block-type="carousel" class="flex flex-col items-center gap-1 p-3 border border-gray-200 rounded-lg hover:border-[#135bec] hover:bg-[#135bec]/5 transition-colors">
                    <span class="material-symbols-outlined text-xl text-[#135bec]">view_carousel</span>
                    <span class="text-xs font-medium text-gray-700">Carousel</span>
                </button>
            </div>
        </div>
        
        <!-- Features & Benefits -->
        <div class="mb-4">
            <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Features & Benefits</h4>
            <div class="grid grid-cols-4 gap-2">
                <button type="button" data-action="add-block" data-block-type="features" class="flex flex-col items-center gap-1 p-3 border border-gray-200 rounded-lg hover:border-[#135bec] hover:bg-[#135bec]/5 transition-colors">
                    <span class="material-symbols-outlined text-xl text-[#135bec]">star</span>
                    <span class="text-xs font-medium text-gray-700">Features</span>
                </button>
                <button type="button" data-action="add-block" data-block-type="cards" class="flex flex-col items-center gap-1 p-3 border border-gray-200 rounded-lg hover:border-[#135bec] hover:bg-[#135bec]/5 transition-colors">
                    <span class="material-symbols-outlined text-xl text-[#135bec]">grid_view</span>
                    <span class="text-xs font-medium text-gray-700">Cards</span>
                </button>
                <button type="button" data-action="add-block" data-block-type="stats" class="flex flex-col items-center gap-1 p-3 border border-gray-200 rounded-lg hover:border-[#135bec] hover:bg-[#135bec]/5 transition-colors">
                    <span class="material-symbols-outlined text-xl text-[#135bec]">analytics</span>
                    <span class="text-xs font-medium text-gray-700">Stats</span>
                </button>
                <button type="button" data-action="add-block" data-block-type="checklist" class="flex flex-col items-center gap-1 p-3 border border-gray-200 rounded-lg hover:border-[#135bec] hover:bg-[#135bec]/5 transition-colors">
                    <span class="material-symbols-outlined text-xl text-[#135bec]">checklist</span>
                    <span class="text-xs font-medium text-gray-700">Checklist</span>
                </button>
                <button type="button" data-action="add-block" data-block-type="steps" class="flex flex-col items-center gap-1 p-3 border border-gray-200 rounded-lg hover:border-[#135bec] hover:bg-[#135bec]/5 transition-colors">
                    <span class="material-symbols-outlined text-xl text-[#135bec]">format_list_numbered</span>
                    <span class="text-xs font-medium text-gray-700">Steps</span>
                </button>
                <button type="button" data-action="add-block" data-block-type="progress" class="flex flex-col items-center gap-1 p-3 border border-gray-200 rounded-lg hover:border-[#135bec] hover:bg-[#135bec]/5 transition-colors">
                    <span class="material-symbols-outlined text-xl text-[#135bec]">donut_large</span>
                    <span class="text-xs font-medium text-gray-700">Progress</span>
                </button>
            </div>
        </div>
        
        <!-- Social Proof -->
        <div class="mb-4">
            <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Social Proof</h4>
            <div class="grid grid-cols-4 gap-2">
                <button type="button" data-action="add-block" data-block-type="testimonials" class="flex flex-col items-center gap-1 p-3 border border-gray-200 rounded-lg hover:border-[#135bec] hover:bg-[#135bec]/5 transition-colors">
                    <span class="material-symbols-outlined text-xl text-[#135bec]">format_quote</span>
                    <span class="text-xs font-medium text-gray-700">Testimonials</span>
                </button>
                <button type="button" data-action="add-block" data-block-type="logo_cloud" class="flex flex-col items-center gap-1 p-3 border border-gray-200 rounded-lg hover:border-[#135bec] hover:bg-[#135bec]/5 transition-colors">
                    <span class="material-symbols-outlined text-xl text-[#135bec]">business</span>
                    <span class="text-xs font-medium text-gray-700">Logos</span>
                </button>
                <button type="button" data-action="add-block" data-block-type="team" class="flex flex-col items-center gap-1 p-3 border border-gray-200 rounded-lg hover:border-[#135bec] hover:bg-[#135bec]/5 transition-colors">
                    <span class="material-symbols-outlined text-xl text-[#135bec]">group</span>
                    <span class="text-xs font-medium text-gray-700">Team</span>
                </button>
            </div>
        </div>
        
        <!-- Pricing & Data -->
        <div class="mb-4">
            <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Pricing & Data</h4>
            <div class="grid grid-cols-4 gap-2">
                <button type="button" data-action="add-block" data-block-type="pricing" class="flex flex-col items-center gap-1 p-3 border border-gray-200 rounded-lg hover:border-[#135bec] hover:bg-[#135bec]/5 transition-colors">
                    <span class="material-symbols-outlined text-xl text-[#135bec]">payments</span>
                    <span class="text-xs font-medium text-gray-700">Pricing</span>
                </button>
                <button type="button" data-action="add-block" data-block-type="comparison" class="flex flex-col items-center gap-1 p-3 border border-gray-200 rounded-lg hover:border-[#135bec] hover:bg-[#135bec]/5 transition-colors">
                    <span class="material-symbols-outlined text-xl text-[#135bec]">compare</span>
                    <span class="text-xs font-medium text-gray-700">Compare</span>
                </button>
                <button type="button" data-action="add-block" data-block-type="table" class="flex flex-col items-center gap-1 p-3 border border-gray-200 rounded-lg hover:border-[#135bec] hover:bg-[#135bec]/5 transition-colors">
                    <span class="material-symbols-outlined text-xl text-[#135bec]">table_chart</span>
                    <span class="text-xs font-medium text-gray-700">Table</span>
                </button>
                <button type="button" data-action="add-block" data-block-type="timeline" class="flex flex-col items-center gap-1 p-3 border border-gray-200 rounded-lg hover:border-[#135bec] hover:bg-[#135bec]/5 transition-colors">
                    <span class="material-symbols-outlined text-xl text-[#135bec]">timeline</span>
                    <span class="text-xs font-medium text-gray-700">Timeline</span>
                </button>
            </div>
        </div>
        
        <!-- Navigation & Structure -->
        <div class="mb-4">
            <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Navigation & Structure</h4>
            <div class="grid grid-cols-4 gap-2">
                <button type="button" data-action="add-block" data-block-type="tabs" class="flex flex-col items-center gap-1 p-3 border border-gray-200 rounded-lg hover:border-[#135bec] hover:bg-[#135bec]/5 transition-colors">
                    <span class="material-symbols-outlined text-xl text-[#135bec]">tab</span>
                    <span class="text-xs font-medium text-gray-700">Tabs</span>
                </button>
                <button type="button" data-action="add-block" data-block-type="accordion" class="flex flex-col items-center gap-1 p-3 border border-gray-200 rounded-lg hover:border-[#135bec] hover:bg-[#135bec]/5 transition-colors">
                    <span class="material-symbols-outlined text-xl text-[#135bec]">expand_more</span>
                    <span class="text-xs font-medium text-gray-700">Accordion</span>
                </button>
                <button type="button" data-action="add-block" data-block-type="faq" class="flex flex-col items-center gap-1 p-3 border border-gray-200 rounded-lg hover:border-[#135bec] hover:bg-[#135bec]/5 transition-colors">
                    <span class="material-symbols-outlined text-xl text-[#135bec]">help</span>
                    <span class="text-xs font-medium text-gray-700">FAQ</span>
                </button>
                <button type="button" data-action="add-block" data-block-type="divider" class="flex flex-col items-center gap-1 p-3 border border-gray-200 rounded-lg hover:border-[#135bec] hover:bg-[#135bec]/5 transition-colors">
                    <span class="material-symbols-outlined text-xl text-[#135bec]">horizontal_rule</span>
                    <span class="text-xs font-medium text-gray-700">Divider</span>
                </button>
                <button type="button" data-action="add-block" data-block-type="spacer" class="flex flex-col items-center gap-1 p-3 border border-gray-200 rounded-lg hover:border-[#135bec] hover:bg-[#135bec]/5 transition-colors">
                    <span class="material-symbols-outlined text-xl text-[#135bec]">height</span>
                    <span class="text-xs font-medium text-gray-700">Spacer</span>
                </button>
            </div>
        </div>
        
        <!-- Forms & Contact -->
        <div class="mb-4">
            <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Forms & Contact</h4>
            <div class="grid grid-cols-4 gap-2">
                <button type="button" data-action="add-block" data-block-type="form" class="flex flex-col items-center gap-1 p-3 border border-gray-200 rounded-lg hover:border-[#135bec] hover:bg-[#135bec]/5 transition-colors">
                    <span class="material-symbols-outlined text-xl text-[#135bec]">contact_mail</span>
                    <span class="text-xs font-medium text-gray-700">Form</span>
                </button>
                <button type="button" data-action="add-block" data-block-type="newsletter" class="flex flex-col items-center gap-1 p-3 border border-gray-200 rounded-lg hover:border-[#135bec] hover:bg-[#135bec]/5 transition-colors">
                    <span class="material-symbols-outlined text-xl text-[#135bec]">mail</span>
                    <span class="text-xs font-medium text-gray-700">Newsletter</span>
                </button>
                <button type="button" data-action="add-block" data-block-type="contact_info" class="flex flex-col items-center gap-1 p-3 border border-gray-200 rounded-lg hover:border-[#135bec] hover:bg-[#135bec]/5 transition-colors">
                    <span class="material-symbols-outlined text-xl text-[#135bec]">contact_page</span>
                    <span class="text-xs font-medium text-gray-700">Contact</span>
                </button>
                <button type="button" data-action="add-block" data-block-type="map" class="flex flex-col items-center gap-1 p-3 border border-gray-200 rounded-lg hover:border-[#135bec] hover:bg-[#135bec]/5 transition-colors">
                    <span class="material-symbols-outlined text-xl text-[#135bec]">map</span>
                    <span class="text-xs font-medium text-gray-700">Map</span>
                </button>
                <button type="button" data-action="add-block" data-block-type="social" class="flex flex-col items-center gap-1 p-3 border border-gray-200 rounded-lg hover:border-[#135bec] hover:bg-[#135bec]/5 transition-colors">
                    <span class="material-symbols-outlined text-xl text-[#135bec]">share</span>
                    <span class="text-xs font-medium text-gray-700">Social</span>
                </button>
                <button type="button" data-action="add-block" data-block-type="download" class="flex flex-col items-center gap-1 p-3 border border-gray-200 rounded-lg hover:border-[#135bec] hover:bg-[#135bec]/5 transition-colors">
                    <span class="material-symbols-outlined text-xl text-[#135bec]">download</span>
                    <span class="text-xs font-medium text-gray-700">Download</span>
                </button>
            </div>
        </div>
        
        <!-- Feedback -->
        <div class="mb-4">
            <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Feedback & Alerts</h4>
            <div class="grid grid-cols-4 gap-2">
                <button type="button" data-action="add-block" data-block-type="alert" class="flex flex-col items-center gap-1 p-3 border border-gray-200 rounded-lg hover:border-[#135bec] hover:bg-[#135bec]/5 transition-colors">
                    <span class="material-symbols-outlined text-xl text-[#135bec]">warning</span>
                    <span class="text-xs font-medium text-gray-700">Alert</span>
                </button>
            </div>
        </div>
        
        <button type="button" data-action="hide-block-picker" 
                class="mt-4 w-full px-4 py-2.5 bg-gray-100 text-gray-700 font-medium rounded-lg hover:bg-gray-200 transition-colors">
            Cancel
        </button>
    </div>
</div>

<!-- Media Picker Modal -->
<div id="media-picker" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-xl max-w-4xl w-full max-h-[80vh] flex flex-col">
        <div class="flex items-center justify-between p-4 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-900">Select Image</h3>
            <button type="button" id="close-media-picker" class="p-1 text-gray-500 hover:text-gray-700 rounded">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        
        <!-- Upload Zone -->
        <div id="media-upload-zone" class="mx-4 mt-4 border-2 border-dashed border-gray-300 rounded-lg p-4 text-center bg-gray-50 hover:border-[#135bec] hover:bg-blue-50/30 cursor-pointer">
            <p class="text-gray-700 text-sm"><span class="text-[#135bec] font-medium">Click to upload</span> or drag and drop</p>
            <input type="file" id="media-file-input" accept="image/*" class="hidden">
        </div>
        
        <div class="flex-1 overflow-y-auto p-4">
            <div id="media-grid" class="grid grid-cols-4 md:grid-cols-5 lg:grid-cols-6 gap-3">
                <!-- Media items loaded via JS -->
            </div>
            <div id="media-loading" class="text-center text-gray-500 py-8">Loading media...</div>
            <div id="media-empty" class="hidden text-center text-gray-500 py-8">No images found. Upload one above.</div>
        </div>
    </div>
</div>

<!-- AI Generate Modal -->
<div id="ai-generate-modal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between p-4 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                <span class="material-symbols-outlined text-purple-600">auto_awesome</span>
                AI Generate Page
            </h3>
            <button type="button" id="close-ai-modal" class="p-1 text-gray-500 hover:text-gray-700 rounded">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <div class="p-6 space-y-4">
            <!-- Page Type Selection -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Page Type</label>
                <select id="ai-page-type" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500 outline-none">
                    <option value="landing">Landing Page (hero, features, testimonials, CTA)</option>
                    <option value="about">About Page (story, team, values, timeline)</option>
                    <option value="services">Services Page (offerings, pricing, process)</option>
                    <option value="pricing">Pricing Page (plans, comparison, FAQ)</option>
                    <option value="contact">Contact Page (form, map, info)</option>
                    <option value="portfolio">Portfolio/Gallery (showcase, case studies)</option>
                    <option value="blog">Blog/Article (content, author, related)</option>
                    <option value="faq">FAQ Page (questions, categories)</option>
                    <option value="team">Team Page (members, culture, careers)</option>
                    <option value="product">Product Page (features, specs, reviews)</option>
                    <option value="custom">Custom (AI decides structure)</option>
                </select>
                <p class="text-xs text-gray-500 mt-1">Select a page type for optimized block recommendations.</p>
            </div>
            
            <!-- Recommended Blocks Preview -->
            <div id="ai-block-recommendations" class="p-3 bg-purple-50 rounded-lg">
                <p class="text-xs font-medium text-purple-700 mb-2">Recommended blocks for this page type:</p>
                <div id="ai-recommended-blocks" class="flex flex-wrap gap-1 text-xs"></div>
            </div>
            
            <!-- Description -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Describe your page</label>
                <textarea id="ai-prompt" rows="4" placeholder="e.g., A services page for a digital marketing agency showcasing SEO, PPC, and social media marketing services with pricing tiers and a contact form..."
                          class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500 outline-none resize-y"></textarea>
                <p class="text-xs text-gray-500 mt-1">Be specific about the content, tone, and sections you want.</p>
            </div>
            
            <!-- Advanced Options -->
            <details class="border border-gray-200 rounded-lg">
                <summary class="px-4 py-2 text-sm font-medium text-gray-700 cursor-pointer hover:bg-gray-50">Advanced Options</summary>
                <div class="p-4 pt-2 space-y-3 border-t border-gray-200">
                    <div class="flex items-center gap-3">
                        <input type="checkbox" id="ai-multi-stage" checked class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                        <label for="ai-multi-stage" class="text-sm text-gray-700">Multi-stage generation (better quality for complex pages)</label>
                    </div>
                    <div class="flex items-center gap-3">
                        <input type="checkbox" id="ai-detailed-content" checked class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                        <label for="ai-detailed-content" class="text-sm text-gray-700">Generate detailed content (more text, real examples)</label>
                    </div>
                </div>
            </details>
            
            <div id="ai-error" class="hidden p-3 bg-red-50 border border-red-200 rounded-lg text-red-700 text-sm"></div>
            
            <!-- Multi-Stage Progress -->
            <div id="ai-progress" class="hidden space-y-3">
                <div class="p-4 bg-purple-50 rounded-lg">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="animate-spin rounded-full h-5 w-5 border-2 border-purple-600 border-t-transparent"></div>
                        <span id="ai-progress-text" class="text-purple-700 font-medium">Planning page layout...</span>
                    </div>
                    <div class="space-y-2">
                        <div class="flex items-center gap-2">
                            <div id="stage-1-icon" class="w-5 h-5 rounded-full bg-purple-200 flex items-center justify-center">
                                <span class="material-symbols-outlined text-sm text-purple-600">hourglass_top</span>
                            </div>
                            <span class="text-sm text-gray-600">Stage 1: Plan layout & structure</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <div id="stage-2-icon" class="w-5 h-5 rounded-full bg-gray-200 flex items-center justify-center">
                                <span class="material-symbols-outlined text-sm text-gray-400">circle</span>
                            </div>
                            <span class="text-sm text-gray-400">Stage 2: Generate page metadata</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <div id="stage-3-icon" class="w-5 h-5 rounded-full bg-gray-200 flex items-center justify-center">
                                <span class="material-symbols-outlined text-sm text-gray-400">circle</span>
                            </div>
                            <span class="text-sm text-gray-400">Stage 3: Create content blocks</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="flex gap-3 p-4 border-t border-gray-200">
            <button type="button" id="cancel-ai" class="flex-1 px-4 py-2.5 bg-gray-100 text-gray-700 font-medium rounded-lg hover:bg-gray-200 transition-colors">
                Cancel
            </button>
            <button type="button" id="submit-ai" class="flex-1 px-4 py-2.5 bg-gradient-to-r from-purple-600 to-indigo-600 text-white font-medium rounded-lg hover:from-purple-700 hover:to-indigo-700 transition-all">
                Generate
            </button>
        </div>
    </div>
</div>

<!-- Quill WYSIWYG -->
<link href="/cdn/quill.snow.css" rel="stylesheet">
<script src="/cdn/quill.min.js"></script>
<script nonce="{{csp_nonce}}">
let blockCounter = {{block_count}};
const blockTemplates = {
    hero: `<div class="space-y-3">
           <div><label class="block text-sm font-medium text-gray-700 mb-1">Heading</label><input type="text" name="blocks[NEW_ID][data][title]" placeholder="Welcome to our site" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"></div>
           <div><label class="block text-sm font-medium text-gray-700 mb-1">Subtitle</label><input type="text" name="blocks[NEW_ID][data][subtitle]" placeholder="We make amazing things" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"></div>
           <div class="grid grid-cols-2 gap-3"><div><label class="block text-sm font-medium text-gray-700 mb-1">Button Text</label><input type="text" name="blocks[NEW_ID][data][button]" placeholder="Learn More" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"></div><div><label class="block text-sm font-medium text-gray-700 mb-1">Button URL</label><input type="text" name="blocks[NEW_ID][data][url]" placeholder="/about" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"></div></div></div>`,
    text: `<div><label class="block text-sm font-medium text-gray-700 mb-1">Content</label><div class="wysiwyg-editor bg-white border border-gray-300 rounded-lg" data-field="NEW_ID"></div><textarea name="blocks[NEW_ID][data][content]" class="wysiwyg-content hidden"></textarea></div>`,
    image: `<div class="space-y-3">
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Image URL</label><input type="text" name="blocks[NEW_ID][data][url]" placeholder="/assets/..." class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Alt Text</label><input type="text" name="blocks[NEW_ID][data][alt]" placeholder="Image description" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Caption</label><input type="text" name="blocks[NEW_ID][data][caption]" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"></div></div>`,
    cta: `<div class="space-y-3">
          <div><label class="block text-sm font-medium text-gray-700 mb-1">Title</label><input type="text" name="blocks[NEW_ID][data][title]" placeholder="Ready to get started?" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"></div>
          <div><label class="block text-sm font-medium text-gray-700 mb-1">Text</label><textarea name="blocks[NEW_ID][data][text]" rows="2" placeholder="Join thousands of satisfied customers..." class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"></textarea></div>
          <div class="grid grid-cols-2 gap-3"><div><label class="block text-sm font-medium text-gray-700 mb-1">Button Text</label><input type="text" name="blocks[NEW_ID][data][button]" placeholder="Get Started" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"></div><div><label class="block text-sm font-medium text-gray-700 mb-1">Button URL</label><input type="text" name="blocks[NEW_ID][data][url]" placeholder="/contact" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"></div></div></div>`,
    gallery: `<div class="space-y-3">
              <div><label class="block text-sm font-medium text-gray-700 mb-1">Images (one URL per line)</label><textarea name="blocks[NEW_ID][data][images]" rows="4" placeholder="/assets/img1.jpg&#10;/assets/img2.jpg" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"></textarea></div>
              <div><label class="block text-sm font-medium text-gray-700 mb-1">Columns</label><select name="blocks[NEW_ID][data][columns]" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"><option value="2">2</option><option value="3" selected>3</option><option value="4">4</option></select></div></div>`,
    form: `<div class="p-4 bg-blue-50 rounded-lg"><p class="text-sm text-blue-700 flex items-center gap-2"><span class="material-symbols-outlined">info</span>This block displays a contact form. No additional configuration needed.</p></div>`,
    features: `<div class="space-y-3">
               <div><label class="block text-sm font-medium text-gray-700 mb-1">Section Title</label><input type="text" name="blocks[NEW_ID][data][title]" placeholder="Our Features" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"></div>
               <div><label class="block text-sm font-medium text-gray-700 mb-1">Items (JSON array)</label><textarea name="blocks[NEW_ID][data][items_json]" rows="6" placeholder='[{"icon": "⚡", "title": "Feature 1", "description": "Description here"}]' class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none font-mono text-sm"></textarea></div></div>`,
    stats: `<div><label class="block text-sm font-medium text-gray-700 mb-1">Stats Items (JSON array)</label><textarea name="blocks[NEW_ID][data][items_json]" rows="4" placeholder='[{"value": "100+", "label": "Customers"}]' class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none font-mono text-sm"></textarea></div>`,
    testimonials: `<div><label class="block text-sm font-medium text-gray-700 mb-1">Testimonials (JSON array)</label><textarea name="blocks[NEW_ID][data][items_json]" rows="6" placeholder='[{"quote": "Great product!", "author": "John Doe", "role": "CEO"}]' class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none font-mono text-sm"></textarea></div>`,
    pricing: `<div class="space-y-3">
              <div><label class="block text-sm font-medium text-gray-700 mb-1">Section Title</label><input type="text" name="blocks[NEW_ID][data][title]" placeholder="Pricing Plans" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"></div>
              <div><label class="block text-sm font-medium text-gray-700 mb-1">Plans (JSON array)</label><textarea name="blocks[NEW_ID][data][plans_json]" rows="8" placeholder='[{"name": "Basic", "price": "$9/mo", "features": ["Feature 1"], "button": "Get Started", "url": "/signup"}]' class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none font-mono text-sm"></textarea></div></div>`,
    team: `<div class="space-y-3">
           <div><label class="block text-sm font-medium text-gray-700 mb-1">Section Title</label><input type="text" name="blocks[NEW_ID][data][title]" placeholder="Our Team" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"></div>
           <div><label class="block text-sm font-medium text-gray-700 mb-1">Members (JSON array)</label><textarea name="blocks[NEW_ID][data][members_json]" rows="6" placeholder='[{"name": "John Doe", "role": "CEO", "bio": "Bio here"}]' class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none font-mono text-sm"></textarea></div></div>`,
    cards: `<div class="space-y-3">
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Section Title</label><input type="text" name="blocks[NEW_ID][data][title]" placeholder="Our Services" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Cards (JSON array)</label><textarea name="blocks[NEW_ID][data][items_json]" rows="6" placeholder='[{"icon": "🎯", "title": "Card 1", "description": "Description"}]' class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none font-mono text-sm"></textarea></div></div>`,
    faq: `<div class="space-y-3">
          <div><label class="block text-sm font-medium text-gray-700 mb-1">Section Title</label><input type="text" name="blocks[NEW_ID][data][title]" placeholder="FAQ" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"></div>
          <div><label class="block text-sm font-medium text-gray-700 mb-1">Questions (JSON array)</label><textarea name="blocks[NEW_ID][data][items_json]" rows="6" placeholder='[{"question": "How does it work?", "answer": "It works by..."}]' class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none font-mono text-sm"></textarea></div></div>`,
    // === NEW BLOCK TYPES ===
    quote: `<div class="space-y-3">
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Quote Text</label><textarea name="blocks[NEW_ID][data][text]" rows="3" placeholder="Inspirational quote..." class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"></textarea></div>
            <div class="grid grid-cols-2 gap-3"><div><label class="block text-sm font-medium text-gray-700 mb-1">Author</label><input type="text" name="blocks[NEW_ID][data][author]" placeholder="Author Name" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"></div><div><label class="block text-sm font-medium text-gray-700 mb-1">Role/Title</label><input type="text" name="blocks[NEW_ID][data][role]" placeholder="CEO, Company" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"></div></div></div>`,
    divider: `<div class="space-y-3">
              <div><label class="block text-sm font-medium text-gray-700 mb-1">Style</label><select name="blocks[NEW_ID][data][style]" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"><option value="line">Line</option><option value="dots">Dots</option><option value="icon">Icon</option></select></div>
              <div><label class="block text-sm font-medium text-gray-700 mb-1">Icon (for icon style)</label><input type="text" name="blocks[NEW_ID][data][icon]" placeholder="⭐" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"></div></div>`,
    video: `<div class="space-y-3">
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Title</label><input type="text" name="blocks[NEW_ID][data][title]" placeholder="Watch Our Story" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Video Embed URL</label><input type="text" name="blocks[NEW_ID][data][url]" placeholder="https://youtube.com/embed/..." class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Caption</label><input type="text" name="blocks[NEW_ID][data][caption]" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"></div></div>`,
    carousel: `<div><label class="block text-sm font-medium text-gray-700 mb-1">Slides (JSON array)</label><textarea name="blocks[NEW_ID][data][items_json]" rows="6" placeholder='[{"image": "/slide1.jpg", "title": "Slide 1", "text": "Description"}]' class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none font-mono text-sm"></textarea></div>`,
    checklist: `<div class="space-y-3">
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Title</label><input type="text" name="blocks[NEW_ID][data][title]" placeholder="What's Included" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"></div>
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Items (one per line)</label><textarea name="blocks[NEW_ID][data][items_text]" rows="5" placeholder="Feature one\nFeature two\nFeature three" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"></textarea></div></div>`,
    logo_cloud: `<div class="space-y-3">
                 <div><label class="block text-sm font-medium text-gray-700 mb-1">Title</label><input type="text" name="blocks[NEW_ID][data][title]" placeholder="Trusted By" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"></div>
                 <div><label class="block text-sm font-medium text-gray-700 mb-1">Logos (JSON array)</label><textarea name="blocks[NEW_ID][data][items_json]" rows="4" placeholder='[{"name": "Company", "url": "/logo.png"}]' class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none font-mono text-sm"></textarea></div></div>`,
    comparison: `<div class="space-y-3">
                 <div><label class="block text-sm font-medium text-gray-700 mb-1">Title</label><input type="text" name="blocks[NEW_ID][data][title]" placeholder="Compare Plans" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"></div>
                 <div><label class="block text-sm font-medium text-gray-700 mb-1">Headers (JSON array)</label><input type="text" name="blocks[NEW_ID][data][headers_json]" placeholder='["Feature", "Basic", "Pro"]' class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none font-mono text-sm"></div>
                 <div><label class="block text-sm font-medium text-gray-700 mb-1">Rows (JSON array of arrays)</label><textarea name="blocks[NEW_ID][data][rows_json]" rows="4" placeholder='[["Storage", "10GB", "100GB"], ["Support", "Email", "24/7"]]' class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none font-mono text-sm"></textarea></div></div>`,
    tabs: `<div><label class="block text-sm font-medium text-gray-700 mb-1">Tabs (JSON array)</label><textarea name="blocks[NEW_ID][data][items_json]" rows="6" placeholder='[{"label": "Tab 1", "content": "<p>Tab content...</p>"}]' class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none font-mono text-sm"></textarea></div>`,
    accordion: `<div class="space-y-3">
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Title</label><input type="text" name="blocks[NEW_ID][data][title]" placeholder="Learn More" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"></div>
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Sections (JSON array)</label><textarea name="blocks[NEW_ID][data][items_json]" rows="6" placeholder='[{"title": "Section 1", "content": "<p>Content...</p>"}]' class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none font-mono text-sm"></textarea></div></div>`,
    table: `<div class="space-y-3">
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Title</label><input type="text" name="blocks[NEW_ID][data][title]" placeholder="Data Table" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Headers (JSON array)</label><input type="text" name="blocks[NEW_ID][data][headers_json]" placeholder='["Name", "Price", "Status"]' class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none font-mono text-sm"></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Rows (JSON array of arrays)</label><textarea name="blocks[NEW_ID][data][rows_json]" rows="4" placeholder='[["Item 1", "$10", "Active"]]' class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none font-mono text-sm"></textarea></div></div>`,
    timeline: `<div class="space-y-3">
               <div><label class="block text-sm font-medium text-gray-700 mb-1">Title</label><input type="text" name="blocks[NEW_ID][data][title]" placeholder="Our Journey" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"></div>
               <div><label class="block text-sm font-medium text-gray-700 mb-1">Events (JSON array)</label><textarea name="blocks[NEW_ID][data][items_json]" rows="6" placeholder='[{"date": "2024", "title": "Founded", "description": "We started..."}]' class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none font-mono text-sm"></textarea></div></div>`,
    list: `<div class="space-y-3">
           <div><label class="block text-sm font-medium text-gray-700 mb-1">Title</label><input type="text" name="blocks[NEW_ID][data][title]" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"></div>
           <div><label class="block text-sm font-medium text-gray-700 mb-1">Style</label><select name="blocks[NEW_ID][data][style]" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"><option value="bullet">Bullet</option><option value="number">Numbered</option><option value="check">Checkmarks</option></select></div>
           <div><label class="block text-sm font-medium text-gray-700 mb-1">Items (one per line)</label><textarea name="blocks[NEW_ID][data][items_text]" rows="5" placeholder="First item\nSecond item\nThird item" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"></textarea></div></div>`,
    newsletter: `<div class="space-y-3">
                 <div><label class="block text-sm font-medium text-gray-700 mb-1">Title</label><input type="text" name="blocks[NEW_ID][data][title]" placeholder="Stay Updated" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"></div>
                 <div><label class="block text-sm font-medium text-gray-700 mb-1">Text</label><textarea name="blocks[NEW_ID][data][text]" rows="2" placeholder="Subscribe to our newsletter..." class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"></textarea></div>
                 <div class="grid grid-cols-2 gap-3"><div><label class="block text-sm font-medium text-gray-700 mb-1">Button Text</label><input type="text" name="blocks[NEW_ID][data][button]" value="Subscribe" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"></div><div><label class="block text-sm font-medium text-gray-700 mb-1">Placeholder</label><input type="text" name="blocks[NEW_ID][data][placeholder]" value="Enter your email" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"></div></div></div>`,
    download: `<div class="space-y-3">
               <div><label class="block text-sm font-medium text-gray-700 mb-1">Title</label><input type="text" name="blocks[NEW_ID][data][title]" placeholder="Download Our Guide" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"></div>
               <div><label class="block text-sm font-medium text-gray-700 mb-1">Description</label><textarea name="blocks[NEW_ID][data][description]" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"></textarea></div>
               <div class="grid grid-cols-2 gap-3"><div><label class="block text-sm font-medium text-gray-700 mb-1">File URL</label><input type="text" name="blocks[NEW_ID][data][file]" placeholder="/files/guide.pdf" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"></div><div><label class="block text-sm font-medium text-gray-700 mb-1">Button Text</label><input type="text" name="blocks[NEW_ID][data][button]" value="Download Now" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"></div></div></div>`,
    alert: `<div class="space-y-3">
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Type</label><select name="blocks[NEW_ID][data][type]" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"><option value="info">Info</option><option value="success">Success</option><option value="warning">Warning</option><option value="error">Error</option></select></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Title</label><input type="text" name="blocks[NEW_ID][data][title]" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Message</label><textarea name="blocks[NEW_ID][data][text]" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"></textarea></div></div>`,
    progress: `<div class="space-y-3">
               <div><label class="block text-sm font-medium text-gray-700 mb-1">Title</label><input type="text" name="blocks[NEW_ID][data][title]" placeholder="Our Skills" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"></div>
               <div><label class="block text-sm font-medium text-gray-700 mb-1">Progress Items (JSON array)</label><textarea name="blocks[NEW_ID][data][items_json]" rows="4" placeholder='[{"label": "Design", "value": 90}, {"label": "Development", "value": 85}]' class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none font-mono text-sm"></textarea></div></div>`,
    steps: `<div class="space-y-3">
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Title</label><input type="text" name="blocks[NEW_ID][data][title]" placeholder="How It Works" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Steps (JSON array)</label><textarea name="blocks[NEW_ID][data][items_json]" rows="6" placeholder='[{"number": 1, "title": "Sign Up", "description": "Create account"}]' class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none font-mono text-sm"></textarea></div></div>`,
    columns: `<div><label class="block text-sm font-medium text-gray-700 mb-1">Columns (JSON array)</label><textarea name="blocks[NEW_ID][data][columns_json]" rows="6" placeholder='[{"content": "<p>Column 1</p>"}, {"content": "<p>Column 2</p>"}]' class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none font-mono text-sm"></textarea></div>`,
    spacer: `<div><label class="block text-sm font-medium text-gray-700 mb-1">Size</label><select name="blocks[NEW_ID][data][size]" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"><option value="small">Small (1rem)</option><option value="medium" selected>Medium (2rem)</option><option value="large">Large (4rem)</option><option value="xlarge">Extra Large (6rem)</option></select></div>`,
    map: `<div class="space-y-3">
          <div><label class="block text-sm font-medium text-gray-700 mb-1">Title</label><input type="text" name="blocks[NEW_ID][data][title]" placeholder="Find Us" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"></div>
          <div><label class="block text-sm font-medium text-gray-700 mb-1">Address</label><input type="text" name="blocks[NEW_ID][data][address]" placeholder="123 Main St, City, Country" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"></div>
          <div><label class="block text-sm font-medium text-gray-700 mb-1">Google Maps Embed URL</label><input type="text" name="blocks[NEW_ID][data][embed]" placeholder="https://maps.google.com/maps?..." class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"></div></div>`,
    contact_info: `<div class="space-y-3">
                   <div><label class="block text-sm font-medium text-gray-700 mb-1">Title</label><input type="text" name="blocks[NEW_ID][data][title]" placeholder="Contact Information" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"></div>
                   <div><label class="block text-sm font-medium text-gray-700 mb-1">Contact Items (JSON array)</label><textarea name="blocks[NEW_ID][data][items_json]" rows="5" placeholder='[{"icon": "📧", "label": "Email", "value": "hello@example.com", "url": "mailto:hello@example.com"}]' class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none font-mono text-sm"></textarea></div></div>`,
    social: `<div class="space-y-3">
             <div><label class="block text-sm font-medium text-gray-700 mb-1">Title</label><input type="text" name="blocks[NEW_ID][data][title]" placeholder="Follow Us" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none"></div>
             <div><label class="block text-sm font-medium text-gray-700 mb-1">Social Links (JSON array)</label><textarea name="blocks[NEW_ID][data][items_json]" rows="4" placeholder='[{"platform": "twitter", "url": "https://twitter.com/...", "icon": "🐦"}]' class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none font-mono text-sm"></textarea></div></div>`
};
const typeLabels = {
    hero:'Hero Section',text:'Text Content',image:'Image',cta:'Call to Action',gallery:'Gallery',form:'Contact Form',
    features:'Features',stats:'Statistics',testimonials:'Testimonials',pricing:'Pricing',team:'Team',cards:'Cards',faq:'FAQ',
    quote:'Quote',divider:'Divider',video:'Video',carousel:'Carousel',checklist:'Checklist',logo_cloud:'Logo Cloud',
    comparison:'Comparison Table',tabs:'Tabs',accordion:'Accordion',table:'Data Table',timeline:'Timeline',list:'List',
    newsletter:'Newsletter',download:'Download',alert:'Alert',progress:'Progress Bars',steps:'Steps',
    columns:'Columns',spacer:'Spacer',map:'Map',contact_info:'Contact Info',social:'Social Links'
};
const typeIcons = {
    hero:'featured_video',text:'article',image:'image',cta:'campaign',gallery:'photo_library',form:'contact_mail',
    features:'star',stats:'analytics',testimonials:'format_quote',pricing:'payments',team:'group',cards:'grid_view',faq:'help',
    quote:'format_quote',divider:'horizontal_rule',video:'play_circle',carousel:'view_carousel',checklist:'checklist',
    logo_cloud:'business',comparison:'compare',tabs:'tab',accordion:'expand_more',table:'table_chart',timeline:'timeline',
    list:'format_list_bulleted',newsletter:'mail',download:'download',alert:'warning',progress:'donut_large',steps:'format_list_numbered',
    columns:'view_column',spacer:'height',map:'map',contact_info:'contact_page',social:'share'
};

function showBlockPicker() { document.getElementById('block-picker').classList.remove('hidden'); }
function hideBlockPicker() { document.getElementById('block-picker').classList.add('hidden'); }

function addBlock(type) {
    blockCounter++;
    const id = 'new_' + blockCounter;
    const template = blockTemplates[type].replace(/NEW_ID/g, id);
    const container = document.getElementById('blocks-container');
    const order = container.children.length;
    
    const div = document.createElement('div');
    div.className = 'block-item bg-gray-50 border border-gray-200 rounded-lg p-4';
    div.dataset.blockId = id;
    div.innerHTML = `
        <div class="flex items-center justify-between mb-3 pb-3 border-b border-gray-200">
            <span class="inline-flex items-center gap-2 px-3 py-1 bg-[#135bec]/10 text-[#135bec] rounded-full text-sm font-medium">
                <span class="material-symbols-outlined text-lg">${typeIcons[type]}</span>
                ${typeLabels[type]}
            </span>
            <div class="flex items-center gap-1">
                <button type="button" data-action="move-block-up" class="p-1.5 text-gray-500 hover:text-gray-700 hover:bg-gray-200 rounded transition-colors" title="Move up">
                    <span class="material-symbols-outlined text-lg">arrow_upward</span>
                </button>
                <button type="button" data-action="move-block-down" class="p-1.5 text-gray-500 hover:text-gray-700 hover:bg-gray-200 rounded transition-colors" title="Move down">
                    <span class="material-symbols-outlined text-lg">arrow_downward</span>
                </button>
                <button type="button" data-action="delete-block" class="p-1.5 text-red-500 hover:text-red-700 hover:bg-red-50 rounded transition-colors" title="Delete">
                    <span class="material-symbols-outlined text-lg">delete</span>
                </button>
            </div>
        </div>
        <input type="hidden" name="blocks[${id}][id]" value="">
        <input type="hidden" name="blocks[${id}][type]" value="${type}">
        <input type="hidden" name="blocks[${id}][order]" class="block-order" value="${order}">
        <div class="block-fields space-y-3">
            ${template}
        </div>
    `;
    container.appendChild(div);
    hideBlockPicker();
    
    // Initialize WYSIWYG for text blocks
    if (type === 'text') {
        initWysiwyg(div.querySelector('.wysiwyg-editor'));
    }
}

function deleteBlock(btn) {
    const block = btn.closest('.block-item');
    if (!block) return;
    if (confirm('Remove this block?')) {
        block.remove();
        updateBlockOrder();
    }
}

function moveBlockUp(btn) {
    const block = btn.closest('.block-item');
    if (!block) return;
    const prev = block.previousElementSibling;
    if (prev && prev.classList.contains('block-item')) {
        block.parentNode.insertBefore(block, prev);
        updateBlockOrder();
    }
}

function moveBlockDown(btn) {
    const block = btn.closest('.block-item');
    if (!block) return;
    const next = block.nextElementSibling;
    if (next && next.classList.contains('block-item')) {
        block.parentNode.insertBefore(next, block);
        updateBlockOrder();
    }
}

function updateBlockOrder() {
    document.querySelectorAll('.block-item').forEach((block, i) => {
        const orderInput = block.querySelector('.block-order');
        if (orderInput) orderInput.value = i;
    });
}

function initWysiwyg(el) {
    const quill = new Quill(el, {
        theme: 'snow',
        modules: { toolbar: [['bold', 'italic', 'underline'], ['link'], [{ 'list': 'ordered'}, { 'list': 'bullet' }], ['clean']] }
    });
    el._quill = quill;
}

// Initialize existing WYSIWYG editors
document.querySelectorAll('.wysiwyg-editor').forEach(initWysiwyg);

// Sync WYSIWYG content before submit
document.getElementById('page-form').addEventListener('submit', function() {
    document.querySelectorAll('.wysiwyg-editor').forEach(el => {
        if (el._quill) {
            const textarea = el.parentNode.querySelector('.wysiwyg-content');
            if (textarea) textarea.value = el._quill.root.innerHTML;
        }
    });
});

// Event delegation for all block actions
document.addEventListener('click', function(e) {
    const target = e.target.closest('[data-action]');
    if (!target) return;
    
    const action = target.dataset.action;
    
    switch(action) {
        case 'show-block-picker':
            showBlockPicker();
            break;
        case 'hide-block-picker':
            hideBlockPicker();
            break;
        case 'add-block':
            addBlock(target.dataset.blockType);
            break;
        case 'delete-block':
            deleteBlock(target);
            break;
        case 'move-block-up':
            moveBlockUp(target);
            break;
        case 'move-block-down':
            moveBlockDown(target);
            break;
    }
});

// ─── OG Image Media Picker ───────────────────────────────────────────────────
const mediaPicker = document.getElementById('media-picker');
const mediaGrid = document.getElementById('media-grid');
const mediaLoading = document.getElementById('media-loading');
const mediaEmpty = document.getElementById('media-empty');
const ogImageInput = document.getElementById('og-image-input');
const ogImagePreview = document.getElementById('og-image-preview');
const mediaUploadZone = document.getElementById('media-upload-zone');
const mediaFileInput = document.getElementById('media-file-input');
let mediaPickerCallback = null;

document.getElementById('select-og-image').addEventListener('click', () => {
    openMediaPicker(url => {
        ogImageInput.value = url;
        ogImagePreview.innerHTML = `<img src="${url}" alt="OG Image" class="max-h-[100px] object-contain">`;
    });
});

document.getElementById('clear-og-image').addEventListener('click', () => {
    ogImageInput.value = '';
    ogImagePreview.innerHTML = '<span class="text-gray-400 text-sm">No image selected</span>';
});

document.getElementById('close-media-picker').addEventListener('click', closeMediaPicker);
mediaPicker.addEventListener('click', e => { if (e.target === mediaPicker) closeMediaPicker(); });

function openMediaPicker(callback) {
    mediaPickerCallback = callback;
    mediaPicker.classList.remove('hidden');
    loadMediaItems();
}

function closeMediaPicker() {
    mediaPicker.classList.add('hidden');
    mediaPickerCallback = null;
}

function loadMediaItems() {
    mediaLoading.classList.remove('hidden');
    mediaEmpty.classList.add('hidden');
    mediaGrid.innerHTML = '';
    
    fetch('/api/media')
        .then(r => r.json())
        .then(data => {
            mediaLoading.classList.add('hidden');
            if (!data.assets || data.assets.length === 0) {
                mediaEmpty.classList.remove('hidden');
                return;
            }
            data.assets.filter(a => a.is_image).forEach(asset => {
                const div = document.createElement('div');
                div.className = 'aspect-square rounded-lg border-2 border-transparent hover:border-[#135bec] cursor-pointer overflow-hidden bg-gray-100';
                div.innerHTML = `<img src="/assets/${asset.hash}" alt="${asset.filename}" class="w-full h-full object-cover">`;
                div.addEventListener('click', () => {
                    if (mediaPickerCallback) mediaPickerCallback('/assets/' + asset.hash);
                    closeMediaPicker();
                });
                mediaGrid.appendChild(div);
            });
        })
        .catch(() => {
            mediaLoading.textContent = 'Error loading media';
        });
}

// Media upload in picker
mediaUploadZone.addEventListener('click', () => mediaFileInput.click());
mediaUploadZone.addEventListener('dragover', e => { e.preventDefault(); mediaUploadZone.classList.add('border-[#135bec]'); });
mediaUploadZone.addEventListener('dragleave', () => mediaUploadZone.classList.remove('border-[#135bec]'));
mediaUploadZone.addEventListener('drop', e => {
    e.preventDefault();
    mediaUploadZone.classList.remove('border-[#135bec]');
    uploadMediaFile(e.dataTransfer.files[0]);
});
mediaFileInput.addEventListener('change', () => uploadMediaFile(mediaFileInput.files[0]));

function uploadMediaFile(file) {
    if (!file) return;
    const formData = new FormData();
    formData.append('file', file);
    formData.append('_csrf', '{{csrf_token}}');
    
    mediaUploadZone.innerHTML = '<p class="text-sm text-gray-500">Uploading...</p>';
    
    fetch('/admin/media/upload', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            mediaUploadZone.innerHTML = '<p class="text-gray-700 text-sm"><span class="text-[#135bec] font-medium">Click to upload</span> or drag and drop</p>';
            if (data.success && data.url) {
                if (mediaPickerCallback) mediaPickerCallback(data.url);
                closeMediaPicker();
            } else {
                loadMediaItems();
            }
        })
        .catch(() => {
            mediaUploadZone.innerHTML = '<p class="text-red-500 text-sm">Upload failed</p>';
        });
}

// ─── Navigation Toggle ───────────────────────────────────────────────────────
const addToNavCheckbox = document.getElementById('add-to-nav');
const navPositionGroup = document.getElementById('nav-position-group');

if (addToNavCheckbox && navPositionGroup) {
    addToNavCheckbox.addEventListener('change', () => {
        navPositionGroup.classList.toggle('hidden', !addToNavCheckbox.checked);
    });
}

// ─── AI Page Generation ──────────────────────────────────────────────────────
const aiGenerateBtn = document.getElementById('ai-generate-btn');
const aiModal = document.getElementById('ai-generate-modal');
const aiPrompt = document.getElementById('ai-prompt');
const aiError = document.getElementById('ai-error');
const aiProgress = document.getElementById('ai-progress');
const submitAiBtn = document.getElementById('submit-ai');
const closeAiBtn = document.getElementById('close-ai-modal');
const cancelAiBtn = document.getElementById('cancel-ai');
const aiPageType = document.getElementById('ai-page-type');
const aiRecommendedBlocks = document.getElementById('ai-recommended-blocks');
const aiMultiStage = document.getElementById('ai-multi-stage');

// Block recommendations per page type
const pageTypeBlocks = {
    landing: ['hero', 'features', 'stats', 'testimonials', 'logo_cloud', 'pricing', 'faq', 'cta'],
    about: ['hero', 'text', 'timeline', 'team', 'stats', 'quote', 'gallery', 'cta'],
    services: ['hero', 'cards', 'features', 'steps', 'pricing', 'testimonials', 'faq', 'cta', 'form'],
    pricing: ['hero', 'pricing', 'comparison', 'features', 'faq', 'testimonials', 'cta'],
    contact: ['hero', 'form', 'map', 'contact_info', 'faq'],
    portfolio: ['hero', 'gallery', 'cards', 'testimonials', 'stats', 'cta'],
    blog: ['hero', 'text', 'image', 'quote', 'list', 'cta', 'newsletter'],
    faq: ['hero', 'faq', 'accordion', 'contact_info', 'cta'],
    team: ['hero', 'team', 'text', 'stats', 'testimonials', 'cta'],
    product: ['hero', 'features', 'gallery', 'stats', 'testimonials', 'comparison', 'pricing', 'faq', 'cta'],
    custom: ['hero', 'text', 'features', 'cta']
};

function updateBlockRecommendations() {
    const type = aiPageType?.value || 'landing';
    const blocks = pageTypeBlocks[type] || pageTypeBlocks.custom;
    if (aiRecommendedBlocks) {
        aiRecommendedBlocks.innerHTML = blocks.map(b => 
            `<span class="px-2 py-1 bg-purple-100 text-purple-700 rounded">${typeLabels[b] || b}</span>`
        ).join('');
    }
}

if (aiPageType) {
    aiPageType.addEventListener('change', updateBlockRecommendations);
    updateBlockRecommendations();
}

if (aiGenerateBtn) {
    aiGenerateBtn.addEventListener('click', () => {
        aiModal.classList.remove('hidden');
        updateBlockRecommendations();
    });
}
if (closeAiBtn) closeAiBtn.addEventListener('click', () => aiModal.classList.add('hidden'));
if (cancelAiBtn) cancelAiBtn.addEventListener('click', () => aiModal.classList.add('hidden'));
if (aiModal) aiModal.addEventListener('click', e => { if (e.target === aiModal) aiModal.classList.add('hidden'); });

// Update progress stage UI
function updateStageUI(stage, status) {
    const icon = document.getElementById(`stage-${stage}-icon`);
    if (!icon) return;
    if (status === 'active') {
        icon.className = 'w-5 h-5 rounded-full bg-purple-200 flex items-center justify-center';
        icon.innerHTML = '<span class="material-symbols-outlined text-sm text-purple-600 animate-spin">hourglass_top</span>';
    } else if (status === 'done') {
        icon.className = 'w-5 h-5 rounded-full bg-green-200 flex items-center justify-center';
        icon.innerHTML = '<span class="material-symbols-outlined text-sm text-green-600">check</span>';
    } else {
        icon.className = 'w-5 h-5 rounded-full bg-gray-200 flex items-center justify-center';
        icon.innerHTML = '<span class="material-symbols-outlined text-sm text-gray-400">circle</span>';
    }
}

function setProgressText(text) {
    const el = document.getElementById('ai-progress-text');
    if (el) el.textContent = text;
}

if (submitAiBtn) {
    submitAiBtn.addEventListener('click', async () => {
        const prompt = aiPrompt.value.trim();
        if (!prompt) {
            aiError.textContent = 'Please describe the page you want to create.';
            aiError.classList.remove('hidden');
            return;
        }
        
        const pageType = aiPageType?.value || 'custom';
        const multiStage = aiMultiStage?.checked ?? true;
        const recommendedBlocks = pageTypeBlocks[pageType] || pageTypeBlocks.custom;
        
        aiError.classList.add('hidden');
        aiProgress.classList.remove('hidden');
        submitAiBtn.disabled = true;
        
        // Reset stage indicators
        updateStageUI(1, 'active');
        updateStageUI(2, 'pending');
        updateStageUI(3, 'pending');
        setProgressText('Planning page layout...');
        
        try {
            const response = await fetch('/api/ai/generate-page', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    prompt, 
                    pageType,
                    multiStage,
                    recommendedBlocks,
                    _csrf: '{{csrf_token}}' 
                })
            });
            
            const data = await response.json();
            
            if (!data.success) {
                throw new Error(data.error || 'AI generation failed');
            }
            
            // Update progress for stage 2
            updateStageUI(1, 'done');
            updateStageUI(2, 'active');
            setProgressText('Generating page metadata...');
            await new Promise(r => setTimeout(r, 300)); // Brief visual pause
            
            // Fill in the form with generated content
            document.querySelector('input[name="title"]').value = data.title || '';
            document.getElementById('page-slug').value = data.slug || '';
            document.querySelector('textarea[name="meta_description"]').value = data.meta_description || '';
            
            // Update progress for stage 3
            updateStageUI(2, 'done');
            updateStageUI(3, 'active');
            setProgressText('Creating content blocks...');
            
            // Clear existing blocks and add generated ones
            const container = document.getElementById('blocks-container');
            container.innerHTML = '';
            
            if (data.blocks && data.blocks.length > 0) {
                for (let i = 0; i < data.blocks.length; i++) {
                    const block = data.blocks[i];
                    setProgressText(`Creating block ${i + 1} of ${data.blocks.length}...`);
                    addBlockWithData(block.type, block.content || block.data || {}, i);
                    await new Promise(r => setTimeout(r, 100)); // Visual feedback
                }
            }
            
            updateStageUI(3, 'done');
            setProgressText('Page generated successfully!');
            await new Promise(r => setTimeout(r, 500));
            
            // Auto-check add to nav if AI suggests it
            if (data.add_to_nav && addToNavCheckbox) {
                addToNavCheckbox.checked = true;
                navPositionGroup.classList.remove('hidden');
            }
            
            aiModal.classList.add('hidden');
            aiPrompt.value = '';
            
        } catch (err) {
            aiError.textContent = err.message || 'Failed to generate page. Please try again.';
            aiError.classList.remove('hidden');
        } finally {
            aiProgress.classList.add('hidden');
            submitAiBtn.disabled = false;
        }
    });
}

function addBlockWithData(type, data, order) {
    console.log('addBlockWithData called:', {type, data, order});
    const container = document.getElementById('blocks-container');
    const id = ++blockCounter;
    let template = blockTemplates[type] || '';
    template = template.replace(/NEW_ID/g, id);
    console.log('Block ID:', id, 'Template exists:', !!blockTemplates[type]);
    
    const div = document.createElement('div');
    div.className = 'block-item bg-gray-50 border border-gray-200 rounded-lg p-4';
    div.dataset.blockId = '';
    div.dataset.blockOrder = order;
    div.innerHTML = `
        <div class="flex items-center justify-between mb-3 pb-3 border-b border-gray-200">
            <span class="inline-flex items-center gap-2 px-3 py-1 bg-[#135bec]/10 text-[#135bec] rounded-full text-sm font-medium">
                <span class="material-symbols-outlined text-lg">${typeIcons[type] || 'widgets'}</span>
                ${typeLabels[type] || type.charAt(0).toUpperCase() + type.slice(1)}
            </span>
            <div class="flex items-center gap-1">
                <button type="button" data-action="move-block-up" class="p-1.5 text-gray-500 hover:text-gray-700 hover:bg-gray-200 rounded transition-colors" title="Move up">
                    <span class="material-symbols-outlined text-lg">arrow_upward</span>
                </button>
                <button type="button" data-action="move-block-down" class="p-1.5 text-gray-500 hover:text-gray-700 hover:bg-gray-200 rounded transition-colors" title="Move down">
                    <span class="material-symbols-outlined text-lg">arrow_downward</span>
                </button>
                <button type="button" data-action="delete-block" class="p-1.5 text-red-500 hover:text-red-700 hover:bg-red-50 rounded transition-colors" title="Delete">
                    <span class="material-symbols-outlined text-lg">delete</span>
                </button>
            </div>
        </div>
        <input type="hidden" name="blocks[${id}][id]" value="">
        <input type="hidden" name="blocks[${id}][type]" value="${type}">
        <input type="hidden" name="blocks[${id}][order]" class="block-order" value="${order}">
        <div class="block-fields space-y-3">
            ${template}
        </div>
    `;
    container.appendChild(div);
    
    // Fill in the data based on block type
    console.log('Filling data for type:', type, 'data:', data);
    
    // Helper to set field value
    const setField = (name, value) => {
        const el = div.querySelector(`[name="blocks[${id}][data][${name}]"]`);
        if (el && value !== undefined && value !== null) {
            el.value = typeof value === 'object' ? JSON.stringify(value, null, 2) : value;
        }
    };
    
    // Handle JSON array fields
    const jsonFields = {
        items: 'items_json',
        plans: 'plans_json', 
        members: 'members_json',
        logos: 'items_json',
        links: 'items_json',
        columns: 'columns_json',
        headers: 'headers_json',
        rows: 'rows_json'
    };
    
    // Set title/subtitle if present
    if (data.title) setField('title', data.title);
    if (data.subtitle) setField('subtitle', data.subtitle);
    
    // Handle array data
    for (const [key, jsonField] of Object.entries(jsonFields)) {
        if (data[key] && Array.isArray(data[key])) {
            const el = div.querySelector(`[name="blocks[${id}][data][${jsonField}]"]`);
            if (el) el.value = JSON.stringify(data[key], null, 2);
        }
    }
    
    // Handle text-based array fields (one item per line)
    if (data.items && Array.isArray(data.items) && ['checklist', 'list'].includes(type)) {
        const textField = div.querySelector(`[name="blocks[${id}][data][items_text]"]`);
        if (textField) {
            textField.value = data.items.map(item => typeof item === 'string' ? item : item.text || item).join('\n');
        }
    }
    
    // Set all other simple fields
    Object.entries(data).forEach(([key, value]) => {
        if (!jsonFields[key] && !['title', 'subtitle', 'items', 'plans', 'members', 'logos', 'links', 'columns', 'headers', 'rows'].includes(key)) {
            if (typeof value !== 'object') {
                setField(key, value);
            }
        }
    });
    
    // Initialize WYSIWYG for text blocks
    if (type === 'text') {
        const editor = div.querySelector('.wysiwyg-editor');
        if (editor) {
            initWysiwyg(editor);
            if (data.content && editor._quill) {
                editor._quill.root.innerHTML = data.content;
            }
        }
    }
}
</script>
HTML,
            
            // ─── ADMIN: NAVIGATION ───────────────────────────────────────
            'admin/nav' => <<<'HTML'
<!-- Page Header -->
<div class="mb-8">
    <h1 class="text-2xl font-bold text-gray-900">Navigation</h1>
    <p class="text-gray-600 mt-1">Manage your site's menu items. Drag to reorder.</p>
</div>

{{#if flash_error}}
<div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg flex items-center gap-3">
    <span class="material-symbols-outlined text-red-600">error</span>
    <span class="text-red-800">{{flash_error}}</span>
</div>
{{/if}}

{{#if flash_success}}
<div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-lg flex items-center gap-3">
    <span class="material-symbols-outlined text-green-600">check_circle</span>
    <span class="text-green-800">{{flash_success}}</span>
</div>
{{/if}}

<div class="bg-white rounded-xl border border-gray-200 p-6">
    <form method="post" action="/admin/nav" id="nav-form">
        {{{csrf_field}}}
        
        <div id="nav-items" class="space-y-3">
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
                               class="w-4 h-4 rounded border-gray-300 text-[#135bec] focus:ring-[#135bec]">
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
            <button type="button" data-action="add-nav-item" class="inline-flex items-center gap-2 px-4 py-2.5 bg-gray-100 text-gray-700 font-medium rounded-lg hover:bg-gray-200 transition-colors">
                <span class="material-symbols-outlined text-xl">add</span>
                Add Menu Item
            </button>
            <button type="submit" class="inline-flex items-center gap-2 px-6 py-2.5 bg-[#135bec] text-white font-medium rounded-lg hover:bg-[#0f4fd1] transition-colors">
                <span class="material-symbols-outlined text-xl">save</span>
                Save Navigation
            </button>
        </div>
    </form>
</div>

<script nonce="{{csp_nonce}}">
let navCounter = {{nav_count}};

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
                       class="w-4 h-4 rounded border-gray-300 text-[#135bec] focus:ring-[#135bec]">
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
</script>
HTML,
            
            // ─── ADMIN: MEDIA LIBRARY ────────────────────────────────────
            'admin/media' => <<<'HTML'
<!-- Page Header -->
<div class="mb-8">
    <h1 class="text-2xl font-bold text-gray-900">Media Library</h1>
    <p class="text-gray-600 mt-1">Upload and manage images and documents for your site.</p>
</div>

{{#if flash_error}}
<div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg flex items-center gap-3">
    <span class="material-symbols-outlined text-red-600">error</span>
    <span class="text-red-800">{{flash_error}}</span>
</div>
{{/if}}

{{#if flash_success}}
<div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-lg flex items-center gap-3">
    <span class="material-symbols-outlined text-green-600">check_circle</span>
    <span class="text-green-800">{{flash_success}}</span>
</div>
{{/if}}

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

<script nonce="{{csp_nonce}}">
const uploadZone = document.getElementById('upload-zone');
const fileInput = document.getElementById('file-input');
const progressContainer = document.getElementById('upload-progress');
const progressBar = document.getElementById('progress-bar');
const uploadStatus = document.getElementById('upload-status');

// Click to upload
uploadZone.addEventListener('click', () => fileInput.click());

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
        formData.append('_csrf', '{{csrf_token}}');
        
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
</script>
HTML,
            
            // ─── ADMIN: THEME SETTINGS ───────────────────────────────────
            'admin/theme' => <<<'HTML'
<!-- Page Header -->
<div class="mb-8">
    <h1 class="text-2xl font-bold text-gray-900">Theme Settings</h1>
    <p class="text-gray-600 mt-1">Customize your site's appearance, colors, and branding.</p>
</div>

{{#if flash_error}}
<div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg flex items-center gap-3">
    <span class="material-symbols-outlined text-red-600">error</span>
    <span class="text-red-800">{{flash_error}}</span>
</div>
{{/if}}

{{#if flash_success}}
<div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-lg flex items-center gap-3">
    <span class="material-symbols-outlined text-green-600">check_circle</span>
    <span class="text-green-800">{{flash_success}}</span>
</div>
{{/if}}

<form method="post" action="/admin/theme">
    {{{csrf_field}}}
    
    <!-- Site Identity -->
    <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
            <span class="material-symbols-outlined text-[#135bec]">badge</span>
            Site Identity
        </h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Site Name *</label>
                <input type="text" name="site_name" value="{{site_name}}" required
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Tagline</label>
                <input type="text" name="tagline" value="{{tagline}}"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none">
            </div>
            <div class="md:col-span-2">
                <label class="block text-sm font-medium text-gray-700 mb-1">Logo URL</label>
                <input type="url" name="logo_url" value="{{logo_url}}" placeholder="/assets/..."
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none">
            </div>
        </div>
    </div>
    
    <!-- Color Palette -->
    <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-2 flex items-center gap-2">
            <span class="material-symbols-outlined text-[#135bec]">palette</span>
            Color Palette
        </h2>
        <p class="text-sm text-gray-600 mb-6">These colors are used throughout your site. Dark mode variants are automatically used when visitors prefer dark mode.</p>
        
        <!-- Light Mode -->
        <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Light Mode</h3>
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4 mb-6">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Primary</label>
                <div class="flex items-center gap-2">
                    <input type="color" name="color_primary" value="{{color_primary}}"
                           class="w-10 h-10 rounded-lg border-2 border-gray-200 cursor-pointer p-0">
                    <input type="text" value="{{color_primary}}" disabled
                           class="flex-1 px-2 py-1.5 text-sm font-mono bg-gray-50 border border-gray-200 rounded">
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Secondary</label>
                <div class="flex items-center gap-2">
                    <input type="color" name="color_secondary" value="{{color_secondary}}"
                           class="w-10 h-10 rounded-lg border-2 border-gray-200 cursor-pointer p-0">
                    <input type="text" value="{{color_secondary}}" disabled
                           class="flex-1 px-2 py-1.5 text-sm font-mono bg-gray-50 border border-gray-200 rounded">
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Accent</label>
                <div class="flex items-center gap-2">
                    <input type="color" name="color_accent" value="{{color_accent}}"
                           class="w-10 h-10 rounded-lg border-2 border-gray-200 cursor-pointer p-0">
                    <input type="text" value="{{color_accent}}" disabled
                           class="flex-1 px-2 py-1.5 text-sm font-mono bg-gray-50 border border-gray-200 rounded">
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Background</label>
                <div class="flex items-center gap-2">
                    <input type="color" name="color_background" value="{{color_background}}"
                           class="w-10 h-10 rounded-lg border-2 border-gray-200 cursor-pointer p-0">
                    <input type="text" value="{{color_background}}" disabled
                           class="flex-1 px-2 py-1.5 text-sm font-mono bg-gray-50 border border-gray-200 rounded">
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Text</label>
                <div class="flex items-center gap-2">
                    <input type="color" name="color_text" value="{{color_text}}"
                           class="w-10 h-10 rounded-lg border-2 border-gray-200 cursor-pointer p-0">
                    <input type="text" value="{{color_text}}" disabled
                           class="flex-1 px-2 py-1.5 text-sm font-mono bg-gray-50 border border-gray-200 rounded">
                </div>
            </div>
        </div>
        
        <!-- Dark Mode -->
        <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Dark Mode</h3>
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Primary</label>
                <div class="flex items-center gap-2">
                    <input type="color" name="color_primary_dark" value="{{color_primary_dark}}"
                           class="w-10 h-10 rounded-lg border-2 border-gray-200 cursor-pointer p-0">
                    <input type="text" value="{{color_primary_dark}}" disabled
                           class="flex-1 px-2 py-1.5 text-sm font-mono bg-gray-50 border border-gray-200 rounded">
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Secondary</label>
                <div class="flex items-center gap-2">
                    <input type="color" name="color_secondary_dark" value="{{color_secondary_dark}}"
                           class="w-10 h-10 rounded-lg border-2 border-gray-200 cursor-pointer p-0">
                    <input type="text" value="{{color_secondary_dark}}" disabled
                           class="flex-1 px-2 py-1.5 text-sm font-mono bg-gray-50 border border-gray-200 rounded">
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Accent</label>
                <div class="flex items-center gap-2">
                    <input type="color" name="color_accent_dark" value="{{color_accent_dark}}"
                           class="w-10 h-10 rounded-lg border-2 border-gray-200 cursor-pointer p-0">
                    <input type="text" value="{{color_accent_dark}}" disabled
                           class="flex-1 px-2 py-1.5 text-sm font-mono bg-gray-50 border border-gray-200 rounded">
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Background</label>
                <div class="flex items-center gap-2">
                    <input type="color" name="color_background_dark" value="{{color_background_dark}}"
                           class="w-10 h-10 rounded-lg border-2 border-gray-200 cursor-pointer p-0">
                    <input type="text" value="{{color_background_dark}}" disabled
                           class="flex-1 px-2 py-1.5 text-sm font-mono bg-gray-50 border border-gray-200 rounded">
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Text</label>
                <div class="flex items-center gap-2">
                    <input type="color" name="color_text_dark" value="{{color_text_dark}}"
                           class="w-10 h-10 rounded-lg border-2 border-gray-200 cursor-pointer p-0">
                    <input type="text" value="{{color_text_dark}}" disabled
                           class="flex-1 px-2 py-1.5 text-sm font-mono bg-gray-50 border border-gray-200 rounded">
                </div>
            </div>
        </div>
    </div>
    
    <!-- Typography -->
    <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
            <span class="material-symbols-outlined text-[#135bec]">text_fields</span>
            Typography
        </h2>
        <div class="max-w-md">
            <label class="block text-sm font-medium text-gray-700 mb-1">Font Family</label>
            <select name="font_family"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none">
                <option value="system-ui, -apple-system, sans-serif" {{#if font_system}}selected{{/if}}>System Default</option>
                <option value="'Inter', sans-serif" {{#if font_inter}}selected{{/if}}>Inter</option>
                <option value="'Roboto', sans-serif" {{#if font_roboto}}selected{{/if}}>Roboto</option>
                <option value="'Open Sans', sans-serif" {{#if font_opensans}}selected{{/if}}>Open Sans</option>
                <option value="Georgia, serif" {{#if font_georgia}}selected{{/if}}>Georgia (Serif)</option>
            </select>
        </div>
    </div>
    
    <!-- Header & Footer -->
    <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
            <span class="material-symbols-outlined text-[#135bec]">view_quilt</span>
            Header & Footer
        </h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Header Background</label>
                <div class="flex items-center gap-2">
                    <input type="color" name="header_bg" value="{{header_bg}}"
                           class="w-10 h-10 rounded-lg border-2 border-gray-200 cursor-pointer p-0">
                    <input type="text" value="{{header_bg}}" disabled
                           class="flex-1 px-2 py-1.5 text-sm font-mono bg-gray-50 border border-gray-200 rounded">
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Footer Text</label>
                <input type="text" name="footer_text" value="{{footer_text}}" placeholder="© 2026 Your Company"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none">
            </div>
        </div>
    </div>
    
    <!-- Custom Code -->
    <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
            <span class="material-symbols-outlined text-[#135bec]">code</span>
            Custom Code
        </h2>
        <div class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Custom Head Scripts</label>
                <p class="text-xs text-gray-500 mb-2">Add analytics, fonts, or other scripts to the &lt;head&gt; section.</p>
                <textarea name="head_scripts" rows="4"
                          class="w-full px-3 py-2 font-mono text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none">{{head_scripts}}</textarea>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Custom Footer Scripts</label>
                <p class="text-xs text-gray-500 mb-2">Add scripts before the closing &lt;/body&gt; tag.</p>
                <textarea name="footer_scripts" rows="4"
                          class="w-full px-3 py-2 font-mono text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none">{{footer_scripts}}</textarea>
            </div>
        </div>
    </div>
    
    <button type="submit" class="inline-flex items-center gap-2 px-6 py-2.5 bg-[#135bec] text-white font-medium rounded-lg hover:bg-[#0f4fd1] transition-colors">
        <span class="material-symbols-outlined text-xl">save</span>
        Save Theme Settings
    </button>
</form>
HTML,
            
            // ─── ADMIN: USERS LIST ───────────────────────────────────────
            'admin/users' => <<<'HTML'
<!-- Page Header -->
<div class="mb-8 flex items-center justify-between">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Users</h1>
        <p class="text-gray-600 mt-1">Manage user accounts and permissions.</p>
    </div>
    <a href="/admin/users/new" class="inline-flex items-center gap-2 px-4 py-2.5 bg-[#135bec] text-white font-medium rounded-lg hover:bg-[#0f4fd1] transition-colors">
        <span class="material-symbols-outlined text-xl">person_add</span>
        Add User
    </a>
</div>

{{#if flash_error}}
<div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg flex items-center gap-3">
    <span class="material-symbols-outlined text-red-600">error</span>
    <span class="text-red-800">{{flash_error}}</span>
</div>
{{/if}}

{{#if flash_success}}
<div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-lg flex items-center gap-3">
    <span class="material-symbols-outlined text-green-600">check_circle</span>
    <span class="text-green-800">{{flash_success}}</span>
</div>
{{/if}}

<div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
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
                        <span class="inline-flex items-center px-2.5 py-1 text-xs font-medium bg-red-100 text-red-800 rounded-full">Admin</span>
                        {{/if}}
                        {{#if is_editor}}
                        <span class="inline-flex items-center px-2.5 py-1 text-xs font-medium bg-blue-100 text-blue-800 rounded-full">Editor</span>
                        {{/if}}
                        {{#if is_viewer}}
                        <span class="inline-flex items-center px-2.5 py-1 text-xs font-medium bg-gray-100 text-gray-800 rounded-full">Viewer</span>
                        {{/if}}
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-500">{{created_at}}</td>
                    <td class="px-6 py-4 text-sm text-gray-500">{{last_login}}</td>
                    <td class="px-6 py-4">
                        <div class="flex items-center justify-end gap-2">
                            <a href="/admin/users/{{id}}" class="inline-flex items-center gap-1 px-3 py-1.5 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors">
                                <span class="material-symbols-outlined text-lg">edit</span>
                                Edit
                            </a>
                            {{#if can_delete}}
                            <form method="post" action="/admin/users/{{id}}/delete" class="inline" data-confirm="Delete this user?">
                                {{{csrf_field}}}
                                <button type="submit" class="inline-flex items-center gap-1 px-3 py-1.5 text-sm font-medium text-red-600 bg-red-50 rounded-lg hover:bg-red-100 transition-colors">
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
<!-- Page Header -->
<div class="mb-8 flex items-center gap-3">
    <a href="/admin/users" class="text-gray-500 hover:text-gray-700">
        <span class="material-symbols-outlined">arrow_back</span>
    </a>
    <div>
        <h1 class="text-2xl font-bold text-gray-900">{{#if is_new}}Add User{{else}}Edit User{{/if}}</h1>
        <p class="text-gray-600 mt-1">{{#if is_new}}Create a new user account.{{else}}Update user details and permissions.{{/if}}</p>
    </div>
</div>

{{#if flash_error}}
<div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg flex items-center gap-3">
    <span class="material-symbols-outlined text-red-600">error</span>
    <span class="text-red-800">{{flash_error}}</span>
</div>
{{/if}}

<form method="post" action="/admin/users/{{id}}">
    {{{csrf_field}}}
    
    <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
            <span class="material-symbols-outlined text-[#135bec]">person</span>
            Account Details
        </h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Email *</label>
                <input type="email" name="email" value="{{email}}" required
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Role *</label>
                <select name="role"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none">
                    <option value="admin" {{#if is_admin}}selected{{/if}}>Admin - Full access</option>
                    <option value="editor" {{#if is_editor}}selected{{/if}}>Editor - Manage content</option>
                    <option value="viewer" {{#if is_viewer}}selected{{/if}}>Viewer - Read only</option>
                </select>
            </div>
        </div>
    </div>
    
    <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
            <span class="material-symbols-outlined text-[#135bec]">lock</span>
            Password
        </h2>
        <p class="text-sm text-gray-600 mb-4">{{#if is_new}}Set a strong password.{{else}}Leave blank to keep the current password.{{/if}}</p>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">{{#if is_new}}Password *{{else}}New Password{{/if}}</label>
                <input type="password" name="password" minlength="12" {{#if is_new}}required{{/if}}
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none">
                <p class="text-xs text-gray-500 mt-1">Minimum 12 characters</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Confirm Password</label>
                <input type="password" name="password_confirm"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none">
            </div>
        </div>
    </div>
    
    <div class="flex items-center gap-3">
        <button type="submit" class="inline-flex items-center gap-2 px-6 py-2.5 bg-[#135bec] text-white font-medium rounded-lg hover:bg-[#0f4fd1] transition-colors">
            <span class="material-symbols-outlined text-xl">save</span>
            {{#if is_new}}Create User{{else}}Save Changes{{/if}}
        </button>
        <a href="/admin/users" class="inline-flex items-center gap-2 px-4 py-2.5 bg-gray-100 text-gray-700 font-medium rounded-lg hover:bg-gray-200 transition-colors">
            Cancel
        </a>
    </div>
</form>
HTML,

            'admin_ai' => <<<'HTML'
<!-- Page Header -->
<div class="mb-8">
    <h1 class="text-2xl font-bold text-gray-900">AI Site Generator</h1>
    <p class="text-gray-600 mt-1">Let AI create a complete website for you based on your business information.</p>
</div>

{{#if flash_error}}
<div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg flex items-center gap-3">
    <span class="material-symbols-outlined text-red-600">error</span>
    <span class="text-red-800">{{flash_error}}</span>
</div>
{{/if}}

{{#if flash_success}}
<div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-lg flex items-center gap-3">
    <span class="material-symbols-outlined text-green-600">check_circle</span>
    <span class="text-green-800">{{flash_success}}</span>
</div>
{{/if}}

{{#if is_configured}}
<!-- AI Status Banner -->
<div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-xl flex items-center justify-between">
    <div class="flex items-center gap-3">
        <span class="material-symbols-outlined text-green-600">check_circle</span>
        <div>
            <p class="font-medium text-green-800">AI is configured and ready</p>
            <p class="text-sm text-green-700">Provider: {{current_provider_name}} • Model: {{current_model}}</p>
        </div>
    </div>
    <button type="button" id="show-config-btn" class="px-4 py-2 text-sm font-medium text-green-700 hover:bg-green-100 rounded-lg transition-colors">
        Change Settings
    </button>
</div>

<!-- Configuration Form (hidden by default when configured) -->
<div id="config-form-container" class="hidden mb-6 bg-white rounded-xl border border-gray-200 p-6">
    <form method="post" action="/admin/ai/configure" class="space-y-4">
        {{{csrf_field}}}
        
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">AI Provider</label>
                <select name="ai_provider" required 
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none">
                    <option value="openai" {{#if current_provider_openai}}selected{{/if}}>OpenAI (GPT-5.2)</option>
                    <option value="anthropic" {{#if current_provider_anthropic}}selected{{/if}}>Anthropic (Claude 4.5)</option>
                    <option value="google" {{#if current_provider_google}}selected{{/if}}>Google (Gemini 3)</option>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">API Key</label>
                <input type="password" name="ai_api_key" required 
                       placeholder="Enter new API key to change"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Model (optional)</label>
                <input type="text" name="ai_model" value="{{current_model}}"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none">
            </div>
        </div>
        
        <div class="flex gap-2">
            <button type="submit" class="px-4 py-2 bg-[#135bec] text-white font-medium rounded-lg hover:bg-[#0f4fd1] transition-colors">
                Update Configuration
            </button>
            <button type="button" id="hide-config-btn" class="px-4 py-2 text-gray-600 hover:bg-gray-100 rounded-lg transition-colors">
                Cancel
            </button>
        </div>
    </form>
</div>

<script nonce="{{csp_nonce}}">
document.getElementById('show-config-btn')?.addEventListener('click', function() {
    document.getElementById('config-form-container').classList.remove('hidden');
    this.closest('.mb-6').classList.add('hidden');
});
document.getElementById('hide-config-btn')?.addEventListener('click', function() {
    document.getElementById('config-form-container').classList.add('hidden');
    document.querySelector('.bg-green-50.mb-6').classList.remove('hidden');
});
</script>

<form method="post" action="/admin/ai/generate" class="space-y-6" id="ai-generate-form">
    {{{csrf_field}}}
    
    <!-- Business Information -->
    <div class="bg-white rounded-xl border border-gray-200 p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
            <span class="material-symbols-outlined text-[#135bec]">business</span>
            Business Information
        </h2>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Business Name *</label>
                <input type="text" name="business_name" required 
                       placeholder="e.g., Acme Consulting"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Industry *</label>
                <select name="industry" required 
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none">
                    <option value="">Select an industry...</option>
                    {{#each industries}}
                    <option value="{{this}}">{{this}}</option>
                    {{/each}}
                </select>
            </div>
        </div>
        
        <div class="mt-4">
            <label class="block text-sm font-medium text-gray-700 mb-1">Business Description *</label>
            <textarea name="description" rows="4" required 
                      placeholder="Describe your business, services, products, target audience, and what makes you unique..."
                      class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none resize-none"></textarea>
        </div>
    </div>
    
    <!-- Style Preferences -->
    <div class="bg-white rounded-xl border border-gray-200 p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
            <span class="material-symbols-outlined text-[#135bec]">palette</span>
            Style Preferences
        </h2>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Tone</label>
                <select name="tone" 
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none">
                    {{#each tones}}
                    <option value="{{@key}}">{{@key}} - {{this}}</option>
                    {{/each}}
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Color Preference</label>
                <input type="text" name="color_preference" 
                       placeholder="e.g., Blue and green, Modern dark theme"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">UI Theme</label>
                <select name="ui_theme" 
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none">
                    <optgroup label="Light Themes">
                        <option value="light">Light - Clean & minimal</option>
                        <option value="cupcake">Cupcake - Soft & pastel</option>
                        <option value="bumblebee">Bumblebee - Warm yellow</option>
                        <option value="emerald">Emerald - Fresh green</option>
                        <option value="corporate">Corporate - Professional blue</option>
                        <option value="garden">Garden - Natural tones</option>
                        <option value="lofi">Lo-Fi - Subtle gray</option>
                        <option value="pastel">Pastel - Soft colors</option>
                        <option value="fantasy">Fantasy - Vibrant purple</option>
                        <option value="wireframe">Wireframe - Sketch style</option>
                        <option value="cmyk">CMYK - Print inspired</option>
                        <option value="autumn">Autumn - Warm orange</option>
                        <option value="acid">Acid - Neon lime</option>
                        <option value="lemonade">Lemonade - Bright yellow</option>
                        <option value="winter">Winter - Cool & icy</option>
                    </optgroup>
                    <optgroup label="Dark Themes">
                        <option value="dark">Dark - Modern dark</option>
                        <option value="synthwave">Synthwave - Retro neon</option>
                        <option value="retro">Retro - 70s vintage</option>
                        <option value="cyberpunk">Cyberpunk - Futuristic</option>
                        <option value="valentine">Valentine - Pink love</option>
                        <option value="halloween">Halloween - Spooky</option>
                        <option value="forest">Forest - Deep green</option>
                        <option value="aqua">Aqua - Ocean blue</option>
                        <option value="luxury">Luxury - Gold & black</option>
                        <option value="dracula">Dracula - Dark purple</option>
                        <option value="business">Business - Serious dark</option>
                        <option value="night">Night - Deep blue</option>
                        <option value="coffee">Coffee - Warm brown</option>
                    </optgroup>
                </select>
                <p class="text-xs text-gray-500 mt-1">Choose a pre-built theme or let colors be auto-generated</p>
            </div>
        </div>
    </div>
    
    <!-- Features -->
    <div class="bg-white rounded-xl border border-gray-200 p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
            <span class="material-symbols-outlined text-[#135bec]">widgets</span>
            Features to Include
        </h2>
        
        <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
            {{#each features}}
            <label class="flex items-center gap-2 p-3 bg-gray-50 rounded-lg cursor-pointer hover:bg-gray-100 transition-colors">
                <input type="checkbox" name="features[]" value="{{@key}}" 
                       class="w-4 h-4 text-[#135bec] border-gray-300 rounded focus:ring-[#135bec]">
                <span class="text-sm text-gray-700">{{this}}</span>
            </label>
            {{/each}}
        </div>
    </div>
    
    <!-- Additional Information -->
    <div class="bg-white rounded-xl border border-gray-200 p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
            <span class="material-symbols-outlined text-[#135bec]">info</span>
            Additional Information
        </h2>
        
        <div class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Specific Pages Needed</label>
                <input type="text" name="pages_needed" 
                       placeholder="e.g., Home, About, Services, Portfolio, Contact"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Your Content</label>
                <textarea name="user_content" rows="5" 
                          placeholder="Paste any existing content, mission statement, service descriptions, or text you want included..."
                          class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none resize-none"></textarea>
            </div>
        </div>
    </div>
    
    <!-- Actions -->
    <div class="flex items-center gap-3">
        <button type="submit" id="ai-generate-btn"
                class="inline-flex items-center gap-2 px-6 py-2.5 bg-[#135bec] text-white font-medium rounded-lg hover:bg-[#0f4fd1] transition-colors">
            <span class="material-symbols-outlined text-xl">auto_awesome</span>
            Generate Site Plan
        </button>
        <a href="/admin" 
           class="px-4 py-2.5 text-gray-700 font-medium rounded-lg border border-gray-300 hover:bg-gray-50 transition-colors">
            Cancel
        </a>
    </div>
</form>

<!-- AI Loading Modal -->
<div id="ai-loading-modal" class="hidden fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center">
    <div class="bg-white rounded-2xl shadow-2xl p-8 max-w-md w-full mx-4 text-center">
        <div class="relative w-20 h-20 mx-auto mb-6">
            <!-- Spinning circle -->
            <svg class="animate-spin w-20 h-20" viewBox="0 0 50 50">
                <circle class="opacity-20" cx="25" cy="25" r="20" stroke="#135bec" stroke-width="4" fill="none"/>
                <circle class="opacity-100" cx="25" cy="25" r="20" stroke="#135bec" stroke-width="4" fill="none" 
                        stroke-linecap="round" stroke-dasharray="80, 200" stroke-dashoffset="0"/>
            </svg>
            <!-- AI icon in center -->
            <div class="absolute inset-0 flex items-center justify-center">
                <span class="material-symbols-outlined text-3xl text-[#135bec] animate-pulse">auto_awesome</span>
            </div>
        </div>
        <h3 class="text-xl font-bold text-gray-900 mb-2">Generating Your Website</h3>
        <p class="text-gray-600 mb-4">AI is creating your site plan with pages, content, and design...</p>
        <div class="space-y-2 text-sm text-gray-500">
            <p id="ai-status-text" class="flex items-center justify-center gap-2">
                <span class="w-2 h-2 bg-[#135bec] rounded-full animate-pulse"></span>
                Analyzing your business information...
            </p>
        </div>
        <p class="text-xs text-gray-400 mt-6">This may take 15-30 seconds</p>
    </div>
</div>

<script nonce="{{csp_nonce}}">
(function() {
    const form = document.getElementById('ai-generate-form');
    const modal = document.getElementById('ai-loading-modal');
    const statusText = document.getElementById('ai-status-text');
    const btn = document.getElementById('ai-generate-btn');
    
    const statusMessages = [
        'Analyzing your business information...',
        'Selecting optimal color palette...',
        'Designing page layouts...',
        'Writing compelling content...',
        'Creating navigation structure...',
        'Optimizing for conversions...',
        'Finalizing site plan...'
    ];
    
    let messageIndex = 0;
    let statusInterval;
    
    form?.addEventListener('submit', function(e) {
        // Show modal
        modal?.classList.remove('hidden');
        btn.disabled = true;
        btn.innerHTML = '<span class="material-symbols-outlined text-xl animate-spin">progress_activity</span> Generating...';
        
        // Rotate status messages
        statusInterval = setInterval(() => {
            messageIndex = (messageIndex + 1) % statusMessages.length;
            if (statusText) {
                statusText.innerHTML = '<span class="w-2 h-2 bg-[#135bec] rounded-full animate-pulse"></span> ' + statusMessages[messageIndex];
            }
        }, 3000);
    });
    
    // Clean up on page unload
    window.addEventListener('beforeunload', () => {
        if (statusInterval) clearInterval(statusInterval);
    });
})();
</script>
{{else}}
<div class="bg-white rounded-xl border border-gray-200 p-6">
    <div class="p-4 bg-amber-50 border border-amber-200 rounded-lg mb-6">
        <div class="flex items-start gap-3">
            <span class="material-symbols-outlined text-amber-600">warning</span>
            <div>
                <p class="font-medium text-amber-800">AI Not Configured</p>
                <p class="text-sm text-amber-700 mt-1">Configure your AI provider below to start generating websites.</p>
            </div>
        </div>
    </div>
    
    <form method="post" action="/admin/ai/configure" class="space-y-6">
        {{{csrf_field}}}
        
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">AI Provider *</label>
            <select name="ai_provider" required 
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none">
                <option value="">Select a provider...</option>
                <option value="openai" {{#if current_provider_openai}}selected{{/if}}>OpenAI (GPT-5.2, GPT-4.1)</option>
                <option value="anthropic" {{#if current_provider_anthropic}}selected{{/if}}>Anthropic (Claude 4.5)</option>
                <option value="google" {{#if current_provider_google}}selected{{/if}}>Google (Gemini 3)</option>
            </select>
        </div>
        
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">API Key *</label>
            <input type="password" name="ai_api_key" required 
                   placeholder="{{#if has_api_key}}••••••••••••••••{{else}}Enter your API key{{/if}}"
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none">
            <p class="text-xs text-gray-500 mt-1">
                Get your API key from 
                <a href="https://platform.openai.com/api-keys" target="_blank" class="text-[#135bec] hover:underline">OpenAI</a>, 
                <a href="https://console.anthropic.com/" target="_blank" class="text-[#135bec] hover:underline">Anthropic</a>, or 
                <a href="https://aistudio.google.com/apikey" target="_blank" class="text-[#135bec] hover:underline">Google AI Studio</a>
            </p>
        </div>
        
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Model (optional)</label>
            <input type="text" name="ai_model" value="{{current_model}}"
                   placeholder="gpt-5.2 (default for OpenAI)"
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none">
            <p class="text-xs text-gray-500 mt-1">
                OpenAI: gpt-5.2, gpt-5-mini, gpt-4.1 | Anthropic: claude-sonnet-4-5, claude-opus-4-5 | Google: gemini-3-flash-preview, gemini-3-pro-preview
            </p>
        </div>
        
        <div class="pt-4 border-t border-gray-200">
            <button type="submit" 
                    class="inline-flex items-center gap-2 px-6 py-2.5 bg-[#135bec] text-white font-medium rounded-lg hover:bg-[#0f4fd1] transition-colors">
                <span class="material-symbols-outlined text-xl">save</span>
                Save Configuration
            </button>
        </div>
    </form>
</div>
{{/if}}
HTML,

            'admin_approvals' => <<<'HTML'
<!-- Page Header -->
<div class="mb-8">
    <h1 class="text-2xl font-bold text-gray-900">Approvals Queue</h1>
    <p class="text-gray-600 mt-1">Review and approve AI-generated site plans before applying them.</p>
</div>

{{#if flash_error}}
<div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg flex items-center gap-3">
    <span class="material-symbols-outlined text-red-600">error</span>
    <span class="text-red-800">{{flash_error}}</span>
</div>
{{/if}}

{{#if flash_success}}
<div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-lg flex items-center gap-3">
    <span class="material-symbols-outlined text-green-600">check_circle</span>
    <span class="text-green-800">{{flash_success}}</span>
</div>
{{/if}}

<div class="grid grid-cols-1 gap-6">
    <!-- Pending Approval -->
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="p-4 border-b border-gray-200 bg-amber-50">
            <h2 class="font-semibold text-gray-900 flex items-center gap-2">
                <span class="material-symbols-outlined text-amber-600">pending</span>
                Pending Approval
            </h2>
        </div>
        <div class="divide-y divide-gray-100">
            {{#if has_pending}}
            {{#each pending}}
            <div class="p-4 flex items-center justify-between hover:bg-gray-50">
                <div class="flex items-center gap-4">
                    <div class="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center">
                        <span class="material-symbols-outlined text-amber-600">description</span>
                    </div>
                    <div>
                        <p class="font-medium text-gray-900">Site Plan #{{id}}</p>
                        <p class="text-sm text-gray-500">Created: {{created_at}}</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <span class="px-2 py-1 text-xs font-medium bg-amber-100 text-amber-800 rounded-full">Pending</span>
                    <a href="/admin/approvals/{{id}}" 
                       class="px-3 py-1.5 text-sm font-medium text-[#135bec] hover:bg-[#135bec]/5 rounded-lg transition-colors">
                        Review
                    </a>
                </div>
            </div>
            {{/each}}
            {{else}}
            <div class="p-8 text-center text-gray-500">
                <span class="material-symbols-outlined text-4xl text-gray-300 mb-2">inbox</span>
                <p>No pending approvals.</p>
                <a href="/admin/ai" class="text-[#135bec] hover:underline text-sm">Generate a new site plan</a>
            </div>
            {{/if}}
        </div>
    </div>
    
    <!-- Approved -->
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="p-4 border-b border-gray-200 bg-green-50">
            <h2 class="font-semibold text-gray-900 flex items-center gap-2">
                <span class="material-symbols-outlined text-green-600">check_circle</span>
                Approved (Ready to Apply)
            </h2>
        </div>
        <div class="divide-y divide-gray-100">
            {{#if has_approved}}
            {{#each approved}}
            <div class="p-4 flex items-center justify-between hover:bg-gray-50">
                <div class="flex items-center gap-4">
                    <div class="w-10 h-10 rounded-lg bg-green-100 flex items-center justify-center">
                        <span class="material-symbols-outlined text-green-600">task_alt</span>
                    </div>
                    <div>
                        <p class="font-medium text-gray-900">Site Plan #{{id}}</p>
                        <p class="text-sm text-gray-500">Approved: {{approved_at}}</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <span class="px-2 py-1 text-xs font-medium bg-green-100 text-green-800 rounded-full">Approved</span>
                    <a href="/admin/approvals/{{id}}" 
                       class="px-3 py-1.5 text-sm font-medium text-gray-600 hover:bg-gray-100 rounded-lg transition-colors">
                        View
                    </a>
                    <form method="post" action="/admin/approvals/{{id}}/apply" class="inline" data-confirm="Apply this site plan? This will update your pages, navigation, and theme.">
                        {{{csrf_field}}}
                        <button type="submit" 
                                class="px-3 py-1.5 text-sm font-medium text-white bg-green-600 hover:bg-green-700 rounded-lg transition-colors">
                            Apply
                        </button>
                    </form>
                </div>
            </div>
            {{/each}}
            {{else}}
            <div class="p-6 text-center text-gray-500 text-sm">
                No approved plans waiting to be applied.
            </div>
            {{/if}}
        </div>
    </div>
    
    <!-- Applied -->
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="p-4 border-b border-gray-200 bg-blue-50">
            <h2 class="font-semibold text-gray-900 flex items-center gap-2">
                <span class="material-symbols-outlined text-blue-600">rocket_launch</span>
                Recently Applied
            </h2>
        </div>
        <div class="divide-y divide-gray-100">
            {{#if has_applied}}
            {{#each applied}}
            <div class="p-4 flex items-center justify-between hover:bg-gray-50">
                <div class="flex items-center gap-4">
                    <div class="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center">
                        <span class="material-symbols-outlined text-blue-600">check_circle</span>
                    </div>
                    <div>
                        <p class="font-medium text-gray-900">Site Plan #{{id}}</p>
                        <p class="text-sm text-gray-500">Applied: {{applied_at}}</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <span class="px-2 py-1 text-xs font-medium bg-blue-100 text-blue-800 rounded-full">Applied</span>
                    <a href="/admin/approvals/{{id}}" 
                       class="px-3 py-1.5 text-sm font-medium text-gray-600 hover:bg-gray-100 rounded-lg transition-colors">
                        View
                    </a>
                </div>
            </div>
            {{/each}}
            {{else}}
            <div class="p-6 text-center text-gray-500 text-sm">
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
           class="inline-flex items-center gap-2 px-4 py-2 bg-purple-600 text-white font-medium rounded-lg hover:bg-purple-700 transition-colors">
            <span class="material-symbols-outlined text-xl">preview</span>
            Preview & Edit
        </a>
        {{#if item.is_pending}}
        <form method="post" action="/admin/approvals/{{item.id}}/approve" class="inline">
            {{{csrf_field}}}
            <button type="submit" 
                    class="inline-flex items-center gap-2 px-4 py-2 bg-green-600 text-white font-medium rounded-lg hover:bg-green-700 transition-colors">
                <span class="material-symbols-outlined text-xl">check</span>
                Approve
            </button>
        </form>
        <form method="post" action="/admin/approvals/{{item.id}}/reject" class="inline" data-confirm="Reject this site plan?">
            {{{csrf_field}}}
            <button type="submit" 
                    class="inline-flex items-center gap-2 px-4 py-2 bg-red-600 text-white font-medium rounded-lg hover:bg-red-700 transition-colors">
                <span class="material-symbols-outlined text-xl">close</span>
                Reject
            </button>
        </form>
        {{/if}}
        {{#if item.is_approved}}
        <form method="post" action="/admin/approvals/{{item.id}}/apply" class="inline" data-confirm="Apply this site plan? This will update your pages, navigation, and theme.">
            {{{csrf_field}}}
            <button type="submit" 
                    class="inline-flex items-center gap-2 px-4 py-2 bg-[#135bec] text-white font-medium rounded-lg hover:bg-[#0f4fd1] transition-colors">
                <span class="material-symbols-outlined text-xl">rocket_launch</span>
                Apply to Site
            </button>
        </form>
        {{/if}}
    </div>
</div>

{{#if flash_error}}
<div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg flex items-center gap-3">
    <span class="material-symbols-outlined text-red-600">error</span>
    <span class="text-red-800">{{flash_error}}</span>
</div>
{{/if}}

{{#if flash_success}}
<div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-lg flex items-center gap-3">
    <span class="material-symbols-outlined text-green-600">check_circle</span>
    <span class="text-green-800">{{flash_success}}</span>
</div>
{{/if}}

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Left Column: Overview -->
    <div class="lg:col-span-2 space-y-6">
        <!-- Site Overview -->
        <div class="bg-white rounded-xl border border-gray-200 p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
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
        <div class="bg-white rounded-xl border border-gray-200 p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
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
        <div class="bg-white rounded-xl border border-gray-200 p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
                <span class="material-symbols-outlined text-[#135bec]">description</span>
                Pages ({{pages_count}})
            </h2>
            <div class="space-y-4">
                {{#each plan.pages}}
                <div class="border border-gray-200 rounded-lg overflow-hidden">
                    <div class="p-4 bg-gray-50 border-b border-gray-200">
                        <div class="flex items-center justify-between">
                            <span class="font-medium text-gray-900">{{title}}</span>
                            <span class="text-sm text-gray-500">/{{slug}}</span>
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
        <div class="bg-white rounded-xl border border-gray-200 p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
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
        <div class="bg-white rounded-xl border border-gray-200 p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
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
        <details class="bg-white rounded-xl border border-gray-200 overflow-hidden">
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
HTML,

            // ─── APPROVAL PREVIEW WITH EDITABLE BLOCKS ───────────────────
            'admin_approval_preview' => <<<'HTML'
<!DOCTYPE html>
<html lang="en" data-theme="{{ui_theme}}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preview: {{plan.site_name}} | OneCMS</title>
    <link href="/cdn/daisyui.min.css" rel="stylesheet">
    <script src="/cdn/tailwind.min.js"></script>
    <link rel="stylesheet" href="/cdn/material-icons.css">
    <style>
        .editable-block { position: relative; cursor: pointer; transition: all 0.2s; }
        .editable-block:hover { outline: 2px dashed hsl(var(--p)); outline-offset: 4px; }
        .editable-block:hover::before {
            content: 'Click to Edit';
            position: absolute; top: 8px; right: 8px; z-index: 100;
            background: hsl(var(--p)); color: hsl(var(--pc));
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
        /* Make form inputs more rectangular */
        .input, .textarea, .select { border-radius: 4px !important; }
        .btn { border-radius: 4px; }
    </style>
</head>
<body class="min-h-screen bg-base-100">
    <!-- Preview Toolbar -->
    <div class="preview-toolbar">
        <a href="/admin/approvals/{{item.id}}" class="btn btn-ghost btn-sm text-white">
            <span class="material-symbols-outlined">arrow_back</span>
            Back
        </a>
        <span class="text-white font-bold">Preview: {{plan.site_name}}</span>
        <div class="page-tabs ml-4" id="pageTabs"></div>
        <div class="flex-1"></div>
        <span class="text-gray-400 text-sm">Click any block to edit</span>
        {{#if item.is_pending}}
        <form method="post" action="/admin/approvals/{{item.id}}/approve" class="inline">
            <input type="hidden" name="_csrf" value="{{csrf_token}}">
            <button type="submit" class="btn btn-success btn-sm">Approve & Continue</button>
        </form>
        {{/if}}
        {{#if item.is_approved}}
        <form method="post" action="/admin/approvals/{{item.id}}/apply" class="inline">
            <input type="hidden" name="_csrf" value="{{csrf_token}}">
            <button type="submit" class="btn btn-primary btn-sm">Apply to Site</button>
        </form>
        {{/if}}
    </div>

    <!-- Navbar Preview -->
    <div class="navbar bg-base-200 shadow-lg">
        <div class="navbar-start">
            <div class="dropdown lg:hidden">
                <div tabindex="0" role="button" class="btn btn-ghost">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h8m-8 6h16" />
                    </svg>
                </div>
                <ul tabindex="0" class="menu menu-sm dropdown-content mt-3 z-[100] p-2 shadow bg-base-100 rounded-box w-52">
                    {{#each plan.nav}}
                    <li><a href="#" class="text-base-content">{{label}}</a></li>
                    {{/each}}
                </ul>
            </div>
            <a href="#" class="btn btn-ghost text-xl font-bold">{{plan.site_name}}</a>
        </div>
        <div class="navbar-center hidden lg:flex">
            <ul class="menu menu-horizontal px-1">
                {{#each plan.nav}}
                <li><a href="#" class="text-base-content hover:text-primary">{{label}}</a></li>
                {{/each}}
            </ul>
        </div>
        <div class="navbar-end gap-2">
            <label class="swap swap-rotate btn btn-ghost btn-circle text-base-content" title="Toggle dark mode">
                <input type="checkbox" id="theme-toggle" class="theme-controller" />
                <svg class="swap-off fill-current w-6 h-6" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M5.64,17l-.71.71a1,1,0,0,0,0,1.41,1,1,0,0,0,1.41,0l.71-.71A1,1,0,0,0,5.64,17ZM5,12a1,1,0,0,0-1-1H3a1,1,0,0,0,0,2H4A1,1,0,0,0,5,12Zm7-7a1,1,0,0,0,1-1V3a1,1,0,0,0-2,0V4A1,1,0,0,0,12,5ZM5.64,7.05a1,1,0,0,0,.7.29,1,1,0,0,0,.71-.29,1,1,0,0,0,0-1.41l-.71-.71A1,1,0,0,0,4.93,6.34Zm12,.29a1,1,0,0,0,.7-.29l.71-.71a1,1,0,1,0-1.41-1.41L17,5.64a1,1,0,0,0,0,1.41A1,1,0,0,0,17.66,7.34ZM21,11H20a1,1,0,0,0,0,2h1a1,1,0,0,0,0-2Zm-9,8a1,1,0,0,0-1,1v1a1,1,0,0,0,2,0V20A1,1,0,0,0,12,19ZM18.36,17A1,1,0,0,0,17,18.36l.71.71a1,1,0,0,0,1.41,0,1,1,0,0,0,0-1.41ZM12,6.5A5.5,5.5,0,1,0,17.5,12,5.51,5.51,0,0,0,12,6.5Zm0,9A3.5,3.5,0,1,1,15.5,12,3.5,3.5,0,0,1,12,15.5Z"/></svg>
                <svg class="swap-on fill-current w-5 h-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M21.64,13a1,1,0,0,0-1.05-.14,8.05,8.05,0,0,1-3.37.73A8.15,8.15,0,0,1,9.08,5.49a8.59,8.59,0,0,1,.25-2A1,1,0,0,0,8,2.36,10.14,10.14,0,1,0,22,14.05,1,1,0,0,0,21.64,13Z"/></svg>
            </label>
            <a href="#" class="btn btn-primary btn-sm">Contact</a>
        </div>
    </div>

    <!-- Page Content Areas -->
    <div id="pageContents"></div>

    <!-- Footer Preview -->
    <footer class="footer footer-center p-10 bg-base-200 text-base-content mt-16">
        <aside>
            <p class="font-bold text-lg">{{plan.site_name}}</p>
            <p class="text-base-content/70">{{plan.footer.text}}</p>
        </aside>
    </footer>

    <!-- Block Editor Modal -->
    <dialog id="blockEditorModal" class="modal">
        <div class="modal-box w-11/12 max-w-4xl">
            <form method="dialog">
                <button class="btn btn-sm btn-circle btn-ghost absolute right-2 top-2">✕</button>
            </form>
            <h3 class="font-bold text-lg mb-4" id="modalTitle">Edit Block</h3>
            <div id="editorContent" class="space-y-4"></div>
            <div class="modal-action">
                <button type="button" class="btn btn-ghost" data-action="close-modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="saveBlockBtn">Save Changes</button>
            </div>
        </div>
        <form method="dialog" class="modal-backdrop"><button>close</button></form>
    </dialog>

    <!-- Image Gallery Modal -->
    <dialog id="imageGalleryModal" class="modal">
        <div class="modal-box w-11/12 max-w-5xl">
            <form method="dialog">
                <button class="btn btn-sm btn-circle btn-ghost absolute right-2 top-2">✕</button>
            </form>
            <h3 class="font-bold text-lg mb-4">Select Image</h3>
            <div class="mb-4">
                <label class="btn btn-primary btn-sm">
                    <span class="material-symbols-outlined">upload</span>
                    Upload New
                    <input type="file" accept="image/*" id="galleryUpload" class="hidden">
                </label>
            </div>
            <div id="galleryGrid" class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-5 gap-4 max-h-96 overflow-y-auto">
                <p class="col-span-full text-center text-base-content/50">Loading images...</p>
            </div>
        </div>
        <form method="dialog" class="modal-backdrop"><button>close</button></form>
    </dialog>

    <!-- Hidden data -->
    <script id="planData" type="application/json">{{{plan_json}}}</script>
    <script nonce="{{csp_nonce}}">
    const CSRF_TOKEN = '{{csrf_token}}';
    const QUEUE_ID = {{item.id}};
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
            content.className = 'page-content container mx-auto px-4 py-8' + (pageIndex === 0 ? ' active' : '');
            content.id = 'page-' + pageIndex;
            content.innerHTML = renderPageBlocks(page, pageIndex);
            contentsContainer.appendChild(content);
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
        if (!page.blocks) return '<p class="text-center text-base-content/50">No blocks</p>';
        return page.blocks.map((block, blockIndex) => {
            const blockHtml = renderBlock(block);
            return `<div class="editable-block" data-page="${pageIndex}" data-block="${blockIndex}" data-type="${block.type}">${blockHtml}</div>`;
        }).join('');
    }

    function renderBlock(block) {
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
                return `<div class="py-16">
                    <div class="card bg-base-100 shadow-xl max-w-2xl mx-auto">
                        <div class="card-body">
                            <h2 class="card-title text-2xl mb-6">Contact Us</h2>
                            <div class="space-y-4">
                                <div class="form-control"><label class="label"><span class="label-text">Name</span></label><input type="text" class="input input-bordered w-full" placeholder="Your name"></div>
                                <div class="form-control"><label class="label"><span class="label-text">Email</span></label><input type="email" class="input input-bordered w-full" placeholder="your@email.com"></div>
                                <div class="form-control"><label class="label"><span class="label-text">Message</span></label><textarea class="textarea textarea-bordered h-32" placeholder="Your message..."></textarea></div>
                                <button class="btn btn-primary w-full">Send Message</button>
                            </div>
                        </div>
                    </div>
                </div>`;
            
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
        
        // Generate editor form based on block type
        editor.innerHTML = getEditorForm(type, data);
        document.getElementById('blockEditorModal').showModal();
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
                        <select name="url" class="select select-bordered" onchange="this.nextElementSibling.nextElementSibling.value = this.value || ''">
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
                return getItemsEditor(type, data);
            
            case 'text':
                return `<div class="form-control"><label class="label"><span class="label-text">Content (HTML)</span></label>
                    <textarea name="content" class="textarea textarea-bordered h-48">${escapeHtml(data.content || '')}</textarea></div>`;
            
            default:
                return `<div class="alert alert-info">Editor for ${type} blocks coming soon. You can edit the raw JSON:</div>
                    <textarea name="raw_json" class="textarea textarea-bordered w-full h-48 font-mono text-sm">${escapeHtml(JSON.stringify(data, null, 2))}</textarea>`;
        }
    }

    function getItemsEditor(type, data) {
        const items = data.items || [];
        const fieldMap = {
            features: ['icon', 'title', 'description'],
            stats: ['icon', 'value', 'label'],
            testimonials: ['name', 'role', 'quote', 'rating'],
            faq: ['question', 'answer']
        };
        const fields = fieldMap[type] || ['title', 'description'];
        
        const itemsHtml = items.map((item, i) => {
            const fieldsHtml = fields.map(f => {
                const isTextarea = ['description', 'quote', 'answer', 'bio'].includes(f);
                const isIcon = f === 'icon';
                const val = escapeHtml(item[f] || '');
                
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
        const fieldMap = { features: ['icon', 'title', 'description'], stats: ['icon', 'value', 'label'], testimonials: ['name', 'role', 'quote', 'rating'], faq: ['question', 'answer'] };
        const fields = fieldMap[type] || ['title', 'description'];
        const container = document.getElementById('itemsContainer');
        const i = container.children.length;
        const fieldsHtml = fields.map(f => {
            const isTextarea = ['description', 'quote', 'answer'].includes(f);
            const isIcon = f === 'icon';
            
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
        
        // Handle raw JSON
        const rawJson = form.querySelector('[name="raw_json"]');
        if (rawJson) {
            try { Object.assign(newData, JSON.parse(rawJson.value)); } catch(e) {}
        }
        
        // Update block in planData
        block.content = newData;
        
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
                document.getElementById('blockEditorModal').close();
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
                document.getElementById('blockEditorModal').close();
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

    // Gallery state
    let currentGalleryTarget = null;
    let galleryImages = [];

    async function openGallery(targetName) {
        currentGalleryTarget = targetName;
        document.getElementById('imageGalleryModal').showModal();
        
        // Load images
        const grid = document.getElementById('galleryGrid');
        grid.innerHTML = '<p class="col-span-full text-center text-base-content/50">Loading images...</p>';
        
        try {
            const resp = await fetch('/admin/media/json');
            const data = await resp.json();
            galleryImages = data.images || [];
            
            if (galleryImages.length === 0) {
                grid.innerHTML = '<p class="col-span-full text-center text-base-content/50">No images uploaded yet. Upload an image using the button above.</p>';
                return;
            }
            
            grid.innerHTML = galleryImages.map(img => `
                <div class="cursor-pointer rounded-lg overflow-hidden border-2 border-transparent hover:border-primary transition-all" data-action="select-image" data-url="${escapeHtml(img.url)}">
                    <img src="${escapeHtml(img.url)}" alt="${escapeHtml(img.filename)}" class="w-full h-24 object-cover">
                    <div class="p-1 text-xs truncate bg-base-200">${escapeHtml(img.filename)}</div>
                </div>
            `).join('');
        } catch(e) {
            grid.innerHTML = '<p class="col-span-full text-center text-error">Failed to load images</p>';
        }
    }

    function selectImage(url) {
        if (!currentGalleryTarget) return;
        
        const input = document.querySelector(`[name="${currentGalleryTarget}"]`);
        if (input) {
            input.value = url;
            // Update preview if exists
            const preview = document.getElementById(currentGalleryTarget.replace(/[\[\]]/g, '') + '-preview') || document.getElementById('image-preview');
            if (preview) {
                preview.innerHTML = `<img src="${escapeHtml(url)}" class="mt-2 max-h-32 rounded-lg">`;
            }
        }
        
        document.getElementById('imageGalleryModal').close();
        currentGalleryTarget = null;
    }

    function clearImage(targetName) {
        const input = document.querySelector(`[name="${targetName}"]`);
        if (input) {
            input.value = '';
            const preview = document.getElementById('image-preview');
            if (preview) preview.innerHTML = '';
        }
    }

    // Gallery click handler
    document.getElementById('galleryGrid').addEventListener('click', function(e) {
        const item = e.target.closest('[data-action="select-image"]');
        if (item) {
            selectImage(item.dataset.url);
        }
    });

    // Gallery upload handler
    document.getElementById('galleryUpload').addEventListener('change', async function(e) {
        const file = e.target.files[0];
        if (!file) return;
        
        const formData = new FormData();
        formData.append('file', file);
        formData.append('_csrf', CSRF_TOKEN);
        
        try {
            const resp = await fetch('/admin/media/upload', { method: 'POST', body: formData });
            if (resp.ok) {
                // Reload gallery
                await openGallery(currentGalleryTarget);
            } else {
                alert('Upload failed');
            }
        } catch(e) {
            alert('Upload failed: ' + e.message);
        }
        
        e.target.value = '';
    });

    // Initialize
    init();
    </script>
</body>
</html>
HTML,
        ];
        
        return $templates[$name] ?? '';
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 10: CACHE MANAGEMENT
// ─────────────────────────────────────────────────────────────────────────────

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
        if (file_exists($file)) {
            unlink($file);
        }
    }
    
    // ─── PARTIAL CACHE ───────────────────────────────────────────────────────
    public static function invalidatePartial(string $name): void {
        $file = ONECMS_CACHE . '/partials/' . $name . '.html';
        if (file_exists($file)) {
            unlink($file);
        }
    }
    
    // ─── FULL INVALIDATION ───────────────────────────────────────────────────
    public static function invalidateAll(): void {
        $dirs = ['pages', 'partials'];
        foreach ($dirs as $dir) {
            $path = ONECMS_CACHE . '/' . $dir;
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
        foreach (glob(ONECMS_CACHE . '/pages/*.html') as $file) {
            unlink($file);
        }
    }
    
    private static function invalidateCSS(): void {
        foreach (glob(ONECMS_CACHE . '/assets/app.*.css') as $file) {
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
        
        // Render blocks
        $blocksHtml = '';
        foreach ($blocks as $block) {
            $blocksHtml .= PageController::renderBlockForCache($block);
        }
        
        // Render page
        $html = Template::render('page', array_merge($page, [
            'blocks_html' => $blocksHtml
        ]));
        
        // Cache it
        self::setPage($page['slug'], $html);
    }
    
    // ─── CSS HASH ────────────────────────────────────────────────────────────
    public static function getCSSHash(): string {
        // Find existing CSS file
        $files = glob(ONECMS_CACHE . '/assets/app.*.css');
        if (!empty($files)) {
            return basename($files[0], '.css');
        }
        
        // Generate new CSS
        return CSSGenerator::cacheAndGetHash();
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 10B: CDN CACHE
// ─────────────────────────────────────────────────────────────────────────────

class CDNCache {
    // CDN resources to cache locally
    private static array $resources = [
        'daisyui' => [
            'url' => 'https://cdn.jsdelivr.net/npm/daisyui@4.12.22/dist/full.min.css',
            'type' => 'css',
            'filename' => 'daisyui.min.css'
        ],
        'tailwind' => [
            'url' => 'https://cdn.tailwindcss.com',
            'type' => 'js',
            'filename' => 'tailwind.min.js'
        ],
        'tailwind-forms' => [
            'url' => 'https://cdn.tailwindcss.com?plugins=forms,container-queries',
            'type' => 'js',
            'filename' => 'tailwind-forms.min.js'
        ],
        'material-icons' => [
            'url' => 'https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200',
            'type' => 'css',
            'filename' => 'material-icons.css'
        ],
        'inter-font' => [
            'url' => 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap',
            'type' => 'css',
            'filename' => 'inter-font.css'
        ],
        'pico' => [
            'url' => 'https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css',
            'type' => 'css',
            'filename' => 'pico.min.css'
        ],
        'quill-css' => [
            'url' => 'https://cdn.quilljs.com/1.3.6/quill.snow.css',
            'type' => 'css',
            'filename' => 'quill.snow.css'
        ],
        'quill-js' => [
            'url' => 'https://cdn.quilljs.com/1.3.6/quill.min.js',
            'type' => 'js',
            'filename' => 'quill.min.js'
        ]
    ];
    
    /**
     * Initialize CDN cache - download all resources
     */
    public static function initialize(): array {
        $results = [];
        $cacheDir = ONECMS_CACHE . '/assets/cdn';
        
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        
        foreach (self::$resources as $name => $resource) {
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
                
                file_put_contents($filePath, $content);
                $results[$name] = ['status' => 'downloaded', 'path' => $filePath];
            } else {
                $results[$name] = ['status' => 'failed', 'url' => $resource['url']];
            }
        }
        
        return $results;
    }
    
    /**
     * Download a resource from URL
     */
    private static function downloadResource(string $url, string $type): string|false {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: Mozilla/5.0 (compatible; OneCMS/1.0)',
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
    
    /**
     * Process Google Fonts CSS - download font files and update URLs
     */
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
    
    /**
     * Get the local path for a CDN resource
     */
    public static function getPath(string $name): ?string {
        if (!isset(self::$resources[$name])) {
            return null;
        }
        
        $filePath = ONECMS_CACHE . '/assets/cdn/' . self::$resources[$name]['filename'];
        
        // Auto-download if not cached
        if (!file_exists($filePath)) {
            self::initialize();
        }
        
        return file_exists($filePath) ? '/cache/assets/cdn/' . self::$resources[$name]['filename'] : null;
    }
    
    /**
     * Get all local paths as an array for templates
     */
    public static function getPaths(): array {
        $paths = [];
        foreach (self::$resources as $name => $resource) {
            $paths[$name] = self::getPath($name);
        }
        return $paths;
    }
    
    /**
     * Serve a cached CDN resource
     */
    public static function serve(string $filename): void {
        $filePath = ONECMS_CACHE . '/assets/cdn/' . basename($filename);
        
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
        exit;
    }
    
    /**
     * Clear the CDN cache
     */
    public static function clear(): void {
        $cacheDir = ONECMS_CACHE . '/assets/cdn';
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
// SECTION 11: CSS GENERATOR
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
            // Dark mode variants
            'color_primary_dark' => '#60a5fa',
            'color_secondary_dark' => '#3b82f6',
            'color_accent_dark' => '#fbbf24',
            'color_background_dark' => '#0f172a',
            'color_background_secondary_dark' => '#1e293b',
            'color_text_dark' => '#f1f5f9',
            'color_text_muted_dark' => '#94a3b8',
            'color_border_dark' => '#334155',
        ];
        
        // Merge with stored values
        foreach ($defaults as $key => $default) {
            if (!isset($vars[$key]) || empty($vars[$key])) {
                $vars[$key] = $default;
            }
        }
        
        $css = <<<CSS
/* Light Mode (default) */
:root {
    --color-primary: {$vars['color_primary']};
    --color-secondary: {$vars['color_secondary']};
    --color-accent: {$vars['color_accent']};
    --color-background: {$vars['color_background']};
    --color-background-secondary: {$vars['color_background_secondary']};
    --color-text: {$vars['color_text']};
    --color-text-muted: {$vars['color_text_muted']};
    --color-border: {$vars['color_border']};
    --font-family: {$vars['font_family']};
    --radius-sm: {$vars['radius_sm']};
    --radius-md: {$vars['radius_md']};
    --radius-lg: {$vars['radius_lg']};
    --radius-xl: {$vars['radius_xl']};
    --shadow-sm: {$vars['shadow_sm']};
    --shadow-md: {$vars['shadow_md']};
    --shadow-lg: {$vars['shadow_lg']};
    --shadow-xl: {$vars['shadow_xl']};
    color-scheme: light dark;
}

/* Dark Mode */
@media (prefers-color-scheme: dark) {
    :root {
        --color-primary: {$vars['color_primary_dark']};
        --color-secondary: {$vars['color_secondary_dark']};
        --color-accent: {$vars['color_accent_dark']};
        --color-background: {$vars['color_background_dark']};
        --color-background-secondary: {$vars['color_background_secondary_dark']};
        --color-text: {$vars['color_text_dark']};
        --color-text-muted: {$vars['color_text_muted_dark']};
        --color-border: {$vars['color_border_dark']};
        --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.2);
        --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.3);
        --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.3);
    }
}

/* Reset */
*, *::before, *::after { box-sizing: border-box; }
body { 
    margin: 0; 
    font-family: var(--font-family); 
    color: var(--color-text); 
    background: var(--color-background); 
    line-height: 1.6; 
}

/* Typography */
h1, h2, h3, h4, h5, h6 { margin-top: 0; line-height: 1.2; }
a { color: var(--color-primary); }

/* Buttons */
.btn {
    display: inline-block;
    padding: 0.75rem 1.5rem;
    border-radius: var(--radius-md);
    font-weight: 600;
    text-decoration: none;
    transition: all 0.2s ease;
    cursor: pointer;
    border: none;
}
.btn-primary {
    background: var(--color-primary);
    color: #fff;
}
.btn-primary:hover {
    filter: brightness(1.1);
    transform: translateY(-1px);
    box-shadow: var(--shadow-md);
}
.btn-secondary {
    background: var(--color-background-secondary);
    color: var(--color-text);
    border: 1px solid var(--color-border);
}
.btn-secondary:hover {
    background: var(--color-border);
}

/* Layout */
.container { max-width: 1200px; margin: 0 auto; padding: 0 1rem; }

/* Header */
.site-header { padding: 1rem; }
.header-inner { display: flex; align-items: center; gap: 1rem; }
.logo { max-height: 60px; }
.site-name { font-size: 1.5rem; font-weight: 700; text-decoration: none; color: inherit; }
.tagline { font-size: 1.1rem; opacity: 0.9; }

/* Navigation */
.main-nav { background: var(--color-secondary); }
.nav-list { 
    list-style: none; 
    padding: 0; 
    margin: 0; 
    display: flex; 
    flex-wrap: wrap;
    gap: 0.25rem; 
}
.nav-list a { 
    color: #fff; 
    text-decoration: none; 
    padding: 0.75rem 1rem;
    display: block;
    transition: background 0.2s;
}
.nav-list a:hover { background: rgba(255,255,255,0.1); }

/* Main Content */
main.container { padding: 2rem 1rem; min-height: 60vh; }

/* Footer */
.site-footer { 
    padding: 2rem; 
    text-align: center; 
    margin-top: 2rem; 
}

/* ═══════════════════════════════════════════════════════════════════════════
   CONTENT BLOCKS
   ═══════════════════════════════════════════════════════════════════════════ */

.block { margin-bottom: 3rem; }

/* Hero Block - Large banner with gradient */
.block-hero { 
    padding: 5rem 2rem; 
    text-align: center; 
    background: linear-gradient(135deg, var(--color-primary), var(--color-secondary)); 
    color: #fff; 
    border-radius: var(--radius-lg);
    margin-bottom: 2rem;
    position: relative;
    overflow: hidden;
}
.block-hero::before {
    content: '';
    position: absolute;
    top: 0; right: 0; bottom: 0; left: 0;
    background: radial-gradient(circle at 30% 70%, rgba(255,255,255,0.1) 0%, transparent 50%);
}
.block-hero h2 { font-size: 3rem; margin-bottom: 1rem; position: relative; }
.block-hero p { font-size: 1.25rem; opacity: 0.95; max-width: 600px; margin: 0 auto 1.5rem; position: relative; }
.block-hero .btn { 
    background: #fff; 
    color: var(--color-primary); 
    font-size: 1.1rem;
    padding: 1rem 2rem;
    box-shadow: var(--shadow-lg);
}
.block-hero .btn:hover { transform: translateY(-2px); box-shadow: var(--shadow-xl); }

/* Text Block */
.block-text { padding: 1rem 0; }
.block-text img { max-width: 100%; height: auto; border-radius: var(--radius-md); }

/* Image Block */
.block-image { text-align: center; }
.block-image img { max-width: 100%; height: auto; border-radius: var(--radius-lg); box-shadow: var(--shadow-md); }
.block-image .caption { color: var(--color-text-muted); margin-top: 0.5rem; font-size: 0.9rem; }

/* CTA Block */
.block-cta {
    background: linear-gradient(135deg, var(--color-primary), var(--color-secondary));
    color: #fff;
    padding: 4rem 2rem;
    text-align: center;
    border-radius: var(--radius-lg);
    position: relative;
    overflow: hidden;
}
.block-cta::before {
    content: '';
    position: absolute;
    top: -50%; right: -50%;
    width: 100%; height: 200%;
    background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 60%);
}
.block-cta h3 { font-size: 2rem; margin-bottom: 0.5rem; position: relative; }
.block-cta p { font-size: 1.1rem; opacity: 0.95; margin-bottom: 1.5rem; position: relative; }
.block-cta .btn { 
    background: #fff;
    color: var(--color-primary);
    padding: 1rem 2rem;
    font-size: 1rem;
    box-shadow: var(--shadow-md);
}

/* Gallery Block */
.block-gallery { 
    display: grid; 
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); 
    gap: 1.5rem; 
}
.block-gallery img { 
    width: 100%; 
    height: 220px; 
    object-fit: cover; 
    border-radius: var(--radius-lg); 
    box-shadow: var(--shadow-md);
    transition: transform 0.3s, box-shadow 0.3s;
}
.block-gallery img:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-xl);
}

/* ═══════════════════════════════════════════════════════════════════════════
   ADVANCED BLOCKS - Cards, Features, Stats, Testimonials, Pricing, Team
   ═══════════════════════════════════════════════════════════════════════════ */

/* Block Title (common for all advanced blocks) */
.block-title {
    font-size: 2rem;
    font-weight: 700;
    text-align: center;
    margin-bottom: 2rem;
    color: var(--color-text);
}

/* Feature Grid */
.block-features {
    padding: 2rem 0;
}
.features-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 2rem;
}
.feature-card {
    background: var(--color-background-secondary);
    padding: 2rem;
    border-radius: var(--radius-lg);
    border: 1px solid var(--color-border);
    transition: transform 0.2s, box-shadow 0.2s;
}
.feature-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-lg);
}
.feature-icon {
    width: 48px;
    height: 48px;
    background: linear-gradient(135deg, var(--color-primary), var(--color-secondary));
    border-radius: var(--radius-md);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 1.5rem;
    margin-bottom: 1rem;
}
.feature-card h4 { margin-bottom: 0.5rem; font-size: 1.25rem; }
.feature-card p { color: var(--color-text-muted); margin: 0; }

/* Stats Section */
.block-stats {
    padding: 3rem 2rem;
    background: var(--color-background-secondary);
    border-radius: var(--radius-lg);
    text-align: center;
}
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 2rem;
}
.stat-item h3 {
    font-size: 3rem;
    color: var(--color-primary);
    margin-bottom: 0.25rem;
    font-weight: 700;
}
.stat-item p {
    color: var(--color-text-muted);
    margin: 0;
    font-size: 1rem;
}

/* Testimonial Cards */
.block-testimonials {
    padding: 2rem 0;
}
.testimonials-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap: 2rem;
}
.testimonial-card {
    background: var(--color-background);
    padding: 2rem;
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-md);
    border: 1px solid var(--color-border);
    position: relative;
}
.testimonial-card::before {
    content: '"';
    position: absolute;
    top: 1rem;
    left: 1.5rem;
    font-size: 4rem;
    color: var(--color-primary);
    opacity: 0.2;
    font-family: Georgia, serif;
    line-height: 1;
}
.testimonial-content {
    font-size: 1.1rem;
    font-style: italic;
    margin-bottom: 1.5rem;
    position: relative;
}
.testimonial-author {
    display: flex;
    align-items: center;
    gap: 1rem;
}
.testimonial-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--color-primary), var(--color-secondary));
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-weight: 600;
}
.testimonial-name { font-weight: 600; }
.testimonial-role { color: var(--color-text-muted); font-size: 0.9rem; }

/* Pricing Cards */
.block-pricing {
    padding: 2rem 0;
}
.pricing-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 2rem;
}
.pricing-card {
    background: var(--color-background);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-xl);
    padding: 2rem;
    text-align: center;
    transition: transform 0.2s, box-shadow 0.2s;
}
.pricing-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-lg);
}
.pricing-card.featured {
    border: 2px solid var(--color-primary);
    position: relative;
}
.pricing-card.featured::before {
    content: 'Popular';
    position: absolute;
    top: -12px;
    left: 50%;
    transform: translateX(-50%);
    background: var(--color-primary);
    color: #fff;
    padding: 0.25rem 1rem;
    border-radius: var(--radius-sm);
    font-size: 0.85rem;
    font-weight: 600;
}
.pricing-name { font-size: 1.5rem; font-weight: 600; margin-bottom: 0.5rem; }
.pricing-price { font-size: 3rem; font-weight: 700; color: var(--color-primary); }
.pricing-price span { font-size: 1rem; color: var(--color-text-muted); font-weight: 400; }
.pricing-features { list-style: none; padding: 0; margin: 1.5rem 0; text-align: left; }
.pricing-features li { 
    padding: 0.5rem 0; 
    border-bottom: 1px solid var(--color-border);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.pricing-features li::before { content: '✓'; color: var(--color-primary); font-weight: bold; }
.pricing-card .btn { width: 100%; margin-top: 1rem; }

/* Team Grid */
.block-team {
    padding: 2rem 0;
}
.team-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 2rem;
}
.team-card {
    text-align: center;
    padding: 2rem;
    background: var(--color-background-secondary);
    border-radius: var(--radius-lg);
    transition: transform 0.2s;
}
.team-card:hover { transform: translateY(-4px); }
.team-photo {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    margin: 0 auto 1rem;
    background: linear-gradient(135deg, var(--color-primary), var(--color-secondary));
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 2.5rem;
    font-weight: 600;
    overflow: hidden;
}
.team-photo img { width: 100%; height: 100%; object-fit: cover; }
.team-name { font-size: 1.25rem; font-weight: 600; margin-bottom: 0.25rem; }
.team-role { color: var(--color-primary); margin-bottom: 0.5rem; }
.team-bio { color: var(--color-text-muted); font-size: 0.9rem; }

/* Cards Grid (Generic) */
.block-cards {
    padding: 2rem 0;
}
.cards-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 2rem;
}
.card-icon {
    font-size: 2.5rem;
    text-align: center;
    padding: 1.5rem 0 0.5rem;
}
.card {
    background: var(--color-background);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
    overflow: hidden;
    transition: transform 0.2s, box-shadow 0.2s;
}
.card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-lg);
}
.card-image {
    height: 200px;
    background: linear-gradient(135deg, var(--color-primary), var(--color-secondary));
    overflow: hidden;
}
.card-image img { width: 100%; height: 100%; object-fit: cover; }
.card-content { padding: 1.5rem; }
.card-content h4 { margin-bottom: 0.5rem; }
.card-content p { color: var(--color-text-muted); margin-bottom: 1rem; }

/* FAQ Accordion */
.block-faq { max-width: 800px; margin: 0 auto; padding: 2rem 0; }
.faq-item {
    border-bottom: 1px solid var(--color-border);
    padding: 1.5rem 0;
}
details.faq-item[open] .faq-question::after { content: '−'; }
.faq-question {
    font-weight: 600;
    font-size: 1.1rem;
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    align-items: center;
    list-style: none;
}
.faq-question::-webkit-details-marker { display: none; }
.faq-question::after { content: '+'; font-size: 1.5rem; color: var(--color-primary); }
.faq-answer { 
    color: var(--color-text-muted); 
    margin-top: 1rem; 
    padding-left: 0;
}

/* Form Block */
.block-form { 
    max-width: 600px; 
    margin: 0 auto; 
    background: var(--color-background-secondary);
    padding: 2rem;
    border-radius: var(--radius-lg);
}
.block-form h3 { margin-bottom: 1.5rem; }
.block-form label { display: block; margin-bottom: 1rem; font-weight: 500; }
.block-form input, 
.block-form textarea { 
    width: 100%; 
    padding: 0.75rem 1rem; 
    border: 1px solid var(--color-border); 
    border-radius: var(--radius-md);
    margin-top: 0.5rem;
    background: var(--color-background);
    color: var(--color-text);
    font-size: 1rem;
    transition: border-color 0.2s, box-shadow 0.2s;
}
.block-form input:focus,
.block-form textarea:focus {
    outline: none;
    border-color: var(--color-primary);
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}
.block-form textarea { min-height: 120px; resize: vertical; }
.block-form button { 
    background: var(--color-primary); 
    color: #fff; 
    padding: 1rem 2rem; 
    border: none; 
    border-radius: var(--radius-md); 
    cursor: pointer;
    font-size: 1rem;
    font-weight: 600;
    width: 100%;
    transition: all 0.2s;
}
.block-form button:hover { 
    filter: brightness(1.1); 
    transform: translateY(-1px);
    box-shadow: var(--shadow-md);
}

/* ═══════════════════════════════════════════════════════════════════════════
   ADDITIONAL BLOCK TYPES
   ═══════════════════════════════════════════════════════════════════════════ */

/* Quote Block */
.block-quote {
    max-width: 800px;
    margin: 2rem auto;
    padding: 2rem 3rem;
    background: var(--color-background-secondary);
    border-left: 4px solid var(--color-primary);
    border-radius: var(--radius-lg);
    font-size: 1.25rem;
    font-style: italic;
    position: relative;
}
.block-quote::before {
    content: '"';
    position: absolute;
    top: -0.5rem;
    left: 1rem;
    font-size: 4rem;
    color: var(--color-primary);
    opacity: 0.3;
    font-family: Georgia, serif;
}
.block-quote p { margin: 0 0 1rem; }
.quote-author { display: block; font-style: normal; font-weight: 600; margin-top: 1rem; }
.quote-role { display: block; font-size: 0.9rem; color: var(--color-text-muted); font-weight: 400; }

/* Divider Block */
.block-divider { padding: 2rem 0; text-align: center; }
.divider-line hr { border: none; border-top: 1px solid var(--color-border); }
.divider-dots span { color: var(--color-text-muted); letter-spacing: 0.5rem; font-size: 1.5rem; }
.divider-icon span { font-size: 2rem; }

/* Video Block */
.block-video { max-width: 900px; margin: 0 auto; padding: 2rem 0; }
.video-wrapper { position: relative; padding-bottom: 56.25%; height: 0; overflow: hidden; border-radius: var(--radius-lg); }
.video-wrapper iframe { position: absolute; top: 0; left: 0; width: 100%; height: 100%; border: none; }
.video-caption { text-align: center; color: var(--color-text-muted); margin-top: 1rem; font-size: 0.9rem; }

/* Carousel Block */
.block-carousel { position: relative; max-width: 1000px; margin: 0 auto; padding: 2rem 0; }
.carousel-track { display: flex; overflow: hidden; border-radius: var(--radius-lg); }
.carousel-slide { flex: 0 0 100%; position: relative; display: none; }
.carousel-slide.active { display: block; }
.carousel-slide img { width: 100%; height: 400px; object-fit: cover; }
.carousel-content { position: absolute; bottom: 0; left: 0; right: 0; padding: 2rem; background: linear-gradient(transparent, rgba(0,0,0,0.8)); color: #fff; }
.carousel-content h4 { margin: 0 0 0.5rem; }
.carousel-content p { margin: 0; opacity: 0.9; }
.carousel-nav { position: absolute; top: 50%; left: 0; right: 0; display: flex; justify-content: space-between; transform: translateY(-50%); padding: 0 1rem; pointer-events: none; }
.carousel-prev, .carousel-next { width: 40px; height: 40px; border-radius: 50%; background: rgba(255,255,255,0.9); border: none; cursor: pointer; font-size: 1.5rem; pointer-events: auto; transition: background 0.2s; }
.carousel-prev:hover, .carousel-next:hover { background: #fff; }

/* Checklist Block */
.block-checklist { max-width: 600px; margin: 0 auto; padding: 2rem 0; }
.checklist-items { list-style: none; padding: 0; }
.checklist-items li { display: flex; align-items: flex-start; gap: 0.75rem; padding: 0.75rem 0; border-bottom: 1px solid var(--color-border); }
.check-icon { color: var(--color-primary); font-weight: bold; flex-shrink: 0; }

/* Logo Cloud Block */
.block-logo-cloud { padding: 3rem 0; text-align: center; }
.logo-grid { display: flex; flex-wrap: wrap; justify-content: center; align-items: center; gap: 3rem; margin-top: 2rem; }
.logo-item { opacity: 0.7; transition: opacity 0.2s; }
.logo-item:hover { opacity: 1; }
.logo-item img { max-height: 50px; max-width: 150px; filter: grayscale(100%); transition: filter 0.2s; }
.logo-item:hover img { filter: none; }
.logo-text { font-size: 1.25rem; font-weight: 600; color: var(--color-text-muted); }

/* Comparison Table Block */
.block-comparison { padding: 2rem 0; }
.table-responsive { overflow-x: auto; }
.comparison-table { width: 100%; border-collapse: collapse; }
.comparison-table th, .comparison-table td { padding: 1rem; text-align: center; border-bottom: 1px solid var(--color-border); }
.comparison-table th { background: var(--color-background-secondary); font-weight: 600; }
.comparison-table .feature-name { text-align: left; font-weight: 500; }
.comparison-table .check { color: var(--color-primary); font-weight: bold; }
.comparison-table .cross { color: var(--color-text-muted); }

/* Tabs Block */
.block-tabs { padding: 2rem 0; }
.tabs-nav { display: flex; gap: 0; border-bottom: 2px solid var(--color-border); margin-bottom: 1.5rem; }
.tab-btn { padding: 1rem 1.5rem; background: none; border: none; cursor: pointer; font-weight: 500; color: var(--color-text-muted); border-bottom: 2px solid transparent; margin-bottom: -2px; transition: all 0.2s; }
.tab-btn:hover { color: var(--color-text); }
.tab-btn.active { color: var(--color-primary); border-bottom-color: var(--color-primary); }
.tab-panel { display: none; }
.tab-panel.active { display: block; }

/* Accordion Block */
.block-accordion { max-width: 800px; margin: 0 auto; padding: 2rem 0; }
.accordion-item { border-bottom: 1px solid var(--color-border); }
.accordion-item summary { padding: 1.25rem 0; font-weight: 600; cursor: pointer; list-style: none; display: flex; justify-content: space-between; align-items: center; }
.accordion-item summary::-webkit-details-marker { display: none; }
.accordion-item summary::after { content: '+'; font-size: 1.25rem; color: var(--color-primary); }
.accordion-item[open] summary::after { content: '−'; }
.accordion-content { padding: 0 0 1.25rem; color: var(--color-text-muted); }

/* Table Block */
.block-table { padding: 2rem 0; }
.block-table table { width: 100%; border-collapse: collapse; }
.block-table th, .block-table td { padding: 1rem; text-align: left; border-bottom: 1px solid var(--color-border); }
.block-table th { background: var(--color-background-secondary); font-weight: 600; }
.block-table tr:hover { background: var(--color-background-secondary); }

/* Timeline Block */
.block-timeline { padding: 2rem 0; }
.timeline-track { position: relative; padding-left: 2rem; }
.timeline-track::before { content: ''; position: absolute; left: 0.5rem; top: 0; bottom: 0; width: 2px; background: var(--color-border); }
.timeline-item { position: relative; padding-bottom: 2rem; }
.timeline-marker { position: absolute; left: -1.5rem; top: 0.25rem; width: 12px; height: 12px; background: var(--color-primary); border-radius: 50%; border: 3px solid var(--color-background); }
.timeline-date { font-size: 0.85rem; color: var(--color-primary); font-weight: 600; margin-bottom: 0.25rem; }
.timeline-content h4 { margin: 0 0 0.5rem; }
.timeline-content p { margin: 0; color: var(--color-text-muted); }

/* List Block */
.block-list { padding: 1.5rem 0; }
.block-list ul, .block-list ol { padding-left: 1.5rem; }
.block-list li { padding: 0.5rem 0; }
.list-check { list-style: none; padding-left: 0; }
.list-check li { display: flex; gap: 0.75rem; }
.list-icon { color: var(--color-primary); font-weight: bold; }

/* Newsletter Block */
.block-newsletter { max-width: 600px; margin: 0 auto; padding: 3rem 2rem; background: var(--color-background-secondary); border-radius: var(--radius-lg); text-align: center; }
.block-newsletter h3 { margin: 0 0 0.5rem; }
.block-newsletter p { color: var(--color-text-muted); margin-bottom: 1.5rem; }
.newsletter-form { display: flex; gap: 0.5rem; max-width: 400px; margin: 0 auto; }
.newsletter-form input { flex: 1; padding: 0.75rem 1rem; border: 1px solid var(--color-border); border-radius: var(--radius-md); }
.newsletter-form button { padding: 0.75rem 1.5rem; background: var(--color-primary); color: #fff; border: none; border-radius: var(--radius-md); cursor: pointer; font-weight: 600; }

/* Download Block */
.block-download { display: flex; align-items: center; gap: 1.5rem; padding: 1.5rem 2rem; background: var(--color-background-secondary); border-radius: var(--radius-lg); max-width: 600px; margin: 2rem auto; }
.download-icon { font-size: 3rem; }
.download-content { flex: 1; }
.download-content h4 { margin: 0 0 0.25rem; }
.download-content p { margin: 0; color: var(--color-text-muted); font-size: 0.9rem; }

/* Alert Block */
.block-alert { display: flex; align-items: flex-start; gap: 1rem; padding: 1rem 1.5rem; border-radius: var(--radius-md); margin: 1rem 0; }
.alert-icon { font-size: 1.5rem; line-height: 1; }
.alert-content strong { display: block; margin-bottom: 0.25rem; }
.alert-content p { margin: 0; }
.alert-info { background: #dbeafe; color: #1e40af; }
.alert-success { background: #d1fae5; color: #065f46; }
.alert-warning { background: #fef3c7; color: #92400e; }
.alert-error { background: #fee2e2; color: #991b1b; }

/* Progress Block */
.block-progress { padding: 2rem 0; max-width: 600px; margin: 0 auto; }
.progress-item { margin-bottom: 1.5rem; }
.progress-label { display: flex; justify-content: space-between; margin-bottom: 0.5rem; font-weight: 500; }
.progress-bar { height: 8px; background: var(--color-border); border-radius: 4px; overflow: hidden; }
.progress-fill { height: 100%; background: linear-gradient(90deg, var(--color-primary), var(--color-secondary)); border-radius: 4px; transition: width 0.5s ease; }

/* Steps Block */
.block-steps { padding: 3rem 0; }
.steps-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 2rem; counter-reset: step; }
.step-item { text-align: center; padding: 1.5rem; }
.step-number { width: 50px; height: 50px; background: linear-gradient(135deg, var(--color-primary), var(--color-secondary)); color: #fff; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; font-weight: 700; margin: 0 auto 1rem; }
.step-item h4 { margin: 0 0 0.5rem; }
.step-item p { margin: 0; color: var(--color-text-muted); font-size: 0.9rem; }

/* Columns Block */
.block-columns { display: grid; gap: 2rem; padding: 1.5rem 0; }
.columns-2 { grid-template-columns: repeat(2, 1fr); }
.columns-3 { grid-template-columns: repeat(3, 1fr); }
.columns-4 { grid-template-columns: repeat(4, 1fr); }
@media (max-width: 768px) {
    .columns-2, .columns-3, .columns-4 { grid-template-columns: 1fr; }
}

/* Spacer Block */
.block-spacer { }

/* Map Block */
.block-map { padding: 2rem 0; }
.map-embed { border-radius: var(--radius-lg); overflow: hidden; height: 400px; }
.map-embed iframe { width: 100%; height: 100%; border: none; }
.map-address { text-align: center; margin-top: 1rem; color: var(--color-text-muted); }

/* Contact Info Block */
.block-contact-info { padding: 2rem 0; }
.contact-items { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.5rem; margin-top: 1.5rem; }
.contact-item { display: flex; align-items: flex-start; gap: 1rem; padding: 1rem; background: var(--color-background-secondary); border-radius: var(--radius-md); }
.contact-icon { font-size: 1.5rem; }
.contact-label { display: block; font-size: 0.85rem; color: var(--color-text-muted); }
.contact-value { font-weight: 500; }
.contact-value a { color: var(--color-primary); text-decoration: none; }

/* Social Block */
.block-social { padding: 2rem 0; text-align: center; }
.social-links { display: flex; justify-content: center; gap: 1rem; margin-top: 1rem; }
.social-link { width: 50px; height: 50px; display: flex; align-items: center; justify-content: center; background: var(--color-background-secondary); border-radius: 50%; font-size: 1.5rem; text-decoration: none; transition: transform 0.2s, box-shadow 0.2s; }
.social-link:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }

/* Flash Messages */
.flash { padding: 1rem; margin-bottom: 1rem; border-radius: var(--radius-md); }
.flash-success { background: #d1fae5; color: #065f46; }
.flash-error { background: #fee2e2; color: #991b1b; }
.flash-info { background: #dbeafe; color: #1e40af; }

@media (prefers-color-scheme: dark) {
    .flash-success { background: #065f46; color: #d1fae5; }
    .flash-error { background: #991b1b; color: #fee2e2; }
    .flash-info { background: #1e40af; color: #dbeafe; }
}

/* Editor Bar (for logged-in editors) */
.onecms-edit-bar {
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
    background: #3b82f6;
    color: #fff;
}
.edit-bar-btn-edit:hover {
    background: #2563eb;
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
body:has(.onecms-edit-bar) {
    padding-top: 50px;
}

/* Responsive */
@media (max-width: 768px) {
    .nav-list { flex-direction: column; }
    .block-hero { padding: 3rem 1.5rem; }
    .block-hero h2 { font-size: 2rem; }
    .block-hero p { font-size: 1rem; }
    .block-stats { padding: 2rem 1rem; }
    .stat-item h3 { font-size: 2rem; }
    .pricing-price { font-size: 2.5rem; }
}
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
        $hash = 'app.' . substr(md5($css), 0, 12);
        
        $file = ONECMS_CACHE . '/assets/' . $hash . '.css';
        if (!file_exists($file)) {
            // Clear old CSS files
            foreach (glob(ONECMS_CACHE . '/assets/app.*.css') as $oldFile) {
                unlink($oldFile);
            }
            file_put_contents($file, $css);
        }
        
        return $hash;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 12: ASSET MANAGEMENT
// ─────────────────────────────────────────────────────────────────────────────

class Asset {
    private const ALLOWED_TYPES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/svg+xml', 'image/webp',
        'application/pdf', 'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    ];
    
    private const MAX_SIZE = 10 * 1024 * 1024; // 10MB
    
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
        $hash = hash('sha256', $content);
        
        // Check for duplicate
        $existing = DB::fetch("SELECT id FROM assets WHERE hash = ?", [$hash]);
        if ($existing) {
            return (int) $existing['id'];
        }
        
        // Strip metadata from images (security)
        if (str_starts_with($mimeType, 'image/') && $mimeType !== 'image/svg+xml') {
            $content = self::stripMetadata($content, $mimeType);
        }
        
        // Store in database
        DB::execute(
            "INSERT INTO assets (filename, mime_type, blob_data, hash, file_size, created_at) 
             VALUES (?, ?, ?, ?, ?, datetime('now'))",
            [Sanitize::filename($file['name']), $mimeType, $content, $hash, strlen($content)]
        );
        
        return DB::lastInsertId();
    }
    
    public static function get(int $id): ?array {
        return DB::fetch("SELECT * FROM assets WHERE id = ?", [$id]);
    }
    
    public static function getByHash(string $hash): ?array {
        return DB::fetch("SELECT * FROM assets WHERE hash = ?", [$hash]);
    }
    
    public static function serve(string $hash): void {
        $asset = self::getByHash($hash);
        
        if (!$asset) {
            http_response_code(404);
            exit;
        }
        
        // Set cache headers (1 year)
        header('Content-Type: ' . $asset['mime_type']);
        header('Content-Length: ' . strlen($asset['blob_data']));
        header('Cache-Control: public, max-age=31536000, immutable');
        header('ETag: "' . $hash . '"');
        
        // Handle conditional requests
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === '"' . $hash . '"') {
            http_response_code(304);
            exit;
        }
        
        echo $asset['blob_data'];
        exit;
    }
    
    public static function url(int $id): string {
        $asset = self::get($id);
        return $asset ? '/assets/' . $asset['hash'] : '';
    }
    
    public static function delete(int $id): void {
        DB::execute("DELETE FROM assets WHERE id = ?", [$id]);
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
// SECTION 13: CONTROLLERS
// ─────────────────────────────────────────────────────────────────────────────

class SetupController {
    public static function index(): void {
        // Already set up?
        $hasAdmin = DB::fetch("SELECT COUNT(*) as count FROM users WHERE role = 'admin'");
        if ($hasAdmin && $hasAdmin['count'] > 0) {
            Response::redirect('/admin/login');
        }
        
        $step = (int) Request::input('step', 1);
        
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
        
        $siteName = trim(Request::input('site_name', 'OneCMS'));
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
        
        $provider = Request::input('ai_provider', '');
        $apiKey = Request::input('ai_api_key', '');
        
        if ($provider && $apiKey) {
            Settings::set('ai_provider', $provider);
            Settings::setEncrypted('ai_api_key', $apiKey);
        }
        
        Response::redirect('/setup?step=4');
    }
    
    private static function processStep4(): void {
        CSRF::require();
        
        $host = trim(Request::input('smtp_host', ''));
        $port = (int) Request::input('smtp_port', 587);
        $user = trim(Request::input('smtp_user', ''));
        $pass = Request::input('smtp_pass', '');
        $from = trim(Request::input('smtp_from', ''));
        
        if ($host) {
            Settings::set('smtp_host', $host);
            Settings::set('smtp_port', (string) $port);
            Settings::set('smtp_user', $user);
            Settings::set('smtp_from', $from ?: $user);
            if ($pass) {
                Settings::setEncrypted('smtp_pass', $pass);
            }
        }
        
        Settings::set('setup_complete', '1');
        
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
        CSRF::require();
        
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
        Session::flash('success', 'You have been logged out.');
        Response::redirect('/admin/login');
    }
}

class AdminController {
    /**
     * Helper to render admin page with common layout data
     */
    private static function renderAdmin(string $template, string $title, array $data = [], array $layoutFlags = []): void {
        $user = Auth::user();
        $pendingCount = DB::fetch("SELECT COUNT(*) as c FROM build_queue WHERE status = 'pending'")['c'] ?? 0;
        
        $content = Template::render($template, $data);
        
        $layoutData = array_merge([
            'title' => $title,
            'content' => $content,
            'user_email' => $user['email'] ?? '',
            'user_role' => ucfirst($user['role'] ?? 'User'),
            'pending_count' => $pendingCount,
            'flash_error' => Session::getFlash('error'),
            'flash_success' => Session::getFlash('success'),
            'csp_nonce' => defined('CSP_NONCE') ? CSP_NONCE : '',
            'is_dashboard' => false,
            'is_pages' => false,
            'is_nav' => false,
            'is_media' => false,
            'is_theme' => false,
            'is_users' => false,
            'is_ai' => false,
            'is_approvals' => false,
        ], $layoutFlags);
        
        Response::html(Template::render('admin_layout', $layoutData));
    }
    
    // ─── DASHBOARD ───────────────────────────────────────────────────────────
    public static function dashboard(): void {
        Auth::require();
        
        $stats = [
            'pages_count' => DB::fetch("SELECT COUNT(*) as c FROM pages")['c'] ?? 0,
            'published_count' => DB::fetch("SELECT COUNT(*) as c FROM pages WHERE status = 'published'")['c'] ?? 0,
            'assets_count' => DB::fetch("SELECT COUNT(*) as c FROM assets")['c'] ?? 0,
            'pending_count' => DB::fetch("SELECT COUNT(*) as c FROM build_queue WHERE status = 'pending'")['c'] ?? 0,
        ];
        
        $recentPages = DB::fetchAll(
            "SELECT * FROM pages ORDER BY updated_at DESC LIMIT 5"
        );
        
        // Add display flags
        foreach ($recentPages as &$page) {
            $page['is_published'] = $page['status'] === 'published';
        }
        
        $stats['recent_pages'] = $recentPages;
        $stats['has_pages'] = count($recentPages) > 0;
        
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
        
        global $cspNonce;
        
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
        
        // Get navigation items for position selector
        $navItems = DB::fetchAll("SELECT id, label, sort_order FROM nav ORDER BY sort_order ASC");
        foreach ($navItems as &$item) {
            $item['sort_order_after'] = $item['sort_order'] + 1;
        }
        
        $content = Template::render('admin/page_edit', array_merge($page, [
            'blocks' => $blocks,
            'block_count' => count($blocks),
            'nav_items' => $navItems,
            'csp_nonce' => $cspNonce
        ]));
        
        $title = $id === 'new' ? 'Create Page' : 'Edit: ' . $page['title'];
        self::renderAdmin('admin/page_edit', $title, array_merge($page, [
            'blocks' => $blocks,
            'block_count' => count($blocks),
            'nav_items' => $navItems,
            'csp_nonce' => $cspNonce
        ]), ['is_pages' => true]);
    }
    
    private static function renderBlockFields(array $block): string {
        $data = json_decode($block['block_json'], true) ?? [];
        $id = $block['id'];
        $inputClass = 'w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#135bec] focus:border-[#135bec] outline-none';
        $labelClass = 'block text-sm font-medium text-gray-700 mb-1';
        
        return match ($block['type']) {
            'hero' => sprintf(
                '<div class="space-y-3">
                    <div><label class="%s">Heading</label><input type="text" name="blocks[%s][data][title]" value="%s" class="%s"></div>
                    <div><label class="%s">Subtitle</label><input type="text" name="blocks[%s][data][subtitle]" value="%s" class="%s"></div>
                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="%s">Button Text</label><input type="text" name="blocks[%s][data][button]" value="%s" class="%s"></div>
                        <div><label class="%s">Button URL</label><input type="text" name="blocks[%s][data][url]" value="%s" class="%s"></div>
                    </div>
                </div>',
                $labelClass, $id, Sanitize::html($data['title'] ?? ''), $inputClass,
                $labelClass, $id, Sanitize::html($data['subtitle'] ?? ''), $inputClass,
                $labelClass, $id, Sanitize::html($data['button'] ?? ''), $inputClass,
                $labelClass, $id, Sanitize::html($data['url'] ?? ''), $inputClass
            ),
            'text' => sprintf(
                '<div><label class="%s">Content</label><div class="wysiwyg-editor bg-white border border-gray-300 rounded-lg" data-field="%s">%s</div><textarea name="blocks[%s][data][content]" class="wysiwyg-content hidden">%s</textarea></div>',
                $labelClass, $id, $data['content'] ?? '',
                $id, Sanitize::html($data['content'] ?? '')
            ),
            'image' => sprintf(
                '<div class="space-y-3">
                    <div><label class="%s">Image URL</label><input type="text" name="blocks[%s][data][url]" value="%s" class="%s"></div>
                    <div><label class="%s">Alt Text</label><input type="text" name="blocks[%s][data][alt]" value="%s" class="%s"></div>
                    <div><label class="%s">Caption</label><input type="text" name="blocks[%s][data][caption]" value="%s" class="%s"></div>
                </div>',
                $labelClass, $id, Sanitize::html($data['url'] ?? ''), $inputClass,
                $labelClass, $id, Sanitize::html($data['alt'] ?? ''), $inputClass,
                $labelClass, $id, Sanitize::html($data['caption'] ?? ''), $inputClass
            ),
            'cta' => sprintf(
                '<div class="space-y-3">
                    <div><label class="%s">Title</label><input type="text" name="blocks[%s][data][title]" value="%s" class="%s"></div>
                    <div><label class="%s">Text</label><textarea name="blocks[%s][data][text]" rows="2" class="%s">%s</textarea></div>
                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="%s">Button Text</label><input type="text" name="blocks[%s][data][button]" value="%s" class="%s"></div>
                        <div><label class="%s">Button URL</label><input type="text" name="blocks[%s][data][url]" value="%s" class="%s"></div>
                    </div>
                </div>',
                $labelClass, $id, Sanitize::html($data['title'] ?? ''), $inputClass,
                $labelClass, $id, $inputClass, Sanitize::html($data['text'] ?? ''),
                $labelClass, $id, Sanitize::html($data['button'] ?? ''), $inputClass,
                $labelClass, $id, Sanitize::html($data['url'] ?? ''), $inputClass
            ),
            'gallery' => sprintf(
                '<div class="space-y-3">
                    <div><label class="%s">Images (one URL per line)</label><textarea name="blocks[%s][data][images]" rows="4" class="%s">%s</textarea></div>
                    <div><label class="%s">Columns</label><select name="blocks[%s][data][columns]" class="%s">
                        <option value="2" %s>2</option>
                        <option value="3" %s>3</option>
                        <option value="4" %s>4</option>
                    </select></div>
                </div>',
                $labelClass, $id, $inputClass, Sanitize::html($data['images'] ?? ''),
                $labelClass, $id, $inputClass,
                ($data['columns'] ?? '3') === '2' ? 'selected' : '',
                ($data['columns'] ?? '3') === '3' ? 'selected' : '',
                ($data['columns'] ?? '3') === '4' ? 'selected' : ''
            ),
            'form' => '<div class="p-4 bg-blue-50 rounded-lg"><p class="text-sm text-blue-700 flex items-center gap-2"><span class="material-symbols-outlined">info</span>This block displays a contact form. No additional configuration needed.</p></div>',
            default => ''
        };
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
            $meta = json_encode(['og_image' => $ogImage]);
            
            if ($id === 'new') {
                $stmt = $db->prepare("INSERT INTO pages (title, slug, status, meta_description, meta_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$title, $slug, $status, $metaDesc, $meta, $now, $now]);
                $pageId = $db->lastInsertId();
            } else {
                // Create revision before update
                Revision::create('pages', (int)$id);
                
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
            } elseif ($id !== 'new' && empty($existingBlockIds)) {
                // No blocks submitted, check if we need to keep any
                $db->prepare("DELETE FROM content_blocks WHERE page_id = ?")->execute([$pageId]);
            }
            
            $db->commit();
            
            // Handle add to navigation
            $addToNav = Request::input('add_to_nav', '');
            $navPosition = (int)Request::input('nav_position', 0);
            
            if ($addToNav && $id === 'new') {
                // Shift existing nav items to make room
                DB::execute("UPDATE nav SET sort_order = sort_order + 1 WHERE sort_order >= ?", [$navPosition]);
                
                // Add to navigation
                $navUrl = '/' . $slug;
                DB::execute(
                    "INSERT INTO nav (label, url, sort_order, visible) VALUES (?, ?, ?, 1)",
                    [$title, $navUrl, $navPosition]
                );
                
                // Regenerate nav cache
                Cache::onContentChange('nav');
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
        
        global $cspNonce;
        
        $items = DB::fetchAll("SELECT * FROM nav ORDER BY sort_order ASC");
        
        foreach ($items as &$item) {
            $item['visible'] = (bool)$item['visible'];
        }
        
        self::renderAdmin('admin/nav', 'Navigation', [
            'items' => $items,
            'nav_count' => count($items),
            'csp_nonce' => $cspNonce
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
        
        global $cspNonce;
        
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
            'csp_nonce' => $cspNonce,
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
        
        DB::connect()->prepare("DELETE FROM assets WHERE id = ?")->execute([$id]);
        
        Session::flash('success', 'File deleted.');
        Response::redirect('/admin/media');
    }
    
    /**
     * Get images as JSON for gallery picker
     */
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
            'color_primary' => '#3b82f6',
            'color_secondary' => '#1e40af',
            'color_accent' => '#f59e0b',
            'color_background' => '#ffffff',
            'color_text' => '#1f2937',
            'color_primary_dark' => '#60a5fa',
            'color_secondary_dark' => '#3b82f6',
            'color_accent_dark' => '#fbbf24',
            'color_background_dark' => '#0f172a',
            'color_text_dark' => '#f1f5f9',
            'font_family' => 'system-ui, -apple-system, sans-serif',
            'header_bg' => '#3b82f6',
            'footer_text' => '',
            'head_scripts' => '',
            'footer_scripts' => '',
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
            'color_primary_dark', 'color_secondary_dark', 'color_accent_dark', 'color_background_dark', 'color_text_dark',
            'font_family', 'header_bg', 'footer_text', 'head_scripts', 'footer_scripts'
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
    
    // ─── USERS MANAGEMENT ────────────────────────────────────────────────────
    public static function users(): void {
        Auth::require('*'); // Admin only
        
        $currentUserId = Auth::user()['id'];
        $users = DB::fetchAll("SELECT id, email, role, created_at, last_login FROM users ORDER BY created_at DESC");
        
        foreach ($users as &$user) {
            $user['can_delete'] = $user['id'] != $currentUserId;
        }
        
        self::renderAdmin('admin/users', 'Users', ['users' => $users], ['is_users' => true]);
    }
    
    public static function editUser(string $id): void {
        Auth::require('*');
        
        if ($id === 'new') {
            $user = [
                'id' => 'new',
                'email' => '',
                'role' => 'editor',
                'is_admin' => false,
                'is_editor' => true,
                'is_viewer' => false,
            ];
        } else {
            $user = DB::fetch("SELECT id, email, role FROM users WHERE id = ?", [$id]);
            if (!$user) {
                Session::flash('error', 'User not found.');
                Response::redirect('/admin/users');
            }
            $user['is_admin'] = $user['role'] === 'admin';
            $user['is_editor'] = $user['role'] === 'editor';
            $user['is_viewer'] = $user['role'] === 'viewer';
        }
        
        $title = $id === 'new' ? 'Add User' : 'Edit User';
        self::renderAdmin('admin/user_edit', $title, $user, ['is_users' => true]);
    }
    
    public static function saveUser(string $id): void {
        Auth::require('*');
        CSRF::require();
        
        $email = filter_var(Request::input('email', ''), FILTER_VALIDATE_EMAIL);
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
            if (strlen($password) < 12) {
                Session::flash('error', 'Password must be at least 12 characters.');
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
                $stmt = $db->prepare("INSERT INTO users (email, password_hash, role, created_at) VALUES (?, ?, ?, datetime('now'))");
                $stmt->execute([$email, $hash, $role]);
                Session::flash('success', 'User created.');
            } else {
                if (!empty($password)) {
                    $hash = Password::hash($password);
                    $stmt = $db->prepare("UPDATE users SET email = ?, role = ?, password_hash = ? WHERE id = ?");
                    $stmt->execute([$email, $role, $hash, $id]);
                } else {
                    $stmt = $db->prepare("UPDATE users SET email = ?, role = ? WHERE id = ?");
                    $stmt->execute([$email, $role, $id]);
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
        
        DB::connect()->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
        
        Session::flash('success', 'User deleted.');
        Response::redirect('/admin/users');
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// REVISION TRACKING
// ─────────────────────────────────────────────────────────────────────────────

class Revision {
    public static function create(string $table, int $recordId): void {
        // Get current record data
        $data = DB::fetch("SELECT * FROM $table WHERE id = ?", [$recordId]);
        if (!$data) return;
        
        $userId = Auth::user()['id'] ?? null;
        
        DB::connect()->prepare(
            "INSERT INTO revisions (table_name, record_id, old_json, user_id, created_at) VALUES (?, ?, ?, ?, datetime('now'))"
        )->execute([$table, $recordId, json_encode($data), $userId]);
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
        
        // Don't show title on homepage (hero handles it)
        $showTitle = ($page['slug'] !== 'home');
        
        // Get UI theme from settings
        $uiTheme = Settings::get('ui_theme', 'emerald');
        
        // Render page
        $html = Template::render('page', array_merge($page, [
            'blocks_html' => $blocksHtml,
            'show_title' => $showTitle,
            'ui_theme' => $uiTheme
        ]));
        
        // Only cache if NOT in edit mode (cache without admin bar)
        // Store with placeholder nonce so we can inject fresh nonce on serve
        if (!self::$editMode) {
            $cacheHtml = str_replace(CSP_NONCE, '{{CSP_NONCE_PLACEHOLDER}}', $html);
            Cache::setPage($page['slug'], $cacheHtml);
        }
        
        // Inject admin bar for display
        $html = self::injectAdminBar($html);
        Response::html($html);
    }
    
    /**
     * Inject admin bar for logged-in users (not cached)
     */
    private static function injectAdminBar(string $html): string {
        if (!Auth::can('content.edit')) {
            // Remove placeholder for non-logged-in users
            return str_replace('<!--ONECMS_ADMIN_BAR-->', '', $html);
        }
        
        // Build admin bar HTML
        $editButton = self::$editMode 
            ? '<a href="?" class="btn btn-error btn-sm">Exit Editor</a>'
            : '<a href="?edit" class="btn btn-primary btn-sm">Edit Page</a>';
        
        $adminBar = <<<HTML
<div class="fixed top-0 left-0 right-0 z-[10000] bg-neutral text-neutral-content px-4 py-2">
    <div class="max-w-7xl mx-auto flex items-center gap-4">
        <span class="font-bold text-primary">OneCMS</span>
        <div class="flex-1"></div>
        {$editButton}
        <a href="/admin" class="btn btn-ghost btn-sm">Dashboard</a>
    </div>
</div>
<div class="pt-14"></div>
HTML;
        
        return str_replace('<!--ONECMS_ADMIN_BAR-->', $adminBar, $html);
    }
    
    /**
     * Public method for cache regeneration
     */
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
<div class="onecms-block-wrapper" data-block-id="{$blockId}" data-block-type="{$type}" data-block-index="{$index}">
    <div class="onecms-block-toolbar">
        <span class="block-type-label">{$type}</span>
        <div class="block-actions">
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
        
        return match ($type) {
            'hero' => self::renderHero($data),
            'text' => '<div class="prose prose-lg max-w-none py-6 reveal">' . Sanitize::richText($data['content'] ?? '') . '</div>',
            'image' => sprintf(
                '<figure class="py-6 reveal"><img src="%s" alt="%s" class="rounded-box shadow-lg mx-auto">%s</figure>',
                Sanitize::html($data['url'] ?? ''),
                Sanitize::html($data['alt'] ?? ''),
                !empty($data['caption']) ? '<figcaption class="text-center text-base-content/60 mt-2">' . Sanitize::html($data['caption']) . '</figcaption>' : ''
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
            default => ''
        };
    }
    
    private static function renderHero(array $data): string {
        $title = Sanitize::html($data['title'] ?? '');
        $subtitle = Sanitize::html($data['subtitle'] ?? '');
        $button = Sanitize::html($data['button'] ?? '');
        $url = Sanitize::html($data['url'] ?? '#');
        $image = Sanitize::html($data['image'] ?? '');
        
        $btnHtml = !empty($button) ? "<a href=\"{$url}\" class=\"btn btn-primary btn-lg\">{$button}</a>" : '';
        $bgStyle = !empty($image) ? "background-image: url('{$image}'); background-size: cover; background-position: center;" : '';
        
        return <<<HTML
<div class="hero min-h-[40vh] bg-base-200 reveal" style="{$bgStyle}">
    <div class="hero-overlay bg-opacity-60"></div>
    <div class="hero-content text-center">
        <div class="max-w-2xl">
            <h1 class="text-5xl font-bold animate-fade-in">{$title}</h1>
            <p class="py-6 text-xl opacity-90 animate-slide-up">{$subtitle}</p>
            {$btnHtml}
        </div>
    </div>
</div>
HTML;
    }
    
    private static function renderCTA(array $data): string {
        $title = Sanitize::html($data['title'] ?? '');
        $text = Sanitize::html($data['text'] ?? '');
        $button = Sanitize::html($data['button'] ?? 'Learn More');
        $url = Sanitize::html($data['url'] ?? '#');
        
        return <<<HTML
<div class="bg-primary text-primary-content py-8 px-4 my-4 rounded-box reveal">
    <div class="max-w-4xl mx-auto text-center">
        <h2 class="text-3xl font-bold mb-4">{$title}</h2>
        <p class="text-lg opacity-90 mb-8">{$text}</p>
        <a href="{$url}" class="btn btn-secondary btn-lg">{$button}</a>
    </div>
</div>
HTML;
    }
    
    /**
     * Render icon - supports Material Icons (lowercase names) or emojis/images
     */
    private static function renderIconHtml(string $icon): string {
        if (empty($icon)) {
            return '<span class="material-symbols-outlined text-primary">star</span>';
        }
        
        // If it starts with / it's an image
        if (str_starts_with($icon, '/')) {
            return '<img src="' . Sanitize::html($icon) . '" alt="" class="w-10 h-10 object-contain">';
        }
        
        // If it's a simple lowercase word (or underscore-separated), treat as Material Icon
        if (preg_match('/^[a-z_]+$/', $icon)) {
            return '<span class="material-symbols-outlined text-primary">' . Sanitize::html($icon) . '</span>';
        }
        
        // Otherwise render as-is (emoji)
        return Sanitize::html($icon);
    }
    
    private static function renderFeatures(array $data): string {
        $items = $data['items'] ?? [];
        if (empty($items)) return '';
        
        $title = Sanitize::html($data['title'] ?? '');
        $subtitle = Sanitize::html($data['subtitle'] ?? '');
        
        $titleHtml = !empty($title) ? "<h2 class=\"text-3xl font-bold text-center mb-4\">{$title}</h2>" : '';
        $subtitleHtml = !empty($subtitle) ? "<p class=\"text-center text-base-content/70 mb-12 max-w-2xl mx-auto\">{$subtitle}</p>" : '';
        
        $cardsHtml = '';
        foreach ($items as $item) {
            $iconRaw = $item['icon'] ?? 'star';
            $icon = self::renderIconHtml($iconRaw);
            $itemTitle = Sanitize::html($item['title'] ?? '');
            $description = Sanitize::html($item['description'] ?? '');
            
            $cardsHtml .= <<<CARD
<div class="card bg-base-100 shadow-xl hover:shadow-2xl transition-all duration-300 hover:-translate-y-1 border border-base-200 dark:border-base-300">
    <div class="card-body items-center text-center">
        <div class="text-4xl mb-4 text-primary">{$icon}</div>
        <h3 class="card-title text-base-content">{$itemTitle}</h3>
        <p class="text-base-content/70">{$description}</p>
    </div>
</div>
CARD;
        }
        
        return <<<HTML
<div class="py-16 reveal">
    {$titleHtml}
    {$subtitleHtml}
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        {$cardsHtml}
    </div>
</div>
HTML;
    }
    
    private static function renderStats(array $data): string {
        $items = $data['items'] ?? [];
        if (empty($items)) return '';
        
        $title = Sanitize::html($data['title'] ?? '');
        $titleHtml = !empty($title) ? "<h2 class=\"text-3xl font-bold text-center mb-8\">{$title}</h2>" : '';
        
        $statsHtml = '';
        foreach ($items as $item) {
            $value = Sanitize::html($item['value'] ?? '');
            $label = Sanitize::html($item['label'] ?? '');
            $iconRaw = $item['icon'] ?? '';
            
            $iconHtml = !empty($iconRaw) ? "<div class=\"text-3xl mb-2\">" . self::renderIconHtml($iconRaw) . "</div>" : '';
            
            $statsHtml .= <<<STAT
<div class="stat place-items-center">
    {$iconHtml}
    <div class="stat-value text-primary">{$value}</div>
    <div class="stat-desc">{$label}</div>
</div>
STAT;
        }
        
        return <<<HTML
<div class="py-12 reveal">
    {$titleHtml}
    <div class="stats stats-vertical lg:stats-horizontal shadow w-full bg-base-100">
        {$statsHtml}
    </div>
</div>
HTML;
    }
    
    private static function renderTestimonials(array $data): string {
        $items = $data['items'] ?? [];
        if (empty($items)) return '';
        
        $title = Sanitize::html($data['title'] ?? '');
        $titleHtml = !empty($title) ? "<h2 class=\"text-3xl font-bold text-center mb-12\">{$title}</h2>" : '';
        
        $cardsHtml = '';
        foreach ($items as $item) {
            $quote = Sanitize::html($item['quote'] ?? '');
            $name = Sanitize::html($item['name'] ?? $item['author'] ?? '');
            $role = Sanitize::html($item['role'] ?? '');
            $rating = intval($item['rating'] ?? 5);
            
            $initials = strtoupper(substr($name, 0, 1) . (strpos($name, ' ') ? substr($name, strpos($name, ' ') + 1, 1) : ''));
            $starsHtml = str_repeat('⭐', min($rating, 5));
            
            $cardsHtml .= <<<CARD
<div class="card bg-base-100 shadow-lg">
    <div class="card-body">
        <div class="text-warning mb-2">{$starsHtml}</div>
        <p class="italic text-base-content/80">"{$quote}"</p>
        <div class="flex items-center gap-4 mt-4">
            <div class="avatar placeholder">
                <div class="bg-primary text-primary-content rounded-full w-12">
                    <span>{$initials}</span>
                </div>
            </div>
            <div>
                <div class="font-bold">{$name}</div>
                <div class="text-sm text-base-content/60">{$role}</div>
            </div>
        </div>
    </div>
</div>
CARD;
        }
        
        return <<<HTML
<div class="py-16 bg-base-200 -mx-4 px-4 reveal">
    {$titleHtml}
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 max-w-6xl mx-auto">
        {$cardsHtml}
    </div>
</div>
HTML;
    }
    
    private static function renderPricing(array $data): string {
        $items = $data['plans'] ?? $data['items'] ?? [];
        if (empty($items)) return '';
        
        $title = Sanitize::html($data['title'] ?? '');
        $subtitle = Sanitize::html($data['subtitle'] ?? '');
        
        $titleHtml = !empty($title) ? "<h2 class=\"text-3xl font-bold text-center mb-4\">{$title}</h2>" : '';
        $subtitleHtml = !empty($subtitle) ? "<p class=\"text-center text-base-content/70 mb-12\">{$subtitle}</p>" : '';
        
        $cardsHtml = '';
        foreach ($items as $item) {
            $name = Sanitize::html($item['name'] ?? '');
            $price = Sanitize::html($item['price'] ?? '');
            $period = Sanitize::html($item['period'] ?? '/month');
            $features = $item['features'] ?? [];
            $featured = !empty($item['featured']);
            $buttonText = Sanitize::html($item['button'] ?? 'Get Started');
            $url = Sanitize::html($item['url'] ?? '#');
            
            $cardClass = $featured ? 'border-primary border-2 scale-105' : '';
            $btnClass = $featured ? 'btn-primary' : 'btn-outline btn-primary';
            $badgeHtml = $featured ? '<div class="badge badge-primary absolute top-4 right-4">Popular</div>' : '';
            
            $featuresHtml = '';
            foreach ($features as $feature) {
                $featuresHtml .= '<li class="flex items-center gap-2"><svg class="w-5 h-5 text-success" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>' . Sanitize::html($feature) . '</li>';
            }
            
            $cardsHtml .= <<<CARD
<div class="card bg-base-100 shadow-xl relative {$cardClass}">
    {$badgeHtml}
    <div class="card-body">
        <h3 class="card-title text-xl">{$name}</h3>
        <div class="my-4">
            <span class="text-4xl font-bold">{$price}</span>
            <span class="text-base-content/60">{$period}</span>
        </div>
        <ul class="space-y-2 mb-6 flex-grow">{$featuresHtml}</ul>
        <div class="card-actions">
            <a href="{$url}" class="btn {$btnClass} w-full">{$buttonText}</a>
        </div>
    </div>
</div>
CARD;
        }
        
        return <<<HTML
<div class="py-16 reveal">
    {$titleHtml}
    {$subtitleHtml}
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8 items-start max-w-6xl mx-auto">
        {$cardsHtml}
    </div>
</div>
HTML;
    }
    
    private static function renderTeam(array $data): string {
        $items = $data['members'] ?? $data['items'] ?? [];
        if (empty($items)) return '';
        
        $title = Sanitize::html($data['title'] ?? '');
        $titleHtml = !empty($title) ? "<h2 class=\"text-3xl font-bold text-center mb-12\">{$title}</h2>" : '';
        
        $cardsHtml = '';
        foreach ($items as $item) {
            $name = Sanitize::html($item['name'] ?? '');
            $role = Sanitize::html($item['role'] ?? '');
            $bio = Sanitize::html($item['bio'] ?? '');
            $photo = $item['photo'] ?? '';
            $url = Sanitize::html($item['url'] ?? '');
            
            $initials = strtoupper(substr($name, 0, 1) . (strpos($name, ' ') ? substr($name, strpos($name, ' ') + 1, 1) : ''));
            
            if (!empty($photo)) {
                $avatarHtml = "<img src=\"" . Sanitize::html($photo) . "\" alt=\"{$name}\" class=\"rounded-full\">";
            } else {
                $avatarHtml = "<div class=\"avatar placeholder\"><div class=\"bg-primary text-primary-content rounded-full w-24\"><span class=\"text-2xl\">{$initials}</span></div></div>";
            }
            
            $linkHtml = !empty($url) ? "<a href=\"{$url}\" class=\"btn btn-primary btn-sm mt-4\">View Profile</a>" : '';
            
            $cardsHtml .= <<<CARD
<div class="card bg-base-100 shadow-lg hover:shadow-xl transition-shadow">
    <div class="card-body items-center text-center">
        <div class="avatar mb-4">
            <div class="w-24 rounded-full ring ring-primary ring-offset-base-100 ring-offset-2">
                {$avatarHtml}
            </div>
        </div>
        <h3 class="card-title">{$name}</h3>
        <div class="badge badge-ghost">{$role}</div>
        <p class="text-base-content/70 mt-2">{$bio}</p>
        {$linkHtml}
    </div>
</div>
CARD;
        }
        
        return <<<HTML
<div class="py-16 reveal">
    {$titleHtml}
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        {$cardsHtml}
    </div>
</div>
HTML;
    }
    
    private static function renderCards(array $data): string {
        $items = $data['items'] ?? [];
        if (empty($items)) return '';
        
        $title = Sanitize::html($data['title'] ?? '');
        $titleHtml = !empty($title) ? "<h2 class=\"text-3xl font-bold text-center mb-12\">{$title}</h2>" : '';
        
        $cardsHtml = '';
        foreach ($items as $item) {
            $itemTitle = Sanitize::html($item['title'] ?? '');
            $description = Sanitize::html($item['description'] ?? '');
            $icon = Sanitize::html($item['icon'] ?? '');
            $image = $item['image'] ?? '';
            $url = Sanitize::html($item['url'] ?? '#');
            $buttonText = Sanitize::html($item['button'] ?? '');
            
            $iconHtml = !empty($icon) ? "<div class=\"text-4xl mb-4\">{$icon}</div>" : '';
            $imageHtml = !empty($image) 
                ? "<figure><img src=\"" . Sanitize::html($image) . "\" alt=\"{$itemTitle}\" class=\"rounded-t-box\"></figure>"
                : '';
            $buttonHtml = !empty($buttonText) ? "<div class=\"card-actions justify-end\"><a href=\"{$url}\" class=\"btn btn-primary btn-sm\">{$buttonText}</a></div>" : '';
            
            $cardsHtml .= <<<CARD
<div class="card bg-base-100 shadow-xl hover:shadow-2xl transition-all duration-300 hover:-translate-y-1">
    {$imageHtml}
    <div class="card-body">
        {$iconHtml}
        <h3 class="card-title">{$itemTitle}</h3>
        <p class="text-base-content/70">{$description}</p>
        {$buttonHtml}
    </div>
</div>
CARD;
        }
        
        return <<<HTML
<div class="py-16 reveal">
    {$titleHtml}
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        {$cardsHtml}
    </div>
</div>
HTML;
    }
    
    private static function renderFAQ(array $data): string {
        $items = $data['items'] ?? [];
        if (empty($items)) return '';
        
        $title = Sanitize::html($data['title'] ?? '');
        $titleHtml = !empty($title) ? "<h2 class=\"text-3xl font-bold text-center mb-8\">{$title}</h2>" : '';
        
        $faqHtml = '';
        foreach ($items as $i => $item) {
            $question = Sanitize::html($item['question'] ?? '');
            $answer = Sanitize::html($item['answer'] ?? '');
            $checked = $i === 0 ? ' checked="checked"' : '';
            $name = 'faq-' . md5($title . $i);
            
            $faqHtml .= <<<FAQ
<div class="collapse collapse-arrow bg-base-100 mb-2 shadow">
    <input type="radio" name="{$name}"{$checked}>
    <div class="collapse-title text-lg font-medium">{$question}</div>
    <div class="collapse-content">
        <p class="text-base-content/70">{$answer}</p>
    </div>
</div>
FAQ;
        }
        
        return <<<HTML
<div class="py-16 max-w-3xl mx-auto reveal">
    {$titleHtml}
    {$faqHtml}
</div>
HTML;
    }
    
    private static function renderGallery(array $data): string {
        $images = array_filter(explode("\n", $data['images'] ?? ''));
        $columns = $data['columns'] ?? 3;
        
        if (empty($images)) return '';
        
        $cols = (int)$columns;
        $gridCols = match($cols) {
            1 => 'grid-cols-1',
            2 => 'grid-cols-1 md:grid-cols-2',
            4 => 'grid-cols-2 md:grid-cols-4',
            default => 'grid-cols-2 md:grid-cols-3'
        };
        
        $imagesHtml = '';
        foreach ($images as $url) {
            $url = trim($url);
            if (!empty($url)) {
                $imagesHtml .= '<div class="aspect-square overflow-hidden rounded-lg"><img src="' . Sanitize::html($url) . '" alt="" class="w-full h-full object-cover hover:scale-110 transition-transform duration-300" loading="lazy"></div>';
            }
        }
        
        return <<<HTML
<div class="py-8 reveal">
    <div class="grid {$gridCols} gap-4">
        {$imagesHtml}
    </div>
</div>
HTML;
    }
    
    private static function renderContactForm(): string {
        $csrfToken = CSRF::token();
        return <<<HTML
<div class="py-16 reveal">
    <div class="card bg-base-100 shadow-xl max-w-2xl mx-auto">
        <div class="card-body">
            <h2 class="card-title text-2xl mb-6">Contact Us</h2>
            <form method="post" action="/contact" class="space-y-4">
                <input type="hidden" name="_csrf" value="{$csrfToken}">
                <div class="form-control">
                    <label class="label"><span class="label-text">Name</span></label>
                    <input type="text" name="name" required class="input input-bordered w-full" placeholder="Your name">
                </div>
                <div class="form-control">
                    <label class="label"><span class="label-text">Email</span></label>
                    <input type="email" name="email" required class="input input-bordered w-full" placeholder="your@email.com">
                </div>
                <div class="form-control">
                    <label class="label"><span class="label-text">Message</span></label>
                    <textarea name="message" required class="textarea textarea-bordered h-32" placeholder="Your message..."></textarea>
                </div>
                <button type="submit" class="btn btn-primary w-full">Send Message</button>
            </form>
        </div>
    </div>
</div>
HTML;
    }
    
    // === NEW BLOCK RENDERERS ===
    
    private static function renderQuote(array $data): string {
        $text = Sanitize::html($data['text'] ?? '');
        $author = Sanitize::html($data['author'] ?? '');
        $role = Sanitize::html($data['role'] ?? '');
        
        $attribution = '';
        if ($author) {
            $attribution = "<footer class=\"mt-4\"><cite class=\"font-semibold\">{$author}";
            if ($role) $attribution .= " <span class=\"font-normal text-base-content/60\">— {$role}</span>";
            $attribution .= "</cite></footer>";
        }
        
        return <<<HTML
<div class="py-12 reveal">
    <blockquote class="text-xl italic border-l-4 border-primary pl-6 py-4 bg-base-100 rounded-r-lg shadow-sm max-w-3xl mx-auto">
        <p class="text-base-content/80">"{$text}"</p>
        {$attribution}
    </blockquote>
</div>
HTML;
    }
    
    private static function renderDivider(array $data): string {
        $style = $data['style'] ?? 'line';
        $icon = Sanitize::html($data['icon'] ?? '⭐');
        
        return match($style) {
            'dots' => '<div class="divider py-8">•••</div>',
            'icon' => "<div class=\"divider py-8\">{$icon}</div>",
            default => '<div class="divider py-8"></div>'
        };
    }
    
    private static function renderVideo(array $data): string {
        $title = Sanitize::html($data['title'] ?? '');
        $url = Sanitize::html($data['url'] ?? '');
        $caption = Sanitize::html($data['caption'] ?? '');
        
        $titleHtml = $title ? "<h3 class=\"text-2xl font-bold text-center mb-6\">{$title}</h3>" : '';
        $captionHtml = $caption ? "<p class=\"text-center text-base-content/60 mt-4\">{$caption}</p>" : '';
        
        return <<<HTML
<div class="py-12 reveal">
    {$titleHtml}
    <div class="aspect-video rounded-box overflow-hidden shadow-xl max-w-4xl mx-auto">
        <iframe src="{$url}" class="w-full h-full" allowfullscreen loading="lazy"></iframe>
    </div>
    {$captionHtml}
</div>
HTML;
    }
    
    private static function renderCarousel(array $data): string {
        $items = $data['items'] ?? [];
        if (empty($items)) return '';
        
        $slidesHtml = '';
        foreach ($items as $i => $item) {
            $image = Sanitize::html($item['image'] ?? '');
            $title = Sanitize::html($item['title'] ?? '');
            $text = Sanitize::html($item['text'] ?? '');
            $prev = ($i - 1 + count($items)) % count($items);
            $next = ($i + 1) % count($items);
            
            $contentHtml = ($title || $text) 
                ? "<div class=\"absolute inset-0 bg-black/50 flex items-end p-6\"><div><h4 class=\"text-white text-xl font-bold\">{$title}</h4><p class=\"text-white/80\">{$text}</p></div></div>" 
                : '';
            
            $slidesHtml .= <<<SLIDE
<div id="slide{$i}" class="carousel-item relative w-full">
    <img src="{$image}" class="w-full object-cover" alt="{$title}">
    {$contentHtml}
    <div class="absolute flex justify-between transform -translate-y-1/2 left-5 right-5 top-1/2">
        <a href="#slide{$prev}" class="btn btn-circle">❮</a>
        <a href="#slide{$next}" class="btn btn-circle">❯</a>
    </div>
</div>
SLIDE;
        }
        
        return <<<HTML
<div class="py-8 reveal">
    <div class="carousel w-full rounded-box shadow-xl aspect-video max-w-4xl mx-auto overflow-hidden">
        {$slidesHtml}
    </div>
</div>
HTML;
    }
    
    private static function renderChecklist(array $data): string {
        $title = Sanitize::html($data['title'] ?? '');
        $items = $data['items'] ?? [];
        if (empty($items)) return '';
        
        $titleHtml = $title ? "<h3 class=\"text-2xl font-bold mb-6\">{$title}</h3>" : '';
        $listHtml = '';
        foreach ($items as $item) {
            $text = is_string($item) ? Sanitize::html($item) : Sanitize::html($item['text'] ?? '');
            $listHtml .= <<<ITEM
<li class="flex items-start gap-3 mb-3">
    <svg class="w-6 h-6 text-success flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
    </svg>
    <span class="text-base-content/80">{$text}</span>
</li>
ITEM;
        }
        
        return <<<HTML
<div class="py-12 max-w-2xl mx-auto reveal">
    {$titleHtml}
    <ul class="list-none">{$listHtml}</ul>
</div>
HTML;
    }
    
    private static function renderLogoCloud(array $data): string {
        $title = Sanitize::html($data['title'] ?? '');
        $items = $data['items'] ?? $data['logos'] ?? [];
        if (empty($items)) return '';
        
        $titleHtml = $title ? "<h3 class=\"text-xl text-center text-base-content/60 mb-8\">{$title}</h3>" : '';
        $logosHtml = '';
        foreach ($items as $item) {
            $name = Sanitize::html($item['name'] ?? '');
            $url = Sanitize::html($item['url'] ?? '');
            if ($url) {
                $logosHtml .= "<div class=\"flex items-center justify-center p-4\"><img src=\"{$url}\" alt=\"{$name}\" class=\"max-h-12 grayscale hover:grayscale-0 opacity-60 hover:opacity-100 transition-all\" loading=\"lazy\"></div>";
            } else {
                $logosHtml .= "<div class=\"flex items-center justify-center p-4 text-xl font-bold text-base-content/40\">{$name}</div>";
            }
        }
        
        return <<<HTML
<div class="py-12 bg-base-200 -mx-4 px-4 reveal">
    {$titleHtml}
    <div class="flex flex-wrap justify-center items-center gap-8">
        {$logosHtml}
    </div>
</div>
HTML;
    }
    
    private static function renderComparison(array $data): string {
        $title = Sanitize::html($data['title'] ?? '');
        $headers = $data['headers'] ?? [];
        $rows = $data['rows'] ?? [];
        if (empty($headers) || empty($rows)) return '';
        
        $titleHtml = $title ? "<h3 class=\"text-2xl font-bold text-center mb-8\">{$title}</h3>" : '';
        
        $headerHtml = '';
        foreach ($headers as $h) {
            $headerHtml .= '<th class="bg-primary text-primary-content">' . Sanitize::html($h) . '</th>';
        }
        
        $rowsHtml = '';
        foreach ($rows as $row) {
            $rowsHtml .= '<tr class="hover">';
            foreach ($row as $i => $cell) {
                $class = $i === 0 ? 'font-medium' : 'text-center';
                $cellContent = $cell;
                if ($cell === true || strtolower($cell) === 'yes' || $cell === '✓') {
                    $cellContent = '<svg class="w-6 h-6 text-success mx-auto" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>';
                } elseif ($cell === false || strtolower($cell) === 'no' || $cell === '✗') {
                    $cellContent = '<svg class="w-6 h-6 text-error mx-auto" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>';
                } else {
                    $cellContent = Sanitize::html($cell);
                }
                $rowsHtml .= "<td class=\"{$class}\">{$cellContent}</td>";
            }
            $rowsHtml .= '</tr>';
        }
        
        return <<<HTML
<div class="py-12 reveal">
    {$titleHtml}
    <div class="overflow-x-auto">
        <table class="table table-zebra w-full">
            <thead><tr>{$headerHtml}</tr></thead>
            <tbody>{$rowsHtml}</tbody>
        </table>
    </div>
</div>
HTML;
    }
    
    private static function renderTabs(array $data): string {
        $items = $data['items'] ?? [];
        if (empty($items)) return '';
        
        $tabId = 'tabs-' . uniqid();
        
        $tabsHtml = '';
        $panelsHtml = '';
        foreach ($items as $i => $item) {
            $label = Sanitize::html($item['label'] ?? 'Tab ' . ($i + 1));
            $content = Sanitize::richText($item['content'] ?? '');
            $checked = $i === 0 ? ' checked="checked"' : '';
            $name = "tab-{$tabId}";
            
            $tabsHtml .= "<input type=\"radio\" name=\"{$name}\" role=\"tab\" class=\"tab\" aria-label=\"{$label}\"{$checked}>";
            $tabsHtml .= "<div role=\"tabpanel\" class=\"tab-content bg-base-100 border-base-300 rounded-box p-6\">{$content}</div>";
        }
        
        return <<<HTML
<div class="py-12 reveal">
    <div role="tablist" class="tabs tabs-lifted">
        {$tabsHtml}
    </div>
</div>
HTML;
    }
    
    private static function renderAccordion(array $data): string {
        $title = Sanitize::html($data['title'] ?? '');
        $items = $data['items'] ?? [];
        if (empty($items)) return '';
        
        $titleHtml = $title ? "<h3 class=\"text-2xl font-bold text-center mb-8\">{$title}</h3>" : '';
        $name = 'accordion-' . md5($title . count($items));
        
        $accordionHtml = '';
        foreach ($items as $i => $item) {
            $itemTitle = Sanitize::html($item['title'] ?? '');
            $content = Sanitize::richText($item['content'] ?? '');
            $checked = $i === 0 ? ' checked="checked"' : '';
            
            $accordionHtml .= <<<ITEM
<div class="collapse collapse-plus bg-base-100 mb-2 shadow">
    <input type="radio" name="{$name}"{$checked}>
    <div class="collapse-title text-lg font-medium">{$itemTitle}</div>
    <div class="collapse-content"><div class="prose max-w-none">{$content}</div></div>
</div>
ITEM;
        }
        
        return <<<HTML
<div class="py-12 max-w-3xl mx-auto reveal">
    {$titleHtml}
    {$accordionHtml}
</div>
HTML;
    }
    
    private static function renderTable(array $data): string {
        $title = Sanitize::html($data['title'] ?? '');
        $headers = $data['headers'] ?? [];
        $rows = $data['rows'] ?? [];
        if (empty($rows)) return '';
        
        $titleHtml = $title ? "<h3 class=\"text-2xl font-bold text-center mb-6\">{$title}</h3>" : '';
        
        $headerHtml = '';
        foreach ($headers as $h) {
            $headerHtml .= '<th>' . Sanitize::html($h) . '</th>';
        }
        
        $rowsHtml = '';
        foreach ($rows as $row) {
            $rowsHtml .= '<tr class="hover">';
            foreach ($row as $cell) {
                $rowsHtml .= '<td>' . Sanitize::html($cell) . '</td>';
            }
            $rowsHtml .= '</tr>';
        }
        
        return <<<HTML
<div class="py-8 reveal">
    {$titleHtml}
    <div class="overflow-x-auto">
        <table class="table table-zebra w-full">
            <thead><tr>{$headerHtml}</tr></thead>
            <tbody>{$rowsHtml}</tbody>
        </table>
    </div>
</div>
HTML;
    }
    
    private static function renderTimeline(array $data): string {
        $title = Sanitize::html($data['title'] ?? '');
        $items = $data['items'] ?? [];
        if (empty($items)) return '';
        
        $titleHtml = $title ? "<h3 class=\"text-2xl font-bold text-center mb-12\">{$title}</h3>" : '';
        
        $timelineHtml = '';
        foreach ($items as $i => $item) {
            $date = Sanitize::html($item['date'] ?? '');
            $itemTitle = Sanitize::html($item['title'] ?? '');
            $desc = Sanitize::html($item['description'] ?? '');
            $isLast = $i === count($items) - 1;
            $hrEnd = $isLast ? '' : '<hr class="bg-primary">';
            
            $timelineHtml .= <<<ITEM
<li>
    <hr class="bg-primary">
    <div class="timeline-start timeline-box">{$date}</div>
    <div class="timeline-middle">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5 text-primary">
            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd"/>
        </svg>
    </div>
    <div class="timeline-end timeline-box bg-base-200">
        <div class="font-bold">{$itemTitle}</div>
        <div class="text-sm text-base-content/70">{$desc}</div>
    </div>
    {$hrEnd}
</li>
ITEM;
        }
        
        return <<<HTML
<div class="py-16 reveal">
    {$titleHtml}
    <ul class="timeline timeline-vertical">
        {$timelineHtml}
    </ul>
</div>
HTML;
    }
    
    private static function renderList(array $data): string {
        $title = Sanitize::html($data['title'] ?? '');
        $style = $data['style'] ?? 'bullet';
        $items = $data['items'] ?? [];
        if (empty($items)) return '';
        
        $titleHtml = $title ? "<h3 class=\"text-2xl font-bold mb-6\">{$title}</h3>" : '';
        
        $listClass = match($style) {
            'number' => 'list-decimal',
            'check' => 'list-none',
            default => 'list-disc'
        };
        
        $listHtml = '';
        foreach ($items as $item) {
            $text = is_string($item) ? Sanitize::html($item) : Sanitize::html($item['text'] ?? '');
            if ($style === 'check') {
                $listHtml .= <<<ITEM
<li class="flex items-start gap-3 mb-2">
    <svg class="w-5 h-5 text-success flex-shrink-0 mt-1" fill="currentColor" viewBox="0 0 20 20">
        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
    </svg>
    <span>{$text}</span>
</li>
ITEM;
            } else {
                $listHtml .= "<li class=\"mb-2\">{$text}</li>";
            }
        }
        
        $tag = $style === 'number' ? 'ol' : 'ul';
        
        return <<<HTML
<div class="py-8 max-w-2xl mx-auto reveal">
    {$titleHtml}
    <{$tag} class="{$listClass} ml-5 space-y-1">{$listHtml}</{$tag}>
</div>
HTML;
    }
    
    private static function renderNewsletter(array $data): string {
        $title = Sanitize::html($data['title'] ?? 'Stay Updated');
        $text = Sanitize::html($data['text'] ?? '');
        $button = Sanitize::html($data['button'] ?? 'Subscribe');
        $placeholder = Sanitize::html($data['placeholder'] ?? 'Enter your email');
        $csrf = CSRF::token();
        
        $textHtml = $text ? "<p class=\"text-base-content/70 mb-6\">{$text}</p>" : '';
        
        return <<<HTML
<div class="py-16 bg-base-200 -mx-4 px-4 reveal">
    <div class="max-w-xl mx-auto text-center">
        <h3 class="text-2xl font-bold mb-4">{$title}</h3>
        {$textHtml}
        <form method="post" action="/newsletter" class="join w-full max-w-md mx-auto">
            <input type="hidden" name="_csrf" value="{$csrf}">
            <input type="email" name="email" placeholder="{$placeholder}" required class="input input-bordered join-item flex-1">
            <button type="submit" class="btn btn-primary join-item">{$button}</button>
        </form>
    </div>
</div>
HTML;
    }
    
    private static function renderDownload(array $data): string {
        $title = Sanitize::html($data['title'] ?? '');
        $desc = Sanitize::html($data['description'] ?? '');
        $file = Sanitize::html($data['file'] ?? '');
        $button = Sanitize::html($data['button'] ?? 'Download');
        $icon = Sanitize::html($data['icon'] ?? '📄');
        
        $descHtml = $desc ? "<p class=\"text-base-content/70\">{$desc}</p>" : '';
        
        return <<<HTML
<div class="py-8 reveal">
    <div class="card card-side bg-base-100 shadow-xl max-w-lg mx-auto">
        <figure class="pl-6">
            <span class="text-4xl">{$icon}</span>
        </figure>
        <div class="card-body">
            <h4 class="card-title">{$title}</h4>
            {$descHtml}
            <div class="card-actions justify-end">
                <a href="{$file}" class="btn btn-primary gap-2" download>
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                    </svg>
                    {$button}
                </a>
            </div>
        </div>
    </div>
</div>
HTML;
    }
    
    private static function renderAlert(array $data): string {
        $type = $data['type'] ?? 'info';
        $title = Sanitize::html($data['title'] ?? '');
        $text = Sanitize::html($data['text'] ?? '');
        
        $alertClass = match($type) {
            'success' => 'alert-success',
            'warning' => 'alert-warning',
            'error' => 'alert-error',
            default => 'alert-info'
        };
        
        $icons = [
            'info' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" class="stroke-current shrink-0 w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>',
            'success' => '<svg xmlns="http://www.w3.org/2000/svg" class="stroke-current shrink-0 h-6 w-6" fill="none" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
            'warning' => '<svg xmlns="http://www.w3.org/2000/svg" class="stroke-current shrink-0 h-6 w-6" fill="none" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>',
            'error' => '<svg xmlns="http://www.w3.org/2000/svg" class="stroke-current shrink-0 h-6 w-6" fill="none" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>'
        ];
        $icon = $icons[$type] ?? $icons['info'];
        
        $titleHtml = $title ? "<span class=\"font-bold\">{$title}</span>" : '';
        
        return <<<HTML
<div class="py-4 reveal">
    <div class="alert {$alertClass}">
        {$icon}
        <div>
            {$titleHtml}
            <span>{$text}</span>
        </div>
    </div>
</div>
HTML;
    }
    
    private static function renderProgress(array $data): string {
        $title = Sanitize::html($data['title'] ?? '');
        $items = $data['items'] ?? [];
        if (empty($items)) return '';
        
        $titleHtml = $title ? "<h3 class=\"text-2xl font-bold mb-8\">{$title}</h3>" : '';
        
        $progressHtml = '';
        foreach ($items as $item) {
            $label = Sanitize::html($item['label'] ?? '');
            $value = min(100, max(0, (int)($item['value'] ?? 0)));
            
            $progressHtml .= <<<ITEM
<div class="mb-4">
    <div class="flex justify-between mb-1">
        <span class="text-base-content/80">{$label}</span>
        <span class="text-base-content/60">{$value}%</span>
    </div>
    <progress class="progress progress-primary w-full" value="{$value}" max="100"></progress>
</div>
ITEM;
        }
        
        return <<<HTML
<div class="py-12 max-w-2xl mx-auto reveal">
    {$titleHtml}
    {$progressHtml}
</div>
HTML;
    }
    
    private static function renderSteps(array $data): string {
        $title = Sanitize::html($data['title'] ?? '');
        $items = $data['items'] ?? [];
        if (empty($items)) return '';
        
        $titleHtml = $title ? "<h3 class=\"text-3xl font-bold text-center mb-12\">{$title}</h3>" : '';
        
        $stepsHtml = '';
        foreach ($items as $item) {
            $num = Sanitize::html($item['number'] ?? '');
            $itemTitle = Sanitize::html($item['title'] ?? '');
            $desc = Sanitize::html($item['description'] ?? '');
            
            $stepsHtml .= <<<STEP
<div class="card bg-base-100 shadow-lg hover:shadow-xl transition-shadow">
    <div class="card-body text-center">
        <div class="badge badge-primary badge-lg mx-auto text-xl font-bold px-4 py-3">{$num}</div>
        <h4 class="card-title justify-center mt-4">{$itemTitle}</h4>
        <p class="text-base-content/70">{$desc}</p>
    </div>
</div>
STEP;
        }
        
        return <<<HTML
<div class="py-16 reveal">
    {$titleHtml}
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        {$stepsHtml}
    </div>
</div>
HTML;
    }
    
    private static function renderColumns(array $data): string {
        $columns = $data['columns'] ?? [];
        if (empty($columns)) return '';
        
        $count = count($columns);
        $gridCols = match($count) {
            1 => 'grid-cols-1',
            2 => 'grid-cols-1 md:grid-cols-2',
            3 => 'grid-cols-1 md:grid-cols-3',
            4 => 'grid-cols-1 md:grid-cols-2 lg:grid-cols-4',
            default => 'grid-cols-1 md:grid-cols-2 lg:grid-cols-3'
        };
        
        $colsHtml = '';
        foreach ($columns as $col) {
            $content = Sanitize::richText($col['content'] ?? '');
            $colsHtml .= "<div class=\"prose max-w-none\">{$content}</div>";
        }
        
        return <<<HTML
<div class="py-8 reveal">
    <div class="grid {$gridCols} gap-8">
        {$colsHtml}
    </div>
</div>
HTML;
    }
    
    private static function renderSpacer(array $data): string {
        $size = $data['size'] ?? 'medium';
        $heights = ['small' => 'py-4', 'medium' => 'py-8', 'large' => 'py-16', 'xlarge' => 'py-24'];
        $height = $heights[$size] ?? $heights['medium'];
        return "<div class=\"{$height}\"></div>";
    }
    
    private static function renderMap(array $data): string {
        $title = Sanitize::html($data['title'] ?? '');
        $address = Sanitize::html($data['address'] ?? '');
        $embed = Sanitize::html($data['embed'] ?? '');
        
        $titleHtml = $title ? "<h3 class=\"text-2xl font-bold text-center mb-6\">{$title}</h3>" : '';
        $embedHtml = $embed ? "<div class=\"aspect-video rounded-box overflow-hidden shadow-xl\"><iframe src=\"{$embed}\" class=\"w-full h-full\" allowfullscreen loading=\"lazy\"></iframe></div>" : '';
        $addressHtml = $address ? "<p class=\"flex items-center justify-center gap-2 mt-4 text-base-content/70\"><svg class=\"w-5 h-5\" fill=\"currentColor\" viewBox=\"0 0 20 20\"><path fill-rule=\"evenodd\" d=\"M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z\" clip-rule=\"evenodd\"/></svg>{$address}</p>" : '';
        
        return <<<HTML
<div class="py-12 reveal">
    {$titleHtml}
    {$embedHtml}
    {$addressHtml}
</div>
HTML;
    }
    
    private static function renderContactInfo(array $data): string {
        $title = Sanitize::html($data['title'] ?? '');
        $items = $data['items'] ?? [];
        if (empty($items)) return '';
        
        $titleHtml = $title ? "<h3 class=\"text-2xl font-bold mb-8 text-center\">{$title}</h3>" : '';
        
        $itemsHtml = '';
        foreach ($items as $item) {
            $icon = Sanitize::html($item['icon'] ?? '📧');
            $label = Sanitize::html($item['label'] ?? '');
            $value = Sanitize::html($item['value'] ?? '');
            $url = Sanitize::html($item['url'] ?? '');
            
            $valueHtml = $url 
                ? "<a href=\"{$url}\" class=\"link link-hover hover:text-primary transition-colors\">{$value}</a>" 
                : "<span>{$value}</span>";
            
            $itemsHtml .= <<<ITEM
<div class="flex flex-col items-center gap-3 p-6 text-center hover:bg-base-200/50 rounded-xl transition-all duration-300">
    <span class="text-3xl text-primary mb-2">{$icon}</span>
    <div class="space-y-1">
        <div class="text-xs font-bold uppercase tracking-widest opacity-60">{$label}</div>
        <div class="text-lg font-medium">{$valueHtml}</div>
    </div>
</div>
ITEM;
        }
        
        return <<<HTML
<div class="py-16 reveal">
    {$titleHtml}
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8 max-w-5xl mx-auto">
        {$itemsHtml}
    </div>
</div>
HTML;
    }
    
    private static function renderSocial(array $data): string {
        $title = Sanitize::html($data['title'] ?? '');
        $items = $data['items'] ?? $data['links'] ?? [];
        if (empty($items)) return '';
        
        $titleHtml = $title ? "<h3 class=\"text-xl font-medium mb-4\">{$title}</h3>" : '';
        
        $linksHtml = '';
        foreach ($items as $item) {
            $platform = strtolower($item['platform'] ?? '');
            $url = Sanitize::html($item['url'] ?? '#');
            $icon = Sanitize::html($item['icon'] ?? '🔗');
            
            // Map platforms to badge colors
            $badgeClass = match($platform) {
                'twitter', 'x' => 'badge-info',
                'facebook' => 'badge-primary',
                'instagram' => 'badge-secondary',
                'linkedin' => 'badge-info',
                'youtube' => 'badge-error',
                'github' => 'badge-neutral',
                default => 'badge-ghost'
            };
            
            $linksHtml .= "<a href=\"{$url}\" class=\"btn btn-circle btn-ghost text-2xl hover:scale-110 transition-transform\" target=\"_blank\" rel=\"noopener\" title=\"" . ucfirst($platform) . "\">{$icon}</a>";
        }
        
        return <<<HTML
<div class="py-8 text-center reveal">
    {$titleHtml}
    <div class="flex flex-wrap justify-center gap-2">
        {$linksHtml}
    </div>
</div>
HTML;
    }
}

class AssetController {
    public static function serve(string $hash): void {
        Asset::serve($hash);
    }
    
    public static function serveCSS(string $hash): void {
        $file = ONECMS_CACHE . '/assets/' . $hash . '.css';
        
        if (!file_exists($file)) {
            // Regenerate CSS
            CSSGenerator::cacheAndGetHash();
            $files = glob(ONECMS_CACHE . '/assets/app.*.css');
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
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 13B: VISUAL EDITOR
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
        header('Cache-Control: public, max-age=31536000');
        echo <<<'JS'
// Theme toggle functionality
(function() {
    const darkThemes = {
        'emerald': 'forest',
        'pastel': 'night',
        'light': 'dark',
        'corporate': 'business',
        'cupcake': 'dracula',
        'bumblebee': 'halloween',
        'retro': 'coffee',
        'garden': 'forest',
        'lofi': 'black',
        'fantasy': 'dracula',
        'wireframe': 'black',
        'cmyk': 'night',
        'autumn': 'coffee',
        'acid': 'night',
        'lemonade': 'forest',
        'winter': 'night',
        'nord': 'dim',
        // Already dark themes map to light
        'forest': 'emerald',
        'night': 'pastel',
        'dark': 'light',
        'business': 'corporate',
        'dracula': 'cupcake',
        'halloween': 'bumblebee',
        'coffee': 'retro',
        'black': 'lofi',
        'dim': 'nord',
        'luxury': 'corporate',
        'synthwave': 'cupcake',
        'cyberpunk': 'cupcake',
        'aqua': 'dracula',
        'valentine': 'dracula',
        'sunset': 'cupcake'
    };
    
    const lightThemes = ['emerald', 'pastel', 'light', 'corporate', 'cupcake', 'bumblebee', 'retro', 'garden', 'lofi', 'fantasy', 'wireframe', 'cmyk', 'autumn', 'acid', 'lemonade', 'winter', 'nord'];
    
    function getCurrentTheme() {
        return document.documentElement.getAttribute('data-theme') || 'emerald';
    }
    
    function isLightTheme(theme) {
        return lightThemes.includes(theme);
    }
    
    function setTheme(theme, saveBase = true) {
        document.documentElement.setAttribute('data-theme', theme);
        if (saveBase) {
            const baseTheme = isLightTheme(theme) ? theme : (darkThemes[theme] || 'emerald');
            localStorage.setItem('onecms-base-theme', baseTheme);
        }
        localStorage.setItem('onecms-is-dark', !isLightTheme(theme));
        updateToggle();
    }
    
    function updateToggle() {
        const toggle = document.getElementById('theme-toggle');
        if (toggle) {
            const isDark = !isLightTheme(getCurrentTheme());
            toggle.checked = isDark;
        }
    }
    
    function toggleTheme() {
        const current = getCurrentTheme();
        const newTheme = darkThemes[current] || 'dark';
        setTheme(newTheme, false);
    }
    
    // Initialize
    document.addEventListener('DOMContentLoaded', function() {
        const toggle = document.getElementById('theme-toggle');
        if (toggle) {
            toggle.addEventListener('change', toggleTheme);
        }
        updateToggle();
    });
    
    // Also update immediately in case DOM is already loaded
    updateToggle();
})();
JS;
        exit;
    }
    
    private static function getEditorCSS(): string {
        return <<<'CSS'
/* OneCMS Visual Editor */
.onecms-edit-bar {
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
    background: #3b82f6;
    color: #fff;
}
.edit-bar-btn-edit:hover {
    background: #2563eb;
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
.onecms-block-wrapper {
    position: relative;
    margin-bottom: 2rem;
    transition: all 0.2s;
}
.onecms-block-wrapper:hover {
    outline: 2px dashed #3b82f6;
    outline-offset: 8px;
}
.onecms-block-wrapper:hover .onecms-block-toolbar {
    opacity: 1;
    transform: translateY(0);
}

/* Block Toolbar */
.onecms-block-toolbar {
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
    background: #3b82f6;
}
.block-action-danger:hover {
    background: #ef4444;
}

/* Add Block Button */
.onecms-add-block {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1.5rem;
    border: 2px dashed #cbd5e1;
    border-radius: 12px;
    margin: 2rem 0;
    cursor: pointer;
    transition: all 0.2s;
    color: #64748b;
    font-weight: 500;
}
.onecms-add-block:hover {
    border-color: #3b82f6;
    color: #3b82f6;
    background: rgba(59, 130, 246, 0.05);
}

/* Block Editor Modal */
.onecms-modal-overlay {
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
.onecms-modal {
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 25px 50px rgba(0,0,0,0.25);
    width: 100%;
    max-width: 600px;
    max-height: 80vh;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}
.onecms-modal-header {
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.onecms-modal-title {
    font-weight: 600;
    font-size: 18px;
    color: #1e293b;
}
.onecms-modal-close {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: #64748b;
    padding: 0;
    line-height: 1;
}
.onecms-modal-close:hover {
    color: #1e293b;
}
.onecms-modal-body {
    padding: 1.5rem;
    overflow-y: auto;
    flex: 1;
}
.onecms-modal-footer {
    padding: 1rem 1.5rem;
    border-top: 1px solid #e2e8f0;
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
    color: #475569;
    margin-bottom: 0.5rem;
}
.editor-input, .editor-textarea, .editor-select {
    width: 100%;
    padding: 0.625rem 0.875rem;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    font-size: 14px;
    transition: border-color 0.2s, box-shadow 0.2s;
}
.editor-input:focus, .editor-textarea:focus, .editor-select:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}
.editor-textarea {
    min-height: 100px;
    resize: vertical;
}
.editor-color {
    width: 60px;
    height: 40px;
    padding: 2px;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    cursor: pointer;
}

/* Buttons */
.editor-btn {
    padding: 0.625rem 1.25rem;
    border-radius: 8px;
    font-weight: 500;
    font-size: 14px;
    cursor: pointer;
    transition: all 0.2s;
    border: none;
}
.editor-btn-primary {
    background: #3b82f6;
    color: #fff;
}
.editor-btn-primary:hover {
    background: #2563eb;
}
.editor-btn-secondary {
    background: #f1f5f9;
    color: #475569;
}
.editor-btn-secondary:hover {
    background: #e2e8f0;
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
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s;
}
.block-type-option:hover {
    border-color: #3b82f6;
    background: rgba(59, 130, 246, 0.05);
}
.block-type-option.selected {
    border-color: #3b82f6;
    background: rgba(59, 130, 246, 0.1);
}
.block-type-icon {
    font-size: 24px;
    margin-bottom: 0.5rem;
}
.block-type-name {
    font-weight: 500;
    font-size: 13px;
    color: #1e293b;
}

/* Toast Notifications */
.onecms-toast {
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
.onecms-toast.success {
    background: #059669;
}
.onecms-toast.error {
    background: #dc2626;
}
@keyframes slideIn {
    from { transform: translateY(20px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}

/* Section Wrappers (Header, Nav, Footer) */
.onecms-section-wrapper {
    position: relative;
}
.onecms-section-wrapper:hover {
    outline: 2px dashed #3b82f6;
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
.onecms-section-wrapper[data-section="nav"] .section-edit-btn {
    right: 100px;
}
.onecms-section-wrapper:hover .section-edit-btn {
    opacity: 1;
}
.section-edit-btn:hover {
    background: #3b82f6;
}

/* Dragging State */
.onecms-block-wrapper.dragging {
    opacity: 0.5;
    outline: 2px solid #3b82f6;
}
.onecms-block-wrapper.drag-over {
    outline: 2px solid #10b981;
    outline-offset: 8px;
}

/* Inline Editable */
[data-editable] {
    cursor: text;
    transition: background 0.2s;
}
[data-editable]:hover {
    background: rgba(59, 130, 246, 0.1);
}
[data-editable]:focus {
    outline: 2px solid #3b82f6;
    outline-offset: 2px;
}
CSS;
    }
    
    private static function getEditorJS(): string {
        return <<<'JS'
// OneCMS Visual Editor - Unified with Preview Editor
(function() {
    'use strict';
    
    const pageId = document.querySelector('main.container')?.dataset.pageId;
    const csrfToken = document.getElementById('onecms-csrf')?.value || '';
    
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
        t.className = `onecms-toast ${type}`;
        t.textContent = message;
        document.body.appendChild(t);
        setTimeout(() => t.remove(), 3000);
    }
    
    function createModal(title, content, onSave) {
        const overlay = document.createElement('div');
        overlay.className = 'onecms-modal-overlay';
        overlay.innerHTML = `
            <div class="onecms-modal" style="max-width:700px;max-height:90vh;">
                <div class="onecms-modal-header">
                    <span class="onecms-modal-title">${title}</span>
                    <button class="onecms-modal-close">&times;</button>
                </div>
                <div class="onecms-modal-body" style="max-height:60vh;overflow-y:auto;">${content}</div>
                <div class="onecms-modal-footer">
                    <button class="editor-btn editor-btn-secondary cancel-btn">Cancel</button>
                    <button class="editor-btn editor-btn-primary save-btn">Save Changes</button>
                </div>
            </div>
        `;
        
        document.body.appendChild(overlay);
        
        overlay.querySelector('.onecms-modal-close').onclick = () => overlay.remove();
        overlay.querySelector('.cancel-btn').onclick = () => overlay.remove();
        overlay.querySelector('.save-btn').onclick = async () => {
            await onSave();
            overlay.remove();
        };
        overlay.onclick = (e) => { if (e.target === overlay) overlay.remove(); };
        
        // Add event delegation for actions
        overlay.addEventListener('click', function(e) {
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
    let galleryModal = null;
    
    async function openGallery(targetName) {
        currentGalleryTarget = targetName;
        
        // Create gallery modal
        galleryModal = document.createElement('div');
        galleryModal.className = 'onecms-modal-overlay';
        galleryModal.style.zIndex = '10010';
        galleryModal.innerHTML = `
            <div class="onecms-modal" style="max-width:800px;">
                <div class="onecms-modal-header">
                    <span class="onecms-modal-title">Select Image</span>
                    <button class="onecms-modal-close">&times;</button>
                </div>
                <div class="onecms-modal-body">
                    <div id="galleryGrid" style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;">
                        <p style="grid-column:1/-1;text-align:center;color:#64748b;">Loading images...</p>
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(galleryModal);
        
        galleryModal.querySelector('.onecms-modal-close').onclick = () => galleryModal.remove();
        galleryModal.onclick = (e) => { if (e.target === galleryModal) galleryModal.remove(); };
        
        // Load images
        try {
            const resp = await fetch('/admin/media/json');
            const data = await resp.json();
            const images = data.images || [];
            
            const grid = galleryModal.querySelector('#galleryGrid');
            if (images.length === 0) {
                grid.innerHTML = '<p style="grid-column:1/-1;text-align:center;color:#64748b;">No images uploaded yet.</p>';
                return;
            }
            
            grid.innerHTML = images.map(img => `
                <div class="gallery-item" data-url="${escapeHtml(img.url)}" style="cursor:pointer;border:2px solid transparent;border-radius:8px;overflow:hidden;transition:border-color 0.2s;">
                    <img src="${escapeHtml(img.url)}" alt="${escapeHtml(img.filename)}" style="width:100%;height:80px;object-fit:cover;">
                    <div style="padding:4px;font-size:11px;background:#f1f5f9;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${escapeHtml(img.filename)}</div>
                </div>
            `).join('');
            
            grid.querySelectorAll('.gallery-item').forEach(item => {
                item.onmouseover = () => item.style.borderColor = '#3b82f6';
                item.onmouseout = () => item.style.borderColor = 'transparent';
                item.onclick = () => selectImage(item.dataset.url);
            });
        } catch(e) {
            galleryModal.querySelector('#galleryGrid').innerHTML = '<p style="grid-column:1/-1;text-align:center;color:#dc2626;">Failed to load images</p>';
        }
    }
    
    function selectImage(url) {
        if (!currentGalleryTarget) return;
        const input = document.querySelector(`[name="${currentGalleryTarget}"]`);
        if (input) {
            input.value = url;
            // Update preview
            const container = input.closest('.editor-field, .form-control, div');
            let preview = container?.querySelector('.image-preview');
            if (preview) {
                preview.innerHTML = `<img src="${escapeHtml(url)}" style="max-height:100px;border-radius:8px;margin-top:8px;">`;
            }
        }
        if (galleryModal) galleryModal.remove();
        currentGalleryTarget = null;
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
        const fieldMap = { features: ['icon', 'title', 'description'], stats: ['icon', 'value', 'label'], testimonials: ['name', 'role', 'quote', 'rating'], faq: ['question', 'answer'] };
        const fields = fieldMap[type] || ['title', 'description'];
        const container = document.getElementById('itemsContainer');
        const i = container.children.length;
        
        let fieldsHtml = fields.map(f => {
            const isTextarea = ['description', 'quote', 'answer'].includes(f);
            const isIcon = f === 'icon';
            
            if (isIcon) {
                return `<div class="editor-field"><label class="editor-label">Icon</label>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <select name="items[${i}][${f}]" class="editor-select" style="flex:1;">${getIconOptions('star')}</select>
                        <span class="icon-preview" style="font-family:'Material Symbols Outlined';font-size:24px;color:#3b82f6;">star</span>
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
            
            default:
                return `<div style="color:#64748b;margin-bottom:16px;">Editor for ${type} blocks - edit raw JSON:</div>
                    <textarea name="raw_json" class="editor-textarea" style="height:200px;font-family:monospace;font-size:12px;">${escapeHtml(JSON.stringify(data, null, 2))}</textarea>`;
        }
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
            features: ['icon', 'title', 'description'],
            stats: ['icon', 'value', 'label'],
            testimonials: ['name', 'role', 'quote', 'rating'],
            faq: ['question', 'answer']
        };
        const fields = fieldMap[type] || ['title', 'description'];
        
        const itemsHtml = items.map((item, i) => {
            const fieldsHtml = fields.map(f => {
                const isTextarea = ['description', 'quote', 'answer'].includes(f);
                const isIcon = f === 'icon';
                const val = escapeHtml(item[f] || '');
                
                if (isIcon) {
                    return `<div class="editor-field"><label class="editor-label">Icon</label>
                        <div style="display:flex;gap:8px;align-items:center;">
                            <select name="items[${i}][${f}]" class="editor-select" style="flex:1;">${getIconOptions(item[f] || 'star')}</select>
                            <span class="icon-preview" style="font-family:'Material Symbols Outlined';font-size:24px;color:#3b82f6;">${item[f] || 'star'}</span>
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
        
        // Simple fields
        modal.querySelectorAll('input:not([type="checkbox"]):not([name*="["]), textarea:not([name*="["]), select:not([name*="["])').forEach(el => {
            if (el.name && el.name !== 'raw_json') {
                data[el.name] = el.value;
            }
        });
        
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
    
    // Edit block
    async function editBlock(wrapper) {
        const blockId = wrapper.dataset.blockId;
        const blockType = wrapper.dataset.blockType;
        const dataScript = wrapper.querySelector('.block-data');
        const currentData = dataScript ? JSON.parse(dataScript.textContent) : {};
        
        const blockName = blockType.charAt(0).toUpperCase() + blockType.slice(1);
        const content = getEditorForm(blockType, currentData);
        
        createModal(`Edit ${blockName} Block`, content, async () => {
            const modal = document.querySelector('.onecms-modal');
            const newData = collectFormData(modal, blockType);
            
            try {
                const formData = new FormData();
                formData.append('_csrf', getCsrfToken());
                formData.append('block_json', JSON.stringify(newData));
                
                const res = await fetch(`/api/blocks/${blockId}/update`, {
                    method: 'POST',
                    body: formData
                });
                
                if (res.ok) {
                    toast('Block updated! Refreshing...');
                    setTimeout(() => location.reload(), 500);
                } else {
                    toast('Failed to update block', 'error');
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
        { type: 'newsletter', icon: 'newspaper', name: 'Newsletter' }
    ];
    
    // Add new block
    function showAddBlockModal() {
        const typeOptions = blockTypeList.map(b => `
            <div class="block-type-option" data-type="${b.type}" style="padding:16px;border:2px solid #e2e8f0;border-radius:12px;text-align:center;cursor:pointer;transition:all 0.2s;">
                <span style="font-family:'Material Symbols Outlined';font-size:28px;display:block;margin-bottom:8px;color:#3b82f6;">${b.icon}</span>
                <div style="font-weight:500;font-size:13px;">${b.name}</div>
            </div>
        `).join('');
        
        const content = `
            <p style="margin-bottom:16px;color:#64748b;">Choose a block type to add:</p>
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;">${typeOptions}</div>
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
            opt.onmouseover = () => { if (!opt.classList.contains('selected')) opt.style.borderColor = '#3b82f6'; };
            opt.onmouseout = () => { if (!opt.classList.contains('selected')) opt.style.borderColor = '#e2e8f0'; };
            opt.onclick = () => {
                modal.querySelectorAll('.block-type-option').forEach(o => { o.classList.remove('selected'); o.style.borderColor = '#e2e8f0'; o.style.background = ''; });
                opt.classList.add('selected');
                opt.style.borderColor = '#3b82f6';
                opt.style.background = 'rgba(59,130,246,0.1)';
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
            const modal = document.querySelector('.onecms-modal');
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
            const modalEl = document.querySelector('.onecms-modal');
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
            const modal = document.querySelector('.onecms-modal');
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
        // Handle block actions
        document.querySelectorAll('.onecms-block-wrapper').forEach(wrapper => {
            wrapper.querySelectorAll('.block-action').forEach(btn => {
                btn.onclick = (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    const action = btn.dataset.action;
                    switch (action) {
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
        const main = document.querySelector('main.container');
        if (main && pageId) {
            const addBtn = document.createElement('div');
            addBtn.className = 'onecms-add-block';
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
}

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
        
        // Create revision before updating
        Revision::create('content_blocks', $id);
        
        DB::execute(
            "UPDATE content_blocks SET block_json = ?, updated_at = datetime('now') WHERE id = ?",
            [$blockJson, $id]
        );
        
        // Get page and invalidate cache
        $page = DB::fetch("SELECT slug FROM pages WHERE id = ?", [$block['page_id']]);
        if ($page) {
            Cache::invalidatePage($page['slug']);
        }
        
        Response::json(['success' => true]);
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
        
        // Create revision before deleting
        Revision::create('content_blocks', $id);
        
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
        
        // Get max sort order
        $max = DB::fetch("SELECT MAX(sort_order) as max_order FROM content_blocks WHERE page_id = ?", [$pageId]);
        $sortOrder = ($max['max_order'] ?? 0) + 1;
        
        // Default content for each block type
        $defaults = [
            'hero' => ['title' => 'New Hero', 'subtitle' => 'Add your subtitle here', 'button' => 'Learn More', 'url' => '#'],
            'text' => ['content' => '<p>Add your content here...</p>'],
            'cta' => ['title' => 'Call to Action', 'text' => 'Your message here', 'button' => 'Get Started', 'url' => '#'],
            'features' => ['items' => [['icon' => '⭐', 'title' => 'Feature 1', 'description' => 'Description here']]],
            'stats' => ['items' => [['value' => '100+', 'label' => 'Customers']]],
            'testimonials' => ['items' => [['quote' => 'Great product!', 'name' => 'John Doe', 'role' => 'CEO']]],
            'image' => ['url' => '', 'alt' => '', 'caption' => ''],
            'gallery' => ['images' => '', 'columns' => 3],
            'pricing' => ['items' => [['name' => 'Basic', 'price' => '$9', 'period' => '/mo', 'features' => ['Feature 1'], 'button' => 'Get Started', 'url' => '#']]],
            'team' => ['items' => [['name' => 'Team Member', 'role' => 'Position', 'bio' => 'Bio here']]],
            'faq' => ['items' => [['question' => 'Question?', 'answer' => 'Answer here.']]],
            'form' => []
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
}

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
        
        // Handle logo URL - if it's an uploaded asset path, we need to find or store it
        // For now, we store the logo URL in settings if it's not an asset
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
// SECTION 13B: MEDIA API
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
// SECTION 13C: AI PAGE GENERATION API
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
        'columns' => '{"type": "columns", "content": {"columns": [{"content": "<p>Column 1</p>"}, {"content": "<p>Column 2</p>"}]}}'
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
            
            $fullPrompt = self::buildPrompt($prompt, $pageType, $recommendedBlocks);
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
    
    private static function buildPrompt(string $userPrompt, string $pageType = 'custom', array $recommendedBlocks = []): string {
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
        
        // Page type guidance
        $typeGuidance = match($pageType) {
            'landing' => "This is a LANDING PAGE. Start with an impactful hero, showcase key features/benefits, include social proof (testimonials, stats, logos), and end with a strong CTA.",
            'about' => "This is an ABOUT PAGE. Tell the company story, introduce the team, highlight values/mission, show milestones/timeline, and include trust-building elements.",
            'services' => "This is a SERVICES PAGE. Clearly present service offerings with cards, explain the process/steps, show pricing if applicable, include testimonials, and add a contact form.",
            'pricing' => "This is a PRICING PAGE. Present pricing plans clearly with comparison, highlight the recommended plan, address common questions in FAQ, and include testimonials for trust.",
            'contact' => "This is a CONTACT PAGE. Include a user-friendly form, show contact information (email, phone, address), embed a map if applicable, and add FAQ for common questions.",
            'portfolio' => "This is a PORTFOLIO PAGE. Showcase work with a gallery, highlight key projects with cards, include client testimonials and stats, end with a CTA.",
            'blog' => "This is a BLOG/ARTICLE PAGE. Focus on rich text content, use quotes for emphasis, include relevant images, and end with newsletter signup or related content CTA.",
            'faq' => "This is an FAQ PAGE. Organize questions logically, provide detailed answers, include contact info for unanswered questions.",
            'team' => "This is a TEAM PAGE. Present team members with photos and bios, show company culture, include stats about the team, add testimonials.",
            'product' => "This is a PRODUCT PAGE. Highlight key features, show the product with gallery/images, include specs in a table, add testimonials and comparison with competitors, show pricing.",
            default => "Create a well-structured page that serves the user's needs with appropriate content blocks."
        };
        
        return <<<PROMPT
You are generating a {$pageType} page for a website.

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
5. Write in a professional but engaging tone
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
// SECTION 14: AI INTEGRATION
// ─────────────────────────────────────────────────────────────────────────────

class AI {
    /**
     * Get the configured AI provider settings
     */
    private static function getProvider(): array {
        return [
            'provider' => Settings::get('ai_provider', 'openai'),
            'api_key' => Settings::get('ai_api_key', ''),
            'model' => Settings::get('ai_model', 'gpt-5.2')
        ];
    }
    
    /**
     * Check if AI is configured
     */
    public static function isConfigured(): bool {
        $config = self::getProvider();
        return !empty($config['api_key']);
    }
    
    /**
     * Generate content using the configured AI provider
     */
    public static function generate(string $prompt): ?array {
        $config = self::getProvider();
        
        // Debug logging
        error_log("=== AI GENERATE START ===");
        error_log("Provider: " . $config['provider']);
        error_log("Model: " . $config['model']);
        error_log("Prompt length: " . strlen($prompt) . " chars");
        
        if (empty($config['api_key'])) {
            error_log("ERROR: No API key configured");
            return null;
        }
        
        $response = match($config['provider']) {
            'anthropic' => self::anthropicRequest($prompt, $config),
            'google' => self::googleRequest($prompt, $config),
            default => self::openaiRequest($prompt, $config)
        };
        
        if (!$response) {
            error_log("ERROR: No response from AI provider");
            return null;
        }
        
        error_log("Response length: " . strlen($response) . " chars");
        error_log("Response preview: " . substr($response, 0, 500));
        
        // Try to parse JSON from the response
        $json = self::extractJson($response);
        
        if ($json) {
            error_log("JSON parsed successfully. Keys: " . implode(', ', array_keys($json)));
        } else {
            error_log("ERROR: Failed to parse JSON from response");
        }
        error_log("=== AI GENERATE END ===");
        
        return $json;
    }
    
    /**
     * Make a request to OpenAI API
     */
    private static function openaiRequest(string $prompt, array $config): ?string {
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        
        $payload = [
            'model' => $config['model'],
            'messages' => [
                ['role' => 'system', 'content' => self::getSystemPrompt()],
                ['role' => 'user', 'content' => $prompt]
            ],
            'temperature' => 0.7,
            'max_tokens' => 4096
        ];
        
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $config['api_key']
            ],
            CURLOPT_POSTFIELDS => json_encode($payload)
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error || $httpCode !== 200) {
            error_log("OpenAI API error: HTTP $httpCode - $error - $response");
            return null;
        }
        
        $data = json_decode($response, true);
        return $data['choices'][0]['message']['content'] ?? null;
    }
    
    /**
     * Make a request to Anthropic API
     */
    private static function anthropicRequest(string $prompt, array $config): ?string {
        $model = $config['model'];
        if (str_starts_with($model, 'gpt') || str_starts_with($model, 'gemini')) {
            $model = 'claude-sonnet-4-5'; // Default Claude model
        }
        
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        
        $payload = [
            'model' => $model,
            'max_tokens' => 4096,
            'system' => self::getSystemPrompt(),
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ]
        ];
        
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $config['api_key'],
                'anthropic-version: 2023-06-01'
            ],
            CURLOPT_POSTFIELDS => json_encode($payload)
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error || $httpCode !== 200) {
            error_log("Anthropic API error: HTTP $httpCode - $error - $response");
            return null;
        }
        
        $data = json_decode($response, true);
        return $data['content'][0]['text'] ?? null;
    }
    
    /**
     * Make a request to Google Gemini API
     */
    private static function googleRequest(string $prompt, array $config): ?string {
        $model = $config['model'];
        if (str_starts_with($model, 'gpt') || str_starts_with($model, 'claude')) {
            $model = 'gemini-3-flash-preview'; // Default Gemini model
        }
        
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . $config['api_key'];
        
        $ch = curl_init($url);
        
        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => self::getSystemPrompt() . "\n\n" . $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.7,
                'maxOutputTokens' => 8192
            ]
        ];
        
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode($payload)
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            error_log("Google Gemini API curl error: $error");
            return null;
        }
        
        if ($httpCode !== 200) {
            error_log("Google Gemini API error: HTTP $httpCode - Response: $response");
            return null;
        }
        
        $data = json_decode($response, true);
        
        if (!$data) {
            error_log("Google Gemini API error: Failed to parse JSON response");
            return null;
        }
        
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
        
        if (!$text) {
            error_log("Google Gemini API error: No text in response - " . json_encode($data));
            return null;
        }
        
        return $text;
    }
    
    /**
     * Extract JSON from AI response (handles markdown code blocks)
     */
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
        
        return null;
    }
    
    /**
     * Get the system prompt for site generation
     */
    private static function getSystemPrompt(): string {
        return <<<'PROMPT'
You are an expert website builder AI that creates stunning, modern designs like professional UI tools (similar to Banani, Framer, Webflow). Generate visually sophisticated, well-structured websites.

IMPORTANT: Respond ONLY with valid JSON. Do not include any explanation or markdown formatting.

Your output must be valid JSON with this exact structure:
{
  "site_name": "Business Name",
  "tagline": "A catchy tagline for the business",
  "colors": {
    "primary": "#2563eb",
    "secondary": "#1e40af",
    "accent": "#0ea5e9",
    "background": "#ffffff",
    "text": "#1e293b",
    "header_bg": "#0f172a",
    "header_text": "#f8fafc",
    "footer_bg": "#1e293b",
    "footer_text": "#f1f5f9",
    "cta_bg": "#2563eb",
    "cta_text": "#ffffff"
  },
  "pages": [
    {
      "slug": "home",
      "title": "Home",
      "meta_description": "Welcome to Business Name - brief description",
      "blocks": [...]
    }
  ],
  "nav": [
    {"label": "Home", "url": "/"},
    {"label": "About", "url": "/about"},
    {"label": "Contact", "url": "/contact"}
  ],
  "footer": {
    "text": "© 2025 Business Name. All rights reserved.",
    "links": [{"label": "Privacy Policy", "url": "/privacy"}],
    "social": [{"platform": "twitter", "url": "https://twitter.com/"}]
  }
}

═══════════════════════════════════════════════════════════════════════════════
AVAILABLE BLOCK TYPES - Use these to create professional, visually rich layouts
═══════════════════════════════════════════════════════════════════════════════

1. HERO - Main page banner (gradient background, centered text)
   {"type": "hero", "content": {"title": "Main Heading", "subtitle": "Supporting text", "button": "Get Started", "url": "/contact"}}

2. TEXT - Rich text content 
   {"type": "text", "content": {"content": "<p>HTML content here...</p>"}}

3. CTA - Call-to-action section (gradient background)
   {"type": "cta", "content": {"title": "Call to Action", "text": "Description", "button": "Learn More", "url": "/about"}}

4. FEATURES - Grid of feature cards with icons (great for services/benefits)
   {"type": "features", "content": {"items": [
     {"icon": "⚡", "title": "Fast Performance", "description": "Lightning quick load times"},
     {"icon": "🔒", "title": "Secure", "description": "Enterprise-grade security"},
     {"icon": "📱", "title": "Responsive", "description": "Works on all devices"}
   ]}}

5. STATS - Impressive numbers section (great for social proof)
   {"type": "stats", "content": {"items": [
     {"value": "10K+", "label": "Happy Customers"},
     {"value": "99%", "label": "Satisfaction Rate"},
     {"value": "24/7", "label": "Support Available"}
   ]}}

6. TESTIMONIALS - Customer testimonial cards with avatars
   {"type": "testimonials", "content": {"items": [
     {"quote": "This product transformed our business!", "name": "Sarah Johnson", "role": "CEO, TechCorp"},
     {"quote": "Best decision we ever made.", "name": "Mike Chen", "role": "Founder, StartupXYZ"}
   ]}}

7. PRICING - Pricing table with tiers (set featured:true for highlighted tier)
   {"type": "pricing", "content": {"items": [
     {"name": "Starter", "price": "$9", "period": "/month", "features": ["5 Projects", "Basic Support", "1GB Storage"], "button": "Start Free", "url": "/signup"},
     {"name": "Pro", "price": "$29", "period": "/month", "features": ["Unlimited Projects", "Priority Support", "100GB Storage", "API Access"], "button": "Get Pro", "url": "/signup", "featured": true},
     {"name": "Enterprise", "price": "$99", "period": "/month", "features": ["Everything in Pro", "Dedicated Support", "Custom Integrations"], "button": "Contact Us", "url": "/contact"}
   ]}}

8. TEAM - Team member cards with photos
   {"type": "team", "content": {"items": [
     {"name": "Jane Smith", "role": "CEO & Founder", "bio": "15+ years in tech leadership", "photo": ""},
     {"name": "John Doe", "role": "CTO", "bio": "Former Google engineer", "photo": ""}
   ]}}

9. CARDS - Generic card grid (for services, portfolio, blog posts)
   {"type": "cards", "content": {"items": [
     {"title": "Web Development", "description": "Custom websites built with modern tech", "image": "", "button": "Learn More", "url": "/services/web"},
     {"title": "Mobile Apps", "description": "Native iOS and Android applications", "image": "", "button": "Learn More", "url": "/services/mobile"}
   ]}}

10. FAQ - Frequently asked questions
    {"type": "faq", "content": {"items": [
      {"question": "How does your service work?", "answer": "We provide end-to-end solutions..."},
      {"question": "What's your refund policy?", "answer": "30-day money back guarantee..."}
    ]}}

11. GALLERY - Image gallery grid
    {"type": "gallery", "content": {"items": [{"url": "/assets/img1.jpg", "alt": "Project 1"}], "columns": 3}}

12. FORM - Contact form (no content needed)
    {"type": "form", "content": {}}

13. IMAGE - Single image with caption
    {"type": "image", "content": {"url": "/assets/photo.jpg", "alt": "Description", "caption": "Optional caption"}}

═══════════════════════════════════════════════════════════════════════════════
PAGE LAYOUT BEST PRACTICES - Create professional, conversion-focused designs
═══════════════════════════════════════════════════════════════════════════════

HOME PAGE STRUCTURE (typical SaaS/Business):
1. Hero - Bold headline with CTA button
2. Features - 3-4 key benefits/features
3. Stats - Impressive numbers (optional)
4. Testimonials - Social proof
5. CTA - Final call to action

ABOUT PAGE STRUCTURE:
1. Hero - Company story headline
2. Text - Mission/vision statement
3. Team - Key team members
4. Stats - Company achievements

PRICING PAGE STRUCTURE:
1. Hero - "Simple, Transparent Pricing"
2. Pricing - 2-3 pricing tiers
3. FAQ - Common pricing questions
4. CTA - "Start your free trial"

SERVICES PAGE STRUCTURE:
1. Hero - Services overview
2. Features - Key service offerings
3. Cards - Individual services detail
4. CTA - "Get a quote"

CONTACT PAGE STRUCTURE:
1. Hero - "Get in Touch"
2. Text - Contact info, hours
3. Form - Contact form

═══════════════════════════════════════════════════════════════════════════════
COLOR PALETTE GUIDELINES - CRITICAL
═══════════════════════════════════════════════════════════════════════════════

Select ONE of these pre-designed, harmonious palettes. Do NOT mix colors from different palettes.

PALETTE 1 - Ocean Blue (Professional/Corporate):
  primary: #2563eb, secondary: #1e40af, accent: #0ea5e9, background: #ffffff, text: #1e293b
  header_bg: #0f172a, header_text: #f8fafc, footer_bg: #1e293b, footer_text: #f1f5f9, cta_bg: #2563eb, cta_text: #ffffff

PALETTE 2 - Forest Green (Natural/Health/Eco):
  primary: #059669, secondary: #047857, accent: #34d399, background: #ffffff, text: #1e293b
  header_bg: #064e3b, header_text: #f0fdf4, footer_bg: #1e293b, footer_text: #f1f5f9, cta_bg: #059669, cta_text: #ffffff

PALETTE 3 - Warm Orange (Creative/Food/Energy):
  primary: #ea580c, secondary: #c2410c, accent: #fb923c, background: #fffbeb, text: #1c1917
  header_bg: #1c1917, header_text: #fef3c7, footer_bg: #1c1917, footer_text: #fef3c7, cta_bg: #ea580c, cta_text: #ffffff

PALETTE 4 - Elegant Slate (Law/Finance/Luxury):
  primary: #475569, secondary: #334155, accent: #64748b, background: #f8fafc, text: #0f172a
  header_bg: #0f172a, header_text: #f8fafc, footer_bg: #1e293b, footer_text: #e2e8f0, cta_bg: #334155, cta_text: #ffffff

PALETTE 5 - Rose (Beauty/Lifestyle/Fashion):
  primary: #e11d48, secondary: #be123c, accent: #fb7185, background: #fff1f2, text: #1c1917
  header_bg: #1c1917, header_text: #fecdd3, footer_bg: #1c1917, footer_text: #fecdd3, cta_bg: #e11d48, cta_text: #ffffff

PALETTE 6 - Indigo Tech (Technology/SaaS/Startup):
  primary: #4f46e5, secondary: #4338ca, accent: #818cf8, background: #ffffff, text: #1e293b
  header_bg: #0f172a, header_text: #e0e7ff, footer_bg: #1e293b, footer_text: #e0e7ff, cta_bg: #4f46e5, cta_text: #ffffff

PALETTE 7 - Teal Modern (Consulting/Agency/Services):
  primary: #0d9488, secondary: #0f766e, accent: #2dd4bf, background: #ffffff, text: #1e293b
  header_bg: #0f172a, header_text: #ccfbf1, footer_bg: #1e293b, footer_text: #ccfbf1, cta_bg: #0d9488, cta_text: #ffffff

DESIGN EXCELLENCE RULES:
1. Use the advanced block types (features, stats, testimonials, pricing) for professional layouts
2. Every page should have a Hero block at the top
3. End most pages with a CTA block to drive conversions
4. Use emoji icons for feature blocks (⚡🔒📱🚀💡🎯✨🌟💪🎨)
5. Stats should have impressive, specific numbers with + or % signs
6. Testimonials should include realistic names and roles
7. Generate 4-5 pages typically: Home, About, Services/Products, Pricing, Contact
PROMPT;
    }
}

class AIController {
    /**
     * Render an admin template with layout
     */
    private static function renderAdmin(string $template, array $data = []): void {
        $user = Auth::user();
        $content = Template::render($template, $data);
        
        Response::html(Template::render('admin_layout', array_merge($data, [
            'content' => $content,
            'user_email' => $user['email'] ?? '',
            'user_role' => $user['role'] ?? '',
            'flash_error' => Session::getFlash('error'),
            'flash_success' => Session::getFlash('success'),
            'csp_nonce' => defined('CSP_NONCE') ? CSP_NONCE : '',
            'is_ai' => true,
            'csrf_field' => '<input type="hidden" name="_csrf" value="' . CSRF::token() . '">'
        ])));
    }
    
    /**
     * Display the AI generation form
     */
    public static function form(): void {
        Auth::require('*');
        
        $industries = [
            'Restaurant/Food Service',
            'Law Firm/Legal',
            'Medical/Healthcare',
            'E-commerce/Retail',
            'Portfolio/Creative',
            'Blog/News',
            'Agency/Consulting',
            'Real Estate',
            'Education',
            'Non-Profit',
            'Technology/SaaS',
            'Other'
        ];
        
        $tones = [
            'Professional' => 'Formal and business-oriented',
            'Friendly' => 'Warm and approachable',
            'Playful' => 'Fun and creative',
            'Minimal' => 'Clean and simple',
            'Bold' => 'Strong and confident'
        ];
        
        $features = [
            'contact_form' => 'Contact Form',
            'gallery' => 'Image Gallery',
            'testimonials' => 'Testimonials',
            'faq' => 'FAQ Section',
            'blog' => 'Blog/News',
            'newsletter' => 'Newsletter Signup',
            'team' => 'Team Members',
            'pricing' => 'Pricing Table',
            'portfolio' => 'Portfolio/Projects'
        ];
        
        $isConfigured = AI::isConfigured();
        $currentProvider = Settings::get('ai_provider', '');
        $currentModel = Settings::get('ai_model', '');
        $hasApiKey = !empty(Settings::get('ai_api_key', ''));
        
        $providerNames = [
            'openai' => 'OpenAI',
            'anthropic' => 'Anthropic',
            'google' => 'Google Gemini'
        ];
        
        self::renderAdmin('admin_ai', [
            'industries' => $industries,
            'tones' => $tones,
            'features' => $features,
            'is_configured' => $isConfigured,
            'current_provider_openai' => ($currentProvider === 'openai'),
            'current_provider_anthropic' => ($currentProvider === 'anthropic'),
            'current_provider_google' => ($currentProvider === 'google'),
            'current_provider_name' => $providerNames[$currentProvider] ?? 'Unknown',
            'current_model' => $currentModel,
            'has_api_key' => $hasApiKey
        ]);
    }
    
    /**
     * Save AI configuration
     */
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
        Settings::set('ai_api_key', $apiKey);
        
        if (!empty($model)) {
            Settings::set('ai_model', $model);
        } else {
            // Set default model based on provider
            $defaultModel = match($provider) {
                'anthropic' => 'claude-sonnet-4-5',
                'google' => 'gemini-3-flash-preview',
                default => 'gpt-5.2'
            };
            Settings::set('ai_model', $defaultModel);
        }
        
        Session::flash('success', 'AI configuration saved successfully!');
        Response::redirect('/admin/ai');
    }
    
    /**
     * Generate site plan using AI - Multi-Stage Approach
     */
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
            'business_name' => trim(Request::input('business_name', '')),
            'industry' => Request::input('industry', ''),
            'description' => trim(Request::input('description', '')),
            'tone' => Request::input('tone', 'Professional'),
            'features' => Request::input('features', []),
            'color_preference' => trim(Request::input('color_preference', '')),
            'pages_needed' => trim(Request::input('pages_needed', '')),
            'user_content' => trim(Request::input('user_content', '')),
            'ui_theme' => Request::input('ui_theme', 'emerald')
        ];
        
        // Validation
        if (empty($input['business_name'])) {
            Session::flash('error', 'Business name is required.');
            Response::redirect('/admin/ai');
        }
        
        if (empty($input['description'])) {
            Session::flash('error', 'Business description is required.');
            Response::redirect('/admin/ai');
        }
        
        error_log("=== MULTI-STAGE SITE GENERATION START ===");
        
        // STAGE 1: Generate site structure (pages list, colors, nav)
        error_log("STAGE 1: Generating site structure...");
        $structurePrompt = self::buildStructurePrompt($input);
        $structure = AI::generate($structurePrompt);
        
        if (!$structure || !isset($structure['pages'])) {
            error_log("STAGE 1 FAILED: No valid structure returned");
            Session::flash('error', 'Failed to generate site structure. Please try again.');
            Response::redirect('/admin/ai');
        }
        
        error_log("STAGE 1 SUCCESS: " . count($structure['pages']) . " pages planned");
        
        // STAGE 2: Generate detailed content for each page
        error_log("STAGE 2: Generating page content...");
        $detailedPages = [];
        
        foreach ($structure['pages'] as $index => $pagePlan) {
            $pageNum = $index + 1;
            $totalPages = count($structure['pages']);
            error_log("STAGE 2: Generating page {$pageNum}/{$totalPages}: {$pagePlan['title']}");
            
            $pagePrompt = self::buildPagePrompt($input, $pagePlan, $structure);
            $pageContent = AI::generate($pagePrompt);
            
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
            } else {
                // Fallback: use basic structure if page generation fails
                error_log("Page {$pageNum} generation failed, using basic structure");
                $detailedPages[] = $pagePlan;
            }
        }
        
        // Combine into final plan
        $plan = [
            'site_name' => $structure['site_name'] ?? $input['business_name'],
            'tagline' => $structure['tagline'] ?? '',
            'colors' => $structure['colors'] ?? self::getDefaultColors(),
            'ui_theme' => $input['ui_theme'],
            'pages' => $detailedPages,
            'nav' => $structure['nav'] ?? [],
            'footer' => $structure['footer'] ?? ['text' => '© ' . date('Y') . ' ' . $input['business_name']]
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
            "INSERT INTO build_queue (plan_json, status, created_at) VALUES (?, 'pending', datetime('now'))",
            [json_encode($plan)]
        );
        
        Session::flash('success', 'Site plan generated successfully! Review it in the Approvals queue.');
        Response::redirect('/admin/approvals');
    }
    
    /**
     * Get default color scheme
     */
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
    
    /**
     * Build prompt for STAGE 1: Site structure
     */
    private static function buildStructurePrompt(array $input): string {
        $features = is_array($input['features']) ? implode(', ', $input['features']) : '';
        
        return <<<PROMPT
You are planning the structure for a website. Generate ONLY the site skeleton - no page content yet.

BUSINESS INFO:
- Name: {$input['business_name']}
- Industry: {$input['industry']}
- Description: {$input['description']}
- Tone: {$input['tone']}
- Color preference: {$input['color_preference']}
- Requested pages: {$input['pages_needed']}
- Features needed: {$features}

RESPOND WITH JSON:
{
  "site_name": "Business Name",
  "tagline": "Short tagline",
  "colors": {
    "primary": "#hex",
    "secondary": "#hex", 
    "accent": "#hex",
    "background": "#ffffff",
    "text": "#1e293b",
    "header_bg": "#hex",
    "header_text": "#hex",
    "footer_bg": "#hex",
    "footer_text": "#hex"
  },
  "pages": [
    {"slug": "home", "title": "Home", "purpose": "Main landing page with hero and key features"},
    {"slug": "about", "title": "About", "purpose": "Company story and team"},
    {"slug": "services", "title": "Services", "purpose": "List of services offered"}
  ],
  "nav": [
    {"label": "Home", "url": "/"},
    {"label": "About", "url": "/about"}
  ],
  "footer": {
    "text": "© 2026 Business Name. All rights reserved."
  }
}

GUIDELINES:
- Include 4-7 pages based on the business type
- First page should be "home" with slug "home"
- Order pages logically (Home, About, Services, etc.)
- Choose colors that match the industry and requested preference
- Nav should match the pages in logical order

RESPOND ONLY WITH VALID JSON.
PROMPT;
    }
    
    /**
     * Build prompt for STAGE 2: Individual page content
     */
    private static function buildPagePrompt(array $input, array $pagePlan, array $structure): string {
        $pageType = self::inferPageType($pagePlan['slug'], $pagePlan['title']);
        $blockRecommendations = self::getBlockRecommendations($pageType);
        
        // Smart feature routing - only include relevant features for this page type
        $relevantFeatures = self::getRelevantFeatures($input['features'] ?? [], $pageType);
        $featuresStr = !empty($relevantFeatures) 
            ? "REQUIRED FEATURES FOR THIS PAGE - You MUST include these blocks:\n" . implode("\n", $relevantFeatures)
            : '';
        
        $userContent = !empty($input['user_content']) ? "\nUser-provided content to incorporate:\n{$input['user_content']}" : '';
        
        $pagePurpose = $pagePlan['purpose'] ?? 'Page for ' . $pagePlan['title'];
        
        return <<<PROMPT
Generate detailed content blocks for ONE page of a website.

BUSINESS CONTEXT:
- Name: {$input['business_name']}
- Industry: {$input['industry']}
- Description: {$input['description']}
- Tone: {$input['tone']}
{$userContent}

THIS PAGE:
- Title: {$pagePlan['title']}
- Slug: {$pagePlan['slug']}
- Purpose: {$pagePurpose}

RECOMMENDED BLOCKS FOR THIS PAGE TYPE ({$pageType}):
{$blockRecommendations}

{$featuresStr}

RESPOND WITH JSON:
{
  "meta_description": "SEO description for this page (150-160 chars)",
  "blocks": [
    {"type": "hero", "content": {"title": "...", "subtitle": "...", "button": "...", "url": "..."}},
    {"type": "features", "content": {"title": "...", "items": [{"icon": "star", "title": "...", "description": "..."}]}},
    {"type": "cta", "content": {"title": "...", "text": "...", "button": "...", "url": "/contact"}}
  ]
}

IMPORTANT: Only return the "meta_description" and "blocks" fields above. Do NOT return site_name, colors, pages array, nav, or footer - only the two fields shown above.

AVAILABLE BLOCK TYPES:
- hero: {title, subtitle, button, url}
- text: {content} (HTML)
- features: {title, subtitle, items[{icon, title, description}]}
- cards: {title, items[{icon, title, description, button, url}]}
- stats: {title, items[{value, label, icon}]}
- testimonials: {title, items[{quote, author, role, rating}]}
- pricing: {title, plans[{name, price, period, features[], button, url, featured}]}
- team: {title, members[{name, role, bio, photo}]}
- faq: {title, items[{question, answer}]}
- cta: {title, text, button, url}
- form: {title, fields[{type, label, required}], button}
- gallery: {title, images[{url, alt}], columns}
- quote: {text, author, role}
- steps: {title, items[{number, title, description}]}
- timeline: {title, items[{date, title, description}]}
- checklist: {title, items[]}
- contact_info: {items[{icon, label, value, url}]}
- newsletter: {title, text, button, placeholder}

GUIDELINES:
- Generate 4-8 blocks per page
- Start with hero for main pages
- Include real, detailed content - not placeholders
- Use specific numbers, names relevant to the business
- End with CTA or contact section
- For items arrays, include 3-5 realistic items
- For icons, use Material Symbols names (lowercase with underscores): star, check_circle, rocket_launch, shield, speed, lightbulb, favorite, trending_up, verified, bolt, groups, person, settings, support, payments, handshake, eco, security, insights, analytics, mail, phone, location_on, schedule

RESPOND ONLY WITH VALID JSON.
PROMPT;
    }
    
    /**
     * Get features relevant to a specific page type
     */
    private static function getRelevantFeatures(array $selectedFeatures, string $pageType): array {
        // Map features to the pages where they should appear
        $featurePageMap = [
            'contact_form' => ['contact', 'general'],
            'gallery' => ['portfolio', 'about', 'gallery', 'landing'],
            'testimonials' => ['landing', 'about', 'services', 'testimonials'],
            'faq' => ['faq', 'contact', 'pricing', 'services', 'landing'],
            'blog' => ['blog'],
            'newsletter' => ['landing', 'blog', 'contact', 'general'],
            'team' => ['about', 'team'],
            'pricing' => ['pricing', 'services'],
            'portfolio' => ['portfolio', 'landing']
        ];
        
        // Map feature keys to instructions
        $featureInstructions = [
            'contact_form' => '- CONTACT FORM: Add a "form" block with name, email, phone, and message fields',
            'gallery' => '- GALLERY: Add a "gallery" block showcasing images (use placeholder URLs like /gallery/1.jpg)',
            'testimonials' => '- TESTIMONIALS: Add a "testimonials" block with 3-4 customer reviews including quotes, names, roles, and ratings',
            'faq' => '- FAQ: Add a "faq" block with 5-6 relevant questions and detailed answers',
            'blog' => '- BLOG: Add a "cards" block featuring recent blog posts with titles and descriptions',
            'newsletter' => '- NEWSLETTER: Add a "newsletter" block for email signup with compelling copy',
            'team' => '- TEAM: Add a "team" block with 3-5 team members including names, roles, and bios',
            'pricing' => '- PRICING: Add a "pricing" block with 2-3 pricing tiers, features, and CTAs',
            'portfolio' => '- PORTFOLIO: Add a "gallery" or "cards" block showcasing projects/work'
        ];
        
        $relevant = [];
        foreach ($selectedFeatures as $feature) {
            $allowedPages = $featurePageMap[$feature] ?? [];
            if (in_array($pageType, $allowedPages)) {
                $relevant[] = $featureInstructions[$feature] ?? "- Include {$feature}";
            }
        }
        
        return $relevant;
    }
    
    /**
     * Infer page type from slug/title
     */
    private static function inferPageType(string $slug, string $title): string {
        $slug = strtolower($slug);
        $title = strtolower($title);
        
        return match(true) {
            str_contains($slug, 'home') || $slug === '' => 'landing',
            str_contains($slug, 'about') || str_contains($title, 'about') => 'about',
            str_contains($slug, 'service') || str_contains($title, 'service') => 'services',
            str_contains($slug, 'pricing') || str_contains($slug, 'price') => 'pricing',
            str_contains($slug, 'contact') => 'contact',
            str_contains($slug, 'team') => 'team',
            str_contains($slug, 'faq') || str_contains($title, 'faq') => 'faq',
            str_contains($slug, 'portfolio') || str_contains($slug, 'work') || str_contains($slug, 'project') => 'portfolio',
            str_contains($slug, 'blog') || str_contains($slug, 'news') => 'blog',
            str_contains($slug, 'testimonial') || str_contains($slug, 'review') => 'testimonials',
            default => 'general'
        };
    }
    
    /**
     * Get block recommendations for page type
     */
    private static function getBlockRecommendations(string $pageType): string {
        $recommendations = [
            'landing' => "hero (impactful headline), features (3-4 key benefits), stats (impressive numbers), testimonials (social proof), cta (call to action)",
            'about' => "hero (company intro), text (story/history), timeline (milestones), team (key people), stats (achievements), quote (founder message), cta",
            'services' => "hero (services overview), cards (service offerings), features (why choose us), steps (process), pricing (if applicable), testimonials, cta, form",
            'pricing' => "hero (pricing intro), pricing (plans table), features (what's included), faq (pricing questions), testimonials, cta",
            'contact' => "hero (get in touch), contact_info (phone/email/address), form (contact form), faq (quick answers)",
            'team' => "hero (meet the team), team (members with bios), text (culture/values), stats (team achievements), cta",
            'faq' => "hero (help center), faq (categorized Q&As), cta (still have questions?), contact_info",
            'portfolio' => "hero (our work), gallery (project images), cards (case studies), testimonials, stats, cta",
            'blog' => "hero (blog intro), cards (recent posts), newsletter (subscribe), cta",
            'testimonials' => "hero (what clients say), testimonials (detailed reviews), stats (satisfaction metrics), cta",
            'general' => "hero, text, features, cta"
        ];
        
        return $recommendations[$pageType] ?? $recommendations['general'];
    }
}

class BuildQueue {
    /**
     * Get all pending build queue items
     */
    public static function getPending(): array {
        return DB::fetchAll(
            "SELECT * FROM build_queue WHERE status = 'pending' ORDER BY created_at DESC"
        );
    }
    
    /**
     * Get a single build queue item
     */
    public static function get(int $id): ?array {
        return DB::fetch("SELECT * FROM build_queue WHERE id = ?", [$id]);
    }
    
    /**
     * Approve a build queue item
     */
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
    
    /**
     * Reject a build queue item
     */
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
    
    /**
     * Apply an approved build queue plan
     */
    public static function apply(int $id): bool {
        $queue = self::get($id);
        if (!$queue || $queue['status'] !== 'approved') {
            return false;
        }
        
        $plan = json_decode($queue['plan_json'], true);
        if (!$plan) {
            return false;
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
                    
                    if ($existing) {
                        // Update existing page
                        $pageId = $existing['id'];
                        DB::execute(
                            "UPDATE pages SET title = ?, meta_description = ?, status = 'published', updated_at = datetime('now') WHERE id = ?",
                            [$page['title'], $page['meta_description'] ?? '', $pageId]
                        );
                        // Clear existing blocks
                        DB::execute("DELETE FROM content_blocks WHERE page_id = ?", [$pageId]);
                    } else {
                        // Create new page
                        DB::execute(
                            "INSERT INTO pages (slug, title, meta_description, status, created_at) VALUES (?, ?, ?, 'published', datetime('now'))",
                            [$page['slug'], $page['title'], $page['meta_description'] ?? '']
                        );
                        $pageId = $db->lastInsertId();
                    }
                    
                    // Add blocks
                    if (isset($page['blocks']) && is_array($page['blocks'])) {
                        $blockOrder = 0;
                        foreach ($page['blocks'] as $block) {
                            DB::execute(
                                "INSERT INTO content_blocks (page_id, type, block_json, sort_order) VALUES (?, ?, ?, ?)",
                                [$pageId, $block['type'], json_encode($block['content'] ?? $block), $blockOrder++]
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
    /**
     * Render an admin template with layout
     */
    private static function renderAdmin(string $template, array $data = []): void {
        $user = Auth::user();
        $content = Template::render($template, $data);
        
        Response::html(Template::render('admin_layout', array_merge($data, [
            'content' => $content,
            'user_email' => $user['email'] ?? '',
            'user_role' => $user['role'] ?? '',
            'flash_error' => Session::getFlash('error'),
            'flash_success' => Session::getFlash('success'),
            'csp_nonce' => defined('CSP_NONCE') ? CSP_NONCE : '',
            'is_approvals' => true,
            'csrf_field' => '<input type="hidden" name="_csrf" value="' . CSRF::token() . '">'
        ])));
    }
    
    /**
     * Display the approvals queue
     */
    public static function queue(): void {
        Auth::require('*');
        
        $pending = BuildQueue::getPending();
        $approved = DB::fetchAll(
            "SELECT * FROM build_queue WHERE status = 'approved' ORDER BY approved_at DESC LIMIT 10"
        );
        $applied = DB::fetchAll(
            "SELECT * FROM build_queue WHERE status = 'applied' ORDER BY applied_at DESC LIMIT 10"
        );
        
        self::renderAdmin('admin_approvals', [
            'pending' => $pending,
            'approved' => $approved,
            'applied' => $applied,
            'has_pending' => count($pending) > 0,
            'has_approved' => count($approved) > 0,
            'has_applied' => count($applied) > 0
        ]);
    }
    
    /**
     * View a single approval item
     */
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
        
        self::renderAdmin('admin_approval_view', [
            'item' => $item,
            'plan' => $plan,
            'plan_json' => json_encode($plan, JSON_PRETTY_PRINT),
            'pages_count' => count($plan['pages'] ?? [])
        ]);
    }
    
    /**
     * Approve a build plan
     */
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
    
    /**
     * Apply an approved build plan
     */
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
    
    /**
     * Reject a build plan
     */
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
    
    /**
     * Preview with editable blocks
     */
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
        
        // Get all pages for link mapping dropdown
        $allPages = [];
        foreach ($plan['pages'] ?? [] as $page) {
            $allPages[] = ['slug' => '/' . ($page['slug'] === 'home' ? '' : $page['slug']), 'title' => $page['title']];
        }
        
        // Common links for CTA mapping
        $commonLinks = [
            ['url' => '/', 'label' => 'Home'],
            ['url' => '/contact', 'label' => 'Contact'],
            ['url' => '/about', 'label' => 'About'],
            ['url' => '/services', 'label' => 'Services'],
            ['url' => '/pricing', 'label' => 'Pricing'],
            ['url' => '#signup', 'label' => 'Sign Up Form'],
            ['url' => '#newsletter', 'label' => 'Newsletter'],
            ['url' => 'tel:', 'label' => 'Phone Number'],
            ['url' => 'mailto:', 'label' => 'Email']
        ];
        
        // Escape JSON for safe embedding in HTML script tag
        $planJson = json_encode($plan, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        
        Response::html(Template::render('admin_approval_preview', [
            'item' => $item,
            'plan' => $plan,
            'plan_json' => $planJson,
            'ui_theme' => $uiTheme,
            'all_pages' => $allPages,
            'common_links' => $commonLinks,
            'csrf_token' => CSRF::token()
        ]));
    }
    
    /**
     * Update a specific block in the build queue
     */
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
    
    /**
     * Generate individual team member pages
     */
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
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 15: EMAIL
// ─────────────────────────────────────────────────────────────────────────────

class Mailer {
    public static function send(string $to, string $subject, string $body): bool {
        $host = Settings::get('smtp_host');
        
        if (!$host) {
            // Fallback to PHP mail()
            $from = Settings::get('smtp_from', 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
            $headers = "From: $from\r\nContent-Type: text/html; charset=UTF-8";
            return @mail($to, $subject, $body, $headers);
        }
        
        // SMTP implementation
        return self::sendViaSMTP($to, $subject, $body);
    }
    
    private static function sendViaSMTP(string $to, string $subject, string $body): bool {
        $host = Settings::get('smtp_host');
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
            $message = "From: $from\r\n";
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
        $body = '<h2>New Contact Form Submission</h2>';
        $body .= '<p><strong>Name:</strong> ' . Sanitize::html($data['name']) . '</p>';
        $body .= '<p><strong>Email:</strong> ' . Sanitize::html($data['email']) . '</p>';
        $body .= '<p><strong>Message:</strong><br>' . nl2br(Sanitize::html($data['message'])) . '</p>';
        
        return self::send($to, $subject, $body);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 15: CONTACT FORM
// ─────────────────────────────────────────────────────────────────────────────

class FormController {
    public static function contact(): void {
        CSRF::require();
        
        if (!RateLimit::check('contact_form', 5, 3600)) {
            if (Request::isAjax()) {
                Response::json(['error' => 'Too many submissions. Please wait.'], 429);
            }
            Session::flash('error', 'Too many submissions. Please wait an hour.');
            Response::redirect($_SERVER['HTTP_REFERER'] ?? '/');
        }
        
        $name = trim(Request::input('name', ''));
        $email = filter_var(Request::input('email', ''), FILTER_VALIDATE_EMAIL);
        $message = trim(Request::input('message', ''));
        
        if (!$name || !$email || !$message) {
            if (Request::isAjax()) {
                Response::json(['error' => 'All fields are required.'], 400);
            }
            Session::flash('error', 'All fields are required.');
            Response::redirect($_SERVER['HTTP_REFERER'] ?? '/');
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
        
        Response::redirect($_SERVER['HTTP_REFERER'] ?? '/');
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 16: ROUTE DEFINITIONS
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

// Approvals Queue
Router::get('/admin/approvals', 'ApprovalController::queue');
Router::get('/admin/approvals/{id}', function ($id) { ApprovalController::view((int)$id); });
Router::get('/admin/approvals/{id}/preview', function ($id) { ApprovalController::preview((int)$id); });
Router::post('/admin/approvals/{id}/approve', function ($id) { ApprovalController::approve((int)$id); });
Router::post('/admin/approvals/{id}/apply', function ($id) { ApprovalController::apply((int)$id); });
Router::post('/admin/approvals/{id}/reject', function ($id) { ApprovalController::reject((int)$id); });
Router::post('/admin/approvals/{id}/update-block', function ($id) { ApprovalController::updateBlock((int)$id); });
Router::post('/admin/approvals/{id}/generate-team-pages', function ($id) { ApprovalController::generateTeamPages((int)$id); });

// ─── ASSET ROUTES ────────────────────────────────────────────────────────────
Router::get('/assets/{hash}', 'AssetController::serve');
Router::get('/assets/css/{hash}', 'AssetController::serveCSS');
Router::get('/css', function () {
    $hash = Cache::getCSSHash();
    AssetController::serveCSS($hash);
});

// ─── CDN CACHE ROUTES ────────────────────────────────────────────────────────
Router::get('/cdn/fonts/{filename}', function ($filename) { 
    $filePath = ONECMS_CACHE . '/assets/cdn/fonts/' . basename($filename);
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
Router::get('/css/editor', 'EditorAssets::css');
Router::get('/js/editor', 'EditorAssets::js');
Router::get('/js/reveal', 'EditorAssets::revealJs');
Router::get('/js/theme', 'EditorAssets::themeJs');

// ─── BLOCK EDITING API ───────────────────────────────────────────────────────
Router::post('/api/blocks/{id}/update', function ($id) { BlockAPI::update((int)$id); });
Router::post('/api/blocks/{id}/move', function ($id) { BlockAPI::move((int)$id); });
Router::post('/api/blocks/{id}/delete', function ($id) { BlockAPI::delete((int)$id); });
Router::post('/api/blocks/create', 'BlockAPI::create');

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

// ─── CONTACT FORM ────────────────────────────────────────────────────────────
Router::post('/contact', 'FormController::contact');

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
            'meta_description' => 'Welcome to OneCMS',
            'blocks_html' => '<div class="block block-hero"><h2>Welcome to OneCMS</h2><p>Your AI-powered website is ready. <a href="/admin">Go to Admin</a> to start building.</p></div>'
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

Router::dispatch();
