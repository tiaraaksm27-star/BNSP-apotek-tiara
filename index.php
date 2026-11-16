<?php
session_start();

// 1. Konfigurasi Multi-Environment (Dev / Staging / Prod)
$env = $_GET['env'] ?? ($_SESSION['env'] ?? 'dev');
if (!in_array($env, ['dev', 'staging', 'prod'])) { $env = 'dev'; }
$_SESSION['env'] = $env;

$configFile = __DIR__ . "/config_{$env}.json";
$config = file_exists($configFile) ? json_decode(file_get_contents($configFile), true) : ['db_host' => 'localhost', 'db_name' => 'db_apotek_tiara', 'environment' => 'DEVELOPMENT'];

// 2. Proses Logout
if (isset($_GET['logout'])) {
    unset($_SESSION['user'], $_SESSION['keranjang']);
    header("Location: index.php?env={$env}");
    exit;
}

// 3. Jika Sudah Login, Redirect Otomatis ke Dashboard Sesuai Peran
if (isset($_SESSION['user'])) {
    $peran = $_SESSION['user']['peran'];
    if ($peran === 'Admin Pusat') {
        header("Location: dashboard_admin.php");
    } elseif ($peran === 'Apoteker') {
        header("Location: dashboard_apoteker.php");
    } else {
        header("Location: dashboard_kasir.php");
    }
    exit;
}

$loginError = null;

// 4. Proses Autentikasi Login ke Database
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'login') {
    $inputUser = trim($_POST['username'] ?? '');
    $inputPass = $_POST['password'] ?? '';

    try {
        $dsn = "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4";
        // Menggunakan akun DB Admin Pusat untuk melakukan verifikasi login tabel pengguna
        $pdo = new PDO($dsn, 'app_admin_pusat', 'AdminPusat#2026', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $stmt = $pdo->prepare("
            SELECT u.*, c.nama_cabang 
            FROM pengguna u 
            LEFT JOIN cabang c ON u.kode_cabang = c.kode_cabang 
            WHERE u.username = ?
        ");
        $stmt->execute([$inputUser]);
        $userRow = $stmt->fetch();

        // Verifikasi password (mendukung password_hash bcrypt atau plain teks)
        if ($userRow && (password_verify($inputPass, $userRow['password_hash'] ?? $userRow['password'] ?? '') || $inputPass === ($userRow['password_hash'] ?? $userRow['password'] ?? ''))) {
            $_SESSION['user'] = [
                'id_pengguna'  => $userRow['id_pengguna'],
                'username'     => $userRow['username'],
                'nama_lengkap' => $userRow['nama_lengkap'],
                'peran'        => $userRow['peran'],
                'kode_cabang'  => $userRow['kode_cabang'],
                'nama_cabang'  => $userRow['nama_cabang'] ?? 'Kantor Pusat'
            ];

            // Arahkan ke file dashboard masing-masing peran
            if ($userRow['peran'] === 'Admin Pusat') {
                header("Location: dashboard_admin.php");
            } elseif ($userRow['peran'] === 'Apoteker') {
                header("Location: dashboard_apoteker.php");
            } else {
                header("Location: dashboard_kasir.php");
            }
            exit;
        } else {
            $loginError = "Username atau Password salah!";
        }
    } catch (PDOException $e) {
        $loginError = "Koneksi Database Gagal: " . htmlspecialchars($e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — Apotek Multi-Cabang</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { min-height: 100vh; background: linear-gradient(135deg, #eaf4fc 0%, #d8edf9 100%); display: flex; align-items: center; justify-content: center; font-family: 'Plus Jakarta Sans', sans-serif; }
        .login-card { background: #fff; border-radius: 24px; padding: 36px; box-shadow: 0 15px 35px rgba(29, 99, 184, 0.08); width: 100%; max-width: 420px; border: 1px solid #d9eaf8; }
        .icon-box { width: 52px; height: 52px; background: linear-gradient(135deg, #4aa3ff 0%, #1d74dc 100%); color: #fff; border-radius: 14px; display: inline-flex; align-items: center; justify-content: center; font-size: 1.6rem; margin-bottom: 12px; box-shadow: 0 8px 18px rgba(29, 116, 220, 0.25); }
    </style>
</head>
<body>
    <div class="login-card text-center">
        <!-- Ikon Logo Atas -->
        <div class="icon-box">
            <i class="bi bi-plus-lg"></i>
        </div>
        
        <h4 class="fw-bold mb-1" style="color:#153e6c;">Apotek Multi-Cabang</h4>
        <p class="text-secondary small mb-2">PT Sehat Bersama Farma &hearts;</p>
        
        <div class="mb-3">
            <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-3 py-1 fw-bold" style="font-size:0.7rem;">
                ENV: <?= strtoupper($config['environment'] ?? 'DEVELOPMENT') ?>
            </span>
        </div>

        <?php if ($loginError): ?>
            <div class="alert alert-danger py-2 small rounded-3 mb-3 text-start"><?= $loginError ?></div>
        <?php endif; ?>

        <form method="POST" class="text-start">
            <input type="hidden" name="aksi" value="login">
            <div class="mb-3">
                <label class="form-label small fw-semibold text-secondary">Username Pegawai</label>
                <input type="text" name="username" class="form-control rounded-3 py-2" placeholder="admin_pusat / apoteker_jkt / kasir_jakarta" required autofocus>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold text-secondary">Password</label>
                <input type="password" name="password" class="form-control rounded-3 py-2" placeholder="••••••••" required>
            </div>
            <button type="submit" class="btn btn-primary w-100 rounded-3 fw-bold py-2 shadow-sm mb-3">
                <i class="bi bi-shield-lock-fill me-1"></i> Masuk Sistem
            </button>
        </form>

        <!-- Panduan Akun Uji Coba (Sesuai Screenshot) -->
        <div class="row pt-3 border-top text-secondary small text-start" style="font-size: 0.75rem;">
            <div class="col-7">
                <div><code>admin_pusat</code></div>
                <div><code>apoteker_jkt</code></div>
                <div><code>kasir_jakarta</code></div>
            </div>
            <div class="col-5 text-end fw-semibold text-dark">
                <div>Admin Pusat</div>
                <div>Apoteker (JKT)</div>
                <div>Kasir (JKT)</div>
            </div>
        </div>
    </div>
</body>
</html>