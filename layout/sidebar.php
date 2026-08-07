<?php
$page = basename($_SERVER['PHP_SELF']);
$base_url = "/KasKelas/";
require_once __DIR__ . "/../config/koneksi.php";
require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/kas.php";
?>

<div class="sidebar">
    <div class="sidebar-header">
        <img src="/KasKelas/assets/logosmakenju.png" alt="Logo Kas Kelas" class="logo">
        <div>
            <h5>Kas Kelas</h5>
            <small>XI PPLG 2</small>
        </div>
    </div>

    <div class="sidebar-menu">
        <a href="<?= $base_url ?>dashboard.php"
           class="<?= $page=='dashboard.php'?'active':'' ?>">
            <i class="bi bi-speedometer2"></i> Dashboard
        </a>

        <?php if (can_access('data_murid')): ?>
            <a href="<?= $base_url ?>murid/data_murid.php"
               class="<?= $page=='data_murid.php'?'active':'' ?>">
                <i class="bi bi-people"></i> Data Murid
            </a>
        <?php endif; ?>

        <?php if (can_access('kas_masuk')): ?>
            <a href="<?= $base_url ?>kas_masuk.php"
               class="<?= $page=='kas_masuk.php'?'active':'' ?>">
                <i class="bi bi-arrow-down-circle"></i> Kas Masuk
            </a>
        <?php endif; ?>

        <?php if (can_access('kas_keluar')): ?>
            <a href="<?= $base_url ?>kas_keluar.php"
               class="<?= $page=='kas_keluar.php'?'active':'' ?>">
                <i class="bi bi-arrow-up-circle"></i> Kas Keluar
            </a>
        <?php endif; ?>

        <?php if (can_access('arus_kas')): ?>
            <a href="<?= $base_url ?>arus_kas.php"
               class="<?= $page=='arus_kas.php'?'active':'' ?>">
                <i class="bi bi-bar-chart"></i> Arus Kas
            </a>
        <?php endif; ?>

        <?php if (can_access('status_pembayaran')): ?>
            <a href="<?= $base_url ?>status_pembayaran.php"
               class="<?= $page=='status_pembayaran.php'?'active':'' ?>">
                <i class="bi bi-check-circle"></i> Status Pembayaran
            </a>
        <?php endif; ?>

        <?php if (can_access('laporan')): ?>
            <a href="<?= $base_url ?>laporan.php"
               class="<?= $page=='laporan.php'?'active':'' ?>">
                <i class="bi bi-file-earmark-text"></i> Laporan
            </a>
        <?php endif; ?>
    </div>

    <div class="sidebar-user">
        <div class="sidebar-user-card">
            <div class="d-flex align-items-center gap-2 mb-1">
                <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                    style="width:32px;height:32px;background:rgba(255,255,255,0.15);font-weight:700;font-size:.9rem;">
                    <?= strtoupper(substr(current_user_name(), 0, 1)) ?>
                </div>
                <div style="min-width:0;">
                    <div class="sidebar-user-name"><?= htmlspecialchars(current_user_name()) ?></div>
                    <div class="sidebar-user-role"><?= htmlspecialchars(role_label(current_user_role())) ?></div>
                </div>
                <button id="darkModeBtn" onclick="toggleDarkMode()"
                    class="ms-auto border-0 rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                    style="width:28px;height:28px;background:rgba(255,255,255,0.12);color:rgba(255,255,255,0.8);cursor:pointer;transition:all .2s;"
                    title="Toggle dark mode">
                    <i class="bi bi-moon-fill" style="font-size:.75rem;"></i>
                </button>
            </div>
            <div class="d-flex gap-2 mt-2">
                <a href="<?= $base_url ?>logout.php" class="sidebar-logout"
                   onclick="return confirm('Yakin mau logout?')">
                    <i class="bi bi-box-arrow-right"></i> Logout
                </a>
                <a href="<?= $base_url ?>ganti_password.php" class="sidebar-logout">
                    <i class="bi bi-key"></i> Password
                </a>
            </div>
        </div>
    </div>
</div>
