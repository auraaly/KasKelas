<?php
require_once "config/koneksi.php";
require_once "config/auth.php";
require_once "config/kas.php";
require_access('dashboard');
send_no_cache_headers();
include "layout/header.php";

$loginSuccessMessage = '';
if (!empty($_SESSION['flash_login_success'])) {
    $loginSuccessMessage = (string) $_SESSION['flash_login_success'];
    unset($_SESSION['flash_login_success']);
}

$bulan_ini = (int) date('n');
$tahun_ini = (int) date('Y');

$q           = mysqli_query($conn, "SELECT COUNT(*) AS total FROM murid");
$total_murid = ($q && $r = mysqli_fetch_assoc($q)) ? (int)$r['total'] : 0;

$q           = mysqli_query($conn, "SELECT COALESCE(SUM(jumlah),0) AS total FROM transaksi WHERE jenis='Masuk'");
$total_masuk = ($q && $r = mysqli_fetch_assoc($q)) ? (int)$r['total'] : 0;

$q            = mysqli_query($conn, "SELECT COALESCE(SUM(jumlah),0) AS total FROM transaksi WHERE jenis='Keluar'");
$total_keluar = ($q && $r = mysqli_fetch_assoc($q)) ? (int)$r['total'] : 0;

$saldo = $total_masuk - $total_keluar;

