<?php
declare(strict_types=1);

const MEDIA_TYPES = [
    'image/jpeg' => ['jpg', 'image'],
    'image/png' => ['png', 'image'],
    'image/webp' => ['webp', 'image'],
    'image/gif' => ['gif', 'gif'],
    'video/mp4' => ['mp4', 'video'],
    'video/webm' => ['webm', 'video'],
    'video/quicktime' => ['mov', 'video'],
    'image/x-icon' => ['ico', 'image'],
    'image/vnd.microsoft.icon' => ['ico', 'image'],
];

/** Normalise $_FILES['x'] (single or multiple) into a flat list. */
function files_list(array $f): array
{
    if (!is_array($f['name'])) return [$f];
    $out = [];
    foreach ($f['name'] as $i => $name) {
        $out[] = ['name' => $name, 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
    }
    return $out;
}

/** Store an uploaded file in uploads/YYYY/MM and register it in the media library. */
function store_upload(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $msg = match ($file['error'] ?? 0) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'El archivo es más grande de lo que permite el hosting (' . ini_get('upload_max_filesize') . ').',
            UPLOAD_ERR_NO_FILE => 'No se recibió ningún archivo.',
            default => 'Error al subir el archivo.',
        };
        throw new RuntimeException($msg);
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
    if (!isset(MEDIA_TYPES[$mime])) throw new RuntimeException('Formato no permitido (' . $mime . '). Usa JPG, PNG, WEBP, GIF, MP4 o WEBM.');
    [$ext, $kind] = MEDIA_TYPES[$mime];
    $dir = 'uploads/' . date('Y') . '/' . date('m');
    if (!is_dir(ROOT . '/' . $dir) && !mkdir(ROOT . '/' . $dir, 0755, true)) throw new RuntimeException('No se pudo crear la carpeta ' . $dir);
    $base = substr(slugify(pathinfo((string)$file['name'], PATHINFO_FILENAME)), 0, 40);
    $path = $dir . '/' . $base . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], ROOT . '/' . $path)) {
        if (!@rename($file['tmp_name'], ROOT . '/' . $path)) throw new RuntimeException('No se pudo guardar el archivo.');
    }
    @chmod(ROOT . '/' . $path, 0644);
    $w = $h = null;
    if ($kind === 'image' && $ext !== 'ico') {
        resize_image(ROOT . '/' . $path, $mime, 2000);
        $info = @getimagesize(ROOT . '/' . $path);
        if ($info) [$w, $h] = $info;
    } elseif ($kind === 'gif') {
        $info = @getimagesize(ROOT . '/' . $path);
        if ($info) [$w, $h] = $info;
    }
    $id = insert('media', [
        'path' => $path, 'kind' => $kind, 'mime' => $mime, 'size' => (int)filesize(ROOT . '/' . $path),
        'width' => $w, 'height' => $h, 'original' => mb_substr((string)$file['name'], 0, 250), 'created_at' => date('Y-m-d H:i:s'),
    ]);
    return ['id' => $id, 'path' => $path, 'url' => media_url($path), 'kind' => $kind, 'width' => $w, 'height' => $h, 'name' => $file['name']];
}

/** Downscale big photos so pages stay fast (keeps aspect ratio). */
function resize_image(string $file, string $mime, int $max): void
{
    if (!function_exists('imagecreatefromjpeg')) return;
    $info = @getimagesize($file);
    if (!$info || ($info[0] <= $max && $info[1] <= $max)) return;
    $src = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($file),
        'image/png' => @imagecreatefrompng($file),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file) : false,
        default => false,
    };
    if (!$src) return;
    $ratio = min($max / $info[0], $max / $info[1]);
    $nw = (int)round($info[0] * $ratio);
    $nh = (int)round($info[1] * $ratio);
    $dst = imagecreatetruecolor($nw, $nh);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $info[0], $info[1]);
    match ($mime) {
        'image/jpeg' => imagejpeg($dst, $file, 85),
        'image/png' => imagepng($dst, $file, 6),
        'image/webp' => imagewebp($dst, $file, 85),
    };
    imagedestroy($src);
    imagedestroy($dst);
}

/** Delete a library file only when nothing else references it. */
function media_delete(int $id): void
{
    $m = row('SELECT * FROM media WHERE id = ?', [$id]);
    if (!$m) return;
    q('DELETE FROM media WHERE id = ?', [$id]);
    $inUse = (int)val('SELECT COUNT(*) FROM product_media WHERE path = ? OR poster = ?', [$m['path'], $m['path']]);
    if (!$inUse && str_starts_with($m['path'], 'uploads/') && !str_contains($m['path'], '..')) @unlink(ROOT . '/' . $m['path']);
}
