<?php
// Núcleo compartido: configuración, conexión MySQL, sesión, seguridad y utilidades.

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('CONFIG_FILE', __DIR__ . '/config.php');
define('UPLOAD_DIR', APP_ROOT . '/uploads');
define('DEFAULT_ADMIN_PASSWORD', 'admin123');

mb_internal_encoding('UTF-8');
date_default_timezone_set('America/New_York');

function app_config(): ?array
{
    static $cfg = false;
    if ($cfg === false) {
        $cfg = is_file(CONFIG_FILE) ? require CONFIG_FILE : null;
    }
    return is_array($cfg) ? $cfg : null;
}

function require_installed(string $installUrl): void
{
    if (app_config() === null) {
        header('Location: ' . $installUrl);
        exit;
    }
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = app_config();
        if ($c === null) {
            throw new RuntimeException('Sitio no instalado.');
        }
        $pdo = db_connect($c['db_host'], $c['db_name'], $c['db_user'], $c['db_pass']);
    }
    return $pdo;
}

function db_connect(string $host, string $name, string $user, string $pass): PDO
{
    $dsn = 'mysql:host=' . $host . ';dbname=' . $name . ';charset=utf8mb4';
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

/* ---------- Sesión ---------- */

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_name('vqb_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function is_admin(): bool
{
    start_session();
    return !empty($_SESSION['admin']);
}

function require_admin(bool $json = false): void
{
    if (is_admin()) {
        return;
    }
    if ($json) {
        json_out(['ok' => false, 'error' => 'Sesión expirada. Vuelve a ingresar.'], 401);
    }
    header('Location: ../index.php?login=1');
    exit;
}

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function check_csrf(?string $token): bool
{
    start_session();
    return is_string($token) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

/* ---------- Utilidades ---------- */

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function json_out(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function settings_all(): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT k, v FROM settings') as $row) {
            $cache[$row['k']] = $row['v'];
        }
    }
    return $cache;
}

function setting(string $k, string $default = ''): string
{
    $all = settings_all();
    return isset($all[$k]) && $all[$k] !== '' ? $all[$k] : $default;
}

function setting_save(string $k, string $v): void
{
    $st = db()->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)');
    $st->execute([$k, $v]);
}

/** Ruta de un archivo con su fecha de modificación: obliga al navegador y a la caché a bajar la versión nueva. */
function asset(string $path): string
{
    $file = APP_ROOT . '/' . ltrim($path, '/');
    return $path . (is_file($file) ? '?v=' . filemtime($file) : '');
}

/** Dirección pública del sitio (https://dominio/carpeta/). */
function base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = preg_replace('/[^A-Za-z0-9.:-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https' : 'http') . '://' . $host . $dir . '/';
}

function digits(string $s): string
{
    return preg_replace('/\D+/', '', $s) ?? '';
}

/** Enlace de WhatsApp con el número configurado (se asume número de EE. UU. si tiene 10 dígitos). */
function wa_link(string $text = ''): string
{
    $n = digits(setting('whatsapp', '917-238-8863'));
    if (strlen($n) === 10) {
        $n = '1' . $n;
    }
    $url = 'https://wa.me/' . $n;
    return $text !== '' ? $url . '?text=' . rawurlencode($text) : $url;
}

/** Solo permite URLs seguras o rutas relativas del propio sitio. */
function safe_url(?string $url): string
{
    $url = trim((string) $url);
    if ($url === '') {
        return '';
    }
    if (preg_match('~^(https?:|mailto:|tel:|#)~i', $url)) {
        return $url;
    }
    if (preg_match('~^(assets|uploads)/[A-Za-z0-9._/-]+$~', $url)) {
        return $url;
    }
    return '';
}

/**
 * Limpia el HTML que llega del editor (TipTap): deja solo etiquetas de formato,
 * enlaces seguros, color de texto y alineación.
 */
function sanitize_html(?string $html): string
{
    $html = trim((string) $html);
    if ($html === '') {
        return '';
    }
    $allowed = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'h2', 'h3', 'ul', 'ol', 'li', 'blockquote', 'a', 'span', 'mark'];
    $doc = new DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    $root = $doc->getElementById('root');
    if (!$root) {
        return '';
    }
    $clean = function (DOMNode $node) use (&$clean, $allowed, $doc): void {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMText) {
                continue;
            }
            if (!$child instanceof DOMElement) {
                $node->removeChild($child);
                continue;
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'svg'], true)) {
                $node->removeChild($child);
                continue;
            }
            if (!in_array($tag, $allowed, true)) {
                $clean($child);
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }
            $href = $tag === 'a' ? safe_url($child->getAttribute('href')) : '';
            $style = $child->getAttribute('style');
            $keep = [];
            if (preg_match('/(?:^|;)\s*color:\s*(#[0-9a-f]{3,8}|rgb\([\d\s,.%]+\))/i', $style, $m)) {
                $keep[] = 'color: ' . $m[1];
            }
            if (preg_match('/text-align:\s*(left|center|right|justify)/i', $style, $m)) {
                $keep[] = 'text-align: ' . strtolower($m[1]);
            }
            foreach (iterator_to_array($child->attributes) as $attr) {
                $child->removeAttribute($attr->name);
            }
            if ($href !== '') {
                $child->setAttribute('href', $href);
                if (preg_match('~^https?:~i', $href)) {
                    $child->setAttribute('target', '_blank');
                    $child->setAttribute('rel', 'noopener');
                }
            }
            if ($keep) {
                $child->setAttribute('style', implode('; ', $keep));
            }
            $clean($child);
        }
    };
    $clean($root);
    $out = '';
    foreach ($root->childNodes as $c) {
        $out .= $doc->saveHTML($c);
    }
    return $out;
}

function plain_text(?string $html): string
{
    return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['</p>', '<br>'], ' ', (string) $html)), ENT_QUOTES, 'UTF-8')) ?? '');
}
