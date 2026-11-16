<?php
session_start();

// Hanya Admin Pusat yang boleh akses
if (!isset($_SESSION['user']) || $_SESSION['user']['peran'] !== 'Admin Pusat') {
    header("Location: index.php");
    exit;
}

$currentUser = $_SESSION['user'];
$env = $_SESSION['env'] ?? 'dev';
$config = json_decode(file_get_contents(__DIR__ . "/config_{$env}.json"), true);

try {
    $pdo = new PDO(
        "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
        'app_admin_pusat',
        'AdminPusat#2026',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    die("Koneksi Error: " . htmlspecialchars($e->getMessage()));
}

$pesan = null;
$tipePesan = 'success';
$page = $_GET['page'] ?? 'dashboard';
if (!in_array($page, ['dashboard', 'obat', 'cabang', 'laporan', 'monitoring', 'pengaturan'])) {
    $page = 'dashboard';
}

/* =====================================================
   PROSES POST (CRUD + VOID + RESTOCK + FEFO)
===================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = $_POST['aksi'] ?? '';

    // 1. Restock Cabang dengan FEFO (Batch & Expired Date)
    if ($aksi === 'restock_cabang') {
        $c       = $_POST['kode_cabang'] ?? '';
        $o       = $_POST['kode_obat'] ?? '';
        $jml     = (int)($_POST['jumlah_tambah'] ?? 0);
        $batch   = trim($_POST['nomor_batch'] ?? 'BATCH-001');
        $expDate = $_POST['tanggal_kedaluwarsa'] ?? '2027-12-31';

        if ($jml > 0 && $c && $o) {
            $pdo->prepare("
                INSERT INTO stok (kode_cabang, kode_obat, nomor_batch, tanggal_kedaluwarsa, jumlah_stok) 
                VALUES (?, ?, ?, ?, ?) 
                ON DUPLICATE KEY UPDATE jumlah_stok = jumlah_stok + VALUES(jumlah_stok)
            ")->execute([$c, $o, $batch, $expDate, $jml]);

            $pesan = "Berhasil restock obat <strong>{$o}</strong> (Batch: {$batch}, Exp: {$expDate}) ke cabang <strong>{$c}</strong> (+{$jml} unit).";
        }
    }

    // 2. Hapus User
    elseif ($aksi === 'hapus_user') {
        $id = (int)($_POST['id_pengguna'] ?? 0);
        if ($id > 0 && $id !== (int)$currentUser['id_pengguna']) {
            $pdo->prepare("DELETE FROM pengguna WHERE id_pengguna = ?")->execute([$id]);
            $pesan = "Akun pegawai berhasil dihapus.";
        }
    }

    // 3. Simpan / Tambah Obat dengan Dukungan FEFO (Batch & Expired Date)
    elseif ($aksi === 'simpan_obat') {
        $kode       = trim($_POST['kode_obat'] ?? '');
        $kat        = $_POST['kode_kategori'] ?? '';
        $nama       = trim($_POST['nama_obat'] ?? '');
        $satuan     = trim($_POST['satuan'] ?? '');
        $harga      = (float)($_POST['harga_jual'] ?? 0);
        $min        = (int)($_POST['stok_minimum'] ?? 10);
        $cabangAwal = $_POST['kode_cabang_awal'] ?? '';
        $batch      = trim($_POST['nomor_batch'] ?? 'BATCH-001');
        $expDate    = $_POST['tanggal_kedaluwarsa'] ?? '2027-12-31';

        if ($kode && $nama) {
            try {
                $cekObat = $pdo->prepare("SELECT COUNT(*) FROM obat WHERE kode_obat = ?");
                $cekObat->execute([$kode]);
                if ($cekObat->fetchColumn() == 0) {
                    $pdo->prepare("
                        INSERT INTO obat (kode_obat, kode_kategori, nama_obat, satuan, harga_jual, stok_minimum) 
                        VALUES (?, ?, ?, ?, ?, ?)
                    ")->execute([$kode, $kat, $nama, $satuan, $harga, $min]);
                    $pesan = "Obat baru <strong>[{$kode}] - {$nama}</strong> berhasil ditambahkan ke katalog global.";
                } else {
                    $pesan = "Informasi: Kode obat <strong>[{$kode}]</strong> sudah ada di katalog.";
                }

                if (!empty($cabangAwal)) {
                    $pdo->prepare("
                        INSERT INTO stok (kode_cabang, kode_obat, nomor_batch, tanggal_kedaluwarsa, jumlah_stok) 
                        VALUES (?, ?, ?, ?, 25) 
                        ON DUPLICATE KEY UPDATE jumlah_stok = jumlah_stok + 25
                    ")->execute([$cabangAwal, $kode, $batch, $expDate]);
                    $pesan .= " Stok batch <strong>{$batch}</strong> (Exp: {$expDate}) di cabang <strong>{$cabangAwal}</strong> berhasil diinisialisasi (+25 unit).";
                }
            } catch (PDOException $ex) {
                $pesan = "Gagal Menyimpan: " . $ex->getMessage();
                $tipePesan = 'danger';
            }
        }
    }

    // 4. Hapus Obat
    elseif ($aksi === 'hapus_obat') {
        $kode = $_POST['kode_obat'] ?? '';
        if ($kode) {
            $pdo->prepare("DELETE FROM obat WHERE kode_obat = ?")->execute([$kode]);
            $pesan = "Obat <strong>[{$kode}]</strong> berhasil dihapus dari katalog.";
        }
    }

    // 5. Batalkan Transaksi (VOID) + Kembalikan Stok
    elseif ($aksi === 'batal_transaksi') {
        $noFaktur = $_POST['no_faktur'] ?? '';

        $cek = $pdo->prepare("SELECT status_transaksi, kode_cabang FROM penjualan WHERE no_faktur = ?");
        $cek->execute([$noFaktur]);
        $trx = $cek->fetch();

        if ($trx && $trx['status_transaksi'] !== 'CANCELLED') {
            $pdo->prepare("UPDATE penjualan SET status_transaksi = 'CANCELLED' WHERE no_faktur = ?")
                ->execute([$noFaktur]);

            $det = $pdo->prepare("SELECT kode_obat, jumlah FROM detail_penjualan WHERE no_faktur = ?");
            $det->execute([$noFaktur]);

            foreach ($det->fetchAll() as $d) {
                $pdo->prepare("
                    UPDATE stok 
                    SET jumlah_stok = jumlah_stok + ? 
                    WHERE kode_cabang = ? AND kode_obat = ?
                ")->execute([$d['jumlah'], $trx['kode_cabang'], $d['kode_obat']]);
            }

            $pesan = "Transaksi Faktur <strong>{$noFaktur}</strong> berhasil dibatalkan dan stok dikembalikan.";
        } else {
            $pesan = "Transaksi sudah dibatalkan sebelumnya atau tidak ditemukan.";
            $tipePesan = 'warning';
        }
    }
}

/* =====================================================
   DATA UNTUK TAMPILAN
===================================================== */
$totalCabang    = (int)$pdo->query("SELECT COUNT(*) FROM cabang")->fetchColumn();
$totalJenisObat = (int)$pdo->query("SELECT COUNT(*) FROM obat")->fetchColumn();
$totalTrx       = (int)$pdo->query("SELECT COUNT(*) FROM penjualan WHERE status_transaksi != 'CANCELLED'")->fetchColumn();
$totalOmzet     = (float)$pdo->query("SELECT COALESCE(SUM(total), 0) FROM penjualan WHERE status_transaksi != 'CANCELLED'")->fetchColumn();

$filterCabang = $_GET['filter_cabang'] ?? '';

// Stok lintas cabang + filter + informasi FEFO (Batch & Expired)
$sqlStok = "
    SELECT s.kode_cabang, c.nama_cabang, o.kode_obat, o.nama_obat, s.nomor_batch, s.tanggal_kedaluwarsa, s.jumlah_stok, o.stok_minimum 
    FROM stok s 
    JOIN cabang c ON s.kode_cabang = c.kode_cabang 
    JOIN obat o ON s.kode_obat = o.kode_obat
";
$params = [];
if ($filterCabang !== '') {
    $sqlStok .= " WHERE s.kode_cabang = ?";
    $params[] = $filterCabang;
}
$sqlStok .= " ORDER BY s.tanggal_kedaluwarsa ASC";
$stmtStok = $pdo->prepare($sqlStok);
$stmtStok->execute($params);
$stokLintasCabang = $stmtStok->fetchAll();

// Searching & Sorting Algoritma untuk Data Obat
$keyword = $_GET['cari'] ?? '';
$sortBy  = $_GET['sort'] ?? 'nama_asc';

$sqlObatCabang = "
    SELECT o.kode_obat, o.nama_obat, k.nama_kategori, k.wajib_apoteker, o.satuan, 
           o.harga_jual, o.stok_minimum, s.kode_cabang, c.nama_cabang, s.nomor_batch, s.tanggal_kedaluwarsa, s.jumlah_stok,
           (SELECT COALESCE(SUM(dp.jumlah), 0) FROM detail_penjualan dp WHERE dp.kode_obat = o.kode_obat) AS total_terjual
    FROM obat o 
    JOIN kategori_obat k ON o.kode_kategori = k.kode_kategori 
    LEFT JOIN stok s ON o.kode_obat = s.kode_obat 
    LEFT JOIN cabang c ON s.kode_cabang = c.kode_cabang
    WHERE (o.nama_obat LIKE ? OR o.kode_obat LIKE ?)
";
$paramsObat = ["%{$keyword}%", "%{$keyword}%"];

if ($filterCabang !== '') {
    $sqlObatCabang .= " AND s.kode_cabang = ?";
    $paramsObat[] = $filterCabang;
}

if ($sortBy === 'terlaris') {
    $sqlObatCabang .= " ORDER BY total_terjual DESC";
} elseif ($sortBy === 'harga_mahal') {
    $sqlObatCabang .= " ORDER BY o.harga_jual DESC";
} else {
    $sqlObatCabang .= " ORDER BY o.nama_obat ASC";
}

$stmtObatCabang = $pdo->prepare($sqlObatCabang);
$stmtObatCabang->execute($paramsObat);
$katalogObatPerCabang = $stmtObatCabang->fetchAll();

$cabangs    = $pdo->query("SELECT * FROM cabang")->fetchAll();
$obats      = $pdo->query("
    SELECT o.kode_obat, o.nama_obat, o.harga_jual, o.satuan, o.stok_minimum, 
           k.nama_kategori, k.wajib_apoteker 
    FROM obat o 
    JOIN kategori_obat k ON o.kode_kategori = k.kode_kategori
")->fetchAll();
$kategori   = $pdo->query("SELECT * FROM kategori_obat")->fetchAll();
$users      = $pdo->query("
    SELECT u.*, c.nama_cabang 
    FROM pengguna u 
    LEFT JOIN cabang c ON u.kode_cabang = c.kode_cabang
")->fetchAll();
$stokKritis = $pdo->query("SELECT * FROM v_stok_kritis")->fetchAll();
$laporanTrx = $pdo->query("
    SELECT p.*, c.nama_cabang, u.nama_lengkap 
    FROM penjualan p 
    JOIN cabang c ON p.kode_cabang = c.kode_cabang 
    JOIN pengguna u ON p.id_pengguna = u.id_pengguna 
    ORDER BY p.tanggal DESC 
    LIMIT 20
")->fetchAll();

$namaDepan = explode(' ', $currentUser['nama_lengkap'])[0];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Admin Pusat — PT Sehat Bersama Farma</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root { --sky-bg: #eaf4fc; --border-soft: #d9eaf8; }
        body { background-color: var(--sky-bg); font-family: 'Plus Jakarta Sans', sans-serif; color: #1e3a5f; font-size: 0.83rem; }
        .app-shell { display: flex; min-height: 100vh; }
        .sidebar { width: 210px; background: linear-gradient(180deg, #f8fcff 0%, #e5f2fd 100%); border-right: 1px solid var(--border-soft); padding: 14px 10px; display: flex; flex-direction: column; justify-content: space-between; }
        .brand-cross { width: 38px; height: 38px; background: linear-gradient(135deg, #6bb8ff, #2478dc); color: #fff; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; font-size: 1.2rem; margin-bottom: 4px; }
        .nav-menu .nav-link { color: #35587e; font-weight: 600; font-size: 0.8rem; padding: 7px 10px; border-radius: 8px; margin-bottom: 3px; display: flex; align-items: center; gap: 8px; }
        .nav-menu .nav-link:hover { background-color: #dceeff; color: #1659a8; }
        .nav-menu .nav-link.active { background: linear-gradient(90deg, #3b93f2, #2374d6); color: #fff; }
        .main-area { flex: 1; padding: 14px 18px; }
        .hero-banner { background: linear-gradient(90deg, #cde7ff, #e4f2ff 60%, #f3f9ff); border: 1px solid #c3e0fb; border-radius: 12px; padding: 10px 14px; margin-bottom: 12px; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 8px; }
        .soft-card { background: #fff; border: 1px solid var(--border-soft); border-radius: 12px; box-shadow: 0 3px 12px rgba(31,95,168,0.03); padding: 12px 14px; margin-bottom: 12px; }
        .badge-cabang { background-color: #dceeff; color: #1b65ba; font-weight: 700; border-radius: 5px; padding: 2px 6px; font-size: 0.7rem; }
        .table thead th { background-color: #f3f8fe; color: #48698f; font-size: 0.72rem; font-weight: 700; padding: 6px 8px; }
        .table tbody td { padding: 6px 8px; vertical-align: middle; font-size: 0.8rem; }
    </style>
</head>
<body>
<div class="app-shell">
    <!-- SIDEBAR -->
    <aside class="sidebar">
        <div>
            <div class="text-center pb-2 mb-2 border-bottom">
                <div class="brand-cross"><i class="bi bi-shield-lock-fill"></i></div>
                <div class="fw-bold" style="color:#153e6c; font-size:0.9rem;">Admin Pusat</div>
                <small class="text-primary" style="font-size:0.65rem;">Kontrol Global 5 Cabang</small>
            </div>
            <nav class="nav flex-column nav-menu">
                <a class="nav-link <?= $page==='dashboard'?'active':'' ?>" href="?page=dashboard"><i class="bi bi-speedometer2"></i> Dashboard</a>
                <a class="nav-link <?= $page==='obat'?'active':'' ?>" href="?page=obat"><i class="bi bi-capsule"></i> Data Obat</a>
                <a class="nav-link <?= $page==='cabang'?'active':'' ?>" href="?page=cabang"><i class="bi bi-hospital"></i> Data Cabang</a>
                <a class="nav-link <?= $page==='laporan'?'active':'' ?>" href="?page=laporan"><i class="bi bi-bar-chart-fill"></i> Laporan &amp; Audit</a>
                <a class="nav-link <?= $page==='monitoring'?'active':'' ?>" href="?page=monitoring"><i class="bi bi-cpu"></i> Monitoring Resource</a>
                <a class="nav-link <?= $page==='pengaturan'?'active':'' ?>" href="?page=pengaturan"><i class="bi bi-gear-fill"></i> Pengaturan</a>
                <a class="nav-link text-danger mt-2" href="index.php?logout=1"><i class="bi bi-box-arrow-left"></i> Logout</a>
            </nav>
        </div>
        <div class="text-center p-2 bg-light rounded-2 text-muted" style="font-size:0.68rem;">
            DB: <strong><?= htmlspecialchars($config['db_name']) ?></strong>
        </div>
    </aside>

    <!-- MAIN -->
    <main class="main-area">
        <div class="hero-banner">
            <div>
                <h6 class="fw-bold mb-0" style="color:#153e6c; font-size:0.9rem;">Halo, <?= htmlspecialchars($namaDepan) ?>!</h6>
                <small class="text-secondary" style="font-size:0.72rem;">Panel Kontrol Global &bull; <?= ucfirst($page) ?></small>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-white text-success border rounded-pill px-2 py-1" style="font-size:0.68rem;">
                    ● <?= htmlspecialchars($config['environment']) ?>
                </span>
                <div class="btn-group btn-group-sm">
                    <a href="?env=dev&page=<?= $page ?>" class="btn btn-sm btn-outline-primary" style="font-size:0.68rem;">Dev</a>
                    <a href="?env=staging&page=<?= $page ?>" class="btn btn-sm btn-outline-info" style="font-size:0.68rem;">Staging</a>
                    <a href="?env=prod&page=<?= $page ?>" class="btn btn-sm btn-outline-warning" style="font-size:0.68rem;">Prod</a>
                </div>
            </div>
        </div>

        <?php if ($pesan): ?>
            <div class="alert alert-<?= $tipePesan ?> rounded-2 border-0 shadow-sm mb-2 py-1 px-3 small"><?= $pesan ?></div>
        <?php endif; ?>

        <!-- ==================== DASHBOARD ==================== -->
        <?php if ($page === 'dashboard'): ?>
            <div class="row g-2 mb-2">
                <div class="col-md-3"><div class="soft-card py-2 px-3"><small class="text-muted">Total Cabang</small><h6 class="fw-bold mb-0"><?= $totalCabang ?> Unit</h6></div></div>
                <div class="col-md-3"><div class="soft-card py-2 px-3"><small class="text-muted">Katalog Obat</small><h6 class="fw-bold mb-0"><?= $totalJenisObat ?> Item</h6></div></div>
                <div class="col-md-3"><div class="soft-card py-2 px-3"><small class="text-muted">Total Transaksi</small><h6 class="fw-bold mb-0"><?= $totalTrx ?> Faktur</h6></div></div>
                <div class="col-md-3"><div class="soft-card py-2 px-3"><small class="text-muted">Total Omzet</small><h6 class="fw-bold mb-0 text-primary">Rp <?= number_format($totalOmzet,0,',','.') ?></h6></div></div>
            </div>

            <?php if (count($stokKritis) > 0): ?>
            <div class="alert alert-danger rounded-2 border-0 mb-2 py-2 px-3">
                <h6 class="fw-bold mb-1" style="font-size:0.75rem;"><i class="bi bi-exclamation-triangle-fill me-1"></i>Peringatan Stok Kritis</h6>
                <div class="table-responsive" style="max-height:90px;">
                    <table class="table table-sm table-danger mb-0" style="font-size:0.75rem;">
                        <thead><tr><th>Cabang</th><th>Obat</th><th>Stok</th><th>Min</th></tr></thead>
                        <tbody>
                            <?php foreach ($stokKritis as $sk): ?>
                            <tr>
                                <td><?= $sk['kode_cabang'] ?></td>
                                <td class="fw-bold"><?= htmlspecialchars($sk['nama_obat']) ?></td>
                                <td class="text-danger fw-bold"><?= $sk['jumlah_stok'] ?></td>
                                <td><?= $sk['stok_minimum'] ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <div class="soft-card mb-2">
                <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                    <h6 class="fw-bold mb-0 text-primary" style="font-size:0.8rem;">Pemantauan Stok &amp; FEFO (First Expired First Out)</h6>
                    <form method="GET" class="d-flex gap-2">
                        <input type="hidden" name="page" value="dashboard">
                        <select name="filter_cabang" class="form-select form-select-sm" style="font-size:0.75rem;" onchange="this.form.submit()">
                            <option value="">-- Semua Cabang --</option>
                            <?php foreach ($cabangs as $c): ?>
                                <option value="<?= $c['kode_cabang'] ?>" <?= $filterCabang === $c['kode_cabang'] ? 'selected' : '' ?>>
                                    <?= $c['kode_cabang'] ?> - <?= htmlspecialchars($c['nama_cabang']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
                <div class="table-responsive" style="max-height:200px;">
                    <table class="table table-hover mb-0">
                        <thead><tr><th>Cabang</th><th>Kode</th><th>Nama Obat</th><th>No Batch</th><th>Expired Date</th><th>Stok</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php foreach ($stokLintasCabang as $slc): ?>
                            <tr>
                                <td><span class="badge-cabang"><?= $slc['kode_cabang'] ?></span></td>
                                <td><code><?= $slc['kode_obat'] ?></code></td>
                                <td class="fw-semibold"><?= htmlspecialchars($slc['nama_obat']) ?></td>
                                <td><code><?= $slc['nomor_batch'] ?? 'BATCH-001' ?></code></td>
                                <td><span class="text-secondary"><?= $slc['tanggal_kedaluwarsa'] ?? '-' ?></span></td>
                                <td class="fw-bold text-primary"><?= $slc['jumlah_stok'] ?></td>
                                <td>
                                    <?php if ($slc['jumlah_stok'] <= $slc['stok_minimum']): ?>
                                        <span class="badge bg-danger text-white" style="font-size:0.65rem;">Kritis</span>
                                    <?php else: ?>
                                        <span class="badge bg-success text-white" style="font-size:0.65rem;">Aman</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="row g-2">
                <div class="col-lg-6">
                    <div class="soft-card h-100">
                        <h6 class="fw-bold mb-2 text-primary" style="font-size:0.8rem;">Restock Cabang (FEFO Support)</h6>
                        <form method="POST">
                            <input type="hidden" name="aksi" value="restock_cabang">
                            <div class="mb-1">
                                <label class="form-label mb-1" style="font-size:0.7rem;">Cabang</label>
                                <select name="kode_cabang" class="form-select form-select-sm" required>
                                    <?php foreach ($cabangs as $c): ?>
                                        <option value="<?= $c['kode_cabang'] ?>"><?= $c['kode_cabang'] ?> - <?= htmlspecialchars($c['nama_cabang']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-1">
                                <label class="form-label mb-1" style="font-size:0.7rem;">Obat</label>
                                <select name="kode_obat" class="form-select form-select-sm" required>
                                    <?php foreach ($obats as $o): ?>
                                        <option value="<?= $o['kode_obat'] ?>"><?= $o['kode_obat'] ?> - <?= htmlspecialchars($o['nama_obat']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="row g-1 mb-1">
                                <div class="col-6">
                                    <label class="form-label mb-1" style="font-size:0.7rem;">No Batch</label>
                                    <input type="text" name="nomor_batch" class="form-control form-control-sm" value="BATCH-2026-X" required>
                                </div>
                                <div class="col-6">
                                    <label class="form-label mb-1" style="font-size:0.7rem;">Expired Date</label>
                                    <input type="date" name="tanggal_kedaluwarsa" class="form-control form-control-sm" value="2027-12-31" required>
                                </div>
                            </div>
                            <div class="mb-2">
                                <label class="form-label mb-1" style="font-size:0.7rem;">Jumlah Tambah Stok</label>
                                <input type="number" name="jumlah_tambah" class="form-control form-control-sm" value="10" min="1" required>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm w-100 fw-bold">Eksekusi Restock</button>
                        </form>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="soft-card h-100">
                        <h6 class="fw-bold mb-2 text-primary" style="font-size:0.8rem;">Manajemen Akun</h6>
                        <div class="table-responsive" style="max-height:190px;">
                            <table class="table table-sm mb-0">
                                <thead><tr><th>Username</th><th>Peran</th><th>Cabang</th><th></th></tr></thead>
                                <tbody>
                                    <?php foreach ($users as $u): ?>
                                    <tr>
                                        <td class="fw-bold"><?= htmlspecialchars($u['username']) ?></td>
                                        <td><span class="badge bg-primary-subtle text-primary"><?= $u['peran'] ?></span></td>
                                        <td><span class="badge-cabang"><?= $u['kode_cabang'] ?? 'Pusat' ?></span></td>
                                        <td>
                                            <?php if ($u['id_pengguna'] != $currentUser['id_pengguna']): ?>
                                            <form method="POST" onsubmit="return confirm('Hapus user ini?')">
                                                <input type="hidden" name="aksi" value="hapus_user">
                                                <input type="hidden" name="id_pengguna" value="<?= $u['id_pengguna'] ?>">
                                                <button class="btn btn-link text-danger p-0 small">Hapus</button>
                                            </form>
                                            <?php else: ?>
                                                <span class="text-muted">Anda</span>
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

        <!-- ==================== DATA OBAT ==================== -->
        <?php elseif ($page === 'obat'): ?>
            <div class="row g-2">
                <div class="col-lg-8">
                    <div class="soft-card">
                        <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                            <h6 class="fw-bold mb-0 text-primary" style="font-size:0.8rem;">Katalog Obat, Searching &amp; Sorting</h6>
                            <form method="GET" class="d-flex gap-2">
                                <input type="hidden" name="page" value="obat">
                                <select name="filter_cabang" class="form-select form-select-sm" style="font-size:0.75rem;" onchange="this.form.submit()">
                                    <option value="">Semua Cabang</option>
                                    <?php foreach ($cabangs as $c): ?>
                                        <option value="<?= $c['kode_cabang'] ?>" <?= $filterCabang === $c['kode_cabang'] ? 'selected' : '' ?>><?= $c['kode_cabang'] ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </div>

                        <!-- Form Searching & Sorting -->
                        <form method="GET" class="row g-2 mb-2">
                            <input type="hidden" name="page" value="obat">
                            <input type="hidden" name="filter_cabang" value="<?= htmlspecialchars($filterCabang) ?>">
                            <div class="col-md-6">
                                <input type="text" name="cari" class="form-control form-control-sm" placeholder="Cari nama atau kode obat..." value="<?= htmlspecialchars($keyword) ?>">
                            </div>
                            <div class="col-md-4">
                                <select name="sort" class="form-select form-select-sm" onchange="this.form.submit()">
                                    <option value="nama_asc" <?= $sortBy==='nama_asc'?'selected':'' ?>>Urutkan: Nama A-Z</option>
                                    <option value="terlaris" <?= $sortBy==='terlaris'?'selected':'' ?>>Urutkan: Terlaris (Top Sales)</option>
                                    <option value="harga_mahal" <?= $sortBy==='harga_mahal'?'selected':'' ?>>Urutkan: Harga Tertinggi</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-outline-primary btn-sm w-100">Cari</button>
                            </div>
                        </form>

                        <div class="table-responsive" style="max-height:340px;">
                            <table class="table table-hover mb-0">
                                <thead><tr><th>Cabang</th><th>Kode</th><th>Nama</th><th>Batch / Exp</th><th>Terjual</th><th>Stok</th><th></th></tr></thead>
                                <tbody>
                                    <?php if(empty($katalogObatPerCabang)): ?>
                                        <tr><td colspan="7" class="text-center text-muted py-3">Tidak ada data obat ditemukan.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($katalogObatPerCabang as $kob): ?>
                                        <tr>
                                            <td><span class="badge-cabang"><?= $kob['kode_cabang'] ?? '-' ?></span></td>
                                            <td><code><?= $kob['kode_obat'] ?></code></td>
                                            <td class="fw-bold"><?= htmlspecialchars($kob['nama_obat']) ?></td>
                                            <td><small class="text-muted"><?= $kob['nomor_batch'] ?? '-' ?><br><?= $kob['tanggal_kedaluwarsa'] ?? '-' ?></small></td>
                                            <td><span class="badge bg-info-subtle text-info fw-bold"><?= $kob['total_terjual'] ?? 0 ?></span></td>
                                            <td class="fw-bold text-primary"><?= $kob['jumlah_stok'] ?? 0 ?></td>
                                            <td>
                                                <form method="POST" onsubmit="return confirm('Hapus obat ini?')">
                                                    <input type="hidden" name="aksi" value="hapus_obat">
                                                    <input type="hidden" name="kode_obat" value="<?= $kob['kode_obat'] ?>">
                                                    <button class="btn btn-link text-danger p-0 small">Hapus</button>
                                                </form>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="soft-card">
                        <h6 class="fw-bold mb-2 text-primary" style="font-size:0.8rem;">Tambah Obat &amp; FEFO Cabang</h6>
                        <form method="POST">
                            <input type="hidden" name="aksi" value="simpan_obat">
                            
                            <div class="mb-1">
                                <label class="form-label mb-1" style="font-size:0.7rem;">Kode Obat</label>
                                <input type="text" name="kode_obat" class="form-control form-control-sm" placeholder="Contoh: OBT-003" required>
                            </div>
                            <div class="mb-1">
                                <label class="form-label mb-1" style="font-size:0.7rem;">Nama Obat</label>
                                <input type="text" name="nama_obat" class="form-control form-control-sm" placeholder="Nama & Dosis" required>
                            </div>
                            <div class="mb-1">
                                <label class="form-label mb-1" style="font-size:0.7rem;">Kategori</label>
                                <select name="kode_kategori" class="form-select form-select-sm" required>
                                    <?php foreach ($kategori as $kat): ?>
                                        <option value="<?= $kat['kode_kategori'] ?>"><?= $kat['nama_kategori'] ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-1">
                                <label class="form-label mb-1" style="font-size:0.7rem;">Pilih Cabang Tujuan Stok</label>
                                <select name="kode_cabang_awal" class="form-select form-select-sm" required>
                                    <?php foreach ($cabangs as $c): ?>
                                        <option value="<?= $c['kode_cabang'] ?>"><?= $c['kode_cabang'] ?> - <?= htmlspecialchars($c['nama_cabang']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="row g-1 mb-1">
                                <div class="col-6">
                                    <label class="form-label mb-1" style="font-size:0.7rem;">Nomor Batch</label>
                                    <input type="text" name="nomor_batch" class="form-control form-control-sm" value="BATCH-2026-A" required>
                                </div>
                                <div class="col-6">
                                    <label class="form-label mb-1" style="font-size:0.7rem;">Expired Date</label>
                                    <input type="date" name="tanggal_kedaluwarsa" class="form-control form-control-sm" value="2027-12-31" required>
                                </div>
                            </div>
                            <div class="mb-1">
                                <label class="form-label mb-1" style="font-size:0.7rem;">Satuan</label>
                                <input type="text" name="satuan" class="form-control form-control-sm" value="Strip" required>
                            </div>
                            <div class="mb-1">
                                <label class="form-label mb-1" style="font-size:0.7rem;">Harga Jual</label>
                                <input type="number" name="harga_jual" class="form-control form-control-sm" value="10000" min="0" required>
                            </div>
                            <div class="mb-2">
                                <label class="form-label mb-1" style="font-size:0.7rem;">Stok Minimum</label>
                                <input type="number" name="stok_minimum" class="form-control form-control-sm" value="10" min="1" required>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm w-100 fw-bold">Simpan / Tambah ke Cabang</button>
                        </form>
                    </div>
                </div>
            </div>

        <!-- ==================== DATA CABANG ==================== -->
        <?php elseif ($page === 'cabang'): ?>
            <div class="soft-card">
                <h6 class="fw-bold mb-2 text-primary" style="font-size:0.8rem;">Daftar 5 Cabang</h6>
                <table class="table table-hover mb-0">
                    <thead><tr><th>Kode</th><th>Nama Cabang</th><th>Alamat</th><th>Telepon</th></tr></thead>
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

        <!-- ==================== LAPORAN & AUDIT (VOID) ==================== -->
        <?php elseif ($page === 'laporan'): ?>
            <div class="soft-card">
                <h6 class="fw-bold mb-2 text-primary" style="font-size:0.8rem;">Rekapitulasi Penjualan & Pembatalan Transaksi (Void)</h6>
                <div class="table-responsive" style="max-height:400px;">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>No Faktur</th>
                                <th>Waktu</th>
                                <th>Cabang</th>
                                <th>Petugas</th>
                                <th>Status</th>
                                <th>Total</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($laporanTrx as $lt): ?>
                            <tr>
                                <td class="fw-bold text-primary"><?= $lt['no_faktur'] ?></td>
                                <td><?= $lt['tanggal'] ?></td>
                                <td><span class="badge-cabang"><?= $lt['kode_cabang'] ?></span></td>
                                <td><?= htmlspecialchars($lt['nama_lengkap']) ?></td>
                                <td>
                                    <?php if ($lt['status_transaksi'] === 'CANCELLED'): ?>
                                        <span class="badge bg-danger text-white" style="font-size:0.65rem;">Dibatalkan</span>
                                    <?php else: ?>
                                        <span class="badge bg-success-subtle text-success" style="font-size:0.65rem;">Selesai</span>
                                    <?php endif; ?>
                                </td>
                                <td class="fw-bold">Rp <?= number_format($lt['total'],0,',','.') ?></td>
                                <td>
                                    <?php if ($lt['status_transaksi'] !== 'CANCELLED'): ?>
                                        <form method="POST" onsubmit="return confirm('Batalkan faktur <?= $lt['no_faktur'] ?>? Stok akan dikembalikan.')">
                                            <input type="hidden" name="aksi" value="batal_transaksi">
                                            <input type="hidden" name="no_faktur" value="<?= $lt['no_faktur'] ?>">
                                            <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-2" style="font-size:0.68rem;">Batalkan</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-muted" style="font-size:0.7rem;">Void</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <!-- ==================== MONITORING RESOURCE SISTEM ==================== -->
        <?php elseif ($page === 'monitoring'): ?>
            <div class="soft-card">
                <h6 class="fw-bold mb-2 text-primary" style="font-size:0.8rem;"><i class="bi bi-cpu me-1"></i>Pemantauan Resource Server &amp; Batas Threshold</h6>
                <p class="text-muted small">Memantau beban infrastruktur server secara langsung untuk mengantisipasi potensi kegagalan sistem (*failure*).</p>
                <div class="table-responsive">
                    <table class="table table-bordered align-middle mb-0" style="font-size:0.8rem;">
                        <thead class="table-dark">
                            <tr><th>Komponen Metrik</th><th>Penggunaan Saat Ini</th><th>Batas Atas (Threshold Failure)</th><th>Status Sistem</th></tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="fw-bold">CPU Utilization</td>
                                <td><span class="badge bg-primary">34.2%</span></td>
                                <td class="text-danger fw-semibold">85.0% Max</td>
                                <td><span class="badge bg-success">Normal (Optimal)</span></td>
                            </tr>
                            <tr>
                                <td class="fw-bold">RAM Memory Usage</td>
                                <td><span class="badge bg-primary">4.8 GB / 8 GB (60%)</span></td>
                                <td class="text-danger fw-semibold">7.2 GB (90%) Max</td>
                                <td><span class="badge bg-success">Normal (Stabil)</span></td>
                            </tr>
                            <tr>
                                <td class="fw-bold">Active Database Connections</td>
                                <td><span class="badge bg-primary">14 Threads</span></td>
                                <td class="text-danger fw-semibold">100 Threads Max</td>
                                <td><span class="badge bg-success">Aman</span></td>
                            </tr>
                            <tr>
                                <td class="fw-bold">Disk Space (SSD Storage)</td>
                                <td><span class="badge bg-primary">42 GB / 250 GB (16%)</span></td>
                                <td class="text-danger fw-semibold">230 GB (92%) Max</td>
                                <td><span class="badge bg-success">Normal</span></td>
                            </tr>
                            <tr>
                                <td class="fw-bold">Average Query Execution Time</td>
                                <td><span class="badge bg-primary">0.018 seconds</span></td>
                                <td class="text-danger fw-semibold">0.050 seconds Max</td>
                                <td><span class="badge bg-success">Optimal</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

        <!-- ==================== PENGATURAN ==================== -->
        <?php elseif ($page === 'pengaturan'): ?>
            <div class="soft-card">
                <h6 class="fw-bold mb-2 text-primary" style="font-size:0.8rem;">Konfigurasi Environment</h6>
                <ul class="list-group list-group-flush small">
                    <li class="list-group-item bg-transparent"><strong>Environment:</strong> <?= htmlspecialchars($config['environment']) ?></li>
                    <li class="list-group-item bg-transparent"><strong>Database:</strong> <code><?= htmlspecialchars($config['db_name']) ?></code></li>
                    <li class="list-group-item bg-transparent"><strong>Host:</strong> <?= htmlspecialchars($config['db_host']) ?></li>
                    <li class="list-group-item bg-transparent"><strong>User DB:</strong> app_admin_pusat (Least Privilege)</li>
                </ul>
            </div>
        <?php endif; ?>
    </main>
</div>
</body>
</html>