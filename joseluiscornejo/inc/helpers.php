<?php
require_once __DIR__ . '/db.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function settings_all(bool $refresh = false): array {
    static $cache = null;
    if ($cache === null || $refresh) {
        $cache = [];
        foreach (db()->query('SELECT k, v FROM settings') as $r) $cache[$r['k']] = $r['v'];
    }
    return $cache;
}

function s(string $k, string $default = ''): string {
    $all = settings_all();
    return isset($all[$k]) && $all[$k] !== null ? (string)$all[$k] : $default;
}

function set_setting(string $k, ?string $v): void {
    db()->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')->execute([$k, $v]);
    settings_all(true);
}

// Ruta base del sitio (funciona en la raíz del dominio o en una subcarpeta).
function base_path(): string {
    static $b = null;
    if ($b === null) {
        $root = str_replace('\\', '/', realpath(__DIR__ . '/..'));
        $doc = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '');
        $b = ($doc && str_starts_with($root, $doc)) ? substr($root, strlen($doc)) : '';
        $b = rtrim($b, '/');
    }
    return $b;
}

// ¿El servidor está aplicando las reglas del .htaccess? (las reglas marcan JLC_REWRITE)
function pretty_urls(): bool {
    return getenv('JLC_REWRITE') === '1' || getenv('REDIRECT_JLC_REWRITE') === '1'
        || ($_SERVER['JLC_REWRITE'] ?? '') === '1' || ($_SERVER['REDIRECT_JLC_REWRITE'] ?? '') === '1';
}

function url(string $path = ''): string {
    if (preg_match('#^https?://#', $path)) return $path;
    $path = ltrim($path, '/');
    $first = explode('/', explode('?', explode('#', $path)[0])[0])[0];
    $isFile = $path === '' || $path[0] === '#' || $path[0] === '?' || str_contains($first, '.') || in_array($first, ['assets', 'uploads', 'admin'], true);
    if ($isFile || pretty_urls()) return base_path() . '/' . $path;
    // Sin .htaccess: index.php?r=ruta&consulta#ancla
    $frag = '';
    if (($p = strpos($path, '#')) !== false) { $frag = substr($path, $p); $path = substr($path, 0, $p); }
    $query = '';
    if (($p = strpos($path, '?')) !== false) { $query = '&' . substr($path, $p + 1); $path = substr($path, 0, $p); }
    return base_path() . '/index.php?r=' . $path . $query . $frag;
}

function abs_url(string $path = ''): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . url($path);
}

function asset(string $path): string {
    if ($path === '') return '';
    return url($path);
}

function redirect(string $to): void {
    header('Location: ' . (preg_match('#^https?://#', $to) ? $to : url($to)));
    exit;
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(400);
        exit('La sesión expiró. Vuelve atrás y recarga la página.');
    }
}

function flash(?string $msg = null, string $type = 'ok') {
    if ($msg !== null) { $_SESSION['flash'] = ['m' => $msg, 't' => $type]; return null; }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function money($n): string {
    return '$' . number_format((float)$n, 2) . ' ' . s('currency', 'USD');
}

function slugify(string $t): string {
    $t = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t) ?: $t;
    $t = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $t));
    return trim($t, '-') ?: 'item-' . time();
}

function youtube_id(string $url): string {
    if (preg_match('~(?:youtu\.be/|v=|embed/|shorts/)([A-Za-z0-9_-]{11})~', $url, $m)) return $m[1];
    return '';
}

// ---------- Vista previa en vivo del editor ----------
function is_preview(): bool {
    return !empty($_GET['preview']) && !empty($_SESSION['admin']);
}

// Texto editable: en vista previa se envuelve para poder actualizarlo en vivo.
function v(string $k): string {
    $h = e(s($k));
    return is_preview() ? '<span data-edit="' . e($k) . '">' . $h . '</span>' : $h;
}

// HTML enriquecido editable.
function vr(string $k): string {
    return is_preview() ? '<div data-edit="' . e($k) . '" data-mode="html">' . s($k) . '</div>' : s($k);
}

// Atributos para imágenes u otros elementos editables.
function ea(string $k, string $mode = 'src'): string {
    return is_preview() ? ' data-edit="' . e($k) . '" data-mode="' . e($mode) . '"' : '';
}

function is_short(string $url): bool {
    return str_contains($url, '/shorts/');
}

// Título del video desde YouTube (oEmbed). Devuelve '' si no se puede obtener.
function youtube_title(string $url): string {
    $id = youtube_id($url);
    if ($id === '' || !function_exists('curl_init')) return '';
    $ch = curl_init('https://www.youtube.com/oembed?format=json&url=' . rawurlencode('https://www.youtube.com/watch?v=' . $id));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4]);
    $res = curl_exec($ch);
    curl_close($ch);
    $data = $res ? json_decode($res, true) : null;
    return trim((string)($data['title'] ?? ''));
}

