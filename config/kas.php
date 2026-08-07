<?php

function nama_bulan_indonesia(): array
{
    return [
        1 => 'Januari',
        2 => 'Februari',
        3 => 'Maret',
        4 => 'April',
        5 => 'Mei',
        6 => 'Juni',
        7 => 'Juli',
        8 => 'Agustus',
        9 => 'September',
        10 => 'Oktober',
        11 => 'November',
        12 => 'Desember',
    ];
}

function get_kas_wajib_bulanan(mysqli $conn, int $bulan, int $tahun, int $defaultNominal = 20000): int
{
    $namaBulan = nama_bulan_indonesia();
    $bulanLabel = $namaBulan[$bulan] ?? null;

    if ($bulanLabel === null) {
        return $defaultNominal;
    }

    $stmt = mysqli_prepare($conn, "
        SELECT nominal
        FROM kas_bulanan
        WHERE bulan = ? AND tahun = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return $defaultNominal;
    }

    mysqli_stmt_bind_param($stmt, "si", $bulanLabel, $tahun);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);

    return (int) ($row['nominal'] ?? $defaultNominal);
}

function get_total_bayar_murid_per_periode(mysqli $conn, int $idMurid, int $bulan, int $tahun): int
{
    $stmt = mysqli_prepare($conn, "
        SELECT COALESCE(SUM(jumlah), 0) AS total
        FROM transaksi
        WHERE jenis = 'Masuk'
          AND id_murid = ?
          AND bulan = ?
          AND tahun = ?
    ");

    if (!$stmt) {
        return 0;
    }

    mysqli_stmt_bind_param($stmt, "iii", $idMurid, $bulan, $tahun);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);

    return (int) ($row['total'] ?? 0);
}

function get_periode_awal_default(int $bulanAcuan, int $tahunAcuan, int $bulanMulai = 7): array
{
    $bulanMulai = max(1, min(12, $bulanMulai));
    $tahunMulai = $tahunAcuan;

    if ($bulanAcuan < $bulanMulai) {
        $tahunMulai--;
    }

    return [
        'bulan' => $bulanMulai,
        'tahun' => $tahunMulai,
    ];
}

function get_periode_awal_kas(mysqli $conn, int $bulanAcuan, int $tahunAcuan): array
{
    $periodeDefault = get_periode_awal_default($bulanAcuan, $tahunAcuan);
    $tahunReferensi = [$periodeDefault['tahun']];

    $resultKasBulanan = mysqli_query($conn, "SELECT MIN(tahun) AS tahun_awal FROM kas_bulanan");
    if ($resultKasBulanan && $row = mysqli_fetch_assoc($resultKasBulanan)) {
        $tahunAwal = (int) ($row['tahun_awal'] ?? 0);
        if ($tahunAwal > 0) {
            $tahunReferensi[] = $tahunAwal;
        }
    }

    $resultTransaksi = mysqli_query($conn, "SELECT MIN(tahun) AS tahun_awal FROM transaksi WHERE jenis = 'Masuk'");
    if ($resultTransaksi && $row = mysqli_fetch_assoc($resultTransaksi)) {
        $tahunAwal = (int) ($row['tahun_awal'] ?? 0);
        if ($tahunAwal > 0) {
            $tahunReferensi[] = $tahunAwal;
        }
    }

    if (empty($tahunReferensi)) {
        return $periodeDefault;
    }

    return [
        'bulan' => min($tahunReferensi) < $periodeDefault['tahun'] ? 1 : $periodeDefault['bulan'],
        'tahun' => min($tahunReferensi),
    ];
}

function get_periode_tunggakan_terlama(mysqli $conn, int $idMurid, int $bulanAcuan, int $tahunAcuan, int $defaultNominal = 20000): array
{
    $periodeAwal = get_periode_awal_kas($conn, $bulanAcuan, $tahunAcuan);
    $tahunMulai = (int) $periodeAwal['tahun'];
    $bulanMulai = (int) $periodeAwal['bulan'];

    for ($tahun = $tahunMulai; $tahun <= $tahunAcuan; $tahun++) {
        $bulanAwalDiTahun = $tahun === $tahunMulai ? $bulanMulai : 1;

        for ($bulan = $bulanAwalDiTahun; $bulan <= 12; $bulan++) {
            if ($tahun === $tahunAcuan && $bulan > $bulanAcuan) {
                break;
            }

            $kasWajib = get_kas_wajib_bulanan($conn, $bulan, $tahun, $defaultNominal);
            $sudahDibayar = get_total_bayar_murid_per_periode($conn, $idMurid, $bulan, $tahun);

            if ($sudahDibayar < $kasWajib) {
                return [
                    'bulan' => $bulan,
                    'tahun' => $tahun,
                ];
            }
        }
    }

    return [
        'bulan' => $bulanAcuan,
        'tahun' => $tahunAcuan,
    ];
}

