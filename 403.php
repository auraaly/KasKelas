<?php
require_once "config/auth.php";
require_login();
require_once "layout/header.php";
?>

<div class="login-page">
    <div class="login-shell" style="max-width: 920px;">
        <section class="login-showcase">
            <div class="login-badge">
                <i class="bi bi-shield-lock"></i> Akses Dibatasi
            </div>
            <h1>Halaman ini tidak tersedia untuk role kamu.</h1>
            <p>Akun yang sedang login tidak punya izin untuk membuka halaman tersebut. Kalau perlu akses tambahan, minta ke admin atau bendahara.</p>
        </section>

        <section class="login-card">
            <img src="/KasKelas/assets/logosmakenju.png" alt="Logo Kas Kelas" class="login-logo">
            <h2>Akses Ditolak</h2>
            <p>Kamu login sebagai <strong><?= htmlspecialchars(role_label(current_user_role())) ?></strong>.</p>

            <div class="alert alert-warning border-0 shadow-sm">
                Hanya fitur yang sesuai dengan role akan ditampilkan dan bisa dibuka.
            </div>

            <a href="/KasKelas/dashboard.php" class="btn login-submit w-100 text-center text-decoration-none">
                Kembali ke Dashboard
            </a>
        </section>
    </div>
</div>