$q = mysqli_query($conn, "
    SELECT
        SUM(CASE WHEN jenis='Masuk'  THEN jumlah ELSE 0 END) AS masuk,
        SUM(CASE WHEN jenis='Keluar' THEN jumlah ELSE 0 END) AS keluar
    FROM transaksi
    WHERE MONTH(tanggal) = '$bulan_ini' AND YEAR(tanggal) = '$tahun_ini'
");
$masuk_bulan_ini  = 0;
$keluar_bulan_ini = 0;
if ($q && $d = mysqli_fetch_assoc($q)) {
    $masuk_bulan_ini  = (int)($d['masuk']  ?? 0);
    $keluar_bulan_ini = (int)($d['keluar'] ?? 0);
}

$kas_wajib_bulan_ini = get_kas_wajib_bulanan($conn, $bulan_ini, $tahun_ini);
$target_kas          = $kas_wajib_bulan_ini * $total_murid;
$persentase_target   = $target_kas > 0 ? (int) min(round(($masuk_bulan_ini / $target_kas) * 100), 100) : 0;

$q_status = mysqli_query($conn, "
    SELECT m.id_murid, m.nama,
        COALESCE(SUM(CASE WHEN t.jenis='Masuk' AND MONTH(t.tanggal)='$bulan_ini' AND YEAR(t.tanggal)='$tahun_ini' THEN t.jumlah ELSE 0 END),0) AS bayar
    FROM murid m
    LEFT JOIN transaksi t ON t.id_murid = m.id_murid
    GROUP BY m.id_murid, m.nama ORDER BY m.nama ASC
");
$total_sudah_bayar = 0;
$total_sebagian_bayar = 0;
$total_belum_bayar = 0;
$murid_belum_bayar = [];
$murid_nunggak_parah = [];
if ($q_status) {
    while ($row = mysqli_fetch_assoc($q_status)) {
        $bayar = (int) $row['bayar'];
        if ($bayar >= $kas_wajib_bulan_ini) {
            $total_sudah_bayar++;
        } elseif ($bayar > 0) {
            $total_sebagian_bayar++;
            if (count($murid_belum_bayar) < 5) $murid_belum_bayar[] = $row['nama'];
        } else {
            $total_belum_bayar++;
            if (count($murid_belum_bayar) < 5) $murid_belum_bayar[] = $row['nama'];
            // Cek nunggak 3+ bulan
            $r = get_ringkasan_tunggakan_murid($conn, (int)$row['id_murid'], $bulan_ini, $tahun_ini);
            if ((int)($r['jumlah_bulan'] ?? 0) >= 3)
                $murid_nunggak_parah[] = ['nama' => $row['nama'], 'bulan' => (int)$r['jumlah_bulan'], 'total' => (int)$r['total_tunggakan']];
        }
    }
}

$q_tx = mysqli_query($conn, "
    SELECT t.*, COALESCE(m.nama,'Umum') AS nama_murid
    FROM transaksi t LEFT JOIN murid m ON t.id_murid = m.id_murid
    ORDER BY t.tanggal DESC, t.id_pembayaran DESC LIMIT 5
");
$transaksi_terakhir = [];
if ($q_tx) while ($r = mysqli_fetch_assoc($q_tx)) $transaksi_terakhir[] = $r;

// Trend 6 bulan terakhir
$nama_bulan = nama_bulan_indonesia();
$trend_labels = [];
$trend_masuk = [];
$trend_keluar = [];
for ($i = 5; $i >= 0; $i--) {
    $m = (int)date('n') - $i;
    $y = (int)date('Y');
    if ($m <= 0) { $m += 12; $y--; }
    $trend_labels[] = ($nama_bulan[$m] ?? $m);
    $qTrend = mysqli_query($conn, "SELECT
        COALESCE(SUM(CASE WHEN jenis='Masuk' THEN jumlah ELSE 0 END),0) as masuk,
        COALESCE(SUM(CASE WHEN jenis='Keluar' THEN jumlah ELSE 0 END),0) as keluar
        FROM transaksi
        WHERE MONTH(tanggal) = $m AND YEAR(tanggal) = $y");
    $dt = $qTrend ? mysqli_fetch_assoc($qTrend) : [];
    $trend_masuk[]  = (int)($dt['masuk'] ?? 0);
    $trend_keluar[] = (int)($dt['keluar'] ?? 0);
}
?>

<?php include "layout/sidebar.php"; ?>

<div class="content">
<div class="container-fluid animate-fade-in">

    <div class="top-header d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4>Dashboard</h4>
            <small class="text-white-50"><i class="bi bi-calendar3 me-1"></i><?= date('d F Y') ?></small>
        </div>
        <div class="text-end text-white">
            <strong><?= htmlspecialchars(current_user_name()) ?></strong><br>
            <small class="text-white-50"><?= htmlspecialchars(role_label(current_user_role())) ?></small>
        </div>
    </div>

    <!-- STAT CARDS -->
    <div class="row g-4 mb-4">
        <div class="col-md-3 d-flex">
            <div class="card-box border-start border-primary border-4 w-100">
                <div class="d-flex justify-content-between align-items-center h-100">
                    <div>
                        <div class="card-title">Total Kas Masuk</div>
                        <div class="card-value">Rp <?= number_format($total_masuk, 0, ',', '.') ?></div>
                        <small>Bulan ini: Rp <?= number_format($masuk_bulan_ini, 0, ',', '.') ?></small>
                    </div>
                    <div class="stat-icon"><i class="bi bi-arrow-down-circle-fill"></i></div>
                </div>
            </div>
        </div>
        <div class="col-md-3 d-flex">
            <div class="card-box border-start border-danger border-4 w-100">
                <div class="d-flex justify-content-between align-items-center h-100">
                    <div>
                        <div class="card-title">Total Kas Keluar</div>
                        <div class="card-value">Rp <?= number_format($total_keluar, 0, ',', '.') ?></div>
                        <small>Bulan ini: Rp <?= number_format($keluar_bulan_ini, 0, ',', '.') ?></small>
                    </div>
                    <div class="stat-icon"><i class="bi bi-arrow-up-circle-fill"></i></div>
                </div>
            </div>
        </div>
        <div class="col-md-3 d-flex">
            <div class="card-box border-start border-success border-4 w-100">
                <div class="d-flex justify-content-between align-items-center h-100">
                    <div>
                        <div class="card-title">Saldo Kas</div>
                        <div class="card-value">Rp <?= number_format($saldo, 0, ',', '.') ?></div>
                        <small>Keseluruhan</small>
                    </div>
                    <div class="stat-icon"><i class="bi bi-wallet-fill"></i></div>
                </div>
            </div>
        </div>
        <div class="col-md-3 d-flex">
            <div class="card-box border-start border-warning border-4 w-100">
                <div class="d-flex justify-content-between align-items-center h-100">
                    <div>
                        <div class="card-title">Total Murid</div>
                        <div class="card-value"><?= $total_murid ?> Orang</div>
                        <small><?= $total_sudah_bayar ?> sudah bayar bulan ini</small>
                    </div>
                    <div class="stat-icon"><i class="bi bi-people-fill"></i></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <!-- Murid belum bayar -->
        <div class="col-md-6">
            <div class="table-box h-100">
                <div class="table-box-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-person-exclamation me-2"></i>Belum Bayar Bulan Ini</span>
                    <a href="status_pembayaran.php" class="btn btn-sm btn-light" style="border-radius:8px;">Lihat Semua</a>
                </div>
                <div class="p-3">
                    <?php if (!empty($murid_belum_bayar)): ?>
                        <table class="table table-hover align-middle small mb-0">
                            <tbody>
                            <?php foreach ($murid_belum_bayar as $i => $nama): ?>
                                <tr>
                                    <td width="36">
                                        <div class="rounded-circle d-flex align-items-center justify-content-center"
                                            style="width:32px;height:32px;background:#eff6ff;color:#2563eb;font-weight:700;font-size:.85rem;">
                                            <?= strtoupper(substr($nama, 0, 1)) ?>
                                        </div>
                                    </td>
                                    <td class="fw-semibold"><?= htmlspecialchars($nama) ?></td>
                                    <td><span class="status-badge-soft danger">Belum Bayar</span></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($total_belum_bayar > 5): ?>
                                <tr><td colspan="3" class="text-muted small text-center">+<?= $total_belum_bayar - 5 ?> murid lainnya</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="text-center py-4 text-success">
                            <i class="bi bi-check-circle-fill fs-2 d-block mb-2"></i>
                            <strong>Semua murid sudah bayar!</strong>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Grafik + target -->
        <div class="col-md-6">
            <div class="table-box h-100">
                <div class="table-box-header">
                    <i class="bi bi-pie-chart-fill me-2"></i>Status Pembayaran Bulan Ini
                </div>
                <div class="p-4">
                    <div style="position:relative;height:180px;">
                        <canvas id="chartPembayaran"></canvas>
                    </div>
                    <div class="d-flex justify-content-center gap-4 mt-3 small">
                        <span><span class="badge bg-success me-1">&nbsp;</span>Sudah Bayar: <strong><?= $total_sudah_bayar ?></strong></span>
                        <span><span class="badge bg-warning me-1">&nbsp;</span>Sebagian: <strong><?= $total_sebagian_bayar ?></strong></span>
                        <span><span class="badge bg-danger me-1">&nbsp;</span>Belum Bayar: <strong><?= $total_belum_bayar ?></strong></span>
                    </div>
                    <hr class="my-3">
                    <div class="d-flex justify-content-between small mb-1">
                        <span class="text-muted">Target bulan ini</span>
                        <span class="fw-bold"><?= $persentase_target ?>% — Rp <?= number_format($target_kas, 0, ',', '.') ?></span>
                    </div>
                    <div class="progress" style="height:8px;border-radius:999px;">
                        <div class="progress-bar bg-success" style="width:<?= $persentase_target ?>%"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Transaksi terakhir -->
    <div class="table-box">
        <div class="table-box-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-clock-history me-2"></i>Transaksi Terakhir</span>
            <a href="arus_kas.php" class="btn btn-sm btn-light" style="border-radius:8px;">Lihat Semua</a>
        </div>
        <div class="p-3">
            <div class="table-responsive">
                <table class="table table-hover align-middle small mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Tanggal</th>
                            <th>Nama / Uraian</th>
                            <th>Jenis</th>
                            <th class="text-end">Jumlah</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($transaksi_terakhir)): ?>
                        <?php foreach ($transaksi_terakhir as $r): ?>
                        <tr>
                            <td class="text-muted"><?= date('d/m/Y', strtotime($r['tanggal'])) ?></td>
                            <td>
                                <div class="fw-semibold"><?= htmlspecialchars($r['nama_murid']) ?></div>
                                <small class="text-muted"><?= htmlspecialchars(substr($r['keterangan'] ?? '-', 0, 40)) ?></small>
                            </td>
                            <td>
                                <?php if ($r['jenis'] === 'Masuk'): ?>
                                    <span class="status-badge-soft success"><i class="bi bi-arrow-down"></i> Masuk</span>
                                <?php else: ?>
                                    <span class="status-badge-soft danger"><i class="bi bi-arrow-up"></i> Keluar</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end fw-bold <?= $r['jenis'] === 'Masuk' ? 'text-success' : 'text-danger' ?>">
                                <?= $r['jenis'] === 'Masuk' ? '+' : '-' ?> Rp <?= number_format((int)$r['jumlah'], 0, ',', '.') ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="4" class="text-center py-4 text-muted"><i class="bi bi-inbox fs-3 d-block mb-2"></i>Belum ada transaksi.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>
</div>

<?php if ($loginSuccessMessage !== ''): ?>
<div class="login-success-toast" id="loginSuccessToast">
    <div class="login-success-toast-icon"><i class="bi bi-check-lg"></i></div>
    <div class="login-success-toast-body">
        <strong>Login berhasil</strong>
        <span><?= htmlspecialchars($loginSuccessMessage) ?></span>
    </div>
    <button class="login-success-toast-close" id="loginSuccessToastClose"><i class="bi bi-x"></i></button>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const toast = document.getElementById('loginSuccessToast');
    const closeBtn = document.getElementById('loginSuccessToastClose');
    if (toast) {
        setTimeout(() => toast.classList.add('is-visible'), 120);
        const hide = () => toast.classList.remove('is-visible');
        setTimeout(hide, 4200);
        closeBtn?.addEventListener('click', hide);
    }

    <?php if ($total_murid > 0): ?>
    new Chart(document.getElementById('chartPembayaran'), {
        type: 'doughnut',
        data: {
            labels: ['Sudah Bayar', 'Sebagian', 'Belum Bayar'],
            datasets: [{ data: [<?= $total_sudah_bayar ?>, <?= $total_sebagian_bayar ?>, <?= $total_belum_bayar ?>], backgroundColor: ['#22c55e','#fbbf24','#ef4444'], borderWidth: 0 }]
        },
        options: {
            responsive: true, maintainAspectRatio: false, cutout: '65%',
            plugins: { legend: { display: false } }
        }
    });
    <?php endif; ?>
});
</script>

<?php include "layout/footer.php"; ?>
