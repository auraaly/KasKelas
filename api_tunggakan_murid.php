<?php
include "config/koneksi.php";
require_once "config/auth.php";
require_once "config/kas.php";

if (!can_access('kas_masuk')) {
    http_response_code(403);
    echo json_encode(['error' => 'Akses ditolak.']);
    exit;
}

header('Content-Type: application/json');

$id_murid = (int) ($_GET['id_murid'] ?? 0);
if ($id_murid <= 0) {
    echo json_encode(['error' => 'ID tidak valid.']);
    exit;
}

$bulan_skrg = (int) date('n');
$tahun_skrg = (int) date('Y');
$nama_bulan = nama_bulan_indonesia();

$periodeAwal = get_periode_awal_kas($conn, $bulan_skrg, $tahun_skrg);
$tahunMulai  = (int) $periodeAwal['tahun'];
$bulanMulai  = (int) $periodeAwal['bulan'];

$tunggakan = [];
$total = 0;

for ($tahun = $tahunMulai; $tahun <= $tahun_skrg; $tahun++) {
    $bulanAwal = ($tahun === $tahunMulai) ? $bulanMulai : 1;
    for ($bulan = $bulanAwal; $bulan <= 12; $bulan++) {
        if ($tahun === $tahun_skrg && $bulan > $bulan_skrg) break;
        $kasWajib   = get_kas_wajib_bulanan($conn, $bulan, $tahun);
        $sudahBayar = get_total_bayar_murid_per_periode($conn, $id_murid, $bulan, $tahun);
        $sisa       = max($kasWajib - $sudahBayar, 0);
        if ($sisa > 0) {
            $tunggakan[] = ['label' => ($nama_bulan[$bulan] ?? $bulan) . ' ' . $tahun, 'sisa' => $sisa];
            $total += $sisa;
        }
    }
}

// Mode riwayat lengkap
if (isset($_GET['riwayat'])) {
    $qRiwayat = mysqli_query($conn, "
        SELECT tanggal, bulan, tahun, jumlah, keterangan
        FROM transaksi
        WHERE jenis = 'Masuk' AND id_murid = $id_murid
        ORDER BY tahun ASC, bulan ASC, tanggal ASC
    ");
    $riwayat = [];
    $total_bayar = 0;
    while ($r = mysqli_fetch_assoc($qRiwayat)) {
        $riwayat[] = [
            'tanggal'    => date('d/m/Y', strtotime($r['tanggal'])),
            'periode'    => ($nama_bulan[$r['bulan']] ?? $r['bulan']) . ' ' . $r['tahun'],
            'jumlah'     => (int)$r['jumlah'],
            'keterangan' => $r['keterangan'],
        ];
        $total_bayar += (int)$r['jumlah'];
    }
    echo json_encode([
        'riwayat'         => $riwayat,
        'total_bayar'     => $total_bayar,
        'total_tunggakan' => $total,
        'jumlah_bulan'    => count($tunggakan),
        'bersih'          => empty($tunggakan),
    ]);
    exit;
}

if (empty($tunggakan)) {
    echo json_encode(['bersih' => true]);
} else {
    echo json_encode(['bersih' => false, 'tunggakan' => $tunggakan, 'total' => $total]);
}
