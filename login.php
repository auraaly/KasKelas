<?php
require_once "config/koneksi.php";
require_once "config/auth.php";

require_guest();

$errors = [];
$username = '';
$password = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '') {
        $errors[] = "Username wajib diisi.";
    } elseif (!preg_match('/^[A-Za-z0-9._]+$/', $username)) {
        $errors[] = "Username hanya boleh berisi huruf, angka, titik, atau garis bawah.";
    }

    if ($password === '') {
        $errors[] = "Password wajib diisi.";
    }

    if (empty($errors)) {
        $stmt = mysqli_prepare($conn, "SELECT id_user, nama, username, password, role FROM user WHERE username = ? LIMIT 1");

        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "s", $username);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $user = mysqli_fetch_assoc($result);

            $validPassword = $user && (
                password_verify($password, $user['password']) ||
                $password === $user['password']
            );

            if ($validPassword) {
                login_user($user);
                $_SESSION['flash_login_success'] = "Login berhasil. Selamat datang, " . current_user_name() . ".";
                header("Location: /KasKelas/dashboard.php");
                exit;
            }
        }

        $errors[] = "Username atau password salah.";
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Kas Kelas</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/KasKelas/assets/style.css">
</head>
<body class="login-page">
    <div class="login-shell">
        <section class="login-showcase">
            <div class="login-badge">
                <i class="bi bi-shield-check"></i> Sistem Kas Kelas
            </div>
            <h1>Kelola kas kelas dengan mudah, transparan, dan terorganisir.</h1>
            <p>Pencatatan iuran, pengeluaran, dan laporan kelas tersimpan di satu tempat yang bisa dipantau kapan saja.</p>

            <div class="login-points">
                <div class="login-point">
                    <i class="bi bi-bar-chart-line-fill"></i>
                    <div>
                        <strong>Pantau kas secara langsung</strong><br>
                        <small>Saldo, status pembayaran, dan arus kas tersaji dalam satu dashboard.</small>
                    </div>
                </div>
                <div class="login-point">
                    <i class="bi bi-wallet2"></i>
                    <div>
                        <strong>Catat pemasukan & pengeluaran</strong><br>
                        <small>Iuran murid dan pengeluaran kelas tercatat rapi setiap bulan.</small>
                    </div>
                </div>
                <div class="login-point">
                    <i class="bi bi-journal-richtext"></i>
                    <div>
                        <strong>Laporan siap cetak</strong><br>
                        <small>Rekap bulanan bisa difilter dan dicetak langsung dari aplikasi.</small>
                    </div>
                </div>
            </div>
        </section>

        <section class="login-card">
            <img src="/KasKelas/assets/logosmakenju.png" alt="Logo Kas Kelas" class="login-logo">
            <h2>Login Akun</h2>
            <p>Masukkan username dan password untuk melanjutkan.</p>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger border-0 shadow-sm">
                    <ul class="mb-0 ps-3">
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST" novalidate>
                <div class="mb-3">
                    <label class="form-label text-muted small fw-bold text-uppercase">Username</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-person"></i></span>
                        <input
                            type="text"
                            name="username"
                            class="form-control form-soft-control border-start-0"
                            placeholder="Masukkan username"
                            value="<?= htmlspecialchars($username) ?>"
                            maxlength="50"
                            autocomplete="username"
                            required
                        >
                    </div>
                    <small class="text-muted d-block mt-2">Gunakan username yang sudah terdaftar di sistem.</small>
                </div>

                <div class="mb-4">
                    <label class="form-label text-muted small fw-bold text-uppercase">Password</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-lock"></i></span>
                        <input
                            type="password"
                            name="password"
                            id="inputPassword"
                            class="form-control form-soft-control border-start-0 border-end-0"
                            placeholder="Masukkan password"
                            autocomplete="current-password"
                            required
                        >
                        <button type="button" class="input-group-text bg-white border-start-0" id="togglePassword" style="cursor:pointer;">
                            <i class="bi bi-eye" id="eyeIcon"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn login-submit w-100">Masuk ke Dashboard</button>
            </form>

        </section>
    </div>

<script>
document.getElementById('togglePassword').addEventListener('click', function () {
    const input = document.getElementById('inputPassword');
    const icon  = document.getElementById('eyeIcon');
    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'bi bi-eye-slash';
    } else {
        input.type = 'password';
        icon.className = 'bi bi-eye';
    }
});
</script>
</body>
</html>

