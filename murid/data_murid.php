<?php
require_once "../config/koneksi.php";
require_once "../config/auth.php";
require_access('data_murid');
require_once "../layout/header.php";

$canManageMurid = can_manage_murid();
$cari = trim($_GET['cari'] ?? '');

function getStatusBadgeClass(string $status): string
{
    return strtolower($status) === 'aktif'
        ? 'bg-success-subtle text-success'
        : 'bg-secondary-subtle text-secondary';
}

// ================= TAMBAH DATA =================
if (isset($_POST['tambah'])) {
    if (!$canManageMurid) {
        header("Location: /KasKelas/403.php");
        exit;
    }

    $nama  = trim($_POST['nama'] ?? '');
    $no_hp = trim($_POST['no_hp'] ?? '');

    if ($nama === '') {
        header("Location: data_murid.php");
        exit;
    }

    $stmt = mysqli_prepare($conn, "INSERT INTO murid (nama, status, no_hp) VALUES (?, 'Aktif', ?)");
    $no_hp_val = $no_hp !== '' ? $no_hp : null;
    mysqli_stmt_bind_param($stmt, "ss", $nama, $no_hp_val);
    if (!mysqli_stmt_execute($stmt)) {
        die(mysqli_stmt_error($stmt));
    }
    mysqli_stmt_close($stmt);

    header("Location: data_murid.php");
    exit;
}

// ================= HAPUS DATA =================
if (isset($_POST['hapus_id'])) {
    if (!$canManageMurid) {
        header("Location: /KasKelas/403.php");
        exit;
    }

    $id = (int) ($_POST['hapus_id'] ?? 0);

    if ($id > 0) {
        $stmt = mysqli_prepare($conn, "DELETE FROM murid WHERE id_murid = ?");
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }

    header("Location: data_murid.php");
    exit;
}

