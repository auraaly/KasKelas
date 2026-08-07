<?php
include "config/koneksi.php";
require_once "config/auth.php";
require_once "config/kas.php";
require_access('kas_masuk');
include "layout/header.php";

$success = '';
if (!empty($_SESSION['flash_kas_masuk_success'])) {
    $success = (string) $_SESSION['flash_kas_masuk_success'];
    unset($_SESSION['flash_kas_masuk_success']);
}

// 1. Ambil Saldo Saat Ini
$qSaldo = mysqli_query($conn, "
    SELECT 
        SUM(CASE WHEN jenis='Masuk' THEN jumlah ELSE 0 END) - 
        SUM(CASE WHEN jenis='Keluar' THEN jumlah ELSE 0 END) as saldo_total
    FROM transaksi
");
$saldoData = mysqli_fetch_assoc($qSaldo);
$saldo_saat_ini = (int)($saldoData['saldo_total'] ?? 0);

// 2. Handle Form Submission pemasukan
$errors = [];
if (isset($_POST['submit'])) {
    $id_murid   = mysqli_real_escape_string($conn, $_POST['id_murid']);
    $tanggal    = mysqli_real_escape_string($conn, $_POST['tanggal']);
    $jumlah     = (int) ($_POST['jumlah'] ?? 0);
    $keterangan = mysqli_real_escape_string($conn, $_POST['keterangan']);
    $bulan      = (int) date('n', strtotime($tanggal));
    $tahun      = (int) date('Y', strtotime($tanggal));

    if (empty($id_murid)) $errors[] = "Pilih nama murid!";
    if (empty($tanggal)) $errors[] = "Tanggal bayar wajib diisi!";
    if (empty($jumlah) || $jumlah <= 0) $errors[] = "Jumlah nominal tidak valid!";

    if (empty($errors)) {
        $rencanaAlokasi = alokasikan_pembayaran_kas($conn, (int) $id_murid, $bulan, $tahun, $jumlah);
        $alokasiPembayaran = $rencanaAlokasi['alokasi'];
        $sisaBelumTeralokasi = (int) ($rencanaAlokasi['sisa'] ?? 0);

        if (empty($alokasiPembayaran)) {
            $alokasiPembayaran[] = [
                'bulan' => $bulan,
                'tahun' => $tahun,
                'jumlah' => $jumlah,
                'kas_wajib' => $jumlah,
                'manual' => true,
            ];
            $sisaBelumTeralokasi = 0;
        } elseif ($sisaBelumTeralokasi > 0) {
            $alokasiPembayaran[] = [
                'bulan' => $bulan,
                'tahun' => $tahun,
                'jumlah' => $sisaBelumTeralokasi,
                'kas_wajib' => $sisaBelumTeralokasi,
                'manual' => true,
            ];
            $sisaBelumTeralokasi = 0;
        }

        mysqli_begin_transaction($conn);

        try {
            $stmt = mysqli_prepare($conn, "
                INSERT INTO transaksi (id_murid, tanggal, bulan, tahun, jenis, jumlah, keterangan)
                VALUES (?, ?, ?, ?, 'Masuk', ?, ?)
            ");

            if (!$stmt) {
                throw new Exception(mysqli_error($conn));
            }

            $namaBulan = nama_bulan_indonesia();

            foreach ($alokasiPembayaran as $item) {
                $labelPeriode = ($namaBulan[$item['bulan']] ?? $item['bulan']) . ' ' . $item['tahun'];
                $isManualTambahan = !empty($item['manual']);
                $keteranganFinal = trim($keterangan);
                $suffixKeterangan = $isManualTambahan
                    ? 'Pemasukan tambahan ' . $labelPeriode
                    : 'Alokasi ' . $labelPeriode;
                $keteranganFinal = $keteranganFinal !== ''
                    ? $keteranganFinal . ' - ' . $suffixKeterangan
                    : $suffixKeterangan;

                $bulanAlokasi = (int) $item['bulan'];
                $tahunAlokasi = (int) $item['tahun'];
                $jumlahAlokasi = (int) $item['jumlah'];
                $idMuridInt = (int) $id_murid;

                mysqli_stmt_bind_param($stmt, "isiiis", $idMuridInt, $tanggal, $bulanAlokasi, $tahunAlokasi, $jumlahAlokasi, $keteranganFinal);

                if (!mysqli_stmt_execute($stmt)) {
                    throw new Exception(mysqli_stmt_error($stmt));
                }
            }

            mysqli_stmt_close($stmt);
            mysqli_commit($conn);

            $jumlahPeriode = count($alokasiPembayaran);
            $pesan = $jumlahPeriode > 1
                ? "Berhasil simpan. Pembayaran dibagi ke {$jumlahPeriode} baris pemasukan."
                : "Berhasil simpan pembayaran.";

            // Kirim notifikasi WhatsApp
            $qMurid = mysqli_prepare($conn, "SELECT nama, no_hp FROM murid WHERE id_murid = ? LIMIT 1");
            mysqli_stmt_bind_param($qMurid, "i", $idMuridInt);
            mysqli_stmt_execute($qMurid);
            $dataMurid = mysqli_fetch_assoc(mysqli_stmt_get_result($qMurid));
            mysqli_stmt_close($qMurid);

            if ($dataMurid && !empty($dataMurid['no_hp'])) {
                $ringkasan = get_ringkasan_tunggakan_murid($conn, $idMuridInt, $bulan, $tahun);
                $totalTunggakan = (int) ($ringkasan['total_tunggakan'] ?? 0);
                $waSent = kirim_wa_pembayaran(
                    $dataMurid['no_hp'],
                    $dataMurid['nama'],
                    $jumlah,
                    $bulan,
                    $tahun,
                    $totalTunggakan
                );
                $pesan .= $waSent
                    ? " Notifikasi WhatsApp terkirim."
                    : " (Notifikasi WhatsApp gagal terkirim.)";
            }

            $_SESSION['flash_kas_masuk_success'] = $pesan;
            header("Location: kas_masuk.php");
            exit;
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $errors[] = "Gagal simpan: " . $e->getMessage();
        }
    }
}

// 3. Statistik
$bln_ini  = date('m');
$thn_ini  = date('Y');
$tgl_skrg = date('Y-m-d');

$total_masuk = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COALESCE(SUM(jumlah),0) as total FROM transaksi WHERE jenis='Masuk'"
))['total'];

