<?php
require_once "config/koneksi.php";
require_once "config/auth.php";
require_once "config/pengajuan.php";
require_access('pengajuan_pengeluaran');
require_once "layout/header.php";

ensure_pengajuan_pengeluaran_table($conn);

$errors = [];
$successMessage = '';
$isWaliKelas = is_wali_kelas();

$qSaldo = mysqli_query($conn, "
    SELECT 
        COALESCE(SUM(CASE WHEN jenis='Masuk' THEN jumlah ELSE 0 END),0) -
        COALESCE(SUM(CASE WHEN jenis='Keluar' THEN jumlah ELSE 0 END),0) AS saldo_total
    FROM transaksi
");
$saldoSaatIni = ($qSaldo && $rowSaldo = mysqli_fetch_assoc($qSaldo)) ? (int) ($rowSaldo['saldo_total'] ?? 0) : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isWaliKelas) {
    $aksi = $_POST['aksi'] ?? '';
    $idPengajuan = (int) ($_POST['id_pengajuan'] ?? 0);
    $catatanWali = trim($_POST['catatan_wali'] ?? '');

    if ($idPengajuan <= 0 || !in_array($aksi, ['setujui', 'tolak'], true)) {
        $errors[] = "Aksi pengajuan tidak valid.";
    } else {
        $stmt = mysqli_prepare($conn, "SELECT * FROM pengajuan_pengeluaran WHERE id_pengajuan = ? AND status = 'Menunggu' LIMIT 1");
        mysqli_stmt_bind_param($stmt, "i", $idPengajuan);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $pengajuan = $result ? mysqli_fetch_assoc($result) : null;

        if (!$pengajuan) {
            $errors[] = "Pengajuan tidak ditemukan atau sudah diproses.";
        } elseif ($aksi === 'setujui') {
            $jumlahPengajuan = (int) ($pengajuan['jumlah'] ?? 0);

            if ($jumlahPengajuan > $saldoSaatIni) {
                $errors[] = "Saldo saat ini tidak cukup untuk menyetujui pengajuan tersebut.";
            } else {
                mysqli_begin_transaction($conn);

                try {
                    $tanggalTransaksi = tanggal_transaksi_dari_pengajuan($pengajuan);
                    $kategori = (string) ($pengajuan['kategori'] ?? 'Lainnya');
                    $keterangan = (string) $pengajuan['keterangan'];
                    $buktiFile = $pengajuan['bukti_file'] ?? null;

                    $stmtInsert = mysqli_prepare($conn, "
                        INSERT INTO transaksi (id_murid, kategori, tanggal, bulan, tahun, jenis, jumlah, keterangan, bukti_file)
                        VALUES (NULL, ?, ?, ?, ?, 'Keluar', ?, ?, ?)
                    ");

                    if (!$stmtInsert) {
                        throw new RuntimeException("Gagal menyiapkan transaksi pengeluaran.");
                    }

                    mysqli_stmt_bind_param(
                        $stmtInsert,
                        "ssiiiss",
                        $kategori,
                        $tanggalTransaksi['tanggal'],
                        $tanggalTransaksi['bulan'],
                        $tanggalTransaksi['tahun'],
                        $jumlahPengajuan,
                        $keterangan,
                        $buktiFile
                    );

                    if (!mysqli_stmt_execute($stmtInsert)) {
                        throw new RuntimeException("Gagal menyimpan transaksi pengeluaran.");
                    }

                    $idTransaksi = (int) mysqli_insert_id($conn);
                    $statusBaru = 'Disetujui';
                    $waliId = (int) ($_SESSION['user_id'] ?? 0);
                    $stmtUpdate = mysqli_prepare($conn, "
                        UPDATE pengajuan_pengeluaran
                        SET status = ?, catatan_wali = ?, disetujui_oleh_id = ?, id_transaksi_keluar = ?, decided_at = NOW()
                        WHERE id_pengajuan = ?
                    ");

                    if (!$stmtUpdate) {
                        throw new RuntimeException("Gagal memperbarui status pengajuan.");
                    }

                    mysqli_stmt_bind_param($stmtUpdate, "ssiii", $statusBaru, $catatanWali, $waliId, $idTransaksi, $idPengajuan);

                    if (!mysqli_stmt_execute($stmtUpdate)) {
                        throw new RuntimeException("Gagal memperbarui status pengajuan.");
                    }

                    mysqli_commit($conn);
                    $_SESSION['flash_success_pengajuan'] = "Pengajuan berhasil disetujui dan dicatat sebagai kas keluar.";
                    header("Location: pengajuan_pengeluaran.php");
                    exit;
                } catch (Throwable $e) {
                    mysqli_rollback($conn);
                    $errors[] = $e->getMessage();
                }
            }
        } else {
            $statusBaru = 'Ditolak';
            $waliId = (int) ($_SESSION['user_id'] ?? 0);
            $stmtUpdate = mysqli_prepare($conn, "
                UPDATE pengajuan_pengeluaran
                SET status = ?, catatan_wali = ?, disetujui_oleh_id = ?, decided_at = NOW()
                WHERE id_pengajuan = ?
            ");

            if ($stmtUpdate) {
                mysqli_stmt_bind_param($stmtUpdate, "ssii", $statusBaru, $catatanWali, $waliId, $idPengajuan);
            }

            if ($stmtUpdate && mysqli_stmt_execute($stmtUpdate)) {
                $_SESSION['flash_success_pengajuan'] = "Pengajuan berhasil ditolak.";
                header("Location: pengajuan_pengeluaran.php");
                exit;
            }

            $errors[] = "Gagal memperbarui status pengajuan.";
        }
    }
}

if (!empty($_SESSION['flash_success_pengajuan'])) {
    $successMessage = (string) $_SESSION['flash_success_pengajuan'];
    unset($_SESSION['flash_success_pengajuan']);
}

$qSummary = mysqli_query($conn, "
    SELECT
        COUNT(*) AS total_pengajuan,
        SUM(CASE WHEN status = 'Menunggu' THEN 1 ELSE 0 END) AS total_menunggu,
        SUM(CASE WHEN status = 'Disetujui' THEN 1 ELSE 0 END) AS total_disetujui,
        SUM(CASE WHEN status = 'Ditolak' THEN 1 ELSE 0 END) AS total_ditolak
    FROM pengajuan_pengeluaran
");
$summary = $qSummary ? mysqli_fetch_assoc($qSummary) : [];
$totalPengajuan = (int) ($summary['total_pengajuan'] ?? 0);
$totalMenunggu = (int) ($summary['total_menunggu'] ?? 0);
$totalDisetujui = (int) ($summary['total_disetujui'] ?? 0);
$totalDitolak = (int) ($summary['total_ditolak'] ?? 0);

$qPengajuan = mysqli_query($conn, "
    SELECT
        p.*,
        COALESCE(NULLIF(TRIM(u.nama), ''), NULLIF(TRIM(u.username), '')) AS diajukan_oleh_nama_tampil,
        COALESCE(NULLIF(TRIM(uu.nama), ''), NULLIF(TRIM(uu.username), '')) AS disetujui_oleh_nama_tampil
    FROM pengajuan_pengeluaran p
    LEFT JOIN user u ON p.diajukan_oleh_id = u.id_user
    LEFT JOIN user uu ON p.disetujui_oleh_id = uu.id_user
    ORDER BY
        CASE p.status
            WHEN 'Menunggu' THEN 0
            WHEN 'Disetujui' THEN 1
            ELSE 2
        END,
        p.created_at DESC
");

$daftarPengajuan = [];
if ($qPengajuan) {
    while ($row = mysqli_fetch_assoc($qPengajuan)) {
        $daftarPengajuan[] = $row;
    }
}
?>

<?php include "layout/sidebar.php"; ?>

<div class="content">
    <div class="page-shell">
        <div class="page-hero animate-fade-in">
            <div class="page-hero-copy">
                <h2 class="fw-bold mb-1">Pengajuan Pengeluaran</h2>
                <p>Pengeluaran di atas Rp <?= number_format(LIMIT_PENGAJUAN_WALI_KELAS, 0, ',', '.') ?> harus disetujui wali kelas terlebih dahulu.</p>
            </div>
            <div class="glass-card balance-pill balance-pill-primary">
                <span class="balance-pill-label">Saldo Tersedia</span>
                <h4 class="fw-bold text-primary mb-0">Rp <?= number_format($saldoSaatIni, 0, ',', '.') ?></h4>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger border-0 shadow-sm mb-0">
                <ul class="mb-0 small ps-3"><?php foreach ($errors as $error) echo '<li>' . htmlspecialchars($error) . '</li>'; ?></ul>
            </div>
        <?php endif; ?>

        <?php if ($successMessage !== ''): ?>
            <div class="alert alert-success border-0 shadow-sm mb-0"><?= htmlspecialchars($successMessage) ?></div>
        <?php endif; ?>

        <div class="row g-4 animate-fade-in" style="animation-delay: 0.1s;">
            <div class="col-md-3 d-flex">
                <div class="card-box border-start border-primary border-4 w-100 d-flex flex-column justify-content-between">
                    <div class="d-flex justify-content-between align-items-center h-100">
                        <div>
                            <div class="card-title">Total Pengajuan</div>
                            <div class="card-value"><?= $totalPengajuan ?></div>
                            <small>Seluruh permintaan tercatat</small>
                        </div>
                        <div class="stat-icon"><i class="bi bi-journal-text"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 d-flex">
                <div class="card-box border-start border-warning border-4 w-100 d-flex flex-column justify-content-between">
                    <div class="d-flex justify-content-between align-items-center h-100">
                        <div>
                            <div class="card-title">Menunggu</div>
                            <div class="card-value"><?= $totalMenunggu ?></div>
                            <small>Butuh tindakan wali kelas</small>
                        </div>
                        <div class="stat-icon"><i class="bi bi-hourglass-split"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 d-flex">
                <div class="card-box border-start border-success border-4 w-100 d-flex flex-column justify-content-between">
                    <div class="d-flex justify-content-between align-items-center h-100">
                        <div>
                            <div class="card-title">Disetujui</div>
                            <div class="card-value"><?= $totalDisetujui ?></div>
                            <small>Sudah masuk transaksi keluar</small>
                        </div>
                        <div class="stat-icon"><i class="bi bi-check2-circle"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 d-flex">
                <div class="card-box border-start border-danger border-4 w-100 d-flex flex-column justify-content-between">
                    <div class="d-flex justify-content-between align-items-center h-100">
                        <div>
                            <div class="card-title">Ditolak</div>
                            <div class="card-value"><?= $totalDitolak ?></div>
                            <small>Belum bisa dicairkan</small>
                        </div>
                        <div class="stat-icon"><i class="bi bi-x-octagon"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="table-panel animate-fade-in" style="animation-delay: 0.2s;">
            <div class="table-panel-header">
                <div>
                    <div class="table-panel-title">Daftar Pengajuan</div>
                    <p class="table-panel-subtitle"><?= $isWaliKelas ? 'Setujui atau tolak pengajuan yang masuk dari bendahara.' : 'Pantau status pengajuan pengeluaran yang butuh persetujuan wali kelas.' ?></p>
                </div>
                <span class="summary-chip"><i class="bi bi-shield-check"></i>Limit approval Rp <?= number_format(LIMIT_PENGAJUAN_WALI_KELAS, 0, ',', '.') ?></span>
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Tanggal</th>
                            <th>Pengaju</th>
                            <th>Keterangan</th>
                            <th class="text-end">Jumlah</th>
                            <th>Status</th>
                            <th><?= $isWaliKelas ? 'Aksi Wali Kelas' : 'Catatan' ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($daftarPengajuan)): ?>
                            <?php foreach ($daftarPengajuan as $row): ?>
                                <?php $statusMeta = format_status_pengajuan((string) $row['status']); ?>
                                <tr>
                                    <td class="small fw-semibold">
                                        <?= htmlspecialchars(format_tanggal_pengajuan($row)) ?><br>
                                        <span class="text-muted"><?= htmlspecialchars(format_jam_pengajuan($row)) ?></span>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-dark"><?= htmlspecialchars($row['diajukan_oleh_nama_tampil'] ?: ('ID ' . (int) ($row['diajukan_oleh_id'] ?? 0))) ?></div>
                                        <div class="text-muted small">Pengaju</div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-dark"><?= htmlspecialchars($row['keterangan']) ?></div>
                                        <?php if (!empty($row['disetujui_oleh_nama_tampil'])): ?>
                                            <div class="text-muted small mt-1">Diproses oleh <?= htmlspecialchars($row['disetujui_oleh_nama_tampil']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end fw-bold text-danger">Rp <?= number_format((int) $row['jumlah'], 0, ',', '.') ?></td>
                                    <td>
                                        <span class="status-badge-soft <?= $statusMeta['class'] ?>">
                                            <i class="bi <?= $statusMeta['icon'] ?>"></i>
                                            <?= htmlspecialchars($row['status']) ?>
                                        </span>
                                    </td>
                                    <td style="min-width: 280px;">
                                        <?php if ($isWaliKelas && $row['status'] === 'Menunggu'): ?>
                                            <form method="POST" class="d-grid gap-2">
                                                <input type="hidden" name="id_pengajuan" value="<?= (int) $row['id_pengajuan'] ?>">
                                                <textarea name="catatan_wali" class="form-control form-soft-control" rows="2" placeholder="Catatan wali kelas (opsional)"></textarea>
                                                <div class="d-flex gap-2">
                                                    <button type="submit" name="aksi" value="setujui" class="btn btn-success w-100">Setujui</button>
                                                    <button type="submit" name="aksi" value="tolak" class="btn btn-outline-danger w-100">Tolak</button>
                                                </div>
                                            </form>
                                        <?php else: ?>
                                            <div class="small text-muted">
                                                <?= !empty($row['catatan_wali']) ? nl2br(htmlspecialchars($row['catatan_wali'])) : 'Belum ada catatan.' ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6">
                                    <div class="empty-state">
                                        <i class="bi bi-inbox"></i>
                                        Belum ada pengajuan pengeluaran.
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include "layout/footer.php"; ?>
