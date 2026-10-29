<?php
declare(strict_types=1);

/* ---------- Output ---------- */

function e($v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base_path(): string
{
    static $base = null;
    if ($base === null) {
        $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
        $base = rtrim($dir, '/');
    }
    return $base;
}

function url(string $path = '/'): string
{
    if (preg_match('#^(https?:)?//#', $path)) return $path;
    return base_path() . '/' . ltrim($path, '/');
}

function abs_url(string $path = '/'): string
{
    if (preg_match('#^https?://#', $path)) return $path;
    $domain = rtrim((string)setting('site_url', ''), '/');
    if ($domain === '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
        $domain = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . base_path();
    }
    return $domain . '/' . ltrim($path, '/');
}

/** URL of an uploaded file (stored as relative path like uploads/2026/10/x.jpg). */
function media_url(?string $path): string
{
    if (!$path) return '';
    if (preg_match('#^(https?:)?//#', $path)) return $path;
    return url($path);
}

function redirect(string $to, int $code = 302): never
{
    header('Location: ' . (preg_match('#^https?://#', $to) ? $to : url($to)), true, $code);
    exit;
}

function json_out($data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function view(string $name, array $vars = []): void
{
    extract($vars, EXTR_SKIP);
    require APP . '/views/' . $name . '.php';
}

function render(string $name, array $vars = []): string
{
    ob_start();
    view($name, $vars);
    return (string)ob_get_clean();
}

/* ---------- Requests ---------- */

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function input(string $key, $default = '')
{
    return $_POST[$key] ?? $_GET[$key] ?? $default;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function check_csrf(): void
{
    $sent = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        exit('La sesión expiró. Vuelve a cargar la página e inténtalo de nuevo. / Session expired, please reload.');
    }
}

function flash(?string $msg = null, string $type = 'ok')
{
    if ($msg === null) {
        $f = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        return $f;
    }
    $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
    return null;
}

function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

/* ---------- Data helpers ---------- */

function jdec(?string $json, $default = [])
{
    if ($json === null || $json === '') return $default;
    $v = json_decode($json, true);
    return $v === null ? $default : $v;
}

function jenc($v): string
{
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** Short random id for page blocks. */
function bid(): string
{
    return 'b' . bin2hex(random_bytes(4));
}

function slugify(string $s): string
{
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
    $s = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $s));
    return trim($s, '-') ?: 'item';
}

function lines(?string $text): array
{
    return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)$text)), 'strlen'));
}

function money($amount): string
{
    $symbol = setting('currency', 'USD') === 'MXN' ? 'MX$' : '$';
    return $symbol . number_format((float)$amount, 2);
}

function discount_pct($price, $compare): int
{
    $price = (float)$price;
    $compare = (float)$compare;
    if ($compare <= 0 || $compare <= $price) return 0;
    return (int)round(100 - ($price / $compare) * 100);
}

/** Phone digits for wa.me links (adds US +1 when 10 digits). */
function wa_number(): string
{
    $d = preg_replace('/\D+/', '', (string)setting('whatsapp_number', '+1 6562238763'));
    if (strlen($d) === 10) $d = '1' . $d;
    return $d;
}

function wa_link(string $text = ''): string
{
    $t = $text !== '' ? $text : (string)setting('whatsapp_message_' . lang(), setting('whatsapp_message_en', ''));
    return 'https://wa.me/' . wa_number() . ($t !== '' ? '?text=' . rawurlencode($t) : '');
}

function wa_display(): string
{
    $d = wa_number();
    if (strlen($d) === 11 && $d[0] === '1') {
        return '+1 (' . substr($d, 1, 3) . ') ' . substr($d, 4, 3) . '-' . substr($d, 7);
    }
    return '+' . $d;
}

