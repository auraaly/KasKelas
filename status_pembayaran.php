<?php
include "config/koneksi.php";
require_once "config/auth.php";
require_once "config/kas.php";
require_access('status_pembayaran');
include "layout/header.php";

$bulan_pilih = (int) ($_GET['bulan'] ?? date('m'));
$tahun_pilih = (int) ($_GET['tahun'] ?? date('Y'));
$nama_bulan = nama_bulan_indonesia();
$kasWajib = get_kas_wajib_bulanan($conn, $bulan_pilih, $tahun_pilih);

$qSaldo = mysqli_query($conn, "
    SELECT 
        COALESCE(SUM(CASE WHEN jenis='Masuk' THEN jumlah ELSE 0 END),0) -
        COALESCE(SUM(CASE WHEN jenis='Keluar' THEN jumlah ELSE 0 END),0) AS saldo_total
    FROM transaksi
");
$saldo = mysqli_fetch_assoc($qSaldo)['saldo_total'] ?? 0;

$q = mysqli_query($conn, "
    SELECT 
        m.id_murid,
        m.nama,
        COALESCE(SUM(CASE 
            WHEN t.jenis='Masuk'
             AND t.bulan = '$bulan_pilih'
             AND t.tahun = '$tahun_pilih'
            THEN t.jumlah ELSE 0 END),0) AS bayar_bulan_ini
    FROM murid m
    LEFT JOIN transaksi t ON t.id_murid = m.id_murid
    GROUP BY m.id_murid, m.nama
    ORDER BY m.nama ASC
");

if (!$q) {
    die("Query error: " . mysqli_error($conn));
}

$dataMurid = [];
$jumlah_lunas = 0;
$jumlah_sebagian = 0;
$jumlah_belum = 0;
$total_murid_nunggak = 0;
$total_nominal_tunggakan = 0;

while ($r = mysqli_fetch_assoc($q)) {
    $bayar_bulan_ini = (int) $r['bayar_bulan_ini'];
    $sisa_bulan_ini = max($kasWajib - $bayar_bulan_ini, 0);
    $persentase_bayar = $kasWajib > 0 ? (int) min(round(($bayar_bulan_ini / $kasWajib) * 100), 100) : 0;
    $ringkasanTunggakan = get_ringkasan_tunggakan_murid($conn, (int) $r['id_murid'], $bulan_pilih, $tahun_pilih);
    $jumlahBulanTunggakan = (int) ($ringkasanTunggakan['jumlah_bulan'] ?? 0);
    $totalTunggakan = (int) ($ringkasanTunggakan['total_tunggakan'] ?? 0);
    $periodeTerlamaTunggakan = $ringkasanTunggakan['periode_terlama']['label'] ?? '-';

    if ($bayar_bulan_ini == 0) {
        $status = "Belum Bayar";
        $status_class = "danger";
        $jumlah_belum++;
    } elseif ($bayar_bulan_ini < $kasWajib) {
        $status = "Sebagian Bayar";
        $status_class = "warning";
        $jumlah_sebagian++;
    } else {
        $status = "Lunas";
        $status_class = "success";
        $jumlah_lunas++;
    }

    if ($totalTunggakan > 0) {
        $total_murid_nunggak++;
        $total_nominal_tunggakan += $totalTunggakan;
    }

    $r['bayar_bulan_ini'] = $bayar_bulan_ini;
    $r['sisa_bulan_ini'] = $sisa_bulan_ini;
    $r['persentase_bayar'] = $persentase_bayar;
    $r['status'] = $status;
    $r['status_class'] = $status_class;
    $r['jumlah_bulan_tunggakan'] = $jumlahBulanTunggakan;
    $r['total_tunggakan'] = $totalTunggakan;
    $r['periode_tunggakan_terlama'] = $periodeTerlamaTunggakan;
    $dataMurid[] = $r;
}
?>

<?php include "layout/sidebar.php"; ?>

<div class="content">
    <div class="container-fluid animate-fade-in">

        <div class="top-header d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4>Status Pembayaran</h4>
                <small class="text-white-50">
                    <i class="bi bi-calendar-event me-1"></i> Periode <?= $nama_bulan[$bulan_pilih] ?> <?= $tahun_pilih ?>
                </small>
            </div>
            <div class="text-end">
                <strong>Saldo Kas</strong><br>
                <small>Rp <?= number_format($saldo, 0, ',', '.') ?></small>
            </div>
        </div>

        <!-- Filter + Ringkasan dalam satu baris -->
        <div class="filter-panel mb-3">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-auto">
                    <label class="form-label text-muted small fw-bold text-uppercase mb-1">Bulan</label>
                    <select name="bulan" class="form-select form-select-sm form-soft-control">
                        <?php foreach ($nama_bulan as $key => $val): ?>
                            <option value="<?= $key ?>" <?= ($bulan_pilih === $key) ? 'selected' : '' ?>><?= $val ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <label class="form-label text-muted small fw-bold text-uppercase mb-1">Tahun</label>
                    <select name="tahun" class="form-select form-select-sm form-soft-control">
                        <?php for ($y = (int)date('Y') + 1; $y >= 2025; $y--): ?>
                            <option value="<?= $y ?>" <?= $tahun_pilih == $y ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn-premium px-4 py-2">Filter</button>
                </div>
                <div class="col-auto ms-auto text-end">
                    <small class="text-muted d-block">Kas wajib: <strong>Rp <?= number_format($kasWajib, 0, ',', '.') ?></strong></small>
                    <small class="text-muted">Total tunggakan: <strong class="text-danger">Rp <?= number_format($total_nominal_tunggakan, 0, ',', '.') ?></strong></small>
                </div>
            </form>
        </div>

        <!-- Ringkasan Status Singkat -->
        <div class="d-flex gap-2 mb-3 flex-wrap">
            <span class="badge rounded-pill px-3 py-2 fs-6" style="background:#d1fae5;color:#065f46;">
                <i class="bi bi-check-circle-fill me-1"></i> Lunas: <?= $jumlah_lunas ?>
            </span>
            <span class="badge rounded-pill px-3 py-2 fs-6" style="background:#fef9c3;color:#854d0e;">
                <i class="bi bi-exclamation-circle-fill me-1"></i> Sebagian: <?= $jumlah_sebagian ?>
            </span>
            <span class="badge rounded-pill px-3 py-2 fs-6" style="background:#fee2e2;color:#991b1b;">
                <i class="bi bi-x-circle-fill me-1"></i> Belum Bayar: <?= $jumlah_belum ?>
            </span>
        </div>

        <div class="table-box">
            <div class="table-box-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span><i class="bi bi-table me-2"></i>Daftar Status Pembayaran</span>
                <div class="d-flex gap-2 align-items-center flex-wrap">
                    <!-- Search nama -->
                    <input type="text" id="searchNama" class="form-control form-control-sm form-soft-control" placeholder="Cari nama..." style="width:160px;">
                    <!-- Filter status -->
                    <select id="filterStatus" class="form-select form-select-sm form-soft-control" style="width:150px;">
                        <option value="">Semua Status</option>
                        <option value="success">Lunas</option>
                        <option value="warning">Sebagian</option>
                        <option value="danger">Belum Bayar</option>
                        <option value="nunggak">Ada Tunggakan</option>
                    </select>
                    <span class="badge bg-light text-primary">Periode <?= $nama_bulan[$bulan_pilih] ?></span>
                </div>
            </div>
            <div class="p-3">
                <div class="table-responsive" style="max-height:420px;overflow-y:auto;">
                    <table class="table table-hover align-middle mb-0 small" id="tabelStatus">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th width="40">No</th>
                                <th>Nama Murid</th>
                                <th>Bayar Bulan Ini</th>
                                <th>Tunggakan</th>
                                <th width="120">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!empty($dataMurid)): ?>
                            <?php $no = 1; ?>
                            <?php foreach ($dataMurid as $r): ?>
                                <tr data-status="<?= $r['status_class'] ?>" data-nunggak="<?= $r['total_tunggakan'] > 0 ? '1' : '0' ?>">
                                    <td class="text-muted"><?= $no++ ?></td>
                                    <td class="fw-semibold">
                                        <span style="cursor:pointer;color:inherit;" 
                                            onclick="lihatRiwayat(<?= $r['id_murid'] ?>, '<?= htmlspecialchars(addslashes($r['nama'])) ?>')">
                                            <?= htmlspecialchars($r['nama']) ?>
                                            <i class="bi bi-clock-history ms-1 text-muted" style="font-size:.75rem;"></i>
                                        </span>
                                    </td>
                                    <td style="min-width:160px;">
                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                            <span class="fw-bold <?= $r['bayar_bulan_ini'] >= $kasWajib ? 'text-success' : ($r['bayar_bulan_ini'] > 0 ? 'text-warning' : 'text-danger') ?>">
                                                Rp <?= number_format($r['bayar_bulan_ini'], 0, ',', '.') ?>
                                            </span>
                                            <span class="text-muted" style="font-size:0.75rem;"><?= $r['persentase_bayar'] ?>%</span>
                                        </div>
                                        <div class="progress" style="height:6px;border-radius:999px;background:#e9eef5;">
                                            <div class="progress-bar bg-<?= $r['status_class'] ?>" role="progressbar"
                                                style="width:<?= $r['persentase_bayar'] ?>%;"
                                                aria-valuenow="<?= $r['persentase_bayar'] ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                        </div>
                                        <?php if ($r['sisa_bulan_ini'] > 0): ?>
                                            <small class="text-muted">kurang Rp <?= number_format($r['sisa_bulan_ini'], 0, ',', '.') ?></small>
                                        <?php else: ?>
                                            <small class="text-success">lunas bulan ini</small>
                                        <?php endif; ?>
                                    </td>
                                    <td style="min-width:180px;">
                                        <?php if ($r['total_tunggakan'] > 0): ?>
                                            <span class="fw-bold text-danger">Rp <?= number_format($r['total_tunggakan'], 0, ',', '.') ?></span>
                                            <br>
                                            <small class="text-muted">
                                                <i class="bi bi-clock-history me-1"></i><?= $r['jumlah_bulan_tunggakan'] ?> bln
                                                · sejak <?= htmlspecialchars($r['periode_tunggakan_terlama']) ?>
                                            </small>
                                            <?php
                                                $urgency = '';
                                                if ($r['jumlah_bulan_tunggakan'] >= 3) $urgency = 'text-danger fw-semibold';
                                                elseif ($r['jumlah_bulan_tunggakan'] == 2) $urgency = 'text-warning fw-semibold';
                                            ?>
                                            <?php if ($urgency): ?>
                                                <br><small class="<?= $urgency ?>">
                                                    <i class="bi bi-exclamation-triangle-fill me-1"></i>
                                                    <?= $r['jumlah_bulan_tunggakan'] >= 3 ? 'Perlu tindak lanjut' : 'Perhatian' ?>
                                                </small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-success"><i class="bi bi-check2-circle me-1"></i>Bersih</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge-soft <?= $r['status_class'] ?>">
                                            <?php if ($r['status_class'] === 'success'): ?>
                                                <i class="bi bi-check-circle"></i>
                                            <?php elseif ($r['status_class'] === 'warning'): ?>
                                                <i class="bi bi-exclamation-circle"></i>
                                            <?php else: ?>
                                                <i class="bi bi-x-circle"></i>
                                            <?php endif; ?>
                                            <?= $r['status'] ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="text-center py-5 text-muted">
                                    <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                                    Belum ada data murid untuk ditampilkan.
                                </td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="mt-2 text-muted small" id="jumlahTampil"></div>
            </div>
        </div>

    </div>
</div>

<script>
(function () {
    const searchInput = document.getElementById('searchNama');
    const filterSelect = document.getElementById('filterStatus');
    const tbody = document.querySelector('#tabelStatus tbody');
    const jumlahTampil = document.getElementById('jumlahTampil');

    function applyFilter() {
        const keyword = searchInput.value.toLowerCase().trim();
        const status = filterSelect.value;
        const rows = tbody.querySelectorAll('tr[data-status]');
        let visible = 0;

        rows.forEach(row => {
            const nama = row.querySelector('td:nth-child(2)')?.textContent.toLowerCase() ?? '';
            const rowStatus = row.dataset.status;
            const rowNunggak = row.dataset.nunggak;

            const matchNama = nama.includes(keyword);
            const matchStatus = status === ''
                || (status === 'nunggak' ? rowNunggak === '1' : rowStatus === status);

            if (matchNama && matchStatus) {
                row.style.display = '';
                visible++;
            } else {
                row.style.display = 'none';
            }
        });

        jumlahTampil.textContent = visible + ' murid ditampilkan';
    }

    searchInput.addEventListener('input', applyFilter);
    filterSelect.addEventListener('change', applyFilter);
    applyFilter();
})();
</script>

<!-- Modal Riwayat Pembayaran -->
<div class="modal fade" id="modalRiwayat" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header border-0">
                <h6 class="modal-title fw-bold"><i class="bi bi-clock-history me-2"></i>Riwayat Pembayaran — <span id="riwayatNama"></span></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-0">
                <div id="riwayatContent"><div class="text-center py-4 text-muted">Memuat...</div></div>
            </div>
        </div>
    </div>
</div>

<script>
function lihatRiwayat(idMurid, nama) {
    document.getElementById('riwayatNama').textContent = nama;
    document.getElementById('riwayatContent').innerHTML = '<div class="text-center py-4 text-muted"><i class="bi bi-hourglass-split me-2"></i>Memuat...</div>';
    new bootstrap.Modal(document.getElementById('modalRiwayat')).show();

    fetch(`/KasKelas/api_tunggakan_murid.php?id_murid=${idMurid}&riwayat=1`)
        .then(r => r.json())
        .then(d => {
            if (!d.riwayat || d.riwayat.length === 0) {
                document.getElementById('riwayatContent').innerHTML = '<p class="text-muted text-center py-3">Belum ada riwayat pembayaran.</p>';
                return;
            }
            let html = '<div class="table-responsive"><table class="table table-sm table-hover align-middle small mb-0"><thead class="table-light"><tr><th>Tanggal</th><th>Periode</th><th class="text-end">Jumlah</th><th>Keterangan</th></tr></thead><tbody>';
            for (const r of d.riwayat) {
                html += `<tr>
                    <td class="text-muted">${r.tanggal}</td>
                    <td>${r.periode}</td>
                    <td class="text-end text-success fw-semibold">Rp ${Number(r.jumlah).toLocaleString('id-ID')}</td>
                    <td class="text-muted">${r.keterangan || '-'}</td>
                </tr>`;
            }
            html += `</tbody><tfoot class="table-light fw-bold"><tr><td colspan="2">Total</td><td class="text-end text-success">Rp ${Number(d.total_bayar).toLocaleString('id-ID')}</td><td></td></tr></tfoot></table></div>`;
            if (d.total_tunggakan > 0) {
                html += `<div class="alert alert-warning border-0 mt-3 small mb-0"><i class="bi bi-exclamation-triangle me-2"></i>Masih ada tunggakan <strong>Rp ${Number(d.total_tunggakan).toLocaleString('id-ID')}</strong> (${d.jumlah_bulan} bulan)</div>`;
            } else {
                html += `<div class="alert alert-success border-0 mt-3 small mb-0"><i class="bi bi-check2-circle me-2"></i>Tidak ada tunggakan.</div>`;
            }
            document.getElementById('riwayatContent').innerHTML = html;
        });
}
</script>

<?php include "layout/footer.php"; ?>
