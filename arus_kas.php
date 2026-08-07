<?php
include "config/koneksi.php";
require_once "config/auth.php";
require_once "config/kas.php";
require_access('arus_kas');
include "layout/header.php";

// Handle hapus transaksi (khusus bendahara)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hapus_id']) && can_access('kas_masuk')) {
    $hapus_id = (int) $_POST['hapus_id'];
    if ($hapus_id > 0) {
        $stmt = mysqli_prepare($conn, "DELETE FROM transaksi WHERE id_pembayaran = ?");
        mysqli_stmt_bind_param($stmt, "i", $hapus_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
    // Redirect biar ga resubmit
    $qs = http_build_query(array_diff_key($_GET, ['hapus_id' => '']));
    header("Location: arus_kas.php" . ($qs ? "?$qs" : ''));
    exit;
}

$filter_bulan = $_GET['bulan'] ?? date('m');
$filter_tahun = $_GET['tahun'] ?? date('Y');
$filter_jenis = $_GET['jenis'] ?? '';
$search       = $_GET['search'] ?? '';

$nama_bulan_raw = nama_bulan_indonesia();
$nama_bulan = [];
foreach ($nama_bulan_raw as $k => $v) {
    $nama_bulan[str_pad((string)$k, 2, '0', STR_PAD_LEFT)] = $v;
}

$where = "WHERE 1=1";
if ($filter_bulan != '') { $where .= " AND MONTH(t.tanggal) = '$filter_bulan'"; }
if ($filter_tahun != '') { $where .= " AND YEAR(t.tanggal) = '$filter_tahun'"; }
if ($filter_jenis != '') { $where .= " AND t.jenis = '$filter_jenis'"; }
if ($search != '') {
    $s = mysqli_real_escape_string($conn, $search);
    $where .= " AND (m.nama LIKE '%$s%' OR t.keterangan LIKE '%$s%')";
}

$qSummary = mysqli_query($conn, "
    SELECT 
        SUM(CASE WHEN jenis='Masuk' THEN jumlah ELSE 0 END) as total_masuk,
        SUM(CASE WHEN jenis='Keluar' THEN jumlah ELSE 0 END) as total_keluar
    FROM transaksi t
    LEFT JOIN murid m ON t.id_murid = m.id_murid
    $where
");
$summary = mysqli_fetch_assoc($qSummary);
$total_masuk = (int) ($summary['total_masuk'] ?? 0);
$total_keluar = (int) ($summary['total_keluar'] ?? 0);
$saldo_periode = $total_masuk - $total_keluar;

$qSaldoTotal = mysqli_query($conn, "
    SELECT SUM(CASE WHEN jenis='Masuk' THEN jumlah ELSE 0 END) - SUM(CASE WHEN jenis='Keluar' THEN jumlah ELSE 0 END) as saldo 
    FROM transaksi
");
$saldo_total = (int) (mysqli_fetch_assoc($qSaldoTotal)['saldo'] ?? 0);

$per_page = 25;
$page     = (int) max(1, (int) ($_GET['page'] ?? 1));
$offset   = (int) (($page - 1) * $per_page);

$qCount = mysqli_query($conn, "
    SELECT COUNT(*) as total
    FROM transaksi t
    LEFT JOIN murid m ON t.id_murid = m.id_murid
    $where
");
$total_rows  = (int) ($qCount ? mysqli_fetch_assoc($qCount)['total'] : 0);
$total_pages = (int) max(1, ceil($total_rows / $per_page));

$qTransaksi = mysqli_query($conn, "
    SELECT t.*, m.nama AS nama_murid
    FROM transaksi t
    LEFT JOIN murid m ON t.id_murid = m.id_murid
    $where
    ORDER BY t.tanggal DESC
    LIMIT $per_page OFFSET $offset
");
?>

<?php include "layout/sidebar.php"; ?>

<div class="content">
    <div class="container-fluid animate-fade-in">

        <div class="top-header d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4>Arus Kas</h4>
                <small class="text-white-50">
                    <i class="bi bi-graph-up me-1"></i> Ringkasan transaksi masuk dan keluar
                </small>
            </div>
            <div class="text-end">
                <strong>Saldo Kas</strong><br>
                <small>Rp <?= number_format($saldo_total, 0, ',', '.') ?></small>
            </div>
        </div>


        <div class="filter-panel mb-4">
            <form method="GET" action="" class="row g-3 align-items-end">
                <div class="col-lg-4">
                    <label class="form-label text-muted small fw-bold text-uppercase">Cari Transaksi</label>
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" class="form-control form-soft-control" placeholder="Nama murid atau keterangan">
                </div>
                <div class="col-md-3 col-lg-2">
                    <label class="form-label text-muted small fw-bold text-uppercase">Bulan</label>
                    <select name="bulan" class="form-select form-soft-control">
                        <option value="">Semua Bulan</option>
                        <?php foreach ($nama_bulan as $k => $v): ?>
                            <option value="<?= $k ?>" <?= $filter_bulan == $k ? 'selected' : '' ?>><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 col-lg-2">
                    <label class="form-label text-muted small fw-bold text-uppercase">Tahun</label>
                    <select name="tahun" class="form-select form-soft-control">
                        <?php for ($y = (int)date('Y') + 1; $y >= 2025; $y--): ?>
                            <option value="<?= $y ?>" <?= $filter_tahun == $y ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-3 col-lg-2">
                    <label class="form-label text-muted small fw-bold text-uppercase">Jenis</label>
                    <select name="jenis" class="form-select form-soft-control">
                        <option value="">Semua</option>
                        <option value="Masuk" <?= $filter_jenis == 'Masuk' ? 'selected' : '' ?>>Masuk</option>
                        <option value="Keluar" <?= $filter_jenis == 'Keluar' ? 'selected' : '' ?>>Keluar</option>
                    </select>
                </div>
                <div class="col-md-3 col-lg-2">
                    <div class="filter-panel-actions">
                        <button type="submit" class="btn-premium w-100 py-3">
                            <i class="bi bi-search me-2"></i>Filter
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <div class="row g-4 mb-4 align-items-stretch">
            <div class="col-md-4 d-flex">
                <div class="card-box border-start border-primary border-4 w-100 d-flex flex-column justify-content-between">
                    <div class="d-flex justify-content-between align-items-center h-100">
                        <div>
                            <div class="card-title">Total Kas Masuk</div>
                            <div class="card-value">Rp <?= number_format($total_masuk, 0, ',', '.') ?></div>
                            <small>Akumulasi sesuai filter</small>
                        </div>
                        <div class="stat-icon"><i class="bi bi-arrow-down-circle-fill"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 d-flex">
                <div class="card-box border-start border-danger border-4 w-100 d-flex flex-column justify-content-between">
                    <div class="d-flex justify-content-between align-items-center h-100">
                        <div>
                            <div class="card-title">Total Kas Keluar</div>
                            <div class="card-value">Rp <?= number_format($total_keluar, 0, ',', '.') ?></div>
                            <small>Akumulasi sesuai filter</small>
                        </div>
                        <div class="stat-icon"><i class="bi bi-arrow-up-circle-fill"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 d-flex">
                <div class="card-box border-start border-success border-4 w-100 d-flex flex-column justify-content-between">
                    <div class="d-flex justify-content-between align-items-center h-100">
                        <div>
                            <div class="card-title">Saldo Periode</div>
                            <div class="card-value">Rp <?= number_format($saldo_periode, 0, ',', '.') ?></div>
                            <small>Selisih masuk dan keluar</small>
                        </div>
                        <div class="stat-icon"><i class="bi bi-wallet-fill"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="table-box">
                <div class="table-box-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-list-stars me-2"></i>Rincian Arus Kas</span>
                <span class="badge bg-light text-primary">Filter aktif untuk penelusuran transaksi</span>
            </div>
            <div class="p-3">
                <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th width="50">No</th>
                            <th>Tanggal</th>
                            <th>Nama / Uraian</th>
                            <th>Status</th>
                            <th class="text-end">Nominal</th>
                            <?php if (can_access('kas_masuk')): ?><th width="60"></th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ((int)mysqli_num_rows($qTransaksi) > 0): ?>
                            <?php $no = $offset + 1; ?>
                            <?php while ($r = mysqli_fetch_assoc($qTransaksi)): ?>
                                <?php $jumlah = (int)$r['jumlah']; ?>
                                <tr>
                                    <td><?= $no++ ?></td>
                                    <td>
                                        <div class="fw-semibold text-dark"><?= date('d/m/Y', strtotime($r['tanggal'])) ?></div>
                                        <small class="text-muted"><?= strtoupper(date('D', strtotime($r['tanggal']))) ?></small>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-dark"><?= $r['nama_murid'] ? htmlspecialchars($r['nama_murid']) : 'Umum' ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($r['keterangan'] ?: '-') ?></small>
                                    </td>
                                    <td>
                                        <?php if ($r['jenis'] === 'Masuk'): ?>
                                            <span class="status-badge-soft success"><i class="bi bi-plus-circle"></i>Masuk</span>
                                        <?php else: ?>
                                            <span class="status-badge-soft danger"><i class="bi bi-dash-circle"></i>Keluar</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end fw-bold <?= $r['jenis'] === 'Masuk' ? 'text-success' : 'text-danger' ?>">
                                        <?= $r['jenis'] === 'Masuk' ? '+' : '-' ?> Rp <?= number_format($jumlah, 0, ',', '.') ?>
                                    </td>
                                    <?php if (can_access('kas_masuk')): ?>
                                    <td class="text-center">
                                        <form method="POST" onsubmit="return confirm('Hapus transaksi ini?')">
                                            <input type="hidden" name="hapus_id" value="<?= $r['id_pembayaran'] ?>">
                                            <?php foreach ($_GET as $k => $v): ?>
                                                <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars($v) ?>">
                                            <?php endforeach; ?>
                                            <button type="submit" class="btn btn-sm btn-light text-danger" style="border-radius:8px;" title="Hapus">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="text-center py-5 text-muted">
                                    <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                                    Tidak ada data transaksi yang cocok dengan filter.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>

                <?php if ($total_pages > 1): ?>
                <div class="d-flex justify-content-between align-items-center mt-3 pt-2 border-top small text-muted">
                    <span>Menampilkan <?= $offset + 1 ?>–<?= min($offset + $per_page, $total_rows) ?> dari <?= $total_rows ?> transaksi</span>
                    <nav>
                        <ul class="pagination pagination-sm mb-0">
                            <?php
                            $params = $_GET;
                            for ($p = 1; $p <= $total_pages; $p++):
                                $params['page'] = $p;
                                $url = '?' . http_build_query($params);
                            ?>
                            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                <a class="page-link" href="<?= htmlspecialchars($url) ?>"><?= $p ?></a>
                            </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<?php include "layout/footer.php"; ?>