$masuk_bulan_ini = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COALESCE(SUM(jumlah),0) as total FROM transaksi 
     WHERE jenis='Masuk' AND MONTH(tanggal)='$bln_ini' AND YEAR(tanggal)='$thn_ini'"
))['total'];

$masuk_hari_ini = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COALESCE(SUM(jumlah),0) as total FROM transaksi 
     WHERE jenis='Masuk' AND DATE(tanggal)='$tgl_skrg'"
))['total'];
// 4. Riwayat — digroup per murid+tanggal, jumlah dijumlah, keterangan alokasi diringkas
$qRecent = mysqli_query($conn, "
    SELECT * FROM (
        SELECT
            t.id_murid,
            t.tanggal,
            t.jenis,
            COALESCE(m.nama, 'Siswa Umum') as nama_murid,
            SUM(t.jumlah) as jumlah,
            COUNT(*) as jumlah_alokasi,
            MIN(t.bulan) as bulan_awal,
            MIN(t.tahun) as tahun_awal,
            MAX(t.bulan) as bulan_akhir,
            MAX(t.tahun) as tahun_akhir,
            MAX(t.id_pembayaran) as id_terbaru
        FROM transaksi t
        LEFT JOIN murid m ON t.id_murid = m.id_murid
        WHERE t.jenis = 'Masuk'
        GROUP BY t.id_murid, t.tanggal, t.jenis
    ) grouped
    ORDER BY id_terbaru DESC
    LIMIT 10
");

if (!$qRecent) die("Query Error: " . mysqli_error($conn));

// 5. Daftar Murid
$daftar_murid = mysqli_query($conn, "SELECT id_murid, nama FROM murid ORDER BY nama ASC");
if (!$daftar_murid) die("Query Murid Error: " . mysqli_error($conn));
?>

<?php include "layout/sidebar.php"; ?>

<div class="content">
    <div class="container-fluid animate-fade-in page-shell">

        <div class="page-hero">
            <div class="page-hero-copy">
                <h2 class="fw-bold">
                    <span class="page-title-gradient-primary">
                        <i class="bi bi-arrow-down-circle-fill me-2"></i>Pemasukan Kas
                    </span>
                </h2>
                <p>Kelola iuran siswa dengan tampilan yang rapi, cepat dibaca, dan konsisten.</p>
                <a href="/KasKelas/pengaturan_kas.php" class="btn btn-sm fw-semibold mt-1"
                    style="background:#ede9fe;color:#6d28d9;border:none;border-radius:8px;">
                    <i class="bi bi-gear me-1"></i> Atur Kas Wajib
                </a>
            </div>
            <div class="glass-card balance-pill balance-pill-primary">
                <span class="balance-pill-label">Saldo Kas</span>
                <h4 class="fw-bold text-primary mb-0">Rp <?= number_format($saldo_saat_ini, 0, ',', '.') ?></h4>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger border-0 shadow-sm mb-0">
                <ul class="mb-0 small ps-3"><?php foreach ($errors as $e) echo "<li>$e</li>"; ?></ul>
            </div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="alert alert-success border-0 shadow-sm mb-0">
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="card-box border-success border-4 shadow-sm h-100">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="card-title mb-1">Total Pemasukan</p>
                            <h3 class="card-value mb-0">Rp <?= number_format($total_masuk, 0, ',', '.') ?></h3>
                        </div>
                        <i class="bi bi-cash-coin fs-1 opacity-25"></i>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card-box border-success border-4 shadow-sm h-100">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="card-title mb-1">Bulan Ini</p>
                            <h3 class="card-value mb-0">Rp <?= number_format($masuk_bulan_ini, 0, ',', '.') ?></h3>
                        </div>
                        <i class="bi bi-calendar2-check fs-1 opacity-25"></i>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card-box border-success border-4 shadow-sm h-100">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="card-title mb-1">Hari Ini</p>
                            <h3 class="card-value mb-0">Rp <?= number_format($masuk_hari_ini, 0, ',', '.') ?></h3>
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
                        <div class="form-panel-icon success">
                            <i class="bi bi-journal-check"></i>
                        </div>
                        <div>
                            <div class="form-panel-title">Catat Pemasukan</div>
                            <p class="form-panel-subtitle">Pembayaran otomatis dialokasikan ke tunggakan paling lama terlebih dahulu, mulai dari bulan yang belum lunas.</p>
                        </div>
                    </div>

                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold text-uppercase">Nama Siswa</label>
                            <select name="id_murid" id="selectMurid" class="form-select form-soft-control" required>
                                <option value="">Pilih siswa</option>
                                <?php while ($m = mysqli_fetch_assoc($daftar_murid)): ?>
                                    <option value="<?= $m['id_murid'] ?>"><?= htmlspecialchars($m['nama']) ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <!-- Panel tunggakan — muncul setelah pilih siswa -->
                        <div id="panelTunggakan" class="mb-3" style="display:none;">
                            <div id="tunggakanLoading" class="text-muted small py-2">
                                <span class="spinner-border spinner-border-sm me-1"></span> Mengecek tunggakan...
                            </div>
                            <div id="tunggakanBersih" style="display:none;">
                                <div class="d-flex align-items-center gap-2 px-3 py-2 rounded-3"
                                    style="background:#f0fdf4;border:1px solid #bbf7d0;">
                                    <i class="bi bi-check-circle-fill text-success"></i>
                                    <span class="small fw-semibold text-success">Tidak ada tunggakan — semua bulan lunas</span>
                                </div>
                            </div>
                            <div id="tunggakanAda" style="display:none;">
                                <div class="rounded-3 overflow-hidden" style="border:1px solid #fde68a;">
                                    <div class="d-flex justify-content-between align-items-center px-3 py-2"
                                        style="background:#fffbeb;">
                                        <span class="small fw-bold text-warning-emphasis">
                                            <i class="bi bi-exclamation-triangle-fill me-1 text-warning"></i>Ada Tunggakan
                                        </span>
                                        <button type="button" id="btnBayarSemua"
                                            class="btn btn-sm fw-semibold"
                                            style="background:#6d28d9;color:white;border:none;border-radius:6px;font-size:.78rem;padding:3px 10px;">
                                            <i class="bi bi-lightning-fill me-1"></i>Bayar Semua
                                        </button>
                                    </div>
                                    <div id="tunggakanRows" class="px-3 py-2"
                                        style="background:white;max-height:180px;overflow-y:auto;font-size:.83rem;">
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center px-3 py-2"
                                        style="background:#fef9c3;border-top:1px solid #fde68a;">
                                        <span class="small fw-bold">Total Tunggakan</span>
                                        <span id="tunggakanTotal" class="fw-bold text-danger"></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold text-uppercase">Tanggal Bayar</label>
                            <input type="date" name="tanggal" class="form-control form-soft-control" value="<?= date('Y-m-d') ?>" required>
                            <small class="text-muted d-block mt-2">Sistem akan melunasi bulan sebelumnya dulu. Jadi pembayaran tidak bisa langsung loncat ke bulan terbaru jika masih ada tunggakan lama.</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold text-uppercase">Nominal</label>
                            <input type="number" id="inputNominal" name="jumlah" class="form-control form-soft-control" placeholder="0" required>
                        </div>

                        <div class="mb-4">
                            <label class="form-label text-muted small fw-bold text-uppercase">Keterangan</label>
                            <textarea name="keterangan" class="form-control form-soft-control" rows="3" placeholder="Contoh: Iuran Minggu 1"></textarea>
                        </div>

                        <button type="submit" name="submit" class="btn-gradient-success w-100 py-3 fw-bold rounded-3 shadow-sm border-0 text-white">
                            Simpan Pemasukan
                        </button>
                    </form>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="table-panel">
                    <div class="table-panel-header">
                        <div>
                            <div class="table-panel-title">Riwayat Pemasukan</div>
                            <p class="table-panel-subtitle">10 transaksi pemasukan terbaru.</p>
                        </div>
                        <span class="badge text-bg-success-subtle text-success px-3 py-2 rounded-pill">
                            <i class="bi bi-receipt-cutoff me-1"></i>Kas Masuk
                        </span>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th class="ps-4">Nama</th>
                                    <th>Keterangan</th>
                                    <th>Jumlah</th>
                                    <th class="pe-4">Tanggal</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (mysqli_num_rows($qRecent) > 0): ?>
                                <?php
                                $nama_bulan_map = nama_bulan_indonesia();
                                while ($r = mysqli_fetch_assoc($qRecent)):
                                    // Buat label keterangan ringkas
                                    if ($r['jumlah_alokasi'] == 1) {
                                        $ket = ($nama_bulan_map[$r['bulan_awal']] ?? $r['bulan_awal']) . ' ' . $r['tahun_awal'];
                                    } else {
                                        $ket = ($nama_bulan_map[$r['bulan_awal']] ?? $r['bulan_awal']) . ' ' . $r['tahun_awal']
                                             . ' – ' . ($nama_bulan_map[$r['bulan_akhir']] ?? $r['bulan_akhir']) . ' ' . $r['tahun_akhir'];
                                    }
                                ?>
                                <tr>
                                    <td class="ps-4 fw-semibold text-dark"><?= htmlspecialchars($r['nama_murid']) ?></td>
                                    <td class="text-muted">
                                        <?= htmlspecialchars($ket) ?>
                                        <?php if ($r['jumlah_alokasi'] > 1): ?>
                                            <span class="badge bg-light text-muted ms-1"><?= $r['jumlah_alokasi'] ?> bulan</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="fw-bold text-success">+ Rp <?= number_format($r['jumlah'], 0, ',', '.') ?></td>
                                    <td class="pe-4 text-muted"><?= date('d/m/Y', strtotime($r['tanggal'])) ?></td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4">
                                        <div class="empty-state">
                                            <i class="bi bi-inbox"></i>
                                            Belum ada data pemasukan.
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

    </div>
</div>

<!-- Modal Atur Kas Wajib -->
<div class="modal fade" id="modalKasWajib" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h6 class="modal-title fw-bold"><i class="bi bi-gear me-2"></i>Atur Kas Wajib Bulanan</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-3">
                    Set nominal sesuai minggu aktif bulan itu.<br>
                    <span class="text-muted">4 minggu = Rp 20.000 · 3 minggu = Rp 15.000 · 2 minggu = Rp 10.000</span>
                </p>
                <div id="kasWajibAlert"></div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label text-muted small fw-bold text-uppercase mb-1">Bulan</label>
                        <select id="kb_bulan" class="form-select form-select-sm">
                            <?php foreach (nama_bulan_indonesia() as $n => $l): ?>
                                <option value="<?= $n ?>" <?= $n == (int)date('n') ? 'selected' : '' ?>><?= $l ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label text-muted small fw-bold text-uppercase mb-1">Tahun</label>
                        <select id="kb_tahun" class="form-select form-select-sm">
                            <?php for ($y = (int)date('Y') + 1; $y >= 2025; $y--): ?>
                                <option value="<?= $y ?>" <?= $y == (int)date('Y') ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label text-muted small fw-bold text-uppercase mb-1">Nominal</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text">Rp</span>
                        <input type="number" id="kb_nominal" class="form-control" min="0" step="1000">
                    </div>
                    <small id="kasWajibSaatIni" class="text-muted"></small>
                </div>
                <div>
                    <label class="form-label text-muted small fw-bold text-uppercase mb-1">Catatan <span class="fw-normal">(opsional)</span></label>
                    <input type="text" id="kb_catatan" class="form-control form-control-sm" placeholder="Misal: Libur Lebaran">
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-sm btn-light" data-bs-dismiss="modal">Batal</button>
                <button type="button" id="btnSimpanKas" class="btn btn-sm fw-semibold" style="background:#ede9fe;color:#6d28d9;border:none;">
                    <i class="bi bi-save me-1"></i>Simpan
                </button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const sel       = document.getElementById('selectMurid');
    const panel     = document.getElementById('panelTunggakan');
    const loading   = document.getElementById('tunggakanLoading');
    const bersih    = document.getElementById('tunggakanBersih');
    const ada       = document.getElementById('tunggakanAda');
    const rows      = document.getElementById('tunggakanRows');
    const totalEl   = document.getElementById('tunggakanTotal');
    const btnBayar  = document.getElementById('btnBayarSemua');
    const inputJml  = document.getElementById('inputNominal');

    let totalTunggakan = 0;

    sel.addEventListener('change', function () {
        const id = this.value;

        // reset
        panel.style.display = 'none';
        bersih.style.display = 'none';
        ada.style.display    = 'none';
        rows.innerHTML       = '';
        totalTunggakan       = 0;

        if (!id) return;

        panel.style.display   = 'block';
        loading.style.display = 'block';

        fetch(`/KasKelas/api_tunggakan_murid.php?id_murid=${id}`)
            .then(r => r.json())
            .then(d => {
                loading.style.display = 'none';

                if (d.bersih) {
                    bersih.style.display = 'block';
                    return;
                }

                // Isi baris tunggakan
                rows.innerHTML = d.tunggakan.map(t => `
                    <div class="d-flex justify-content-between py-1" style="border-bottom:1px solid #fef3c7;">
                        <span class="text-muted">${t.label}</span>
                        <span class="fw-semibold text-danger">Rp ${Number(t.sisa).toLocaleString('id-ID')}</span>
                    </div>`
                ).join('');

                totalTunggakan = d.total;
                totalEl.textContent = 'Rp ' + Number(d.total).toLocaleString('id-ID');
                ada.style.display = 'block';
            })
            .catch(() => { loading.style.display = 'none'; });
    });

    // Klik "Bayar Semua" → isi nominal otomatis
    btnBayar.addEventListener('click', function () {
        if (totalTunggakan > 0) {
            inputJml.value = totalTunggakan;
            inputJml.focus();
            // Scroll ke input nominal
            inputJml.scrollIntoView({ behavior: 'smooth', block: 'center' });
            // Highlight sebentar
            inputJml.style.transition = 'box-shadow .2s';
            inputJml.style.boxShadow  = '0 0 0 3px rgba(109,40,217,.35)';
            setTimeout(() => inputJml.style.boxShadow = '', 1200);
        }
    });
})();
</script>

