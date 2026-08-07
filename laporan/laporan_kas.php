<?php
include "../config/koneksi.php";
require_once "../config/auth.php";
require_once "../config/kas.php";
require_access('laporan_kas');
include "../layout/header.php";

$filter_bulan = (string) ($_GET['bulan'] ?? date('n'));
$filter_tahun = (string) ($_GET['tahun'] ?? date('Y'));
$bulan_nama   = array_merge([''], array_values(nama_bulan_indonesia()));
$periode_text = ($filter_bulan ? ($bulan_nama[(int)$filter_bulan] ?? '') : 'Semua Bulan') . ' ' . ($filter_tahun ?: 'Semua Tahun');

$where = "WHERE 1=1";
if ($filter_bulan != '') $where .= " AND t.bulan = '$filter_bulan'";
if ($filter_tahun != '') $where .= " AND t.tahun = '$filter_tahun'";

$whereSaldoAwal = "WHERE 1=1";
if ($filter_bulan != '' && $filter_tahun != '')
    $whereSaldoAwal .= " AND (tahun < '$filter_tahun' OR (tahun = '$filter_tahun' AND bulan < '$filter_bulan'))";

$saldo_awal = (int)(mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COALESCE(SUM(CASE WHEN jenis='Masuk' THEN jumlah ELSE 0 END),0) -
            COALESCE(SUM(CASE WHEN jenis='Keluar' THEN jumlah ELSE 0 END),0) AS s
     FROM transaksi $whereSaldoAwal"))['s'] ?? 0);

$qTx = mysqli_query($conn, "SELECT t.*, m.nama as nama_murid FROM transaksi t LEFT JOIN murid m ON t.id_murid=m.id_murid $where ORDER BY t.tanggal ASC, t.id_pembayaran ASC");
$transactions = [];
if ($qTx) while ($r = mysqli_fetch_assoc($qTx)) $transactions[] = $r;

$running = $saldo_awal;
foreach ($transactions as &$t) {
    $running += $t['jenis']==='Masuk' ? (int)$t['jumlah'] : -(int)$t['jumlah'];
    $t['running_saldo'] = $running;
} unset($t);

$total_masuk  = array_sum(array_map(fn($t) => $t['jenis']==='Masuk'  ? (int)$t['jumlah'] : 0, $transactions));
$total_keluar = array_sum(array_map(fn($t) => $t['jenis']==='Keluar' ? (int)$t['jumlah'] : 0, $transactions));
$saldo_akhir  = $saldo_awal + $total_masuk - $total_keluar;

$pengeluaran_kategori = [];
foreach ($transactions as $t) {
    if ($t['jenis'] !== 'Keluar') continue;
    $kat = $t['kategori'] ?: 'Lainnya';
    $pengeluaran_kategori[$kat] = ($pengeluaran_kategori[$kat] ?? 0) + (int)$t['jumlah'];
}
arsort($pengeluaran_kategori);

$kas_wajib = ($filter_bulan != '' && $filter_tahun != '') ? get_kas_wajib_bulanan($conn, (int)$filter_bulan, (int)$filter_tahun) : 20000;
$qMurid = mysqli_query($conn, "
    SELECT m.id_murid, m.nama,
        COALESCE(SUM(CASE WHEN t.jenis='Masuk'
            " . ($filter_bulan != '' ? "AND t.bulan='$filter_bulan'" : "") . "
            " . ($filter_tahun != '' ? "AND t.tahun='$filter_tahun'" : "") . "
            THEN t.jumlah ELSE 0 END),0) AS bayar_periode
    FROM murid m LEFT JOIN transaksi t ON t.id_murid=m.id_murid
    WHERE m.status='Aktif' GROUP BY m.id_murid, m.nama ORDER BY m.nama ASC
");
$rekap_murid = [];
$total_lunas = $total_sebagian = $total_belum = $total_tunggakan = 0;
if ($qMurid) {
    while ($row = mysqli_fetch_assoc($qMurid)) {
        $bayar = (int)$row['bayar_periode'];
        $sisa  = max($kas_wajib - $bayar, 0);
        $bulan_acuan = $filter_bulan != '' ? (int)$filter_bulan : (int)date('n');
        $tahun_acuan = $filter_tahun != '' ? (int)$filter_tahun : (int)date('Y');
        $ringkasan = get_ringkasan_tunggakan_murid($conn, (int)$row['id_murid'], $bulan_acuan, $tahun_acuan);
        if ($bayar >= $kas_wajib)  { $status = 'Lunas';      $total_lunas++; }
        elseif ($bayar > 0)        { $status = 'Sebagian';   $total_sebagian++; }
        else                       { $status = 'Belum Bayar'; $total_belum++; }
        $total_tunggakan += (int)($ringkasan['total_tunggakan'] ?? 0);
        $rekap_murid[] = ['nama' => $row['nama'], 'bayar_periode' => $bayar, 'sisa_periode' => $sisa,
            'status' => $status, 'total_tunggakan' => (int)($ringkasan['total_tunggakan'] ?? 0),
            'bulan_nunggak' => (int)($ringkasan['jumlah_bulan'] ?? 0)];
    }
}
?>

<div class="no-print"><?php include "../layout/sidebar.php"; ?></div>

<style>
/* ---- PRINT ---- */
@media print {
    .no-print, .sidebar, footer, .content > .no-print { display: none !important; }
    .content { margin-left: 0 !important; width: 100% !important; padding: 0 !important; }
    body { background: white !important; font-size: 9px !important; font-family: Arial, sans-serif !important; color: #000 !important; line-height: 1.3 !important; }
    .laporan-body { padding: 0 !important; }
    .section-card { box-shadow: none !important; border: 1px solid #ccc !important; margin-bottom: 6px !important; border-radius: 0 !important; }
    .section-card:last-child { break-inside: avoid; page-break-inside: avoid; }
    .section-header { padding: 4px 10px !important; font-size: 8.5px !important; background: #f5f5f5 !important; }
    .stat-grid { display: flex !important; }
    .stat-item { padding: 6px 10px !important; border-right: 1px solid #ddd !important; }
    .stat-label { font-size: 7px !important; }
    .stat-value { font-size: 10px !important; }
    .tx-table th, .tx-table td { padding: 2px 6px !important; font-size: 8px !important; }
    .kop-title { font-size: 12px !important; }
    .kop-sub { font-size: 8px !important; }
    .p-4 { padding: 8px !important; }
    .bar-wrap { height: 4px !important; }
    .pill { font-size: 7px !important; padding: 1px 5px !important; border: 1px solid #999 !important; background: white !important; color: #000 !important; }
    .dot { width: 6px !important; height: 6px !important; }
    .ttd-space { height: 35px !important; margin: 8px 0 4px !important; }
    .mb-4, .mb-3, .mb-2 { margin-bottom: 4px !important; }
    .gap-3 { gap: 4px !important; }
}

/* ---- SCREEN ---- */
.laporan-body { max-width: 960px; margin: 0 auto; }

.section-card {
    background: white;
    border-radius: 16px;
    box-shadow: 0 2px 16px rgba(15,23,42,0.07);
    border: 1px solid #e2e8f0;
    overflow: hidden;
    margin-bottom: 1.25rem;
}

.section-header {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 14px 20px;
    border-bottom: 1px solid #f1f5f9;
    font-weight: 700;
    font-size: .9rem;
    color: #0f172a;
}

.section-header .dot {
    width: 10px; height: 10px;
    border-radius: 50%;
    flex-shrink: 0;
}

.stat-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 0;
}

.stat-item {
    padding: 18px 20px;
    border-right: 1px solid #f1f5f9;
    text-align: center;
}
.stat-item:last-child { border-right: none; }
.stat-label { font-size: .72rem; font-weight: 600; text-transform: uppercase; letter-spacing: .06em; color: #94a3b8; margin-bottom: 4px; }
.stat-value { font-size: 1.15rem; font-weight: 700; }
.stat-value.green  { color: #059669; }
.stat-value.red    { color: #dc2626; }
.stat-value.blue   { color: #2563eb; }
.stat-value.slate  { color: #334155; }

.tx-table { width: 100%; border-collapse: collapse; font-size: .84rem; }
.tx-table th { background: #f8fafc; color: #64748b; font-size: .72rem; text-transform: uppercase; letter-spacing: .06em; padding: 10px 14px; border-bottom: 1px solid #e2e8f0; font-weight: 600; }
.tx-table td { padding: 9px 14px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
.tx-table tr:last-child td { border-bottom: none; }
.tx-table tr:hover td { background: #f8fafc; }
.tx-table tfoot td { background: #f8fafc; font-weight: 700; border-top: 2px solid #e2e8f0; }

.pill {
    display: inline-flex; align-items: center;
    padding: 3px 10px; border-radius: 999px;
    font-size: .75rem; font-weight: 600;
}
.pill-green  { background: #dcfce7; color: #15803d; }
.pill-yellow { background: #fef9c3; color: #854d0e; }
.pill-red    { background: #fee2e2; color: #b91c1c; }

.kop-title { font-size: 1.3rem; font-weight: 800; letter-spacing: -.01em; color: #0f172a; }
.kop-sub   { font-size: .85rem; color: #64748b; }

.ttd-space { height: 56px; border-bottom: 1px solid #e2e8f0; margin: 12px 0 6px; width: 200px; }
.col-6.text-end .ttd-space { margin-left: auto; }

.bar-wrap { background: #f1f5f9; border-radius: 999px; height: 8px; overflow: hidden; }
.bar-fill  { height: 100%; border-radius: 999px; background: linear-gradient(90deg, #ef4444, #f87171); }
</style>

<div class="content">

    <!-- TOOLBAR -->
    <div class="no-print mb-4 animate-fade-in">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h4 class="fw-bold mb-0">Laporan Kas</h4>
                <small class="text-muted">Preview & cetak laporan keuangan kelas</small>
            </div>
            <div class="d-flex gap-2">
                <button onclick="window.print()" class="btn-premium px-4 py-2">
                    <i class="bi bi-printer me-2"></i>Cetak
                </button>
                <button onclick="exportToPDF()" class="btn btn-light fw-semibold px-4 py-2" style="border-radius:12px;">
                    <i class="bi bi-file-earmark-pdf me-2"></i>PDF
                </button>
                <button onclick="exportToExcel()" class="btn btn-light fw-semibold px-4 py-2" style="border-radius:12px;">
                    <i class="bi bi-file-earmark-excel me-2"></i>Excel
                </button>
            </div>
        </div>
        <div class="filter-panel">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-auto">
                    <label class="form-label text-muted small fw-bold text-uppercase mb-1">Bulan</label>
                    <select name="bulan" class="form-select form-select-sm form-soft-control">
                        <option value="">Semua</option>
                        <?php for ($i=1;$i<=12;$i++): ?>
                            <option value="<?=$i?>" <?=$filter_bulan==$i?'selected':''?>><?=$bulan_nama[$i]?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <label class="form-label text-muted small fw-bold text-uppercase mb-1">Tahun</label>
                    <select name="tahun" class="form-select form-select-sm form-soft-control">
                        <?php for ($y=(int)date('Y')+1;$y>=2025;$y--): ?>
                            <option value="<?=$y?>" <?=$filter_tahun==$y?'selected':''?>><?=$y?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn-premium px-4 py-2">Filter</button>
                </div>
            </form>
        </div>
    </div>

    <!-- LAPORAN BODY -->
    <div class="laporan-body animate-fade-in">

        <!-- KOP -->
        <div class="section-card">
            <div class="p-4 d-flex justify-content-between align-items-center">
                <div>
                    <div class="kop-title">Laporan Keuangan Kas Kelas</div>
                    <div class="kop-sub mt-1">Periode: <strong><?= htmlspecialchars($periode_text) ?></strong></div>
                </div>
                <div class="text-end kop-sub">
                    Dicetak: <?= date('d/m/Y H:i') ?><br>
                    Oleh: <?= htmlspecialchars(current_user_name()) ?>
                </div>
            </div>
        </div>

        <!-- RINGKASAN -->
        <div class="section-card">
            <div class="section-header">
                <span class="dot" style="background:#2563eb;"></span>
                Ringkasan Keuangan
            </div>
            <div class="stat-grid stat-row">
                <div class="stat-item">
                    <div class="stat-label">Saldo Awal</div>
                    <div class="stat-value slate">Rp <?= number_format($saldo_awal,0,',','.') ?></div>
                </div>
                <div class="stat-item">
                    <div class="stat-label">Total Masuk</div>
                    <div class="stat-value green">+ Rp <?= number_format($total_masuk,0,',','.') ?></div>
                </div>
                <div class="stat-item">
                    <div class="stat-label">Total Keluar</div>
                    <div class="stat-value red">- Rp <?= number_format($total_keluar,0,',','.') ?></div>
                </div>
                <div class="stat-item">
                    <div class="stat-label">Saldo Akhir</div>
                    <div class="stat-value blue">Rp <?= number_format($saldo_akhir,0,',','.') ?></div>
                </div>
            </div>
        </div>

        <!-- DETAIL TRANSAKSI -->
        <div class="section-card">
            <div class="section-header">
                <span class="dot" style="background:#6366f1;"></span>
                Detail Transaksi
                <span class="ms-auto text-muted fw-normal" style="font-size:.78rem;"><?= count($transactions) ?> transaksi</span>
            </div>
            <div style="overflow-x:auto;">
                <table class="tx-table" id="tabelLaporan">
                    <thead>
                        <tr>
                            <th width="35">No</th>
                            <th width="90">Tanggal</th>
                            <th>Nama</th>
                            <th>Keterangan</th>
                            <th class="text-end">Masuk</th>
                            <th class="text-end">Keluar</th>
                            <th class="text-end">Saldo</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($transactions)): ?>
                        <tr style="background:#f8fafc;">
                            <td colspan="6" style="color:#94a3b8;font-style:italic;font-size:.78rem;">Saldo awal periode</td>
                            <td class="text-end fw-semibold" style="color:#334155;">Rp <?= number_format($saldo_awal,0,',','.') ?></td>
                        </tr>
                        <?php $no=1; foreach ($transactions as $r): ?>
                        <tr>
                            <td style="color:#94a3b8;text-align:center;"><?= $no++ ?></td>
                            <td style="color:#64748b;"><?= date('d/m/Y', strtotime($r['tanggal'])) ?></td>
                            <td class="fw-semibold"><?= $r['nama_murid'] ? htmlspecialchars($r['nama_murid']) : '<span style="color:#94a3b8;font-style:italic;">Umum</span>' ?></td>
                            <td style="color:#64748b;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars(substr($r['keterangan']??'',0,45)) ?></td>
                            <td class="text-end fw-semibold" style="color:#059669;"><?= $r['jenis']==='Masuk' ? 'Rp '.number_format($r['jumlah'],0,',','.') : '' ?></td>
                            <td class="text-end fw-semibold" style="color:#dc2626;"><?= $r['jenis']==='Keluar' ? 'Rp '.number_format($r['jumlah'],0,',','.') : '' ?></td>
                            <td class="text-end fw-semibold" style="color:#334155;">Rp <?= number_format($r['running_saldo'],0,',','.') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="7" style="text-align:center;padding:24px;color:#94a3b8;font-style:italic;">Tidak ada transaksi pada periode ini.</td></tr>
                    <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="4" class="text-end">Total Periode</td>
                            <td class="text-end" style="color:#059669;">Rp <?= number_format($total_masuk,0,',','.') ?></td>
                            <td class="text-end" style="color:#dc2626;">Rp <?= number_format($total_keluar,0,',','.') ?></td>
                            <td class="text-end" style="color:#2563eb;">Rp <?= number_format($saldo_akhir,0,',','.') ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- PENGELUARAN PER KATEGORI -->
        <?php if (!empty($pengeluaran_kategori)): ?>
        <div class="section-card">
            <div class="section-header">
                <span class="dot" style="background:#ef4444;"></span>
                Rincian Pengeluaran
            </div>
            <div class="p-4">
                <?php foreach ($pengeluaran_kategori as $kat => $jml):
                    $pct = $total_keluar > 0 ? round($jml/$total_keluar*100) : 0;
                ?>
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div style="min-width:130px;font-size:.85rem;font-weight:600;color:#334155;"><?= htmlspecialchars($kat) ?></div>
                    <div class="flex-grow-1">
                        <div class="bar-wrap">
                            <div class="bar-fill" style="width:<?= $pct ?>%;"></div>
                        </div>
                    </div>
                    <div style="min-width:120px;text-align:right;font-size:.85rem;font-weight:700;color:#dc2626;">Rp <?= number_format($jml,0,',','.') ?></div>
                    <div style="min-width:36px;text-align:right;font-size:.78rem;color:#94a3b8;"><?= $pct ?>%</div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- REKAP MURID -->
        <div class="section-card">
            <div class="section-header">
                <span class="dot" style="background:#10b981;"></span>
                Rekap Pembayaran Murid
                <div class="ms-auto d-flex gap-2">
                    <span class="pill pill-green">✓ <?= $total_lunas ?> Lunas</span>
                    <span class="pill pill-yellow">~ <?= $total_sebagian ?> Sebagian</span>
                    <span class="pill pill-red">✗ <?= $total_belum ?> Belum</span>
                </div>
            </div>
            <div style="overflow-x:auto;">
                <table class="tx-table" id="tabelRekapMurid">
                    <thead>
                        <tr>
                            <th width="35">No</th>
                            <th>Nama Murid</th>
                            <th class="text-end">Bayar Periode</th>
                            <th class="text-end">Sisa</th>
                            <th class="text-end">Tunggakan</th>
                            <th class="text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($rekap_murid)): $no=1; foreach ($rekap_murid as $rm): ?>
                    <tr <?= $rm['total_tunggakan']>0 ? 'style="background:#fff8f8;"' : '' ?>>
                        <td style="color:#94a3b8;text-align:center;"><?= $no++ ?></td>
                        <td class="fw-semibold"><?= htmlspecialchars($rm['nama']) ?></td>
                        <td class="text-end fw-semibold" style="color:#059669;">Rp <?= number_format($rm['bayar_periode'],0,',','.') ?></td>
                        <td class="text-end fw-semibold" style="color:<?= $rm['sisa_periode']>0?'#dc2626':'#94a3b8' ?>;">
                            <?= $rm['sisa_periode']>0 ? 'Rp '.number_format($rm['sisa_periode'],0,',','.') : '—' ?>
                        </td>
                        <td class="text-end" style="color:<?= $rm['total_tunggakan']>0?'#dc2626':'#94a3b8' ?>;font-weight:<?= $rm['total_tunggakan']>0?'700':'400' ?>;">
                            <?= $rm['total_tunggakan']>0 ? 'Rp '.number_format($rm['total_tunggakan'],0,',','.').' <span style="font-weight:400;font-size:.75rem;color:#94a3b8;">('.$rm['bulan_nunggak'].' bln)</span>' : '—' ?>
                        </td>
                        <td class="text-center">
                            <?php if ($rm['status']==='Lunas'): ?>
                                <span class="pill pill-green">Lunas</span>
                            <?php elseif ($rm['status']==='Sebagian'): ?>
                                <span class="pill pill-yellow">Sebagian</span>
                            <?php else: ?>
                                <span class="pill pill-red">Belum Bayar</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="6" style="text-align:center;padding:20px;color:#94a3b8;">Tidak ada data murid aktif.</td></tr>
                    <?php endif; ?>
                    </tbody>
                    <?php if ($total_tunggakan > 0): ?>
                    <tfoot>
                        <tr>
                            <td colspan="4" class="text-end">Total Tunggakan Kumulatif</td>
                            <td class="text-end" style="color:#dc2626;">Rp <?= number_format($total_tunggakan,0,',','.') ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>

        <!-- TANDA TANGAN -->
        <div class="section-card">
            <div class="p-4">
                <div class="row">
                    <div class="col-6">
                        <div class="kop-sub mb-1">Mengetahui,</div>
                        <div class="fw-semibold" style="font-size:.9rem;">Wali Kelas</div>
                        <div class="ttd-space"></div>
                        <div class="kop-sub">(__________________________)</div>
                    </div>
                    <div class="col-6 text-end">
                        <div class="kop-sub mb-1">Bendahara Kelas,</div>
                        <div class="fw-semibold" style="font-size:.9rem;"><?= htmlspecialchars(current_user_name()) ?></div>
                        <div class="ttd-space"></div>
                        <div class="kop-sub">(__________________________)</div>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /laporan-body -->
</div><!-- /content -->

<script>
function exportToPDF() {
    const btn = event.target.closest('button');
    const oriText = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Membuat PDF...';
    btn.disabled = true;

    const el = document.querySelector('.laporan-body');

    html2canvas(el, {
        scale: 2,
        useCORS: true,
        backgroundColor: '#ffffff',
    }).then(canvas => {
        const { jsPDF } = window.jspdf;
        const pdf = new jsPDF('p', 'mm', 'a4');
        const pageW = pdf.internal.pageSize.getWidth();
        const pageH = pdf.internal.pageSize.getHeight();
        const imgW  = pageW;
        const imgH  = (canvas.height * imgW) / canvas.width;
        const imgData = canvas.toDataURL('image/jpeg', 0.95);

        let y = 0;
        while (y < imgH) {
            if (y > 0) pdf.addPage();
            pdf.addImage(imgData, 'JPEG', 0, -y, imgW, imgH);
            y += pageH;
        }

        pdf.save('Laporan_Kas_<?= preg_replace('/\s+/','_',$periode_text) ?>.pdf');
        btn.innerHTML = oriText;
        btn.disabled = false;
    });
}

function exportToExcel() {
    const periode = '<?= addslashes($periode_text) ?>';
    const dicetak = '<?= date('d/m/Y H:i') ?>';

    let html = `
        <html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel">
        <head><meta charset="UTF-8">
        <style>
            table { border-collapse: collapse; }
            th, td { border: 1px solid #ccc; padding: 6px 10px; font-size: 11px; }
            th { background: #f0f0f0; font-weight: bold; }
            .title { font-size: 14px; font-weight: bold; }
            .green { color: #059669; } .red { color: #dc2626; } .blue { color: #2563eb; }
        </style></head><body>
        <table>
            <tr><td colspan="7" class="title">LAPORAN KEUANGAN KAS KELAS</td></tr>
            <tr><td colspan="7">Periode: ${periode}</td></tr>
            <tr><td colspan="7">Dicetak: ${dicetak}</td></tr>
            <tr><td colspan="7"></td></tr>
            <tr>
                <th>Saldo Awal</th><th>Total Masuk</th><th>Total Keluar</th><th>Saldo Akhir</th>
                <td colspan="3"></td>
            </tr>
            <tr>
                <td>Rp <?= number_format($saldo_awal,0,',','.') ?></td>
                <td class="green">Rp <?= number_format($total_masuk,0,',','.') ?></td>
                <td class="red">Rp <?= number_format($total_keluar,0,',','.') ?></td>
                <td class="blue">Rp <?= number_format($saldo_akhir,0,',','.') ?></td>
                <td colspan="3"></td>
            </tr>
            <tr><td colspan="7"></td></tr>
        </table><br>`;

    for (const id of ['tabelLaporan', 'tabelRekapMurid']) {
        const el = document.getElementById(id);
        if (!el) continue;
        html += `<p style="font-weight:bold;font-size:12px;">${id === 'tabelLaporan' ? 'DETAIL TRANSAKSI' : 'REKAP PEMBAYARAN MURID'}</p>`;
        html += '<table>';
        for (const row of el.querySelectorAll('tr')) {
            html += '<tr>';
            for (const cell of row.querySelectorAll('th,td')) {
                const tag = cell.tagName.toLowerCase();
                html += `<${tag}>${cell.innerText.trim()}</${tag}>`;
            }
            html += '</tr>';
        }
        html += '</table><br>';
    }

    html += '</body></html>';

    const blob = new Blob([html], { type: 'application/vnd.ms-excel;charset=utf-8;' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'Laporan_Kas_<?= preg_replace('/\s+/','_',$periode_text) ?>.xls';
    a.click();
}
</script>

<?php include "../layout/footer.php"; ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
