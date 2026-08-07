<?php
include "config/koneksi.php";
require_once "config/auth.php";
require_once "config/pengajuan.php";
require_access('kas_keluar');
include "layout/header.php";

ensure_pengajuan_pengeluaran_table($conn);

// ==========================
// HITUNG SALDO
// ==========================
$qSaldo = mysqli_query($conn, "
    SELECT 
        COALESCE(SUM(CASE WHEN jenis='Masuk' THEN jumlah ELSE 0 END),0) -
        COALESCE(SUM(CASE WHEN jenis='Keluar' THEN jumlah ELSE 0 END),0) as saldo_total
    FROM transaksi
");
$dataSaldo = mysqli_fetch_assoc($qSaldo);
$saldo_saat_ini = $dataSaldo['saldo_total'] ?? 0;

// ==========================
// HANDLE FORM
// ==========================
$errors = [];
$successMessage = '';
$isWaliKelas = is_wali_kelas();
$kategoriList = daftar_kategori_pengeluaran();

if (isset($_POST['submit']) || isset($_POST['ajukan'])) {
    $tanggal    = $_POST['tanggal'] ?? '';
    $jumlahInput = trim((string) ($_POST['jumlah'] ?? ''));
    $jumlah     = (int) preg_replace('/\D+/', '', $jumlahInput);
    $kategori   = trim($_POST['kategori'] ?? '');
    $keterangan = trim($_POST['keterangan'] ?? '');
    $bulan      = !empty($tanggal) ? (int) date('n', strtotime($tanggal)) : 0;
    $tahun      = !empty($tanggal) ? (int) date('Y', strtotime($tanggal)) : 0;
    $modeAksi   = isset($_POST['ajukan']) ? 'ajukan' : 'simpan';
    $buktiFilePath = null;

    if (empty($tanggal)) $errors[] = "Tanggal harus diisi!";
    if (empty($jumlah) || $jumlah <= 0) $errors[] = "Jumlah tidak valid!";
    if ($kategori === '') $errors[] = "Kategori wajib dipilih!";
    if (empty($keterangan)) $errors[] = "Keterangan wajib diisi!";
    if ($kategori !== '' && !in_array($kategori, $kategoriList, true)) $errors[] = "Kategori tidak valid!";

    if (empty($errors) && $modeAksi === 'simpan' && $jumlah > LIMIT_PENGAJUAN_WALI_KELAS && !is_kategori_rutin($kategori)) {
        $errors[] = "Nominal di atas Rp " . number_format(LIMIT_PENGAJUAN_WALI_KELAS, 0, ',', '.') . " harus diajukan manual ke wali kelas lewat tombol Ajukan ke Wali Kelas.";
    }

    if (empty($errors) && $modeAksi === 'simpan' && $jumlah > $saldo_saat_ini) {
        $errors[] = "Saldo tidak cukup. Saldo tersedia saat ini Rp " . number_format((int) $saldo_saat_ini, 0, ',', '.') . ".";
    }

    if (empty($errors)) {
        try {
            $buktiFilePath = simpan_bukti_pengeluaran($_FILES['bukti_file'] ?? []);
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }

    if (empty($errors) && $modeAksi === 'ajukan') {
        $stmt = mysqli_prepare($conn, "
            INSERT INTO pengajuan_pengeluaran (
                jumlah, kategori, keterangan, bukti_file, diajukan_oleh_id, diajukan_oleh_nama, created_at
            )
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        if (!$stmt) {
            $errors[] = "Gagal menyiapkan pengajuan: " . mysqli_error($conn);
        } else {
            $pengajuId   = (int) ($_SESSION['user_id'] ?? 0);
            $pengajuNama = current_user_name();
            $createdAtPengajuan = created_at_pengajuan_dari_input($tanggal);
            mysqli_stmt_bind_param($stmt, "isssiss", $jumlah, $kategori, $keterangan, $buktiFilePath, $pengajuId, $pengajuNama, $createdAtPengajuan);

            if (mysqli_stmt_execute($stmt)) {
                $successMessage = "Pengajuan berhasil dikirim ke wali kelas.";
            } else {
                $errors[] = "Gagal mengirim pengajuan: " . mysqli_error($conn);
            }
        }
    }

    if (empty($errors) && $modeAksi === 'simpan' && $successMessage === '') {
        $stmt = mysqli_prepare($conn, "
            INSERT INTO transaksi (id_murid, kategori, tanggal, bulan, tahun, jenis, jumlah, keterangan, bukti_file)
            VALUES (NULL, ?, ?, ?, ?, 'Keluar', ?, ?, ?)
        ");

        if (!$stmt) {
            $errors[] = "Gagal menyiapkan transaksi pengeluaran: " . mysqli_error($conn);
        } else {
            mysqli_stmt_bind_param($stmt, "ssiiiss", $kategori, $tanggal, $bulan, $tahun, $jumlah, $keterangan, $buktiFilePath);

            if (mysqli_stmt_execute($stmt)) {
                $successMessage = "Pengeluaran berhasil disimpan.";
            } else {
                $errors[] = "Gagal simpan: " . mysqli_stmt_error($stmt);
            }
        }
    }

    if ($successMessage !== '' && empty($errors)) {
        $_SESSION['flash_success'] = $successMessage;
        header("Location: kas_keluar.php");
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isWaliKelas && isset($_POST['aksi_pengajuan'])) {
    $aksiPengajuan = $_POST['aksi_pengajuan'] ?? '';
    $idPengajuan = (int) ($_POST['id_pengajuan'] ?? 0);
    $catatanWali = trim($_POST['catatan_wali'] ?? '');

    if ($idPengajuan <= 0 || !in_array($aksiPengajuan, ['setujui', 'tolak'], true)) {
        $errors[] = "Aksi pengajuan tidak valid.";
    } else {
        $stmtCari = mysqli_prepare($conn, "SELECT * FROM pengajuan_pengeluaran WHERE id_pengajuan = ? AND status = 'Menunggu' LIMIT 1");
        mysqli_stmt_bind_param($stmtCari, "i", $idPengajuan);
        mysqli_stmt_execute($stmtCari);
        $resultCari = mysqli_stmt_get_result($stmtCari);
        $dataPengajuan = $resultCari ? mysqli_fetch_assoc($resultCari) : null;

        if (!$dataPengajuan) {
            $errors[] = "Pengajuan tidak ditemukan atau sudah diproses.";
        } elseif ($aksiPengajuan === 'setujui') {
            $jumlahPengajuan = (int) ($dataPengajuan['jumlah'] ?? 0);

            if ($jumlahPengajuan > $saldo_saat_ini) {
                $errors[] = "Saldo saat ini tidak cukup untuk menyetujui pengajuan. Saldo tersedia Rp " . number_format((int) $saldo_saat_ini, 0, ',', '.') . ".";
            } else {
                mysqli_begin_transaction($conn);

                try {
                    $tanggalTransaksi = tanggal_transaksi_dari_pengajuan($dataPengajuan);
                    $kategoriPengajuan = (string) ($dataPengajuan['kategori'] ?? 'Lainnya');
                    $keteranganPengajuan = (string) $dataPengajuan['keterangan'];
                    $buktiPengajuan = $dataPengajuan['bukti_file'] ?? null;

                    $stmtTransaksi = mysqli_prepare($conn, "
                        INSERT INTO transaksi (id_murid, kategori, tanggal, bulan, tahun, jenis, jumlah, keterangan, bukti_file)
                        VALUES (NULL, ?, ?, ?, ?, 'Keluar', ?, ?, ?)
                    ");

                    if (!$stmtTransaksi) {
                        throw new RuntimeException("Gagal menyiapkan transaksi pengeluaran.");
                    }

                    mysqli_stmt_bind_param(
                        $stmtTransaksi,
                        "ssiiiss",
                        $kategoriPengajuan,
                        $tanggalTransaksi['tanggal'],
                        $tanggalTransaksi['bulan'],
                        $tanggalTransaksi['tahun'],
                        $jumlahPengajuan,
                        $keteranganPengajuan,
                        $buktiPengajuan
                    );

                    if (!mysqli_stmt_execute($stmtTransaksi)) {
                        throw new RuntimeException("Gagal mencatat transaksi pengeluaran.");
                    }

                    $idTransaksiKeluar = (int) mysqli_insert_id($conn);
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

                    mysqli_stmt_bind_param($stmtUpdate, "ssiii", $statusBaru, $catatanWali, $waliId, $idTransaksiKeluar, $idPengajuan);

                    if (!mysqli_stmt_execute($stmtUpdate)) {
                        throw new RuntimeException("Gagal memperbarui status pengajuan.");
                    }

                    mysqli_commit($conn);
                    $_SESSION['flash_success'] = "Pengajuan berhasil disetujui dan dicatat sebagai kas keluar.";
                    header("Location: kas_keluar.php");
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
                $_SESSION['flash_success'] = "Pengajuan berhasil ditolak.";
                header("Location: kas_keluar.php");
                exit;
            }

            $errors[] = "Gagal memperbarui status pengajuan." . ($stmtUpdate ? '' : ' ' . mysqli_error($conn));
        }
    }
}

if (!empty($_SESSION['flash_success'])) {
    $successMessage = (string) $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

$qSummaryPengajuan = mysqli_query($conn, "
    SELECT
        SUM(CASE WHEN status = 'Menunggu' THEN 1 ELSE 0 END) AS total_menunggu,
        SUM(CASE WHEN status = 'Disetujui' THEN 1 ELSE 0 END) AS total_disetujui,
        SUM(CASE WHEN status = 'Ditolak' THEN 1 ELSE 0 END) AS total_ditolak
    FROM pengajuan_pengeluaran
");
$summaryPengajuan = $qSummaryPengajuan ? mysqli_fetch_assoc($qSummaryPengajuan) : [];
$totalMenunggu = (int) ($summaryPengajuan['total_menunggu'] ?? 0);
$totalDisetujui = (int) ($summaryPengajuan['total_disetujui'] ?? 0);
$totalDitolak = (int) ($summaryPengajuan['total_ditolak'] ?? 0);

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
    LIMIT 12
");

$daftarPengajuan = [];
if ($qPengajuan) {
    while ($row = mysqli_fetch_assoc($qPengajuan)) {
        $daftarPengajuan[] = $row;
    }
}

// ==========================
// STATISTIK
// ==========================
$bln = date('m');
$thn = date('Y');
$tgl = date('Y-m-d');

$total_keluar = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COALESCE(SUM(jumlah),0) as t FROM transaksi WHERE jenis='Keluar'
"))['t'];

$keluar_bulan = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COALESCE(SUM(jumlah),0) as t FROM transaksi 
    WHERE jenis='Keluar' AND bulan='$bln' AND tahun='$thn'
"))['t'];

$keluar_hari = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COALESCE(SUM(jumlah),0) as t FROM transaksi 
    WHERE jenis='Keluar' AND DATE(tanggal)='$tgl'
"))['t'];

// ==========================
// RIWAYAT
// ==========================
$qRecent = mysqli_query($conn, "
    SELECT * FROM transaksi 
    WHERE jenis='Keluar'
    ORDER BY id_pembayaran DESC, tanggal DESC
    LIMIT 10
");

if (!$qRecent) {
    die("Query Error: " . mysqli_error($conn));
}
?>

<?php include "layout/sidebar.php"; ?>

<div class="content">
    <div class="container-fluid animate-fade-in page-shell">

        <div class="page-hero">
            <div class="page-hero-copy">
                <h2 class="fw-bold">
                    <span class="page-title-gradient-primary">
                        <i class="bi bi-arrow-up-circle-fill me-2"></i>Pengeluaran Kas
                    </span>
                </h2>
                <p>Kelola dana keluar kelas dengan susunan visual yang sama rapi seperti halaman pemasukan.</p>
            </div>
            <div class="glass-card balance-pill balance-pill-primary">
                <span class="balance-pill-label">Saldo Kas</span>
                <h4 class="fw-bold text-primary mb-0">Rp <?= number_format($saldo_saat_ini, 0, ',', '.') ?></h4>
            </div>
        </div>

        <div class="glass-card p-3 animate-fade-in">
            <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
                <div class="small text-muted">
                    Pengeluaran di atas <strong>Rp <?= number_format(LIMIT_PENGAJUAN_WALI_KELAS, 0, ',', '.') ?></strong> tidak otomatis diajukan. Gunakan tombol <strong>Ajukan ke Wali Kelas</strong> jika nominal memang perlu persetujuan.
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <span class="summary-chip"><i class="bi bi-hourglass-split"></i><?= $totalMenunggu ?> menunggu</span>
                    <span class="summary-chip"><i class="bi bi-check-circle"></i><?= $totalDisetujui ?> disetujui</span>
                    <span class="summary-chip"><i class="bi bi-x-circle"></i><?= $totalDitolak ?> ditolak</span>
                </div>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger border-0 shadow-sm mb-0">
                <ul class="mb-0 small ps-3"><?php foreach ($errors as $e) echo "<li>$e</li>"; ?></ul>
            </div>
        <?php endif; ?>

        <?php if ($successMessage !== ''): ?>
            <div class="alert alert-success border-0 shadow-sm mb-0">
                <?= htmlspecialchars($successMessage) ?>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="card-box border-danger border-4 shadow-sm h-100">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="card-title mb-1">Total Pengeluaran</p>
                            <h3 class="card-value mb-0">Rp <?= number_format($total_keluar, 0, ',', '.') ?></h3>
                        </div>
                        <i class="bi bi-wallet2 fs-1 opacity-25"></i>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card-box border-danger border-4 shadow-sm h-100">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="card-title mb-1">Bulan Ini</p>
                            <h3 class="card-value mb-0">Rp <?= number_format($keluar_bulan, 0, ',', '.') ?></h3>
                        </div>
                        <i class="bi bi-calendar2-x fs-1 opacity-25"></i>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card-box border-danger border-4 shadow-sm h-100">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="card-title mb-1">Hari Ini</p>
                            <h3 class="card-value mb-0">Rp <?= number_format($keluar_hari, 0, ',', '.') ?></h3>
                        </div>
                        <i class="bi bi-clock-history fs-1 opacity-25"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-5">
                <div class="form-panel">
                    <div class="form-panel-header">
                        <div class="form-panel-icon danger">
                            <i class="bi bi-journal-minus"></i>
                        </div>
                        <div>
                            <div class="form-panel-title"><?= $isWaliKelas ? 'Persetujuan Pengeluaran' : 'Catat Pengeluaran' ?></div>
                            <p class="form-panel-subtitle"><?= $isWaliKelas ? 'Wali kelas dapat memantau dan menyetujui pengajuan langsung dari halaman ini.' : 'Simpan transaksi keluar atau ajukan nominal besar ke wali kelas dari tempat yang sama.' ?></p>
                        </div>
                    </div>

                    <?php if (!$isWaliKelas): ?>
                    <form method="POST" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold text-uppercase">Tanggal Pengeluaran</label>
                            <input type="date" name="tanggal" class="form-control form-soft-control" value="<?= date('Y-m-d') ?>" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold text-uppercase">Nominal</label>
                            <input
                                type="text"
                                name="jumlah"
                                id="inputJumlahKeluar"
                                class="form-control form-soft-control"
                                placeholder="0"
                                inputmode="numeric"
                                autocomplete="off"
                                value="<?= htmlspecialchars((string) ($_POST['jumlah'] ?? '')) ?>"
                                required
                            >
                            <small class="text-muted d-block mt-2">Jika nominal di atas Rp <?= number_format(LIMIT_PENGAJUAN_WALI_KELAS, 0, ',', '.') ?>, gunakan tombol ajukan agar masuk ke persetujuan wali kelas.</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold text-uppercase">Kategori</label>
                            <select name="kategori" class="form-select form-soft-control" required>
                                <option value="">Pilih kategori</option>
                                <?php foreach ($kategoriList as $itemKategori): ?>
                                    <option value="<?= htmlspecialchars($itemKategori) ?>" <?= (($_POST['kategori'] ?? '') === $itemKategori) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($itemKategori) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-4">
                            <label class="form-label text-muted small fw-bold text-uppercase">Keterangan</label>
                            <textarea name="keterangan" class="form-control form-soft-control" rows="3" placeholder="Contoh: Beli ATK kelas" required></textarea>
                        </div>

                        <div class="mb-4">
                            <label class="form-label text-muted small fw-bold text-uppercase">File Bukti</label>
                            <input type="file" name="bukti_file" class="form-control form-soft-control" accept=".jpg,.jpeg,.png,.pdf,.webp">
                            <small class="text-muted d-block mt-2">Opsional. Format: JPG, JPEG, PNG, WEBP, atau PDF. Maksimal 2MB.</small>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" name="submit" class="btn-gradient-danger w-100 py-3 fw-bold rounded-3 shadow-sm border-0 text-white">
                                Simpan Pengeluaran
                            </button>
                            <button type="submit" name="ajukan" class="btn btn-light w-100 py-3 fw-bold rounded-3 shadow-sm">
                                Ajukan ke Wali Kelas
                            </button>
                        </div>
                    </form>
                    <?php else: ?>
                    <div class="d-grid gap-3">
                        <div class="border rounded-3 p-3 bg-light">
                            <div class="small text-muted text-uppercase fw-bold mb-1">Pengajuan Menunggu</div>
                            <div class="fw-bold fs-4 text-warning"><?= $totalMenunggu ?></div>
                            <small class="text-muted">Pengajuan yang menunggu tindakan wali kelas.</small>
                        </div>
                        <div class="border rounded-3 p-3 bg-light">
                            <div class="small text-muted text-uppercase fw-bold mb-1">Batas Approval</div>
                            <div class="fw-bold fs-4 text-primary">Rp <?= number_format(LIMIT_PENGAJUAN_WALI_KELAS, 0, ',', '.') ?></div>
                            <small class="text-muted">Nominal di atas batas ini sebaiknya melalui pengajuan.</small>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="table-panel">
                    <div class="table-panel-header">
                        <div>
                            <div class="table-panel-title">Riwayat Pengeluaran</div>
                            <p class="table-panel-subtitle">10 transaksi pengeluaran terbaru.</p>
                        </div>
                        <span class="badge text-bg-danger-subtle text-danger px-3 py-2 rounded-pill">
                            <i class="bi bi-receipt me-1"></i>Kas Keluar
                        </span>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th class="ps-4">Kategori</th>
                                    <th>Keterangan</th>
                                    <th>Jumlah</th>
                                    <th>Bukti</th>
                                    <th class="pe-4">Tanggal</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (mysqli_num_rows($qRecent) > 0): ?>
                                <?php while ($r = mysqli_fetch_assoc($qRecent)): ?>
                                <tr>
                                    <td class="ps-4">
                                        <span class="status-badge-soft danger"><?= htmlspecialchars($r['kategori'] ?: 'Lainnya') ?></span>
                                    </td>
                                    <td class="ps-4 text-dark fw-semibold"><?= htmlspecialchars($r['keterangan']) ?></td>
                                    <td class="fw-bold text-danger">- Rp <?= number_format($r['jumlah'], 0, ',', '.') ?></td>
                                    <td>
                                        <?php if (!empty($r['bukti_file'])): ?>
                                            <a href="<?= htmlspecialchars($r['bukti_file']) ?>" target="_blank" class="btn btn-sm btn-light" style="border-radius: 10px;">
                                                <i class="bi bi-paperclip me-1"></i>Lihat
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted small">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="pe-4 text-muted"><?= date('d/m/Y', strtotime($r['tanggal'])) ?></td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5">
                                        <div class="empty-state">
                                            <i class="bi bi-inbox"></i>
                                            Belum ada data pengeluaran.
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

        <div class="table-panel">
            <div class="table-panel-header">
                <div>
                    <div class="table-panel-title">Pengajuan ke Wali Kelas</div>
                    <p class="table-panel-subtitle"><?= $isWaliKelas ? 'Setujui atau tolak pengajuan dari bendahara langsung di sini.' : 'Pantau status pengajuan pengeluaran tanpa pindah halaman.' ?></p>
                </div>
                <span class="badge text-bg-warning-subtle text-warning px-3 py-2 rounded-pill">
                    <i class="bi bi-journal-check me-1"></i>Pengajuan
                </span>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th class="ps-4">Tanggal</th>
                            <th>Pengaju</th>
                            <th>Kategori</th>
                            <th>Keterangan</th>
                            <th class="text-end">Jumlah</th>
                            <th>Status</th>
                            <th>Bukti</th>
                            <th class="pe-4"><?= $isWaliKelas ? 'Aksi' : 'Catatan' ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($daftarPengajuan)): ?>
                        <?php foreach ($daftarPengajuan as $row): ?>
                            <?php
                            $statusMeta = format_status_pengajuan((string) $row['status']);
                            $labelPengaju = trim((string) ($row['diajukan_oleh_nama_tampil'] ?? ''));
                            if ($labelPengaju === '') {
                                $pengajuId = (int) ($row['diajukan_oleh_id'] ?? 0);
                                $labelPengaju = $pengajuId > 0 ? 'ID ' . $pengajuId : '-';
                            }
                            ?>
                            <tr>
                                <td class="ps-4 small fw-semibold">
                                    <?= htmlspecialchars(format_tanggal_pengajuan($row)) ?><br>
                                    <span class="text-muted"><?= htmlspecialchars(format_jam_pengajuan($row)) ?></span>
                                </td>
                                <td>
                                    <div class="fw-semibold text-dark"><?= htmlspecialchars($labelPengaju) ?></div>
                                    <div class="text-muted small">Pengaju</div>
                                </td>
                                <td>
                                    <span class="status-badge-soft warning"><?= htmlspecialchars($row['kategori'] ?: 'Lainnya') ?></span>
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
                                <td>
                                    <?php if (!empty($row['bukti_file'])): ?>
                                        <a href="<?= htmlspecialchars($row['bukti_file']) ?>" target="_blank" class="btn btn-sm btn-light" style="border-radius: 10px;">
                                            <i class="bi bi-paperclip me-1"></i>Lihat
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted small">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="pe-4" style="min-width: 280px;">
                                    <?php if ($isWaliKelas && $row['status'] === 'Menunggu'): ?>
                                        <form method="POST" class="d-grid gap-2">
                                            <input type="hidden" name="id_pengajuan" value="<?= (int) $row['id_pengajuan'] ?>">
                                            <textarea name="catatan_wali" class="form-control form-soft-control" rows="2" placeholder="Catatan wali kelas (opsional)"></textarea>
                                            <div class="d-flex gap-2">
                                                <button type="submit" name="aksi_pengajuan" value="setujui" class="btn btn-success w-100">Setujui</button>
                                                <button type="submit" name="aksi_pengajuan" value="tolak" class="btn btn-outline-danger w-100">Tolak</button>
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
                            <td colspan="8">
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

<script>
document.addEventListener('DOMContentLoaded', () => {
    const inputJumlahKeluar = document.getElementById('inputJumlahKeluar');
    if (inputJumlahKeluar) {
        inputJumlahKeluar.addEventListener('input', () => {
            inputJumlahKeluar.value = inputJumlahKeluar.value.replace(/\D+/g, '');
        });
    }
});
</script>

<?php include "layout/footer.php"; ?>