// Redes sociales configuradas en el panel (solo las que tienen enlace).
function social_links(): array {
    $icons = [
        'facebook' => '<path d="M14 8h3V4h-3a4 4 0 0 0-4 4v3H7v4h3v7h4v-7h3l1-4h-4V8z"/>',
        'instagram' => '<rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="0.8" fill="currentColor"/>',
        'youtube' => '<rect x="2.5" y="5.5" width="19" height="13" rx="4"/><path d="M10 9.2v5.6l4.8-2.8z" fill="currentColor"/>',
        'amazon' => '<path d="M15 10.5c0-3-1.4-4.5-4-4.5-2 0-3.3 1-3.8 2.6"/><path d="M15 10.5V16"/><path d="M15 11.5c-4.5 0-7 1-7 3.2 0 1.6 1.2 2.3 2.6 2.3 2.4 0 4.4-1.8 4.4-4.5"/><path d="M4 18.5c4.5 2.6 11 2.6 16 0"/><path d="M18 17l2.2 1.5-1 2.4"/>',
    ];
    $names = ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'youtube' => 'YouTube', 'amazon' => 'Amazon'];
    $out = [];
    foreach ($icons as $k => $svg) {
        $u = s($k . '_url');
        if ($u !== '') $out[] = ['key' => $k, 'name' => $names[$k], 'url' => $u, 'svg' => $svg];
    }
    return $out;
}

function social_icons(string $class = ''): string {
    $h = '';
    foreach (social_links() as $l) {
        $h .= '<a class="soc soc-' . e($l['key']) . '" href="' . e($l['url']) . '" target="_blank" rel="noopener" aria-label="' . e($l['name']) . '">'
            . '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $l['svg'] . '</svg></a>';
    }
    return $h ? '<div class="socials ' . e($class) . '">' . $h . '</div>' : '';
}

function whatsapp_link(string $text = ''): string {
    $n = preg_replace('/\D/', '', s('whatsapp'));
    return 'https://wa.me/' . $n . ($text ? '?text=' . rawurlencode($text) : '');
}

// Subida de imágenes del panel. Devuelve la ruta relativa o null.
function handle_upload(string $field): ?string {
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Error al subir el archivo.');
    if ($f['size'] > 8 * 1024 * 1024) throw new RuntimeException('La imagen pesa más de 8 MB.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$mime] ?? null;
    if (!$ext) throw new RuntimeException('Solo se permiten imágenes JPG, PNG, WEBP o GIF.');
    $dir = __DIR__ . '/../uploads';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = date('Ymd') . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) throw new RuntimeException('No se pudo guardar la imagen.');
    return 'uploads/' . $name;
}

// ---------- Carrito ----------
function cart(): array { return $_SESSION['cart'] ?? []; }

function cart_count(): int { return array_sum(array_column(cart(), 'qty')); }

function cart_add(int $pid, string $edition, int $qty = 1): void {
    $edition = $edition === 'firmado' ? 'firmado' : 'estandar';
    $key = $pid . ':' . $edition;
    $c = cart();
    $c[$key] = ['pid' => $pid, 'edition' => $edition, 'qty' => min(50, ($c[$key]['qty'] ?? 0) + max(1, $qty))];
    $_SESSION['cart'] = $c;
}

function cart_set(string $key, int $qty): void {
    $c = cart();
    if (!isset($c[$key])) return;
    if ($qty <= 0) unset($c[$key]); else $c[$key]['qty'] = min(50, $qty);
    $_SESSION['cart'] = $c;
}

// Líneas del carrito con precios tomados SIEMPRE de la base de datos.
function cart_lines(): array {
    $lines = [];
    $st = db()->prepare("SELECT * FROM products WHERE id = ? AND status = 'disponible'");
    foreach (cart() as $key => $it) {
        $st->execute([$it['pid']]);
        $p = $st->fetch();
        if (!$p) continue;
        $signed = $it['edition'] === 'firmado' && $p['price_signed'] !== null;
        $price = $signed ? (float)$p['price_signed'] : (float)$p['price'];
        $lines[] = [
            'key' => $key, 'pid' => (int)$p['id'], 'title' => $p['title'], 'image' => $p['image'],
            'edition' => $signed ? 'Firmado y dedicado' : 'Estándar',
            'price' => $price, 'qty' => (int)$it['qty'], 'total' => $price * (int)$it['qty'],
        ];
    }
    return $lines;
}

function cart_totals(array $lines): array {
    $sub = array_sum(array_column($lines, 'total'));
    $ship = $lines ? (float)s('shipping', '0') : 0.0;
    return ['subtotal' => round($sub, 2), 'shipping' => round($ship, 2), 'total' => round($sub + $ship, 2)];
}
