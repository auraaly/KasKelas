<?php
include "config/koneksi.php";
require_once "config/auth.php";
require_once "config/kas.php";
require_access('kas_masuk');
include "layout/header.php";

$nama_bulan = nama_bulan_indonesia();
$tahun_pilih = (int) ($_GET['tahun'] ?? date('Y'));
$success = '';
$error = '';

// Auto-migrate
$chk = mysqli_query($conn, "SHOW COLUMNS FROM kas_bulanan LIKE 'catatan'");
if ($chk && mysqli_num_rows($chk) === 0) {
    mysqli_query($conn, "ALTER TABLE kas_bulanan ADD COLUMN catatan VARCHAR(255) DEFAULT '' AFTER nominal");
    $chkIdx = mysqli_query($conn, "SHOW INDEX FROM kas_bulanan WHERE Key_name = 'unique_bulan_tahun'");
    if (!$chkIdx || mysqli_num_rows($chkIdx) === 0)
        mysqli_query($conn, "ALTER TABLE kas_bulanan ADD UNIQUE KEY unique_bulan_tahun (bulan, tahun)");
}

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tahun_post = (int) ($_POST['tahun'] ?? 0);
    if ($tahun_post < 2025) {
        $error = "Tahun tidak valid.";
    } else {
        mysqli_begin_transaction($conn);
        try {
            foreach ($nama_bulan as $num => $label) {
                $nominal  = (int) ($_POST['nominal'][$num] ?? 0);
                $catatan  = mysqli_real_escape_string($conn, trim($_POST['catatan'][$num] ?? ''));
                $stmt = mysqli_prepare($conn, "INSERT INTO kas_bulanan (bulan, tahun, nominal, catatan) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE nominal=VALUES(nominal), catatan=VALUES(catatan)");
                mysqli_stmt_bind_param($stmt, "siis", $label, $tahun_post, $nominal, $catatan);
                if (!mysqli_stmt_execute($stmt)) throw new Exception(mysqli_stmt_error($stmt));
                mysqli_stmt_close($stmt);
            }
            mysqli_commit($conn);
            $success = "Tersimpan.";
            $tahun_pilih = $tahun_post;
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = "Gagal: " . $e->getMessage();
        }
    }
}

// Load data
$dataKas = [];
$q = mysqli_query($conn, "SELECT bulan, nominal, catatan FROM kas_bulanan WHERE tahun = $tahun_pilih");
if ($q) while ($r = mysqli_fetch_assoc($q)) $dataKas[$r['bulan']] = $r;

$default = 20000;
?>

<?php include "layout/sidebar.php"; ?>

<div class="content">
    <div class="container-fluid animate-fade-in">

        <div class="top-header d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4>Pengaturan Kas Bulanan</h4>
                <small class="text-white-50">Atur nominal kas wajib per bulan sesuai minggu aktif</small>
            </div>
            <a href="/KasKelas/kas_masuk.php" class="btn btn-sm btn-light" style="border-radius:8px;">
                <i class="bi bi-arrow-left me-1"></i> Kembali
            </a>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success border-0 py-2 small mb-3"><?= $success ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger border-0 py-2 small mb-3"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="filter-panel mb-3">
            <form method="GET" class="d-flex align-items-center gap-3">
                <label class="text-muted small fw-bold text-uppercase mb-0">Tahun</label>
                <select name="tahun" class="form-select form-select-sm form-soft-control" style="width:100px;" onchange="this.form.submit()">
                    <?php for ($y = (int)date('Y') + 1; $y >= 2025; $y--): ?>
                        <option value="<?= $y ?>" <?= $tahun_pilih == $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
                <small class="text-muted">Default: Rp <?= number_format($default, 0, ',', '.') ?>/bulan (4 minggu)</small>
            </form>
        </div>

        <div class="table-box">
            <div class="table-box-header">
                <span><i class="bi bi-calendar3 me-2"></i>Kas Wajib — <?= $tahun_pilih ?></span>
            </div>
            <div class="p-3">
                <form method="POST">
                    <input type="hidden" name="tahun" value="<?= $tahun_pilih ?>">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-3 small">
                            <thead class="table-light">
                                <tr>
                                    <th>Bulan</th>
                                    <th width="180">Nominal (Rp)</th>
                                    <th>Catatan</th>
                                    <th width="90" class="text-center">Minggu</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($nama_bulan as $num => $label):
                                $nominal = (int) ($dataKas[$label]['nominal'] ?? $default);
                                $catatan = $dataKas[$label]['catatan'] ?? '';
                                $minggu  = $default > 0 ? round($nominal / ($default / 4), 1) : 0;
                            ?>
                                <tr>
                                    <td class="fw-semibold"><?= $label ?></td>
                                    <td>
                                        <div class="input-group input-group-sm">
                                            <span class="input-group-text">Rp</span>
                                            <input type="number" name="nominal[<?= $num ?>]"
                                                class="form-control form-soft-control"
                                                value="<?= $nominal ?>" min="0" step="1000">
                                        </div>
                                    </td>
                                    <td>
                                        <input type="text" name="catatan[<?= $num ?>]"
                                            class="form-control form-control-sm form-soft-control"
                                            value="<?= htmlspecialchars($catatan) ?>"
                                            placeholder="Misal: Libur Lebaran">
                                    </td>
                                    <td class="text-center">
                                        <?php if ($nominal == 0): ?>
                                            <span class="badge bg-danger">Libur</span>
                                        <?php elseif ($nominal < $default): ?>
                                            <span class="badge bg-warning text-dark"><?= $minggu ?> mgg</span>
                                        <?php else: ?>
                                            <span class="badge bg-success">4 mgg</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <button type="submit" class="btn-premium px-5 py-2">
                        <i class="bi bi-save me-2"></i>Simpan
                    </button>
                </form>
            </div>
        </div>

    </div>
</div>

<?php include "layout/footer.php"; ?>