<script>
(function () {
    const bulanSel = document.getElementById('kb_bulan');
    const tahunSel = document.getElementById('kb_tahun');
    const nominalInput = document.getElementById('kb_nominal');
    const infoEl = document.getElementById('kasWajibSaatIni');
    const alertEl = document.getElementById('kasWajibAlert');

    function loadNominal() {
        fetch(`/KasKelas/api_kas_wajib.php?bulan=${bulanSel.value}&tahun=${tahunSel.value}`)
            .then(r => r.json())
            .then(d => {
                nominalInput.value = d.nominal;
                infoEl.textContent = d.catatan ? `Catatan tersimpan: ${d.catatan}` : '';
            });
    }

    bulanSel.addEventListener('change', loadNominal);
    tahunSel.addEventListener('change', loadNominal);
    document.getElementById('modalKasWajib').addEventListener('show.bs.modal', loadNominal);

    document.getElementById('btnSimpanKas').addEventListener('click', function () {
        const data = new FormData();
        data.append('kb_bulan', bulanSel.value);
        data.append('kb_tahun', tahunSel.value);
        data.append('kb_nominal', nominalInput.value);
        data.append('kb_catatan', document.getElementById('kb_catatan').value);

        fetch('/KasKelas/api_kas_wajib.php', { method: 'POST', body: data })
            .then(r => r.json())
            .then(d => {
                alertEl.innerHTML = d.ok
                    ? `<div class="alert alert-success py-2 small border-0 mb-2">${d.pesan}</div>`
                    : `<div class="alert alert-danger py-2 small border-0 mb-2">${d.pesan}</div>`;
                if (d.ok) loadNominal();
            });
    });
})();
</script>

<?php include "layout/footer.php"; ?>