/** Keep only a safe subset of HTML for rich descriptions written in the admin. */
function safe_html(?string $html): string
{
    $html = (string)$html;
    $html = strip_tags($html, '<p><br><strong><b><em><i><u><ul><ol><li><a><h3><h4><blockquote><span>');
    // strip event handlers / javascript: urls / styles
    $html = preg_replace('/\s(on\w+|style)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
    $html = preg_replace('/href\s*=\s*("|\')\s*javascript:[^"\']*("|\')/i', 'href="#"', $html);
    return $html;
}

/** YouTube/Vimeo URL -> embeddable URL, or '' if not recognised. */
function embed_url(string $u): string
{
    if (preg_match('~(?:youtube\.com/(?:watch\?v=|shorts/|embed/)|youtu\.be/)([A-Za-z0-9_-]{6,})~', $u, $m)) {
        return 'https://www.youtube-nocookie.com/embed/' . $m[1];
    }
    if (preg_match('~vimeo\.com/(\d+)~', $u, $m)) {
        return 'https://player.vimeo.com/video/' . $m[1];
    }
    return '';
}

function youtube_thumb(string $u): string
{
    if (preg_match('~(?:youtube\.com/(?:watch\?v=|shorts/|embed/)|youtu\.be/)([A-Za-z0-9_-]{6,})~', $u, $m)) {
        return 'https://img.youtube.com/vi/' . $m[1] . '/hqdefault.jpg';
    }
    return '';
}

/* ---------- Icons (inline stroke SVG) ---------- */

function icon(string $name, int $size = 20, string $extra = ''): string
{
    static $p = [
        'search' => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>',
        'heart' => '<path d="M12 20s-7-4.4-8.8-8.8C2 8 4 5 7.2 5c1.9 0 3.4 1 4.8 2.7C13.4 6 14.9 5 16.8 5 20 5 22 8 20.8 11.2 19 15.6 12 20 12 20z"/>',
        'bag' => '<path d="M5 8h14l-1.2 11.2a1 1 0 0 1-1 .8H7.2a1 1 0 0 1-1-.8L5 8z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/>',
        'whatsapp' => '<path d="M4 20l1.3-3.9A8 8 0 1 1 8 18.7L4 20z"/><path d="M9 9.5c0 3 2.5 5.5 5.5 5.5l1-1.5-2-1-1 .8a4 4 0 0 1-1.8-1.8l.8-1-1-2L9 9.5z"/>',
        'instagram' => '<rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r=".8" fill="currentColor"/>',
        'facebook' => '<path d="M15 3h-2.5A3.5 3.5 0 0 0 9 6.5V10H6.5v3.5H9V21h3.5v-7.5h2.7l.5-3.5h-3.2V7a1 1 0 0 1 1-1H15V3z"/>',
        'tiktok' => '<path d="M14 3v11.5a3.5 3.5 0 1 1-3.5-3.5"/><path d="M14 3c.5 2.8 2.4 4.5 5 4.7"/>',
        'pinterest' => '<circle cx="12" cy="12" r="9"/><path d="M11 8.5c3-1 5 1 4 3.5s-3.5 2-3.5 0M11 12l-2 8"/>',
        'youtube' => '<rect x="2" y="5" width="20" height="14" rx="4"/><path d="M10 9l5 3-5 3z"/>',
        'check' => '<path d="M5 12l4 4 10-10"/>',
        'lock' => '<rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
        'shield' => '<path d="M12 3l7 4v5c0 4.5-3 8-7 9-4-1-7-4.5-7-9V7l7-4z"/>',
        'gift' => '<path d="M4 10h16v10H4zM3 7h18v3H3zM12 7v13M12 7c-1.5-3-5-3-5-1s3 1 5 1zm0 0c1.5-3 5-3 5-1s-3 1-5 1z"/>',
        'truck' => '<path d="M3 7h11v9H3zM14 10h4l3 3v3h-7"/><circle cx="7" cy="17.5" r="1.5"/><circle cx="17" cy="17.5" r="1.5"/>',
        'return' => '<path d="M4 12a8 8 0 0 1 14-5l2 2M20 12a8 8 0 0 1-14 5l-2-2M20 4v5h-5M4 20v-5h5"/>',
        'arrow' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'play' => '<path d="M8 5v14l11-7z" fill="currentColor"/>',
        'zoom' => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5M11 8v6M8 11h6"/>',
        'close' => '<path d="M6 6l12 12M18 6L6 18"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3 3 15 0 18M12 3c-3 3-3 15 0 18"/>',
        'mail' => '<path d="M3 5h18v14H3zM3 7l9 6 9-6"/>',
        'star' => '<path d="M12 3l2.8 5.7 6.2.9-4.5 4.4 1 6.2L12 17.3 6.5 20.2l1-6.2L3 9.6l6.2-.9z" fill="currentColor"/>',
        'trash' => '<path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/>',
        'edit' => '<path d="M4 20h4L19 9l-4-4L4 16v4z"/>',
        'copy' => '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M4 16V4h12"/>',
        'eye' => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'eyeoff' => '<path d="M3 3l18 18M10.6 6.1A10 10 0 0 1 12 6c6.5 0 10 6 10 6a17 17 0 0 1-3 3.6M6.6 6.6C3.8 8.4 2 12 2 12s3.5 7 10 7a9.8 9.8 0 0 0 4.4-1"/>',
        'grip' => '<circle cx="9" cy="6" r="1" fill="currentColor"/><circle cx="15" cy="6" r="1" fill="currentColor"/><circle cx="9" cy="12" r="1" fill="currentColor"/><circle cx="15" cy="12" r="1" fill="currentColor"/><circle cx="9" cy="18" r="1" fill="currentColor"/><circle cx="15" cy="18" r="1" fill="currentColor"/>',
        'upload' => '<path d="M12 16V4M7 9l5-5 5 5M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/>',
        'image' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 15l3-3 3 3 3-4 3 4"/>',
        'video' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M10 9l5 3-5 3z"/>',
        'dashboard' => '<path d="M4 13h6V4H4zM14 20h6v-9h-6zM4 20h6v-3H4zM14 4v3h6V4z"/>',
        'pages' => '<path d="M4 20h4L19 9l-4-4L4 16v4zM13 7l4 4"/>',
        'orders' => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2zM9 8h6M9 12h6"/>',
        'tag' => '<path d="M20 12l-8 8-9-9V3h8l9 9z"/><circle cx="7.5" cy="7.5" r="1"/>',
        'seo' => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4M8 11h6M11 8v6"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M5 19l2-2M17 7l2-2"/>',
        'folder' => '<path d="M3 6h6l2 2h10v11H3z"/>',
        'logout' => '<path d="M15 4h4v16h-4M10 8l-4 4 4 4M6 12h10"/>',
        'external' => '<path d="M14 4h6v6M20 4l-9 9M18 14v6H4V6h6"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'minus' => '<path d="M5 12h14"/>',
        'up' => '<path d="M12 19V5M6 11l6-6 6 6"/>',
        'down' => '<path d="M12 5v14M6 13l6 6 6-6"/>',
        'desktop' => '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>',
        'mobile' => '<rect x="7" y="3" width="10" height="18" rx="2"/>',
        'tablet' => '<rect x="5" y="3" width="14" height="18" rx="2"/>',
        'cats' => '<path d="M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z"/>',
        'text' => '<path d="M4 5h16M4 10h16M4 15h10M4 20h7"/>',
        'quote' => '<path d="M4 5h16v11H8l-4 4z"/>',
        'help' => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.7M12 17h.01"/>',
        'form' => '<path d="M4 5h16v14H4zM8 9h8M8 13h5"/>',
        'spacer' => '<path d="M4 12h16M12 4v4M12 16v4"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'bar' => '<path d="M3 7h18v10H3zM7 12h10"/>',
        'hero' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 15l3-3 3 3 3-4 3 4"/>',
        'database' => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>',
    ];
    $inner = $p[$name] ?? '';
    return '<svg class="ic ' . e($extra) . '" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $inner . '</svg>';
}
