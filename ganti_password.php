<?php
include "config/koneksi.php";
require_once "config/auth.php";
require_login();
include "layout/header.php";

$success = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password_lama  = $_POST['password_lama'] ?? '';
    $password_baru  = $_POST['password_baru'] ?? '';
    $password_ulang = $_POST['password_ulang'] ?? '';
    $user_id        = (int) ($_SESSION['user_id'] ?? 0);

    if ($password_lama === '') $errors[] = "Password lama wajib diisi.";
    if (strlen($password_baru) < 6) $errors[] = "Password baru minimal 6 karakter.";
    if ($password_baru !== $password_ulang) $errors[] = "Konfirmasi password tidak cocok.";

    if (empty($errors)) {
        $stmt = mysqli_prepare($conn, "SELECT password FROM user WHERE id_user = ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        $valid = $row && (
            password_verify($password_lama, $row['password']) ||
            $password_lama === $row['password']
        );

        if (!$valid) {
            $errors[] = "Password lama salah.";
        } else {
            $hash = password_hash($password_baru, PASSWORD_DEFAULT);
            $stmt2 = mysqli_prepare($conn, "UPDATE user SET password = ? WHERE id_user = ?");
            mysqli_stmt_bind_param($stmt2, "si", $hash, $user_id);
            if (mysqli_stmt_execute($stmt2)) {
                $success = "Password berhasil diubah.";
            } else {
                $errors[] = "Gagal menyimpan password baru.";
            }
            mysqli_stmt_close($stmt2);
        }
    }
}
?>

<?php include "layout/sidebar.php"; ?>

<div class="content">
    <div class="container-fluid animate-fade-in" style="max-width:480px;">

        <div class="top-header d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4>Ganti Password</h4>
                <small class="text-white-50">Ubah password akun kamu</small>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success border-0"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger border-0">
                <ul class="mb-0 small ps-3"><?php foreach ($errors as $e) echo "<li>" . htmlspecialchars($e) . "</li>"; ?></ul>
            </div>
        <?php endif; ?>

        <div class="form-panel">
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label text-muted small fw-bold text-uppercase">Password Lama</label>
                    <input type="password" name="password_lama" class="form-control form-soft-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label text-muted small fw-bold text-uppercase">Password Baru</label>
                    <input type="password" name="password_baru" class="form-control form-soft-control" required minlength="6">
                </div>
                <div class="mb-4">
                    <label class="form-label text-muted small fw-bold text-uppercase">Ulangi Password Baru</label>
                    <input type="password" name="password_ulang" class="form-control form-soft-control" required>
                </div>
                <button type="submit" class="btn-premium w-100 py-2">Simpan Password</button>
            </form>
        </div>

    </div>
</div>

<?php include "layout/footer.php"; ?>
