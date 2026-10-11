<?php
// API JSON del panel. Toda escritura exige sesión de admin y token CSRF.

declare(strict_types=1);

require dirname(__DIR__) . '/inc/bootstrap.php';
require_installed('../install.php');
require_admin(true);

const TABLES = [
    'sections' => ['cols' => ['name', 'visible', 'eyebrow', 'title', 'body', 'cta', 'link', 'bg', 'image'], 'html' => ['body']],
    'dresses' => ['cols' => ['name', 'price', 'category', 'tag', 'image', 'featured', 'visible'], 'html' => []],
    'accessories' => ['cols' => ['name', 'image', 'visible'], 'html' => []],
    'faqs' => ['cols' => ['question', 'answer', 'visible'], 'html' => ['answer']],
];
const ENUMS = [
    'link' => ['wa', 'coleccion', 'accesorios', 'visitanos', 'salon', 'web'],
    'bg' => ['claro', 'rosa', 'fucsia', 'ciruela'],
    'category' => ['rosa', 'azul', 'verde', 'lila', 'charro', 'rojo', 'marfil', 'negro'],
];
const SETTING_KEYS = [
    'site_title', 'tagline', 'whatsapp', 'phone2', 'wa_message', 'address', 'maps_url', 'facebook_url',
    'topbar', 'float_button', 'float_label', 'logo', 'logo_white', 'hero_inset', 'hero_badge', 'salon_web', 'salon_capacity',
];
const URL_SETTINGS = ['maps_url', 'facebook_url', 'logo', 'logo_white', 'hero_inset', 'salon_web'];

function fail(string $msg, int $code = 400): void
{
    json_out(['ok' => false, 'error' => $msg], $code);
}

function clean_field(string $table, string $col, $val)
{
    if (in_array($col, ['visible', 'featured'], true)) {
        return $val ? 1 : 0;
    }
    $val = (string) $val;
    if (in_array($col, TABLES[$table]['html'], true)) {
        return sanitize_html($val);
    }
    if ($col === 'image') {
        return safe_url($val);
    }
    if (isset(ENUMS[$col])) {
        return in_array($val, ENUMS[$col], true) ? $val : ENUMS[$col][0];
    }
    return mb_substr(trim($val), 0, $col === 'title' || $col === 'question' ? 255 : 160);
}

function load_all(): array
{
    $pdo = db();
    $q = fn(string $sql) => $pdo->query($sql)->fetchAll();
    $settings = settings_all();
    unset($settings['admin_pass_hash']);
    return [
        'sections' => $q('SELECT * FROM sections ORDER BY position, id'),
        'dresses' => $q('SELECT * FROM dresses ORDER BY position, id'),
        'accessories' => $q('SELECT * FROM accessories ORDER BY position, id'),
        'faqs' => $q('SELECT * FROM faqs ORDER BY position, id'),
        'media' => $q('SELECT * FROM media ORDER BY id DESC'),
        'settings' => $settings,
    ];
}

/** Guarda una imagen subida, la reduce si es muy grande y la registra en la biblioteca. */
function handle_upload(): array
{
    if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
        fail('No llegó ningún archivo.');
    }
    $f = $_FILES['file'];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        fail($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE ? 'La imagen es demasiado grande.' : 'Error al subir la imagen.');
    }
    if ($f['size'] > 10 * 1024 * 1024) {
        fail('La imagen supera 10 MB.');
    }
    $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $info = @getimagesize($f['tmp_name']);
    if (!isset($types[$mime]) || $info === false) {
        fail('Formato no permitido. Usa JPG, PNG, WEBP o GIF.');
    }
    if (!is_dir(UPLOAD_DIR) && !@mkdir(UPLOAD_DIR, 0755, true)) {
        fail('No existe la carpeta uploads/ en el servidor.', 500);
    }
    $ext = $types[$mime];
    $name = date('Ymd') . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
    $dest = UPLOAD_DIR . '/' . $name;

    $resized = false;
    $max = 1800;
    if (function_exists('imagecreatefromstring') && $ext !== 'gif' && max($info[0], $info[1]) > $max) {
        $src = @imagecreatefromstring((string) file_get_contents($f['tmp_name']));
        if ($src) {
            $ratio = $max / max($info[0], $info[1]);
            $w = (int) round($info[0] * $ratio);
            $h = (int) round($info[1] * $ratio);
            $dst = imagecreatetruecolor($w, $h);
            if ($ext !== 'jpg') {
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
            }
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, $info[0], $info[1]);
            $resized = $ext === 'jpg' ? imagejpeg($dst, $dest, 84) : ($ext === 'png' ? imagepng($dst, $dest, 7) : imagewebp($dst, $dest, 84));
            imagedestroy($src);
            imagedestroy($dst);
        }
    }
    if (!$resized && !move_uploaded_file($f['tmp_name'], $dest)) {
        fail('No se pudo guardar la imagen en el servidor.', 500);
    }
    @chmod($dest, 0644);

    $label = mb_substr(pathinfo((string) $f['name'], PATHINFO_FILENAME), 0, 120) ?: 'Imagen';
    $url = 'uploads/' . $name;
    db()->prepare('INSERT INTO media (name, url) VALUES (?, ?)')->execute([$label, $url]);
    return ['id' => (int) db()->lastInsertId(), 'name' => $label, 'url' => $url];
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    json_out(['ok' => true, 'data' => load_all()]);
}
if ($method !== 'POST') {
    fail('Método no permitido.', 405);
}

// Subida de imágenes (multipart)
if (!empty($_FILES)) {
    if (!check_csrf($_POST['csrf'] ?? null)) {
        fail('Token de seguridad inválido. Recarga la página.', 403);
    }
    json_out(['ok' => true, 'media' => handle_upload()]);
}