// ================= EDIT DATA =================
if (isset($_POST['edit'])) {
    if (!$canManageMurid) {
        header("Location: /KasKelas/403.php");
        exit;
    }

    $id     = (int) ($_POST['id'] ?? 0);
    $nama   = trim($_POST['nama'] ?? '');
    $no_hp  = trim($_POST['no_hp'] ?? '');
    $status = in_array($_POST['status'] ?? '', ['Aktif', 'Nonaktif']) ? $_POST['status'] : 'Aktif';

    if ($id <= 0 || $nama === '') {
        header("Location: data_murid.php");
        exit;
    }

    $no_hp_val = $no_hp !== '' ? $no_hp : null;
    $stmt = mysqli_prepare($conn, "UPDATE murid SET nama = ?, status = ?, no_hp = ? WHERE id_murid = ?");
    mysqli_stmt_bind_param($stmt, "sssi", $nama, $status, $no_hp_val, $id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    header("Location: data_murid.php");
    exit;
}



$cariSafe = mysqli_real_escape_string($conn, $cari);
$query = mysqli_query(
    $conn,
    "SELECT * FROM murid WHERE nama LIKE '%$cariSafe%' ORDER BY nama ASC"
);

$daftarMurid = [];
if ($query) {
    while ($row = mysqli_fetch_assoc($query)) {
        $daftarMurid[] = $row;
    }
}

$totalMurid = count($daftarMurid);
?>

<?php require_once "../layout/sidebar.php"; ?>

<div class="content">
    <div class="container-fluid animate-fade-in page-shell">

        <div class="page-hero">
            <div class="page-hero-copy">
                <h2 class="fw-bold mb-1">Data Murid</h2>
            </div>
            <div class="glass-card balance-pill balance-pill-primary">
                <span class="balance-pill-label">Total Murid</span>
                <h4 class="fw-bold text-primary mb-0"><?= $totalMurid ?> Orang</h4>
            </div>
        </div>

        <div class="glass-card p-3 animate-fade-in" style="animation-delay: 0.1s;">
            <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
                <form method="GET" class="d-flex gap-2 flex-grow-1">
                    <div class="flex-grow-1 position-relative">
                        <i class="bi bi-search position-absolute top-50 start-0 translate-middle-y ms-3 text-muted"></i>
                        <input
                            type="text"
                            name="cari"
                            class="form-control form-soft-control ps-5"
                            placeholder="           Cari nama murid..."
                            value="<?= htmlspecialchars($cari) ?>"
                        >
                    </div>
                    <button type="submit" class="btn btn-light px-4" style="border-radius: 12px; font-weight: 600;">Cari</button>
                </form>
                <?php if ($canManageMurid): ?>
                <div class="d-flex justify-content-end">
                    <button class="btn btn-premium" data-bs-toggle="modal" data-bs-target="#modalTambah">
                        <i class="bi bi-plus-lg me-2"></i>Tambah Murid
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="table-panel animate-fade-in" style="animation-delay: 0.2s;">
            <div class="table-panel-header">
                <div>
                    <div class="table-panel-title">Daftar Murid</div>
                </div>
                <span class="summary-chip"><i class="bi bi-people"></i><?= $totalMurid ?> murid tampil</span>
            </div>

            <div class="table-responsive" style="max-height: 480px; overflow-y: auto;">
                <table class="table table-hover align-middle mb-0">
                    <thead style="position: sticky; top: 0; z-index: 1; background: #fff;">
                        <tr>
                            <th class="ps-4" style="width: 80px;">NO</th>
                            <th>NAMA MURID</th>
                            <th>NO. HP</th>
                            <th>STATUS</th>
                            <?php if ($canManageMurid): ?>
                            <th class="text-end pe-4" style="width: 200px;">AKSI</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $no = 1;
                        $jumlahKolom = $canManageMurid ? 5 : 4;
                        ?>

                        <?php if (empty($daftarMurid)): ?>
                        <tr>
                            <td colspan="<?= $jumlahKolom ?>" class="text-center py-5 text-muted">Data murid tidak ditemukan</td>
                        </tr>
                        <?php else: ?>
                            <?php foreach ($daftarMurid as $murid): ?>
                                <?php
                                $statusMurid = trim((string) ($murid['status'] ?? 'Aktif'));
                                if ($statusMurid === '') {
                                    $statusMurid = 'Aktif';
                                }
                                ?>
                        <tr>
                            <td class="ps-4 text-muted small"><?= $no++ ?></td>
                            <td>
                                <div class="d-flex align-items-center gap-3">
                                    <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 36px; height: 36px; background: #eff6ff; color: var(--primary); font-weight: 700;">
                                        <?= strtoupper(substr((string) $murid['nama'], 0, 1)) ?>
                                    </div>
                                    <span class="fw-bold" style="font-size: 14px;"><?= htmlspecialchars($murid['nama']) ?></span>
                                </div>
                            </td>
                            <td class="text-muted small">
                                <?= !empty($murid['no_hp']) ? htmlspecialchars($murid['no_hp']) : '<span class="text-muted fst-italic">—</span>' ?>
                            </td>
                            <td>
                                <span class="badge <?= getStatusBadgeClass($statusMurid) ?> px-3 py-1" style="border-radius: 8px; font-size: 11px;">
                                    <?= strtoupper(htmlspecialchars($statusMurid)) ?>
                                </span>
                            </td>
                            <?php if ($canManageMurid): ?>
                            <td class="text-end pe-4">
                                <button
                                    class="btn btn-light btn-sm me-1"
                                    style="border-radius: 8px;"
                                    data-bs-toggle="modal"
                                    data-bs-target="#edit<?= $murid['id_murid'] ?>"
                                >
                                    <i class="bi bi-pencil-square me-1"></i> Edit
                                </button>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Yakin hapus murid ini? Semua data transaksinya akan terputus.')">
                                    <input type="hidden" name="hapus_id" value="<?= $murid['id_murid'] ?>">
                                    <button type="submit" class="btn btn-danger-subtle btn-sm" style="border-radius: 8px;">
                                        <i class="bi bi-trash me-1"></i> Hapus
                                    </button>
                                </form>
                            </td>
                            <?php endif; ?>
                        </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php if ($canManageMurid): ?>
    <?php foreach ($daftarMurid as $murid): ?>
    <div class="modal fade" id="edit<?= $murid['id_murid'] ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 glass-card p-2" style="background: white;">
                <form method="POST">
                    <div class="modal-header border-0 pb-0">
                        <h5 class="fw-bold mb-0">Edit Murid</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="id" value="<?= $murid['id_murid'] ?>">
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold">NAMA LENGKAP</label>
                            <input
                                type="text"
                                name="nama"
                                class="form-control form-control-premium"
                                value="<?= htmlspecialchars($murid['nama']) ?>"
                                required
                            >
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold">NO. HP (WhatsApp)</label>
                            <input
                                type="text"
                                name="no_hp"
                                class="form-control form-control-premium"
                                value="<?= htmlspecialchars($murid['no_hp'] ?? '') ?>"
                                placeholder="Contoh: 628123456789"
                            >
                            <small class="text-muted">Format 62xxx, dipakai untuk notifikasi WhatsApp.</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold">STATUS</label>
                            <select name="status" class="form-select form-control-premium">
                                <option value="Aktif" <?= ($murid['status'] ?? 'Aktif') === 'Aktif' ? 'selected' : '' ?>>Aktif</option>
                                <option value="Nonaktif" <?= ($murid['status'] ?? 'Aktif') === 'Nonaktif' ? 'selected' : '' ?>>Nonaktif</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer border-0 pt-0">
                        <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal" style="border-radius: 12px;">Batal</button>
                        <button class="btn btn-premium px-4" name="edit">Simpan Perubahan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php if ($canManageMurid): ?>
<div class="modal fade" id="modalTambah" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 glass-card p-2" style="background: white;">
            <form method="POST">
                <div class="modal-header border-0 pb-0">
                    <h5 class="fw-bold mb-0">Tambah Murid Baru</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label text-muted small fw-bold">NAMA LENGKAP</label>
                        <input type="text" name="nama" class="form-control form-control-premium" placeholder="Masukkan nama murid..." required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-muted small fw-bold">NO. HP (WhatsApp)</label>
                        <input type="text" name="no_hp" class="form-control form-control-premium" placeholder="Contoh: 628123456789">
                        <small class="text-muted">Format 62xxx, dipakai untuk notifikasi WhatsApp.</small>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal" style="border-radius: 12px;">Batal</button>
                    <button class="btn btn-premium px-4" name="tambah">Simpan Murid</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include "../layout/footer.php"; ?>
