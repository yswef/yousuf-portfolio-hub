<?php
declare(strict_types=1);
/* Minimal RFC 6238 TOTP (SHA-1, 6 digits, 30s) — optional 2FA for the admin. */
function b32_encode(string $bin): string {
    $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $out = '';
    foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    foreach (str_split($bits, 5) as $ch) $out .= $a[bindec(str_pad($ch, 5, '0'))];
    return $out;
}
function b32_decode(string $s): string {
    $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $out = '';
    foreach (str_split(strtoupper(preg_replace('/[^A-Z2-7]/i', '', $s))) as $c) $bits .= str_pad(decbin((int)strpos($a, $c)), 5, '0', STR_PAD_LEFT);
    foreach (str_split($bits, 8) as $b) if (strlen($b) === 8) $out .= chr(bindec($b));
    return $out;
}
function totp_new_secret(): string { return b32_encode(random_bytes(20)); }
function totp_code(string $secret, ?int $t = null): string {
    $c = intdiv($t ?? time(), 30);
    $h = hash_hmac('sha1', pack('N2', 0, $c), b32_decode($secret), true);
    $o = ord($h[19]) & 15;
    $n = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
    return str_pad((string)($n % 1000000), 6, '0', STR_PAD_LEFT);
}
function totp_verify(string $secret, string $code): bool {
    $code = preg_replace('/\D/', '', $code);
    if (strlen((string)$code) !== 6) return false;
    foreach ([-1, 0, 1] as $d) if (hash_equals(totp_code($secret, time() + $d * 30), $code)) return true;
    return false;
}
