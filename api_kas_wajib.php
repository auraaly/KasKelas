<?php
include "config/koneksi.php";
require_once "config/auth.php";
require_once "config/kas.php";

// Hanya bendahara yang boleh
if (!can_access('kas_masuk')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'pesan' => 'Akses ditolak.']);
    exit;
}

header('Content-Type: application/json');

// Auto-migrate kolom catatan & unique key kalau belum ada
$chk = mysqli_query($conn, "SHOW COLUMNS FROM kas_bulanan LIKE 'catatan'");
if ($chk && mysqli_num_rows($chk) === 0) {
    mysqli_query($conn, "ALTER TABLE kas_bulanan ADD COLUMN catatan VARCHAR(255) DEFAULT '' AFTER nominal");
    $chkIdx = mysqli_query($conn, "SHOW INDEX FROM kas_bulanan WHERE Key_name = 'unique_bulan_tahun'");
    if (!$chkIdx || mysqli_num_rows($chkIdx) === 0)
        mysqli_query($conn, "ALTER TABLE kas_bulanan ADD UNIQUE KEY unique_bulan_tahun (bulan, tahun)");
}

$namaBulan = nama_bulan_indonesia();

// GET — ambil nominal bulan tertentu
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $bulan = (int) ($_GET['bulan'] ?? date('n'));
    $tahun = (int) ($_GET['tahun'] ?? date('Y'));
    $label = $namaBulan[$bulan] ?? null;

    $nominal = 20000;
    $catatan = '';

    if ($label) {
        $stmt = mysqli_prepare($conn, "SELECT nominal, catatan FROM kas_bulanan WHERE bulan = ? AND tahun = ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, "si", $label, $tahun);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if ($row) {
            $nominal = (int) $row['nominal'];
            $catatan = $row['catatan'] ?? '';
        }
    }

    echo json_encode(['nominal' => $nominal, 'catatan' => $catatan]);
    exit;
}

// POST — simpan nominal
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bulan   = (int) ($_POST['kb_bulan'] ?? 0);
    $tahun   = (int) ($_POST['kb_tahun'] ?? 0);
    $nominal = (int) ($_POST['kb_nominal'] ?? 0);
    $catatan = trim($_POST['kb_catatan'] ?? '');
    $label   = $namaBulan[$bulan] ?? null;

    if (!$label || $tahun < 2025 || $nominal < 0) {
        echo json_encode(['ok' => false, 'pesan' => 'Data tidak valid.']);
        exit;
    }

    $stmt = mysqli_prepare($conn, "
        INSERT INTO kas_bulanan (bulan, tahun, nominal, catatan)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE nominal = VALUES(nominal), catatan = VALUES(catatan)
    ");
    mysqli_stmt_bind_param($stmt, "siis", $label, $tahun, $nominal, $catatan);

    if (mysqli_stmt_execute($stmt)) {
        $pesan = "Kas {$label} {$tahun} diset ke Rp " . number_format($nominal, 0, ',', '.') . ($catatan ? " ({$catatan})" : "") . ".";
        echo json_encode(['ok' => true, 'pesan' => $pesan]);
    } else {
        echo json_encode(['ok' => false, 'pesan' => 'Gagal simpan: ' . mysqli_stmt_error($stmt)]);
    }
    mysqli_stmt_close($stmt);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'pesan' => 'Method tidak diizinkan.']);
