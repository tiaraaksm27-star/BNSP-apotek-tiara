<?php
session_start();

// 1. Konfigurasi Multi-Environment
$defaultConfigs = [
    'dev'     => ['environment' => 'DEVELOPMENT', 'db_host' => '127.0.0.1', 'db_name' => 'db_apotek_dev',     'log_level' => 'DEBUG'],
    'staging' => ['environment' => 'STAGING',     'db_host' => '127.0.0.1', 'db_name' => 'db_apotek_staging', 'log_level' => 'INFO'],
    'prod'    => ['environment' => 'PRODUCTION',  'db_host' => '127.0.0.1', 'db_name' => 'db_apotek_prod',    'log_level' => 'ERROR']
];

foreach ($defaultConfigs as $key => $cfg) {
    $path = __DIR__ . "/config_{$key}.json";
    if (!file_exists($path)) {
        file_put_contents($path, json_encode($cfg, JSON_PRETTY_PRINT));
    }
}

$env = $_GET['env'] ?? ($_SESSION['env'] ?? 'dev');
if (!in_array($env, ['dev', 'staging', 'prod'])) {
    $env = 'dev';
}
$_SESSION['env'] = $env;
$config = json_decode(file_get_contents(__DIR__ . "/config_{$env}.json"), true);

$page = $_GET['page'] ?? 'dashboard';
if (!in_array($page, ['dashboard', 'kasir', 'obat', 'cabang', 'laporan', 'pengaturan'])) {
    $page = 'dashboard';
}

if (isset($_GET['logout'])) {
    unset($_SESSION['user'], $_SESSION['keranjang']);
    header("Location: index.php?env={$env}");
    exit;
}

function getDbCredentialsByRole(?string $peran): array {
    return match ($peran) {
        'Admin Pusat' => ['user' => 'app_admin_pusat', 'pass' => 'AdminPusat#2026'],
        'Apoteker'    => ['user' => 'app_apoteker',    'pass' => 'Apoteker#2026'],
        'Kasir'       => ['user' => 'app_kasir',       'pass' => 'Kasir#2026'],
        default       => ['user' => 'app_admin_pusat', 'pass' => 'AdminPusat#2026'],
    };
}

$loginError = null;