function get_ringkasan_tunggakan_murid(mysqli $conn, int $idMurid, int $bulanAcuan, int $tahunAcuan, int $defaultNominal = 20000): array
{
    $periodeAwal = get_periode_awal_kas($conn, $bulanAcuan, $tahunAcuan);
    $tahunMulai = (int) $periodeAwal['tahun'];
    $bulanMulai = (int) $periodeAwal['bulan'];
    $namaBulan = nama_bulan_indonesia();

    $totalTunggakan = 0;
    $jumlahBulanNunggak = 0;
    $periodeTerlama = null;
    $periodeTerbaru = null;

    for ($tahun = $tahunMulai; $tahun <= $tahunAcuan; $tahun++) {
        $bulanAwalDiTahun = $tahun === $tahunMulai ? $bulanMulai : 1;

        for ($bulan = $bulanAwalDiTahun; $bulan <= 12; $bulan++) {
            if ($tahun === $tahunAcuan && $bulan > $bulanAcuan) {
                break;
            }

            $kasWajib = get_kas_wajib_bulanan($conn, $bulan, $tahun, $defaultNominal);
            $sudahDibayar = get_total_bayar_murid_per_periode($conn, $idMurid, $bulan, $tahun);
            $sisaPeriode = max($kasWajib - $sudahDibayar, 0);

            if ($sisaPeriode <= 0) {
                continue;
            }

            if ($periodeTerlama === null) {
                $periodeTerlama = [
                    'bulan' => $bulan,
                    'tahun' => $tahun,
                    'label' => ($namaBulan[$bulan] ?? $bulan) . ' ' . $tahun,
                ];
            }

            $periodeTerbaru = [
                'bulan' => $bulan,
                'tahun' => $tahun,
                'label' => ($namaBulan[$bulan] ?? $bulan) . ' ' . $tahun,
            ];

            $jumlahBulanNunggak++;
            $totalTunggakan += $sisaPeriode;
        }
    }

    return [
        'jumlah_bulan' => $jumlahBulanNunggak,
        'total_tunggakan' => $totalTunggakan,
        'periode_terlama' => $periodeTerlama,
        'periode_terbaru' => $periodeTerbaru,
    ];
}

function alokasikan_pembayaran_kas(mysqli $conn, int $idMurid, int $bulanMulai, int $tahunMulai, int $jumlahBayar, int $defaultNominal = 20000): array
{
    $alokasi = [];
    $sisaBayar = $jumlahBayar;
    $periodeMulai = get_periode_tunggakan_terlama($conn, $idMurid, $bulanMulai, $tahunMulai, $defaultNominal);
    $bulan = (int) $periodeMulai['bulan'];
    $tahun = (int) $periodeMulai['tahun'];
    $batasLoop = 240;

    $bulanMaks = (int) date('n');
    $tahunMaks = (int) date('Y');

    while ($sisaBayar > 0 && $batasLoop-- > 0) {
        // Jangan alokasikan ke bulan yang belum terjadi
        if ($tahun > $tahunMaks || ($tahun === $tahunMaks && $bulan > $bulanMaks)) {
            break;
        }

        $kasWajib = get_kas_wajib_bulanan($conn, $bulan, $tahun, $defaultNominal);
        $sudahDibayar = get_total_bayar_murid_per_periode($conn, $idMurid, $bulan, $tahun);
        $sisaPeriode = max($kasWajib - $sudahDibayar, 0);

        if ($sisaPeriode > 0) {
            $nominalAlokasi = min($sisaBayar, $sisaPeriode);
            $alokasi[] = [
                'bulan' => $bulan,
                'tahun' => $tahun,
                'jumlah' => $nominalAlokasi,
                'kas_wajib' => $kasWajib,
            ];
            $sisaBayar -= $nominalAlokasi;
        }

        $bulan++;
        if ($bulan > 12) {
            $bulan = 1;
            $tahun++;
        }
    }

    return [
        'alokasi' => $alokasi,
        'sisa' => $sisaBayar,
    ];
}

function kirim_wa_pembayaran(string $no_hp, string $nama, int $jumlah_bayar, int $bulan, int $tahun, int $total_tunggakan): bool
{
    $namaBulan = nama_bulan_indonesia();
    $labelBulan = $namaBulan[$bulan] ?? (string) $bulan;
    $tgl = date('d/m/Y');

    $tunggakanText = $total_tunggakan > 0
        ? 'Rp ' . number_format($total_tunggakan, 0, ',', '.')
        : 'Tidak ada tunggakan';

    $pesan = "Halo {$nama} 👋\n\n"
        . "Pembayaran kas bulan {$labelBulan} {$tahun} sebesar Rp " . number_format($jumlah_bayar, 0, ',', '.') . " sudah diterima ✅\n"
        . "Tanggal pembayaran: {$tgl}\n"
        . "Total tunggakan saat ini: {$tunggakanText}\n\n"
        . "Terima kasih 🙏";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'https://api.fonnte.com/send',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => [
            'target'  => $no_hp,
            'message' => $pesan,
        ],
        CURLOPT_HTTPHEADER => [
            'Authorization: 15ZeycH2DVn46kNUPfoK',
        ],
    ]);

    $response = curl_exec($ch);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($err) return false;

    $data = json_decode($response, true);
    return !empty($data['status']);
}
