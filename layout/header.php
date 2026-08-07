<?php 
$page = basename($_SERVER['PHP_SELF']);
$base_url = "/KasKelas/";

$breadcrumbs = [
    'dashboard.php'              => ['Dashboard'],
    'kas_masuk.php'              => ['Dashboard', 'Kas Masuk'],
    'kas_keluar.php'             => ['Dashboard', 'Kas Keluar'],
    'arus_kas.php'               => ['Dashboard', 'Arus Kas'],
    'status_pembayaran.php'      => ['Dashboard', 'Status Pembayaran'],
    'laporan.php'                => ['Dashboard', 'Laporan'],
    'laporan_kas.php'            => ['Dashboard', 'Laporan', 'Cetak Laporan'],
    'data_murid.php'             => ['Dashboard', 'Data Murid'],
    'pengajuan_pengeluaran.php'  => ['Dashboard', 'Pengajuan Pengeluaran'],
    'pengaturan_kas.php'         => ['Dashboard', 'Kas Masuk', 'Pengaturan Kas'],
    'ganti_password.php'         => ['Dashboard', 'Ganti Password'],
];
$currentBreadcrumb = $breadcrumbs[$page] ?? null;
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Kas Kelas - Premium Finance Management</title>

<!-- Google Fonts -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<!-- Bootstrap CSS -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

<!-- Bootstrap Icons -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

<!-- Custom CSS -->
<link rel="stylesheet" href="/KasKelas/assets/style.css">

</head>
<body>