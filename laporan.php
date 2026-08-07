<?php
include "config/koneksi.php";
require_once "config/auth.php";
require_once "config/kas.php";
require_access('laporan');
include "layout/header.php";

$bulan_pilih  = $_GET['bulan'] ?? date('n');
$tahun_pilih  = $_GET['tahun'] ?? date('Y');
$nama_bulan   = nama_bulan_indonesia();
$bulan_filter = $bulan_pilih === '' ? '' : (int)$bulan_pilih;
$tahun_filter = $tahun_pilih === '' ? '' : (int)$tahun_pilih;
$tahun_grafik = $tahun_filter !== '' ? $tahun_filter : (int) date('Y');
$tahun_opsi = [2025, 2026, 2027];

$qTahun = mysqli_query($conn, "SELECT DISTINCT YEAR(tanggal) AS tahun FROM transaksi WHERE tanggal IS NOT NULL ORDER BY tahun DESC");
if ($qTahun) {
    while ($row = mysqli_fetch_assoc($qTahun)) {
        $tahunData = (int) ($row['tahun'] ?? 0);
        if ($tahunData > 0) {
            $tahun_opsi[] = $tahunData;
        }
    }
}

$tahun_opsi[] = $tahun_grafik;
$tahun_opsi = array_values(array_unique($tahun_opsi));
rsort($tahun_opsi, SORT_NUMERIC);

$periode_label = $bulan_filter !== '' && $tahun_filter !== ''
    ? ($nama_bulan[$bulan_filter] ?? '') . ' ' . $tahun_filter
    : ($tahun_filter !== '' ? 'Tahun ' . $tahun_filter : 'Semua Periode');

$kas_wajib = ($bulan_filter !== '' && $tahun_filter !== '')
    ? get_kas_wajib_bulanan($conn, $bulan_filter, $tahun_filter) : 20000;

$wTx = "WHERE 1=1";
if ($bulan_filter !== '') $wTx .= " AND MONTH(tanggal)='$bulan_filter'";
if ($tahun_filter !== '') $wTx .= " AND YEAR(tanggal)='$tahun_filter'";

$wTxT = "WHERE 1=1";
if ($bulan_filter !== '') $wTxT .= " AND MONTH(t.tanggal)='$bulan_filter'";
if ($tahun_filter !== '') $wTxT .= " AND YEAR(t.tanggal)='$tahun_filter'";

// Summary
$qS = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COALESCE(SUM(CASE WHEN jenis='Masuk' THEN jumlah ELSE 0 END),0) AS masuk,
           COALESCE(SUM(CASE WHEN jenis='Keluar' THEN jumlah ELSE 0 END),0) AS keluar,
           SUM(CASE WHEN jenis='Masuk' THEN 1 ELSE 0 END) AS cnt_masuk,
           SUM(CASE WHEN jenis='Keluar' THEN 1 ELSE 0 END) AS cnt_keluar
    FROM transaksi $wTx"));
$total_masuk  = (int)($qS['masuk']  ?? 0);
$total_keluar = (int)($qS['keluar'] ?? 0);
$saldo        = $total_masuk - $total_keluar;

