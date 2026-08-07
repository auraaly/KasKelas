<?php

declare(strict_types=1);

const AUTH_LOGIN_URL = '/KasKelas/login.php';
const AUTH_DASHBOARD_URL = '/KasKelas/dashboard.php';
const AUTH_FORBIDDEN_URL = '/KasKelas/403.php';

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
    );

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');

    session_start();
}

function send_no_cache_headers(): void
{
    header('Expires: Tue, 01 Jan 2000 00:00:00 GMT');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}

function login_user(array $user): void
{
    start_secure_session();
    session_regenerate_id(true);

    $_SESSION['user_id'] = (int) ($user['id_user'] ?? 0);
    $_SESSION['user_nama'] = trim((string) ($user['nama'] ?? '')) ?: (string) ($user['username'] ?? 'Pengguna');
    $_SESSION['user_username'] = (string) ($user['username'] ?? '');
    $_SESSION['user_role'] = trim((string) ($user['role'] ?? '')) ?: 'bendahara';
    $_SESSION['logged_in_at'] = time();
}

function logout_user(): void
{
    start_secure_session();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            (bool) $params['secure'],
            (bool) $params['httponly']
        );
    }

    session_destroy();
}

function require_login(): void
{
    start_secure_session();
    send_no_cache_headers();

    if (!is_logged_in()) {
        redirect(AUTH_LOGIN_URL);
    }
}

function redirect_if_logged_in(): void
{
    start_secure_session();
    send_no_cache_headers();

    if (is_logged_in()) {
        redirect(AUTH_DASHBOARD_URL);
    }
}

function require_guest(): void
{
    redirect_if_logged_in();
}

function current_user_name(): string
{
    return $_SESSION['user_nama'] ?? 'Pengguna';
}

function current_user_role(): string
{
    return $_SESSION['user_role'] ?? 'bendahara';
}

function role_label(string $role): string
{
    return ucwords(str_replace('_', ' ', $role));
}

function can_manage_murid(): bool
{
    return current_user_role() === 'wali kelas';
}

function is_wali_kelas(): bool
{
    return current_user_role() === 'wali kelas';
}

function role_permissions(): array
{
    return [
        'bendahara' => [
            'dashboard',
            'data_murid',
            'kas_masuk',
            'kas_keluar',
            'arus_kas',
            'status_pembayaran',
            'laporan',
            'laporan_kas',
            'pengajuan_pengeluaran',
        ],
        'ketua kelas' => [
            'dashboard',
            'data_murid',
            'kas_masuk',
            'arus_kas',
            'status_pembayaran',
            'laporan',
            'laporan_kas',
        ],
        'wali kelas' => [
            'dashboard',
            'data_murid',
            'kas_keluar',
            'arus_kas',
            'status_pembayaran',
            'laporan',
            'laporan_kas',
            'pengajuan_pengeluaran',
        ],
    ];
}

function can_access(string $feature): bool
{
    $role = current_user_role();
    $permissions = role_permissions();

    if (!isset($permissions[$role])) {
        return false;
    }

    return in_array($feature, $permissions[$role], true);
}

function require_access(string $feature): void
{
    require_login();

    if (!can_access($feature)) {
        redirect(AUTH_FORBIDDEN_URL);
    }
}

start_secure_session();
send_no_cache_headers();
