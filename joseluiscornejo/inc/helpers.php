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

function url(string $path = ''): string {
    if (preg_match('#^https?://#', $path)) return $path;
    return base_path() . '/' . ltrim($path, '/');
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