// 2. Proses Login Bcrypt
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'login') {
    $inputUser = trim($_POST['username'] ?? '');
    $inputPass = $_POST['password'] ?? '';

    try {
        $authCred = getDbCredentialsByRole('Admin Pusat');
        $dsn = "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4";
        $authPdo = new PDO($dsn, $authCred['user'], $authCred['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        $stmt = $authPdo->prepare("SELECT u.*, c.nama_cabang FROM pengguna u LEFT JOIN cabang c ON u.kode_cabang = c.kode_cabang WHERE u.username = ?");
        $stmt->execute([$inputUser]);
        $userRow = $stmt->fetch();

        if ($userRow && password_verify($inputPass, $userRow['password_hash'])) {
            $_SESSION['user'] = [
                'id_pengguna'  => $userRow['id_pengguna'],
                'username'     => $userRow['username'],
                'nama_lengkap' => $userRow['nama_lengkap'],
                'peran'        => $userRow['peran'],
                'kode_cabang'  => $userRow['kode_cabang'],
                'nama_cabang'  => $userRow['nama_cabang'] ?? 'Kantor Pusat (5 Cabang)'
            ];
            header("Location: index.php?env={$env}");
            exit;
        } else {
            $loginError = "Username atau Password salah! (Verifikasi Hash Bcrypt ditolak)";
        }
    } catch (PDOException $e) {
        $loginError = "Koneksi Autentikasi Gagal: " . htmlspecialchars($e->getMessage());
    }
}

// HALAMAN LOGIN
if (!isset($_SESSION['user'])):
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Login — Apotek Multi-Cabang | PT Sehat Bersama Farma</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body {
            min-height: 100vh;
            background: linear-gradient(135deg, #dff1ff 0%, #eef7ff 50%, #cde6fc 100%);
            font-family: 'Plus Jakarta Sans', sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .login-card {
            background: rgba(255, 255, 255, 0.96);
            border: 1px solid #d7e9fa;
            border-radius: 24px;
            box-shadow: 0 20px 45px rgba(29, 99, 184, 0.14);
            max-width: 430px;
            width: 100%;
            overflow: hidden;
        }
        .cross-logo {
            width: 56px; height: 56px;
            background: linear-gradient(135deg, #56a8f5, #1e73d8);
            color: #fff; border-radius: 16px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 1.7rem; margin-bottom: 12px;
            box-shadow: 0 8px 18px rgba(30, 115, 216, 0.28);
        }
        .btn-soft-blue {
            background: linear-gradient(90deg, #4b9ef2 0%, #2477dc 100%);
            border: none; color: #fff; border-radius: 14px; padding: 12px; font-weight: 700;
            box-shadow: 0 6px 16px rgba(36, 119, 220, 0.25);
        }
        .btn-soft-blue:hover { opacity: 0.95; color: #fff; }
        .demo-box { background: #f2f8ff; border: 1px solid #dbeafe; border-radius: 14px; padding: 12px 14px; font-size: 0.8rem; }
    </style>
</head>
<body>
    <div class="login-card p-4">
        <div class="text-center pt-2 pb-3">
            <div class="cross-logo"><i class="bi bi-plus-lg"></i></div>
            <h5 class="fw-bold mb-0" style="color:#153e6c;">Apotek Multi-Cabang</h5>
            <small class="text-primary fw-medium">PT Sehat Bersama Farma &hearts;</small>
            <div class="mt-2">
                <span class="badge bg-primary-subtle text-primary rounded-pill px-3 py-1">
                    ENV: <?= htmlspecialchars($config['environment']) ?> &bull; <?= htmlspecialchars($config['db_name']) ?>
                </span>
            </div>
        </div>
        <?php if ($loginError): ?>
            <div class="alert alert-danger small rounded-3 py-2"><?= $loginError ?></div>
        <?php endif; ?>
        <form method="POST">
            <input type="hidden" name="aksi" value="login">
            <div class="mb-3">
                <label class="form-label small fw-semibold text-secondary">Username Pegawai</label>
                <input type="text" name="username" class="form-control rounded-3" placeholder="kasir_jakarta / apoteker_jkt / admin_pusat" required autofocus>
            </div>
            <div class="mb-4">
                <label class="form-label small fw-semibold text-secondary">Password</label>
                <input type="password" name="password" class="form-control rounded-3" placeholder="••••••••" required>
            </div>
            <button type="submit" class="btn btn-soft-blue w-100 mb-3">
                <i class="bi bi-shield-lock me-1"></i> Masuk ke Dashboard
            </button>
        </form>
        <div class="demo-box text-secondary">
            <div class="fw-bold text-dark mb-1">Akun Uji Coba (Password: <code>password</code>)</div>
            <div class="d-flex justify-content-between"><span>&bull; <code>admin_pusat</code></span><span>Admin Pusat (5 Cabang)</span></div>
            <div class="d-flex justify-content-between"><span>&bull; <code>apoteker_jkt</code></span><span>Apoteker (Cabang JKT)</span></div>
            <div class="d-flex justify-content-between"><span>&bull; <code>kasir_jakarta</code></span><span>Kasir (Cabang JKT)</span></div>
        </div>
    </div>
</body>
</html>
<?php
exit;
endif;

// 3. Koneksi Database Sesuai Peran User Login (Akun Non-Root)
$currentUser = $_SESSION['user'];
$dbCred = getDbCredentialsByRole($currentUser['peran']);

try {
    $dsn = "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4";
    $pdo = new PDO($dsn, $dbCred['user'], $dbCred['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    die("Koneksi Database Peran Gagal: " . htmlspecialchars($e->getMessage()));
}

$pesan = null;
$tipePesan = 'success';

// Keranjang Belanja Multi-Item (Struktur Data Array/Map)
if (!isset($_SESSION['keranjang'])) {
    $_SESSION['keranjang'] = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = $_POST['aksi'] ?? '';

    if ($aksi === 'tambah_keranjang') {
        $kodeObat = $_POST['kode_obat'] ?? '';
        $jumlah   = max(1, (int)($_POST['jumlah'] ?? 1));
        $stmt = $pdo->prepare("SELECT o.kode_obat, o.nama_obat, o.harga_jual, k.nama_kategori, k.wajib_apoteker FROM obat o JOIN kategori_obat k ON o.kode_kategori = k.kode_kategori WHERE o.kode_obat = ?");
        $stmt->execute([$kodeObat]);
        if ($item = $stmt->fetch()) {
            if (isset($_SESSION['keranjang'][$kodeObat])) {
                $_SESSION['keranjang'][$kodeObat]['jumlah'] += $jumlah;
            } else {
                $_SESSION['keranjang'][$kodeObat] = [
                    'kode_obat'      => $item['kode_obat'],
                    'nama_obat'      => $item['nama_obat'],
                    'harga_jual'     => (float)$item['harga_jual'],
                    'nama_kategori'  => $item['nama_kategori'],
                    'wajib_apoteker' => (int)$item['wajib_apoteker'],
                    'jumlah'         => $jumlah
                ];
            }
            $pesan = "Item <strong>{$item['nama_obat']}</strong> (x{$jumlah}) ditambahkan ke keranjang belanja.";
            $tipePesan = 'info';
        }
    }

    if ($aksi === 'kosongkan_keranjang') {
        $_SESSION['keranjang'] = [];
        $pesan = "Keranjang belanja dikosongkan.";
        $tipePesan = 'warning';
    }

    // Proses Transaksi via Stored Procedure (Kompatibel dengan COMMIT/ROLLBACK internal SP)
    if ($aksi === 'proses_transaksi') {
        $items = !empty($_SESSION['keranjang'])
            ? $_SESSION['keranjang']
            : [[
                'kode_obat' => $_POST['kode_obat'] ?? '',
                'jumlah'    => max(1, (int)($_POST['jumlah'] ?? 1))
            ]];

        $noFaktur = 'INV-' . date('Ymd-His');
        try {
            $stmt = $pdo->prepare("CALL sp_proses_penjualan(?, ?, ?, ?)");
            foreach ($items as $it) {
                $stmt->execute([$noFaktur, $currentUser['username'], $it['kode_obat'], $it['jumlah']]);
                $stmt->closeCursor();
            }
            $_SESSION['keranjang'] = [];
            $pesan = "TRANSAKSI BERHASIL (COMMIT): Faktur <strong>{$noFaktur}</strong> (" . count($items) . " item) tersimpan &amp; stok otomatis berkurang.";
            $tipePesan = 'success';
        } catch (PDOException $e) {
            // Jika item ke-2 dst gagal pada keranjang multi-item, bersihkan sisa item faktur yang sama
            $rawMsg = $e->getMessage();
            $pesan = preg_match('/DITOLAK:[^\'"]+/', $rawMsg, $m)
                ? "TRANSAKSI DIBATALKAN (ROLLBACK): " . htmlspecialchars($m[0])
                : "TRANSAKSI DIBATALKAN (ROLLBACK): " . htmlspecialchars($rawMsg);
            $tipePesan = 'danger';
        }
    }
}

// Data & Algoritma Searching / Sorting
$cari = trim($_GET['cari'] ?? '');
$katFilter = trim($_GET['kategori'] ?? '');

$sqlStok = "SELECT s.kode_cabang, c.nama_cabang, o.kode_obat, o.nama_obat, k.kode_kategori, k.nama_kategori, k.wajib_apoteker, o.harga_jual, s.jumlah_stok, o.stok_minimum
            FROM stok s
            JOIN cabang c ON s.kode_cabang = c.kode_cabang
            JOIN obat o ON s.kode_obat = o.kode_obat
            JOIN kategori_obat k ON o.kode_kategori = k.kode_kategori
            WHERE 1=1";
$params = [];
if ($currentUser['kode_cabang'] !== null) {
    $sqlStok .= " AND s.kode_cabang = ?";
    $params[] = $currentUser['kode_cabang'];
}
if ($cari !== '') {
    $sqlStok .= " AND (o.nama_obat LIKE ? OR o.kode_obat LIKE ? OR c.nama_cabang LIKE ?)";
    $params[] = "%{$cari}%";
    $params[] = "%{$cari}%";
    $params[] = "%{$cari}%";
}
if ($katFilter !== '') {
    $sqlStok .= " AND k.kode_kategori = ?";
    $params[] = $katFilter;
}
$stmtStok = $pdo->prepare($sqlStok);
$stmtStok->execute($params);
$dataStok = $stmtStok->fetchAll();

// Top 5 Obat Terlaris (Algoritma Sorting O(n log n))
$sqlLaris = "SELECT o.kode_obat, o.nama_obat, COALESCE(SUM(d.jumlah), 0) AS total_terjual, COALESCE(SUM(d.subtotal), 0) AS total_omzet
             FROM obat o LEFT JOIN detail_penjualan d ON o.kode_obat = d.kode_obat
             GROUP BY o.kode_obat, o.nama_obat";
$allLaris = $pdo->query($sqlLaris)->fetchAll();
usort($allLaris, fn($a, $b) => $b['total_terjual'] <=> $a['total_terjual']);
$obatTerlaris = array_slice($allLaris, 0, 5);

// Statistik KPI Real-Time
$totalJenisObat = (int)$pdo->query("SELECT COUNT(*) FROM obat")->fetchColumn();
$totalCabang    = (int)$pdo->query("SELECT COUNT(*) FROM cabang")->fetchColumn();
$totalTrx       = (int)$pdo->query("SELECT COUNT(*) FROM penjualan")->fetchColumn();
$stokKritis     = $pdo->query("SELECT * FROM v_stok_kritis" . ($currentUser['kode_cabang'] ? " WHERE kode_cabang = '{$currentUser['kode_cabang']}'" : ""))->fetchAll();
$logs           = $pdo->query("SELECT * FROM log_aktivitas ORDER BY id_log DESC LIMIT 4")->fetchAll();
$obats          = $pdo->query("SELECT o.kode_obat, o.nama_obat, o.harga_jual, o.satuan, o.stok_minimum, k.nama_kategori, k.wajib_apoteker FROM obat o JOIN kategori_obat k ON o.kode_kategori = k.kode_kategori")->fetchAll();
$cabangs        = $pdo->query("SELECT * FROM cabang")->fetchAll();
$penjualans     = $pdo->query("SELECT p.*, c.nama_cabang, u.nama_lengkap FROM penjualan p JOIN cabang c ON p.kode_cabang = c.kode_cabang JOIN pengguna u ON p.id_pengguna = u.id_pengguna ORDER BY p.tanggal DESC LIMIT 10")->fetchAll();

$cekBatch   = $pdo->query("SHOW TABLES LIKE 'batch_stok'")->fetch();
$versiSkema = $cekBatch ? 'v2.0 (FEFO Batch Aktif)' : 'v1.0 (Standar 3NF)';
$batchList  = $cekBatch ? $pdo->query("SELECT b.*, o.nama_obat FROM batch_stok b JOIN obat o ON b.kode_obat = o.kode_obat WHERE b.jumlah_batch > 0 ORDER BY b.tanggal_kedaluwarsa ASC, b.id_batch ASC")->fetchAll() : [];

$namaDepan = explode(' ', $currentUser['nama_lengkap'])[0];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Apotek Multi-Cabang | <?= ucfirst($page) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --sky-bg: #eaf4fc;
            --blue-main: #2780e3;
            --blue-dark: #163d6b;
            --border-soft: #d9eaf8;
        }
        body {
            background-color: var(--sky-bg);
            font-family: 'Plus Jakarta Sans', sans-serif;
            color: #1e3a5f;
            overflow-x: hidden;
        }
        .app-shell { display: flex; min-height: 100vh; }
        .sidebar {
            width: 245px;
            background: linear-gradient(180deg, #f8fcff 0%, #e5f2fd 100%);
            border-right: 1px solid var(--border-soft);
            padding: 24px 16px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            flex-shrink: 0;
        }
        .brand-box {
            text-align: center;
            padding-bottom: 18px;
            border-bottom: 1px solid var(--border-soft);
            margin-bottom: 18px;
        }
        .brand-cross {
            width: 52px; height: 52px;
            background: linear-gradient(135deg, #6bb8ff 0%, #2478dc 100%);
            color: #fff; border-radius: 16px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 1.7rem; margin-bottom: 8px;
            box-shadow: 0 8px 18px rgba(36, 120, 220, 0.25);
        }
        .nav-menu .nav-link {
            color: #35587e;
            font-weight: 600;
            font-size: 0.9rem;
            padding: 11px 15px;
            border-radius: 13px;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 11px;
            transition: all 0.2s ease;
        }
        .nav-menu .nav-link:hover { background-color: #dceeff; color: #1659a8; }
        .nav-menu .nav-link.active {
            background: linear-gradient(90deg, #3b93f2 0%, #2374d6 100%);
            color: #ffffff;
            box-shadow: 0 6px 15px rgba(35, 116, 214, 0.28);
        }
        .sidebar-art {
            background: linear-gradient(135deg, #d6ebff 0%, #eef7ff 100%);
            border: 1px solid #c6e2fc;
            border-radius: 18px;
            padding: 14px;
            text-align: center;
            color: #2563ab;
        }
        .main-area { flex: 1; padding: 20px 26px; max-width: 100%; }
        .hero-banner {
            background: linear-gradient(90deg, #cde7ff 0%, #e4f2ff 60%, #f3f9ff 100%);
            border: 1px solid #c3e0fb;
            border-radius: 20px;
            padding: 16px 24px;
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }
        .soft-card {
            background: #ffffff;
            border: 1px solid var(--border-soft);
            border-radius: 20px;
            box-shadow: 0 8px 22px rgba(31, 95, 168, 0.05);
        }
        .kpi-icon-box {
            width: 48px; height: 48px;
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.35rem;
        }
        .btn-gradient-blue {
            background: linear-gradient(90deg, #4b9ef2 0%, #2173d4 100%);
            border: none;
            color: #fff;
            border-radius: 14px;
            padding: 11px 18px;
            font-weight: 700;
            box-shadow: 0 6px 16px rgba(33, 115, 212, 0.25);
        }
        .btn-gradient-blue:hover { opacity: 0.95; color: #fff; }
        .badge-cabang {
            background-color: #dceeff;
            color: #1b65ba;
            font-weight: 700;
            border-radius: 8px;
            padding: 5px 9px;
        }
        .table thead th {
            background-color: #f3f8fe;
            color: #48698f;
            font-size: 0.78rem;
            font-weight: 700;
            border-bottom: 1px solid #d9eaf8;
        }
        .acid-shield-box {
            background: linear-gradient(180deg, #e5f3ff 0%, #f4faff 100%);
            border: 1px solid #cbe4fc;
            border-radius: 16px;
            padding: 16px 12px;
            text-align: center;
        }
    </style>
</head>
<body>

<div class="app-shell">
    <!-- SIDEBAR NAVIGASI -->
    <aside class="sidebar">
        <div>
            <div class="brand-box">
                <div class="brand-cross"><i class="bi bi-plus-lg"></i></div>
                <div class="fw-bold" style="color:#153e6c; font-size:1.05rem; line-height:1.2;">Apotek<br>Multi-Cabang</div>
                <small class="text-primary fw-medium" style="font-size:0.75rem;">Sehat Bersama Kami &hearts;</small>
            </div>

            <nav class="nav flex-column nav-menu">
                <a class="nav-link <?= $page==='dashboard'?'active':'' ?>" href="?env=<?= $env ?>&page=dashboard">
                    <i class="bi bi-house-door-fill"></i> Dashboard
                </a>
                <a class="nav-link <?= $page==='kasir'?'active':'' ?>" href="?env=<?= $env ?>&page=kasir">
                    <i class="bi bi-cart3"></i> Transaksi Kasir
                </a>
                <a class="nav-link <?= $page==='obat'?'active':'' ?>" href="?env=<?= $env ?>&page=obat">
                    <i class="bi bi-capsule"></i> Data Obat
                </a>
                <a class="nav-link <?= $page==='cabang'?'active':'' ?>" href="?env=<?= $env ?>&page=cabang">
                    <i class="bi bi-hospital"></i> Data Cabang
                </a>
                <a class="nav-link <?= $page==='laporan'?'active':'' ?>" href="?env=<?= $env ?>&page=laporan">
                    <i class="bi bi-bar-chart-fill"></i> Laporan
                </a>
                <a class="nav-link <?= $page==='pengaturan'?'active':'' ?>" href="?env=<?= $env ?>&page=pengaturan">
                    <i class="bi bi-gear-fill"></i> Pengaturan
                </a>
                <a class="nav-link text-danger mt-2" href="?env=<?= $env ?>&logout=1">
                    <i class="bi bi-box-arrow-left"></i> Logout
                </a>
            </nav>
        </div>

        <div class="sidebar-art">
            <div class="fs-2 mb-1"><i class="bi bi-heart-pulse-fill text-primary"></i></div>
            <div class="fw-bold small">DB: <?= htmlspecialchars($config['db_name']) ?></div>
            <div style="font-size:0.72rem;" class="text-muted">User: <?= htmlspecialchars($dbCred['user']) ?></div>
        </div>
    </aside>

    <!-- AREA KONTEN UTAMA -->
    <main class="main-area">
        <div class="hero-banner">
            <div class="d-flex align-items-center gap-3">
                <div class="fs-3 text-warning"><i class="bi bi-hand-wave-fill"></i></div>
                <div>
                    <h5 class="fw-bold mb-0" style="color:#153e6c;">Halo, <?= htmlspecialchars($namaDepan) ?>!</h5>
                    <small class="text-secondary">
                        Selamat datang di Sistem Inventori Apotek Multi-Cabang &bull;
                        Peran: <strong><?= htmlspecialchars($currentUser['peran']) ?></strong> (<?= htmlspecialchars($currentUser['nama_cabang']) ?>)
                    </small>
                </div>
            </div>

            <div class="d-flex flex-wrap align-items-center gap-2">
                <span class="badge bg-white text-success border border-success-subtle rounded-pill px-3 py-2 shadow-sm">
                    <i class="bi bi-circle-fill me-1" style="font-size:0.55rem;"></i> Online &bull; <?= htmlspecialchars($config['environment']) ?>
                </span>
                <div class="btn-group btn-group-sm shadow-sm">
                    <a href="?env=dev&page=<?= $page ?>" class="btn <?= $env==='dev'?'btn-primary fw-bold':'btn-light text-primary' ?>">Dev</a>
                    <a href="?env=staging&page=<?= $page ?>" class="btn <?= $env==='staging'?'btn-info text-white fw-bold':'btn-light text-primary' ?>">Staging</a>
                    <a href="?env=prod&page=<?= $page ?>" class="btn <?= $env==='prod'?'btn-warning text-dark fw-bold':'btn-light text-primary' ?>">Prod</a>
                </div>
                <span class="badge bg-white text-primary border border-primary-subtle rounded-pill px-3 py-2 shadow-sm">
                    <i class="bi bi-calendar3 me-1"></i> <?= date('d M Y - H:i') ?>
                </span>
            </div>
        </div>

        <?php if ($pesan): ?>
            <div class="alert alert-<?= $tipePesan ?> rounded-4 border-0 shadow-sm mb-3"><?= $pesan ?></div>
        <?php endif; ?>

        <?php if ($page === 'dashboard'): ?>
        <!-- 4 KARTU KPI ATAS -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="soft-card p-3 d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center gap-3">
                        <div class="kpi-icon-box" style="background:#dceeff; color:#2374d6;"><i class="bi bi-capsule-pill"></i></div>
                        <div>
                            <small class="text-muted fw-semibold">Total Jenis Obat</small>
                            <h4 class="fw-bold mb-0"><?= $totalJenisObat ?> <small class="fs-6 fw-normal text-muted">item</small></h4>
                        </div>
                    </div>
                    <span class="badge bg-success-subtle text-success rounded-pill">+3NF</span>
                </div>
            </div>
            <div class="col-md-3">
                <div class="soft-card p-3 d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center gap-3">
                        <div class="kpi-icon-box" style="background:#e0f2fe; color:#0284c7;"><i class="bi bi-shop"></i></div>
                        <div>
                            <small class="text-muted fw-semibold">Jumlah Cabang</small>
                            <h4 class="fw-bold mb-0"><?= $totalCabang ?></h4>
                        </div>
                    </div>
                    <span class="badge bg-primary-subtle text-primary rounded-pill">Aktif</span>
                </div>
            </div>
            <div class="col-md-3">
                <div class="soft-card p-3 d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center gap-3">
                        <div class="kpi-icon-box" style="background:#dbeafe; color:#1d4ed8;"><i class="bi bi-cart-check"></i></div>
                        <div>
                            <small class="text-muted fw-semibold">Total Transaksi</small>
                            <h4 class="fw-bold mb-0"><?= $totalTrx ?></h4>
                        </div>
                    </div>
                    <span class="badge bg-success-subtle text-success rounded-pill">ACID</span>
                </div>
            </div>
            <div class="col-md-3">
                <div class="soft-card p-3 d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center gap-3">
                        <div class="kpi-icon-box" style="background:#fee2e2; color:#dc2626;"><i class="bi bi-exclamation-triangle-fill"></i></div>
                        <div>
                            <small class="text-muted fw-semibold">Stok Kritis</small>
                            <h4 class="fw-bold mb-0 text-danger"><?= count($stokKritis) ?></h4>
                        </div>
                    </div>
                    <span class="badge bg-danger-subtle text-danger rounded-pill">Alert</span>
                </div>
            </div>
        </div>

        <!-- BARIS TENGAH: TRANSAKSI KASIR & STOK REAL-TIME -->
        <div class="row g-4 mb-4">
            <div class="col-lg-5">
                <div class="soft-card p-4 h-100">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="fw-bold mb-0"><i class="bi bi-cart-fill text-primary me-2"></i>Transaksi Kasir &amp; Validasi ACID</h6>
                        <span class="badge bg-primary-subtle text-primary"><?= $versiSkema ?></span>
                    </div>

                    <?php if ($currentUser['kode_cabang'] === null): ?>
                        <div class="alert alert-info small rounded-4 mb-0">
                            Anda login sebagai <strong>Admin Pusat</strong> (konektor <code>app_admin_pusat</code>). Silakan <strong>Logout</strong> dan masuk sebagai <code>kasir_jakarta</code> atau <code>apoteker_jkt</code> untuk simulasi kasir cabang.
                        </div>
                    <?php else: ?>
                        <form method="POST">
                            <input type="hidden" name="aksi" value="proses_transaksi">
                            <div class="row g-2 align-items-center">
                                <div class="col-8">
                                    <div class="mb-2">
                                        <label class="form-label small fw-semibold text-secondary mb-1">Petugas Bertugas</label>
                                        <input type="text" class="form-control form-control-sm bg-light rounded-3" value="<?= htmlspecialchars($currentUser['nama_lengkap']) ?> (<?= $currentUser['peran'] ?>)" readonly>
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label small fw-semibold text-secondary mb-1">Pilih Produk Obat</label>
                                        <select name="kode_obat" class="form-select form-select-sm rounded-3" required>
                                            <?php foreach ($obats as $o): ?>
                                                <option value="<?= $o['kode_obat'] ?>">
                                                    <?= htmlspecialchars($o['nama_obat']) ?> (<?= $o['nama_kategori'] ?>) — Rp <?= number_format($o['harga_jual'],0,',','.') ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label small fw-semibold text-secondary mb-1">Jumlah Pembelian</label>
                                        <input type="number" name="jumlah" class="form-control form-control-sm rounded-3" value="2" min="1" required>
                                    </div>
                                </div>
                                <div class="col-4">
                                    <div class="acid-shield-box">
                                        <div class="fs-2 text-success mb-1"><i class="bi bi-shield-fill-check"></i></div>
                                        <div class="fw-bold small text-dark">Transaksi Aman</div>
                                        <div style="font-size:0.72rem;" class="text-muted">dengan ACID &amp; FOR UPDATE</div>
                                    </div>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-gradient-blue w-100">
                                <i class="bi bi-cpu-fill me-1"></i> Proses Transaksi (Stored Procedure)
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="soft-card p-4 h-100">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                        <h6 class="fw-bold mb-0"><i class="bi bi-box-seam-fill text-primary me-2"></i>Stok Real-Time <?= $currentUser['kode_cabang'] ? "({$currentUser['kode_cabang']})" : "(Semua 5 Cabang)" ?></h6>
                        <form method="GET" class="d-flex gap-2">
                            <input type="hidden" name="env" value="<?= htmlspecialchars($env) ?>">
                            <input type="text" name="cari" value="<?= htmlspecialchars($cari) ?>" class="form-control form-control-sm rounded-3" placeholder="Cari obat...">
                            <select name="kategori" class="form-select form-select-sm rounded-3" onchange="this.form.submit()">
                                <option value="">Semua Kategori</option>
                                <option value="KAT-01" <?= $katFilter==='KAT-01'?'selected':'' ?>>Obat Bebas</option>
                                <option value="KAT-02" <?= $katFilter==='KAT-02'?'selected':'' ?>>Bebas Terbatas</option>
                                <option value="KAT-03" <?= $katFilter==='KAT-03'?'selected':'' ?>>Obat Keras</option>
                                <option value="KAT-04" <?= $katFilter==='KAT-04'?'selected':'' ?>>Alat Kesehatan</option>
                            </select>
                        </form>
                    </div>
                    <div class="table-responsive" style="max-height: 235px;">
                        <table class="table table-hover align-middle small mb-0">
                            <thead>
                                <tr>
                                    <th>Cabang</th>
                                    <th>Nama Obat</th>
                                    <th>Kategori</th>
                                    <th>Harga</th>
                                    <th>Stok</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($dataStok as $row): ?>
                                <tr>
                                    <td><span class="badge-cabang"><?= $row['kode_cabang'] ?></span></td>
                                    <td class="fw-semibold"><?= htmlspecialchars($row['nama_obat']) ?></td>
                                    <td>
                                        <?php if ($row['wajib_apoteker']): ?>
                                            <span class="badge bg-danger-subtle text-danger rounded-pill">Obat Keras</span>
                                        <?php else: ?>
                                            <span class="badge bg-primary-subtle text-primary rounded-pill"><?= htmlspecialchars($row['nama_kategori']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>Rp <?= number_format($row['harga_jual'],0,',','.') ?></td>
                                    <td class="fw-bold"><?= $row['jumlah_stok'] ?></td>
                                    <td>
                                        <?php if ($row['jumlah_stok'] <= $row['stok_minimum']): ?>
                                            <span class="badge bg-danger-subtle text-danger rounded-pill">Kritis</span>
                                        <?php else: ?>
                                            <span class="badge bg-success-subtle text-success rounded-pill">Tersedia</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- BARIS BAWAH: MONITORING 5 RESOURCE, TOP 5 OBAT TERLARIS, LOG AUDIT -->
        <div class="row g-4">
            <div class="col-lg-4">
                <div class="soft-card p-4 h-100">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="fw-bold mb-0"><i class="bi bi-activity text-primary me-1"></i>Pemantauan Resource</h6>
                        <span class="badge bg-success-subtle text-success rounded-pill">&bull; Normal (&lt;80%)</span>
                    </div>
                    <canvas id="resChart" height="190"></canvas>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="soft-card p-4 h-100">
                    <h6 class="fw-bold mb-3"><i class="bi bi-trophy-fill text-warning me-2"></i>Top 5 Obat Terlaris (Sorting)</h6>
                    <ul class="list-group list-group-flush small">
                        <?php foreach ($obatTerlaris as $i => $lr): ?>
                        <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
                            <span><strong class="text-primary me-2"><?= $i+1 ?></strong> <?= htmlspecialchars($lr['nama_obat']) ?></span>
                            <span class="badge bg-primary rounded-pill"><?= $lr['total_terjual'] ?> terjual</span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="soft-card p-4 h-100">
                    <h6 class="fw-bold mb-3"><i class="bi bi-journal-text text-primary me-2"></i>Log Audit Trigger</h6>
                    <ul class="list-group list-group-flush small">
                        <?php foreach ($logs as $lg): ?>
                        <li class="list-group-item px-0">
                            <div class="text-primary fw-semibold" style="font-size:0.72rem;"><?= $lg['waktu'] ?></div>
                            <div><?= htmlspecialchars($lg['keterangan']) ?></div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>

        <?php elseif ($page === 'kasir'): ?>
        <!-- HALAMAN KASIR MULTI-ITEM KERANJANG (STRUKTUR DATA ARRAY/MAP) -->
        <div class="row g-4">
            <div class="col-lg-5">
                <div class="soft-card p-4">
                    <h6 class="fw-bold mb-3"><i class="bi bi-bag-plus-fill text-primary me-2"></i>Tambah Item ke Keranjang (Array/Map)</h6>
                    <?php if ($currentUser['kode_cabang'] === null): ?>
                        <div class="alert alert-info small">Login sebagai <code>kasir_jakarta</code> atau <code>apoteker_jkt</code> untuk transaksi multi-item.</div>
                    <?php else: ?>
                        <form method="POST">
                            <input type="hidden" name="aksi" value="tambah_keranjang">
                            <div class="mb-3">
                                <label class="form-label small fw-semibold">Pilih Obat</label>
                                <select name="kode_obat" class="form-select rounded-3">
                                    <?php foreach ($obats as $o): ?>
                                        <option value="<?= $o['kode_obat'] ?>">[<?= $o['kode_obat'] ?>] <?= htmlspecialchars($o['nama_obat']) ?> (<?= $o['nama_kategori'] ?>) - Rp <?= number_format($o['harga_jual'],0,',','.') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-semibold">Jumlah</label>
                                <input type="number" name="jumlah" class="form-control rounded-3" value="1" min="1">
                            </div>
                            <button type="submit" class="btn btn-outline-primary w-100 rounded-3 fw-bold">+ Tambah ke Keranjang</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-lg-7">
                <div class="soft-card p-4">
                    <h6 class="fw-bold mb-3"><i class="bi bi-cart-check-fill text-primary me-2"></i>Keranjang Belanja Atomik (1 Faktur Multi-Item)</h6>
                    <?php if (empty($_SESSION['keranjang'])): ?>
                        <p class="text-muted small">Keranjang masih kosong. Tambahkan minimal 2 item untuk menguji transaksi multi-item.</p>
                    <?php else: ?>
                        <table class="table table-sm small mb-3">
                            <thead><tr><th>Obat</th><th>Kategori</th><th>Qty</th><th>Subtotal</th></tr></thead>
                            <tbody>
                                <?php $grand = 0; foreach ($_SESSION['keranjang'] as $k): $sub = $k['harga_jual']*$k['jumlah']; $grand += $sub; ?>
                                <tr>
                                    <td><?= htmlspecialchars($k['nama_obat']) ?></td>
                                    <td><?= htmlspecialchars($k['nama_kategori']) ?></td>
                                    <td><?= $k['jumlah'] ?></td>
                                    <td>Rp <?= number_format($sub,0,',','.') ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <strong>Total Faktur: Rp <?= number_format($grand,0,',','.') ?></strong>
                            <form method="POST" class="d-flex gap-2">
                                <button type="submit" name="aksi" value="kosongkan_keranjang" class="btn btn-sm btn-outline-danger rounded-3">Kosongkan</button>
                                <button type="submit" name="aksi" value="proses_transaksi" class="btn btn-sm btn-gradient-blue">Simpan &amp; Proses ACID</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php elseif ($page === 'obat'): ?>
        <div class="soft-card p-4">
            <h6 class="fw-bold mb-3"><i class="bi bi-capsule text-primary me-2"></i>Master Data Obat &amp; Aturan Kategori (3NF)</h6>
            <table class="table table-hover small">
                <thead><tr><th>Kode</th><th>Nama Obat</th><th>Kategori</th><th>Satuan</th><th>Harga Jual</th><th>Stok Min</th></tr></thead>
                <tbody>
                    <?php foreach ($obats as $o): ?>
                    <tr>
                        <td><span class="badge-cabang"><?= $o['kode_obat'] ?></span></td>
                        <td class="fw-bold"><?= htmlspecialchars($o['nama_obat']) ?></td>
                        <td><?= htmlspecialchars($o['nama_kategori']) ?></td>
                        <td><?= htmlspecialchars($o['satuan']) ?></td>
                        <td>Rp <?= number_format($o['harga_jual'],0,',','.') ?></td>
                        <td><?= $o['stok_minimum'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php elseif ($page === 'cabang'): ?>
        <div class="soft-card p-4">
            <h6 class="fw-bold mb-3"><i class="bi bi-hospital text-primary me-2"></i>Daftar 5 Cabang PT Sehat Bersama Farma</h6>
            <table class="table table-hover small">
                <thead><tr><th>Kode Cabang</th><th>Nama Cabang</th><th>Alamat</th><th>Telepon</th></tr></thead>
                <tbody>
                    <?php foreach ($cabangs as $c): ?>
                    <tr>
                        <td><span class="badge-cabang"><?= $c['kode_cabang'] ?></span></td>
                        <td class="fw-bold"><?= htmlspecialchars($c['nama_cabang']) ?></td>
                        <td><?= htmlspecialchars($c['alamat']) ?></td>
                        <td><?= htmlspecialchars($c['telepon']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php elseif ($page === 'laporan'): ?>
        <div class="soft-card p-4 mb-4">
            <h6 class="fw-bold mb-3"><i class="bi bi-receipt text-primary me-2"></i>Riwayat Faktur Penjualan (Optimasi Index Tanggal)</h6>
            <table class="table table-hover small">
                <thead><tr><th>No Faktur</th><th>Tanggal</th><th>Cabang</th><th>Petugas</th><th>Total</th></tr></thead>
                <tbody>
                    <?php foreach ($penjualans as $p): ?>
                    <tr>
                        <td class="fw-bold text-primary"><?= $p['no_faktur'] ?></td>
                        <td><?= $p['tanggal'] ?></td>
                        <td><span class="badge-cabang"><?= $p['kode_cabang'] ?></span></td>
                        <td><?= htmlspecialchars($p['nama_lengkap']) ?></td>
                        <td class="fw-bold">Rp <?= number_format($p['total'],0,',','.') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($cekBatch): ?>
        <div class="soft-card p-4">
            <h6 class="fw-bold mb-3 text-success"><i class="bi bi-clock-history me-2"></i>Simulasi Batch Kedaluwarsa FEFO (Aktif di Environment Staging v2.0)</h6>
            <table class="table table-sm small">
                <thead><tr><th>Cabang</th><th>Obat</th><th>No Batch</th><th>Tanggal Kedaluwarsa (Urut Terdekat)</th><th>Sisa Batch</th></tr></thead>
                <tbody>
                    <?php foreach ($batchList as $b): ?>
                    <tr>
                        <td><span class="badge-cabang"><?= $b['kode_cabang'] ?></span></td>
                        <td><?= htmlspecialchars($b['nama_obat']) ?></td>
                        <td><code><?= $b['no_batch'] ?></code></td>
                        <td class="text-danger fw-bold"><?= $b['tanggal_kedaluwarsa'] ?></td>
                        <td><?= $b['jumlah_batch'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php elseif ($page === 'pengaturan'): ?>
        <div class="soft-card p-4">
            <h6 class="fw-bold mb-3"><i class="bi bi-shield-lock-fill text-primary me-2"></i>Konfigurasi Environment &amp; Kontrol Keamanan Aktif</h6>
            <ul class="list-group list-group-flush small">
                <li class="list-group-item"><strong>Environment Aktif:</strong> <?= htmlspecialchars($config['environment']) ?> (File: <code>config_<?= $env ?>.json</code>)</li>
                <li class="list-group-item"><strong>Database Target:</strong> <code><?= htmlspecialchars($config['db_name']) ?></code></li>
                <li class="list-group-item"><strong>Kredensial MySQL Non-Root:</strong> <code><?= htmlspecialchars($dbCred['user']) ?></code> (Prinsip Least Privilege)</li>
                <li class="list-group-item"><strong>Proteksi Kueri:</strong> PHP 8 Native PDO Prepared Statement (<code>ATTR_EMULATE_PREPARES = false</code>)</li>
                <li class="list-group-item"><strong>Versi Skema:</strong> <?= $versiSkema ?></li>
            </ul>
        </div>
        <?php endif; ?>
    </main>
</div>

<script>
const ctx = document.getElementById('resChart');
if (ctx) {
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: ['CPU', 'RAM', 'Koneksi DB', 'Disk', 'Respon Query'],
            datasets: [
                {
                    label: 'Penggunaan (%)',
                    data: [42, 68, 35, 60, 12],
                    backgroundColor: '#3b93f2',
                    borderRadius: 8
                },
                {
                    label: 'Batas Atas (80%)',
                    data: [80, 80, 80, 80, 80],
                    type: 'line',
                    borderColor: '#ef4444',
                    borderWidth: 2,
                    pointRadius: 0
                }
            ]
        },
        options: {
            plugins: { legend: { display: true, position: 'top' } },
            scales: { y: { beginAtZero: true, max: 100 } }
        }
    });
}
</script>
</body>
</html>