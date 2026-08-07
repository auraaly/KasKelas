<?php

declare(strict_types=1);

const LIMIT_PENGAJUAN_WALI_KELAS = 100000;

function ensure_pengajuan_pengeluaran_table(mysqli $conn): void
{
    $sql = "
        CREATE TABLE IF NOT EXISTS pengajuan_pengeluaran (
            id_pengajuan INT AUTO_INCREMENT PRIMARY KEY,
            jumlah INT NOT NULL,
            kategori VARCHAR(100) NULL,
            keterangan TEXT NOT NULL,
            bukti_file VARCHAR(255) NULL,
            status ENUM('Menunggu', 'Disetujui', 'Ditolak') NOT NULL DEFAULT 'Menunggu',
            catatan_wali TEXT NULL,
            diajukan_oleh_id INT NULL,
            disetujui_oleh_id INT NULL,
            id_transaksi_keluar INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            decided_at DATETIME NULL
        )
    ";

    mysqli_query($conn, $sql);

    ensure_pengeluaran_columns($conn);
}

function ensure_pengeluaran_columns(mysqli $conn): void
{
    $kolomTransaksi = [];
    $resultTransaksi = mysqli_query($conn, "SHOW COLUMNS FROM transaksi");
    if ($resultTransaksi) {
        while ($row = mysqli_fetch_assoc($resultTransaksi)) {
            $kolomTransaksi[] = $row['Field'];
        }
    }

    if (!in_array('kategori', $kolomTransaksi, true)) {
        mysqli_query($conn, "ALTER TABLE transaksi ADD COLUMN kategori VARCHAR(100) NULL AFTER id_murid");
        $kolomTransaksi[] = 'kategori';
    }

    if (!in_array('bukti_file', $kolomTransaksi, true)) {
        mysqli_query($conn, "ALTER TABLE transaksi ADD COLUMN bukti_file VARCHAR(255) NULL AFTER keterangan");
    }

    $kolomPengajuan = [];
    $resultPengajuan = mysqli_query($conn, "SHOW COLUMNS FROM pengajuan_pengeluaran");
    if ($resultPengajuan) {
        while ($row = mysqli_fetch_assoc($resultPengajuan)) {
            $kolomPengajuan[] = $row['Field'];
        }
    }

    if (!in_array('kategori', $kolomPengajuan, true)) {
        mysqli_query($conn, "ALTER TABLE pengajuan_pengeluaran ADD COLUMN kategori VARCHAR(100) NULL AFTER jumlah");
    }

    if (!in_array('bukti_file', $kolomPengajuan, true)) {
        mysqli_query($conn, "ALTER TABLE pengajuan_pengeluaran ADD COLUMN bukti_file VARCHAR(255) NULL AFTER keterangan");
    }

    if (!in_array('created_at', $kolomPengajuan, true)) {
        mysqli_query($conn, "ALTER TABLE pengajuan_pengeluaran ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER id_transaksi_keluar");
    }

    if (!in_array('decided_at', $kolomPengajuan, true)) {
        mysqli_query($conn, "ALTER TABLE pengajuan_pengeluaran ADD COLUMN decided_at DATETIME NULL AFTER created_at");
    }

    if (in_array('tanggal', $kolomPengajuan, true)) {
        mysqli_query($conn, "ALTER TABLE pengajuan_pengeluaran MODIFY COLUMN tanggal DATE NULL DEFAULT NULL");
    }

    if (in_array('bulan', $kolomPengajuan, true)) {
        mysqli_query($conn, "ALTER TABLE pengajuan_pengeluaran MODIFY COLUMN bulan TINYINT NULL DEFAULT NULL");
    }

    if (in_array('tahun', $kolomPengajuan, true)) {
        mysqli_query($conn, "ALTER TABLE pengajuan_pengeluaran MODIFY COLUMN tahun SMALLINT NULL DEFAULT NULL");
    }
}

function format_status_pengajuan(string $status): array
{
    return match ($status) {
        'Disetujui' => ['class' => 'success', 'icon' => 'bi-check-circle'],
        'Ditolak' => ['class' => 'danger', 'icon' => 'bi-x-circle'],
        default => ['class' => 'warning', 'icon' => 'bi-hourglass-split'],
    };
}

function daftar_kategori_pengeluaran(): array
{
    return [
        'Sevenpeduli & Kas OSIS',
        'ATK',
        'Konsumsi',
        'Kebersihan',
        'Dekorasi',
        'Transport',
        'Kegiatan Kelas',
        'Fotokopi',
        'Lainnya',
    ];
}

function kategori_rutin(): array
{
    return ['Sevenpeduli & Kas OSIS'];
}

function is_kategori_rutin(string $kategori): bool
{
    return in_array($kategori, kategori_rutin(), true);
}

function datetime_pengajuan(array $pengajuan, array $preferredFields = ['created_at', 'tanggal', 'decided_at']): ?DateTimeImmutable
{
    foreach ($preferredFields as $field) {
        $value = trim((string) ($pengajuan[$field] ?? ''));

        if ($value === '' || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') {
            continue;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            continue;
        }
    }

    return null;
}

function format_tanggal_pengajuan(array $pengajuan): string
{
    $datetime = datetime_pengajuan($pengajuan, ['tanggal', 'created_at', 'decided_at']);

    return $datetime ? $datetime->format('d/m/Y') : '-';
}

function format_jam_pengajuan(array $pengajuan): string
{
    $datetime = datetime_pengajuan($pengajuan, ['created_at', 'tanggal', 'decided_at']);

    return $datetime ? $datetime->format('H:i') : '-';
}

function tanggal_transaksi_dari_pengajuan(array $pengajuan): array
{
    $datetime = datetime_pengajuan($pengajuan, ['tanggal', 'created_at', 'decided_at']);

    if (!$datetime) {
        $datetime = new DateTimeImmutable();
    }

    return [
        'tanggal' => $datetime->format('Y-m-d'),
        'bulan' => (int) $datetime->format('n'),
        'tahun' => (int) $datetime->format('Y'),
    ];
}

function created_at_pengajuan_dari_input(string $tanggal): string
{
    $datetime = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $tanggal . ' ' . date('H:i:s'));

    if ($datetime instanceof DateTimeImmutable) {
        return $datetime->format('Y-m-d H:i:s');
    }

    return date('Y-m-d H:i:s');
}

function simpan_bukti_pengeluaran(array $file): ?string
{
    if (empty($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload file bukti gagal.');
    }

    $allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf', 'webp'];
    $originalName = (string) ($file['name'] ?? '');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if (!in_array($extension, $allowedExtensions, true)) {
        throw new RuntimeException('Format file bukti harus JPG, JPEG, PNG, WEBP, atau PDF.');
    }

    if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
        throw new RuntimeException('Ukuran file bukti maksimal 2MB.');
    }

    $uploadDir = dirname(__DIR__) . '/uploads/pengeluaran';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Folder upload bukti tidak bisa dibuat.');
    }

    $filename = 'bukti-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
    $targetPath = $uploadDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new RuntimeException('File bukti gagal disimpan.');
    }

    return '/KasKelas/uploads/pengeluaran/' . $filename;
}
