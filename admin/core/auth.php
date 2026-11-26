<?php
declare(strict_types=1);
/* Login (+ optional TOTP step). admin_gate() returns true only for an authenticated session. */

function admin_gate(): bool {
    if (!empty($_SESSION['admin_id']) && (time() - (int)($_SESSION['last'] ?? 0)) > 8 * 3600) { $_SESSION = []; session_regenerate_id(true); }
    if (!empty($_SESSION['admin_id'])) {
        // Suspended or deleted team accounts lose access immediately (fail closed if the row is gone).
        if (table_exists('admins')) {
            $me = row('SELECT id, username, status FROM admins WHERE id=?', [(int)$_SESSION['admin_id']]);
            if (!$me || (string)($me['status'] ?? 'active') !== 'active') {
                $_SESSION = []; session_regenerate_id(true);
                adm_login_page('login', 'تم إيقاف هذا الحساب. تواصل مع مالك اللوحة.');
                exit;
            }
            $_SESSION['admin_name'] = (string)$me['username'];
        }
        $_SESSION['last'] = time(); return true;
    }

    // The admins table may not exist yet on a brand-new DB: create core tables first.
    if (!table_exists('admins')) { try { migrate_all(); } catch (Throwable $e) { adm_fatal($e->getMessage()); } }

    $err = ''; $step = !empty($_SESSION['pending_2fa']) ? '2fa' : 'login';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $do = (string)($_POST['do'] ?? '');
        if (!csrf_ok()) $err = 'انتهت الجلسة، أعد المحاولة.';
        elseif ($do === 'login') {
            $u = trim((string)($_POST['username'] ?? '')); $pw = (string)($_POST['password'] ?? '');
            $bIp = 'login_ip:' . hmac(client_ip()); $bUser = 'login_user:' . hmac(strtolower($u));
            if (rate_count($bIp, 900) >= 20 || rate_count($bUser, 900) >= 5) $err = 'محاولات كثيرة. أعد المحاولة بعد 15 دقيقة.';
            else {
                $a = row('SELECT * FROM admins WHERE username=?', [$u]);
                $ok = password_verify($pw, $a['pass_hash'] ?? password_hash('dummy', PASSWORD_DEFAULT));   // equalise timing for unknown users
                if ($a && $ok) {
                    rate_clear($bUser);
                    if (!empty($a['totp_secret'])) { $_SESSION['pending_2fa'] = (int)$a['id']; $step = '2fa'; }
                    else admin_login((array)$a);
                } else {
                    rate_add($bIp); rate_add($bUser); audit('login_failed', $u); $err = 'بيانات الدخول غير صحيحة.';
                }
            }
        } elseif ($do === '2fa' && !empty($_SESSION['pending_2fa'])) {
            $bIp = 'totp_ip:' . hmac(client_ip());
            $a = row('SELECT * FROM admins WHERE id=?', [(int)$_SESSION['pending_2fa']]);
            if (rate_count($bIp, 900) >= 10) $err = 'محاولات كثيرة. أعد المحاولة بعد 15 دقيقة.';
            elseif ($a && totp_verify((string)$a['totp_secret'], (string)($_POST['code'] ?? ''))) admin_login((array)$a);
            else { rate_add($bIp); $err = 'رمز التحقق غير صحيح.'; $step = '2fa'; }
        } elseif ($do === 'cancel_2fa') { unset($_SESSION['pending_2fa']); $step = 'login'; }
    }
    if (!empty($_SESSION['admin_id'])) { $nx = (string)($_SESSION['next'] ?? ''); unset($_SESSION['next']); redirect($nx !== '' ? $nx : url('admin/')); }
    adm_login_page($step, $err);
    return false;
}
function admin_login(array $a): void {
    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int)$a['id']; $_SESSION['admin_name'] = (string)$a['username']; $_SESSION['last'] = time();
    unset($_SESSION['pending_2fa'], $_SESSION['csrf']);
    try { upd('admins', ['last_login' => time()], 'id=?', [(int)$a['id']]); } catch (Throwable $e) {}
    audit('login');
}
