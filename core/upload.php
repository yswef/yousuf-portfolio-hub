<?php
declare(strict_types=1);

const UPLOAD_DIR_REL = 'assets/uploads';
function upload_dir(): string { return ROOT . '/' . UPLOAD_DIR_REL . '/'; }

/** Safe image upload: real MIME check, re-encoded through GD (strips embedded code), random name. Returns file name or null. */
function handle_image_upload(array $file, int $maxW = 1000): ?string {
    if (!function_exists('imagecreatefromjpeg') || !isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) return null;
    if ($file['size'] > 5 * 1024 * 1024) return null;
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $img = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($file['tmp_name']),
        'image/png' => @imagecreatefrompng($file['tmp_name']),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file['tmp_name']) : false,
        default => false,
    };
    if (!$img) return null;
    $w = imagesx($img); $h = imagesy($img);
    if ($w > $maxW) {
        $nh = (int)round($h * ($maxW / $w));
        $r = imagecreatetruecolor($maxW, $nh);
        imagefill($r, 0, 0, imagecolorallocate($r, 255, 255, 255));
        imagecopyresampled($r, $img, 0, 0, 0, 0, $maxW, $nh, $w, $h);
        imagedestroy($img); $img = $r;
    } else {
        $r = imagecreatetruecolor($w, $h);
        imagefill($r, 0, 0, imagecolorallocate($r, 255, 255, 255));
        imagecopy($r, $img, 0, 0, 0, 0, $w, $h);
        imagedestroy($img); $img = $r;
    }
    if (!is_dir(upload_dir())) @mkdir(upload_dir(), 0755, true);
    $name = bin2hex(random_bytes(16)) . '.jpg';
    $ok = imagejpeg($img, upload_dir() . $name, 85);
    imagedestroy($img);
    return $ok ? $name : null;
}
function delete_upload(?string $name): void {
    if (!$name || str_starts_with($name, 'seed-')) return;
    $p = upload_dir() . basename($name);
    if (is_file($p)) @unlink($p);
}
function upload_url(?string $name): string { return $name ? url(UPLOAD_DIR_REL . '/' . rawurlencode(basename($name))) : ''; }