$in = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in)) {
    fail('Solicitud inválida.');
}
if (!check_csrf($_SERVER['HTTP_X_CSRF'] ?? null)) {
    fail('Token de seguridad inválido. Recarga la página.', 403);
}

$pdo = db();
$action = (string) ($in['action'] ?? '');
$table = (string) ($in['table'] ?? '');
if (in_array($action, ['update', 'create', 'delete', 'reorder'], true) && !isset(TABLES[$table])) {
    fail('Tabla desconocida.');
}

try {
    switch ($action) {
        case 'update':
            $fields = is_array($in['fields'] ?? null) ? $in['fields'] : [];
            $sets = [];
            $vals = [];
            foreach ($fields as $col => $val) {
                if (in_array($col, TABLES[$table]['cols'], true)) {
                    $sets[] = "`$col` = ?";
                    $vals[] = clean_field($table, $col, $val);
                }
            }
            if (!$sets) {
                fail('Nada que guardar.');
            }
            $vals[] = (string) ($in['id'] ?? '');
            $pdo->prepare("UPDATE `$table` SET " . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
            $row = $pdo->prepare("SELECT * FROM `$table` WHERE id = ?");
            $row->execute([(string) $in['id']]);
            json_out(['ok' => true, 'row' => $row->fetch()]);

        case 'create':
            $fields = is_array($in['fields'] ?? null) ? $in['fields'] : [];
            $pos = (int) $pdo->query("SELECT COALESCE(MAX(position), -1) + 1 FROM `$table`")->fetchColumn();
            $defaults = [
                'sections' => ['name' => 'Nuevo bloque', 'title' => 'Nuevo bloque', 'body' => '<p>Escribe aquí el contenido.</p>'],
                'dresses' => ['name' => 'Nuevo vestido', 'price' => '$999', 'category' => 'rosa'],
                'accessories' => ['name' => 'Nuevo accesorio'],
                'faqs' => ['question' => 'Nueva pregunta', 'answer' => '<p>Respuesta.</p>'],
            ][$table];
            $data = [];
            foreach (array_merge($defaults, $fields) as $col => $val) {
                if (in_array($col, TABLES[$table]['cols'], true)) {
                    $data[$col] = clean_field($table, $col, $val);
                }
            }
            if ($table === 'sections') {
                $data += ['eyebrow' => '', 'cta' => '', 'image' => ''];
                $data['id'] = 'c' . bin2hex(random_bytes(5));
                $data['kind'] = 'custom';
            }
            $data['position'] = $pos;
            $cols = array_keys($data);
            $pdo->prepare("INSERT INTO `$table` (`" . implode('`, `', $cols) . '`) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
                ->execute(array_values($data));
            $id = $table === 'sections' ? $data['id'] : $pdo->lastInsertId();
            $row = $pdo->prepare("SELECT * FROM `$table` WHERE id = ?");
            $row->execute([$id]);
            json_out(['ok' => true, 'row' => $row->fetch()]);

        case 'delete':
            $id = (string) ($in['id'] ?? '');
            if ($table === 'sections') {
                $k = $pdo->prepare('SELECT kind FROM sections WHERE id = ?');
                $k->execute([$id]);
                if ($k->fetchColumn() !== 'custom') {
                    fail('Las secciones principales no se eliminan: puedes ocultarlas con el interruptor.');
                }
            }
            $pdo->prepare("DELETE FROM `$table` WHERE id = ?")->execute([$id]);
            json_out(['ok' => true]);

        case 'reorder':
            $ids = is_array($in['ids'] ?? null) ? array_values($in['ids']) : [];
            $st = $pdo->prepare("UPDATE `$table` SET position = ? WHERE id = ?");
            $pdo->beginTransaction();
            foreach ($ids as $i => $id) {
                $st->execute([$i, (string) $id]);
            }
            $pdo->commit();
            json_out(['ok' => true]);

        case 'settings':
            $values = is_array($in['values'] ?? null) ? $in['values'] : [];
            foreach ($values as $k => $v) {
                if (!in_array($k, SETTING_KEYS, true)) {
                    continue;
                }
                $v = mb_substr(trim((string) $v), 0, 500);
                if (in_array($k, URL_SETTINGS, true)) {
                    $v = safe_url($v);
                }
                if ($k === 'float_button') {
                    $v = $v === '1' ? '1' : '0';
                }
                setting_save($k, $v);
            }
            json_out(['ok' => true]);

        case 'password':
            $cur = (string) ($in['current'] ?? '');
            $next = (string) ($in['next'] ?? '');
            if (!password_verify($cur, setting('admin_pass_hash'))) {
                fail('La contraseña actual no es correcta.');
            }
            if (mb_strlen($next) < 6) {
                fail('La nueva contraseña debe tener al menos 6 caracteres.');
            }
            setting_save('admin_pass_hash', password_hash($next, PASSWORD_DEFAULT));
            json_out(['ok' => true]);

        case 'media_delete':
            $st = $pdo->prepare('SELECT url FROM media WHERE id = ?');
            $st->execute([(int) ($in['id'] ?? 0)]);
            $url = (string) $st->fetchColumn();
            if ($url !== '' && preg_match('~^uploads/[A-Za-z0-9._-]+$~', $url)) {
                @unlink(APP_ROOT . '/' . $url);
            }
            $pdo->prepare('DELETE FROM media WHERE id = ?')->execute([(int) ($in['id'] ?? 0)]);
            json_out(['ok' => true]);

        default:
            fail('Acción desconocida.');
    }
} catch (PDOException $ex) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[vqb] ' . $ex->getMessage());
    fail('Error de base de datos.', 500);
}