// Murid & status
$total_murid = (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS n FROM murid"))['n'] ?? 0);
$target_kas  = $kas_wajib * $total_murid;
$pct_target  = $target_kas > 0 ? min(round($total_masuk/$target_kas*100), 100) : 0;

$qSP = mysqli_query($conn, "
    SELECT m.id_murid, m.nama,
        COALESCE(SUM(CASE WHEN t.jenis='Masuk'
            ".($bulan_filter!==''?"AND t.bulan='$bulan_filter'":'')."
            ".($tahun_filter!==''?"AND t.tahun='$tahun_filter'":'')."
            THEN t.jumlah ELSE 0 END),0) AS bayar
    FROM murid m LEFT JOIN transaksi t ON t.id_murid=m.id_murid
    GROUP BY m.id_murid, m.nama ORDER BY m.nama ASC");
$lunas = $sebagian = $belum = 0;
$rekap_murid = [];
if ($qSP) while ($r = mysqli_fetch_assoc($qSP)) {
    $b = (int)$r['bayar'];
    $sisa = max($kas_wajib - $b, 0);
    if ($b >= $kas_wajib)  { $status = 'Lunas';      $lunas++; }
    elseif ($b > 0)        { $status = 'Sebagian';   $sebagian++; }
    else                   { $status = 'Belum Bayar'; $belum++; }
    $rekap_murid[] = ['nama'=>$r['nama'], 'bayar'=>$b, 'sisa'=>$sisa, 'status'=>$status];
}

$grafik_label = [];
$grafik_masuk = [];
$grafik_keluar = [];

for ($i = 1; $i <= 12; $i++) {
    $grafik_label[] = $nama_bulan[$i] ?? (string) $i;
    $grafik_masuk[] = 0;
    $grafik_keluar[] = 0;
}

$qGrafik = mysqli_query($conn, "
    SELECT MONTH(tanggal) AS bulan,
           COALESCE(SUM(CASE WHEN jenis='Masuk' THEN jumlah ELSE 0 END),0) AS masuk,
           COALESCE(SUM(CASE WHEN jenis='Keluar' THEN jumlah ELSE 0 END),0) AS keluar
    FROM transaksi
    WHERE YEAR(tanggal) = '$tahun_grafik'
    GROUP BY MONTH(tanggal)
    ORDER BY MONTH(tanggal) ASC");

if ($qGrafik) {
    while ($row = mysqli_fetch_assoc($qGrafik)) {
        $bulanIdx = (int) ($row['bulan'] ?? 0);
        if ($bulanIdx >= 1 && $bulanIdx <= 12) {
            $grafik_masuk[$bulanIdx - 1] = (int) ($row['masuk'] ?? 0);
            $grafik_keluar[$bulanIdx - 1] = (int) ($row['keluar'] ?? 0);
        }
    }
}

// Breakdown pengeluaran per kategori
$pengeluaran_kategori = [];
$qKat = mysqli_query($conn, "
    SELECT COALESCE(NULLIF(kategori,''), 'Lainnya') AS kat,
           SUM(jumlah) AS total
    FROM transaksi
    WHERE jenis='Keluar'
    " . ($bulan_filter !== '' ? "AND MONTH(tanggal)='$bulan_filter'" : "") . "
    " . ($tahun_filter !== '' ? "AND YEAR(tanggal)='$tahun_filter'" : "") . "
    GROUP BY kat ORDER BY total DESC
");
if ($qKat) while ($r = mysqli_fetch_assoc($qKat)) $pengeluaran_kategori[$r['kat']] = (int)$r['total'];

include "layout/sidebar.php";
?>

<div class="content">
<div class="container-fluid animate-fade-in">

    <div class="top-header d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4>Laporan Kas</h4>
            <small class="text-white-50"><i class="bi bi-calendar3 me-1"></i><?= htmlspecialchars($periode_label) ?></small>
        </div>
        <a href="laporan/laporan_kas.php?bulan=<?= urlencode($bulan_pilih) ?>&tahun=<?= urlencode($tahun_pilih) ?>"
           class="btn btn-sm fw-semibold" style="background:rgba(255,255,255,0.15);color:white;border:1px solid rgba(255,255,255,0.25);border-radius:10px;">
            <i class="bi bi-printer me-1"></i> Cetak
        </a>
    </div>

    <!-- FILTER -->
    <div class="filter-panel mb-4">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-auto">
                <label class="form-label text-muted small fw-bold text-uppercase mb-1">Bulan</label>
                <select name="bulan" class="form-select form-select-sm form-soft-control">
                    <option value="">Semua Bulan</option>
                    <?php foreach ($nama_bulan as $k => $v): ?>
                        <option value="<?=$k?>" <?=(string)$bulan_pilih===(string)$k?'selected':''?>><?=$v?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <label class="form-label text-muted small fw-bold text-uppercase mb-1">Tahun</label>
                <select name="tahun" class="form-select form-select-sm form-soft-control">
                    <option value="">Semua Tahun</option>
                    <?php foreach ($tahun_opsi as $tahunOpsi): ?>
                        <option value="<?=$tahunOpsi?>" <?=(string)$tahun_pilih===(string)$tahunOpsi?'selected':''?>><?=$tahunOpsi?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn-premium px-4 py-2">Filter</button>
            </div>
            <div class="col-auto ms-auto text-end">
                <small class="text-muted d-block">Kas wajib: <strong>Rp <?= number_format($kas_wajib,0,',','.') ?></strong></small>
                <small class="text-muted">Target: <strong>Rp <?= number_format($target_kas,0,',','.') ?></strong></small>
            </div>
        </form>
    </div>

    <!-- SUMMARY STRIP -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card-box border-start border-success border-4 h-100">
                <div class="d-flex justify-content-between align-items-center h-100">
                    <div>
                        <div class="card-title">Total Masuk</div>
                        <div class="card-value">Rp <?= number_format($total_masuk,0,',','.') ?></div>
                        <small><?= (int)($qS['cnt_masuk']??0) ?> transaksi</small>
                    </div>
                    <div class="stat-icon"><i class="bi bi-arrow-down-circle-fill"></i></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card-box border-start border-danger border-4 h-100">
                <div class="d-flex justify-content-between align-items-center h-100">
                    <div>
                        <div class="card-title">Total Keluar</div>
                        <div class="card-value">Rp <?= number_format($total_keluar,0,',','.') ?></div>
                        <small><?= (int)($qS['cnt_keluar']??0) ?> transaksi</small>
                    </div>
                    <div class="stat-icon"><i class="bi bi-arrow-up-circle-fill"></i></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card-box border-start border-primary border-4 h-100">
                <div class="d-flex justify-content-between align-items-center h-100">
                    <div>
                        <div class="card-title">Saldo Periode</div>
                        <div class="card-value">Rp <?= number_format($saldo,0,',','.') ?></div>
                        <small>Masuk - Keluar</small>
                    </div>
                    <div class="stat-icon"><i class="bi bi-wallet-fill"></i></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card-box border-start border-warning border-4 h-100">
                <div class="d-flex justify-content-between align-items-center h-100">
                    <div>
                        <div class="card-title">Capaian Target</div>
                        <div class="card-value"><?= $pct_target ?>%</div>
                        <small><?= $lunas ?>/<?= $total_murid ?> murid lunas</small>
                    </div>
                    <div class="stat-icon"><i class="bi bi-bullseye"></i></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-12">
            <div class="table-box h-100">
                <div class="table-box-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-graph-up-arrow me-2"></i>Tren Arus Kas</span>
                    <small class="text-muted"><?= htmlspecialchars('Per bulan dalam tahun ' . $tahun_grafik) ?></small>
                </div>
                <div class="p-3 p-md-4">
                    <div style="position:relative;height:300px;">
                        <canvas id="chartLaporanTren"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- BREAKDOWN KATEGORI PENGELUARAN -->
        <?php if (!empty($pengeluaran_kategori)): ?>
        <div class="col-lg-5">
            <div class="table-box h-100">
                <div class="table-box-header">
                    <span><i class="bi bi-pie-chart me-2"></i>Pengeluaran per Kategori</span>
                </div>
                <div class="p-3">
                    <div style="position:relative;height:220px;">
                        <canvas id="chartKategori"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
        <?php else: ?>
        <div class="col-lg-12">
        <?php endif; ?>
            <div class="table-box h-100">
                <div class="table-box-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-bullseye me-2"></i>Progress Target Kas</span>
                    <small class="text-muted">Kas wajib: Rp <?= number_format($kas_wajib,0,',','.') ?></small>
                </div>
                <div class="p-3">
                    <div class="d-flex justify-content-between mb-1 small">
                        <span class="text-muted">Terkumpul</span>
                        <span class="fw-bold"><?= $pct_target ?>% — Rp <?= number_format($total_masuk,0,',','.') ?></span>
                    </div>
                    <div class="progress mb-3" style="height:12px;border-radius:999px;">
                        <div class="progress-bar bg-success" style="width:<?= $pct_target ?>%"></div>
                    </div>
                    <div class="d-flex justify-content-between small text-muted">
                        <span>Target: Rp <?= number_format($target_kas,0,',','.') ?></span>
                        <span><?= $lunas ?>/<?= $total_murid ?> murid lunas</span>
                    </div>
                    <hr class="my-3">
                    <div class="row g-2 text-center">
                        <div class="col-4">
                            <div class="fw-bold fs-5 text-success"><?= $lunas ?></div>
                            <small class="text-muted">Lunas</small>
                        </div>
                        <div class="col-4">
                            <div class="fw-bold fs-5 text-warning"><?= $sebagian ?></div>
                            <small class="text-muted">Sebagian</small>
                        </div>
                        <div class="col-4">
                            <div class="fw-bold fs-5 text-danger"><?= $belum ?></div>
                            <small class="text-muted">Belum Bayar</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- TABEL REKAP MURID -->
        <div class="col-lg-12">
            <div class="table-box h-100">
                <div class="table-box-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-people me-2"></i>Status Pembayaran Murid</span>
                    <div class="d-flex gap-2">
                        <span class="badge rounded-pill px-3 py-2" style="background:#d1fae5;color:#065f46;">Lunas: <?= $lunas ?></span>
                        <span class="badge rounded-pill px-3 py-2" style="background:#fef9c3;color:#854d0e;">Sebagian: <?= $sebagian ?></span>
                        <span class="badge rounded-pill px-3 py-2" style="background:#fee2e2;color:#991b1b;">Belum: <?= $belum ?></span>
                    </div>
                </div>
                <div class="p-2" style="max-height:360px;overflow-y:auto;">
                    <table class="table table-hover align-middle small mb-0">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th>Nama Murid</th>
                                <th class="text-end">Bayar Periode</th>
                                <th class="text-end">Sisa</th>
                                <th class="text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!empty($rekap_murid)): foreach ($rekap_murid as $r): ?>
                        <tr>
                            <td class="fw-semibold"><?= htmlspecialchars($r['nama']) ?></td>
                            <td class="text-end <?= $r['bayar'] >= $kas_wajib ? 'text-success' : ($r['bayar'] > 0 ? 'text-warning' : 'text-danger') ?> fw-semibold">
                                Rp <?= number_format($r['bayar'],0,',','.') ?>
                            </td>
                            <td class="text-end text-muted">
                                <?= $r['sisa'] > 0 ? 'Rp '.number_format($r['sisa'],0,',','.') : '—' ?>
                            </td>
                            <td class="text-center">
                                <?php if ($r['status']==='Lunas'): ?>
                                    <span class="status-badge-soft success">Lunas</span>
                                <?php elseif ($r['status']==='Sebagian'): ?>
                                    <span class="status-badge-soft warning">Sebagian</span>
                                <?php else: ?>
                                    <span class="status-badge-soft danger">Belum Bayar</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; else: ?>
                        <tr><td colspan="4" class="text-center py-4 text-muted">Tidak ada data murid.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const trenCanvas = document.getElementById('chartLaporanTren');
    if (trenCanvas) {
        new Chart(trenCanvas, {
            type: 'bar',
            data: {
                labels: <?= json_encode($grafik_label, JSON_UNESCAPED_UNICODE) ?>,
                datasets: [
                    { label: 'Kas Masuk', data: <?= json_encode($grafik_masuk) ?>, backgroundColor: 'rgba(34,197,94,0.75)', borderColor: '#16a34a', borderWidth: 1, borderRadius: 8 },
                    { label: 'Kas Keluar', data: <?= json_encode($grafik_keluar) ?>, backgroundColor: 'rgba(239,68,68,0.72)', borderColor: '#dc2626', borderWidth: 1, borderRadius: 8 }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: { position: 'bottom' } },
                scales: { y: { beginAtZero: true, ticks: { callback: v => 'Rp ' + Number(v).toLocaleString('id-ID') } } }
            }
        });
    }

    <?php if (!empty($pengeluaran_kategori)): ?>
    const katCanvas = document.getElementById('chartKategori');
    if (katCanvas) {
        new Chart(katCanvas, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode(array_keys($pengeluaran_kategori), JSON_UNESCAPED_UNICODE) ?>,
                datasets: [{
                    data: <?= json_encode(array_values($pengeluaran_kategori)) ?>,
                    backgroundColor: ['#6366f1','#f59e0b','#10b981','#ef4444','#3b82f6','#8b5cf6','#ec4899','#14b8a6','#f97316'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false, cutout: '55%',
                plugins: {
                    legend: { position: 'bottom', labels: { font: { size: 11 }, boxWidth: 12 } },
                    tooltip: { callbacks: { label: ctx => ctx.label + ': Rp ' + Number(ctx.raw).toLocaleString('id-ID') } }
                }
            }
        });
    }
    <?php endif; ?>
});
</script>

<?php include "layout/footer.php"; ?>
