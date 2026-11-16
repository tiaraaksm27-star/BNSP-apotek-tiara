<?php
session_start();

if (!isset($_SESSION['user']) || $_SESSION['user']['peran'] !== 'Kasir') {
    header("Location: index.php");
    exit;
}

$currentUser = $_SESSION['user'];

if (isset($_GET['env']) && in_array($_GET['env'], ['dev', 'staging', 'prod'])) {
    $_SESSION['env'] = $_GET['env'];
}
$env = $_SESSION['env'] ?? 'dev';
$config = json_decode(file_get_contents(__DIR__ . "/config_{$env}.json"), true);

try {
    $pdo = new PDO(
        "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
        'app_kasir',
        'Kasir#2026',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    die("Koneksi Error: " . htmlspecialchars($e->getMessage()));
}

$cabangUser = $currentUser['kode_cabang'];
$idPengguna = (int)$currentUser['id_pengguna'];
$pesan = null;
$tipePesan = 'success';

$page = $_GET['page'] ?? 'dashboard';
if (!in_array($page, ['dashboard', 'transaksi', 'obat', 'cabang', 'laporan', 'pengaturan'])) {
    $page = 'dashboard';
}

// Proses Transaksi POS oleh Kasir (Koreksi variabel jumlah beli agar akurat 1 banding 1)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'proses_transaksi') {
    $kodeObat = $_POST['kode_obat'] ?? '';
    // Pastikan mengambil nilai dari input jumlah_pembelian dengan benar
    $jumlahBeli = (int)($_POST['jumlah_pembelian'] ?? 1);

    if ($kodeObat && $jumlahBeli > 0) {
        try {
            $pdo->beginTransaction();

            $stmtCek = $pdo->prepare("
                SELECT o.nama_obat, o.harga_jual, k.wajib_apoteker, s.jumlah_stok 
                FROM obat o
                JOIN stok s ON o.kode_obat = s.kode_obat
                JOIN kategori_obat k ON o.kode_kategori = k.kode_kategori
                WHERE s.kode_cabang = ? AND o.kode_obat = ?
            ");
            $stmtCek->execute([$cabangUser, $kodeObat]);
            $item = $stmtCek->fetch();

            if (!$item || (int)$item['jumlah_stok'] < $jumlahBeli) {
                throw new Exception("Stok obat tidak mencukupi di cabang {$cabangUser} (Sisa: " . ($item['jumlah_stok'] ?? 0) . " unit).");
            }

            $noFaktur = "INV-" . date("ymdHis");
            $hargaJual = (float)$item['harga_jual'];
            $subtotal = $hargaJual * $jumlahBeli;

            $statusTrx = ((int)$item['wajib_apoteker'] === 1) ? 'PENDING_APPROVAL' : 'APPROVED';

            // Insert Penjualan
            $stmtP = $pdo->prepare("INSERT INTO penjualan (no_faktur, tanggal, kode_cabang, id_pengguna, total, status_transaksi) VALUES (?, NOW(), ?, ?, ?, ?)");
            $stmtP->execute([$noFaktur, $cabangUser, $idPengguna, $subtotal, $statusTrx]);

            // Insert Detail Penjualan (Menggunakan $jumlahBeli)
            $stmtD = $pdo->prepare("INSERT INTO detail_penjualan (no_faktur, kode_obat, jumlah, harga, subtotal) VALUES (?, ?, ?, ?, ?)");
            $stmtD->execute([$noFaktur, $kodeObat, $jumlahBeli, $hargaJual, $subtotal]);

            // Kurangi Stok Cabang (Mengurangi tepat sejumlah $jumlahBeli)
            $stmtU = $pdo->prepare("UPDATE stok SET jumlah_stok = jumlah_stok - ? WHERE kode_cabang = ? AND kode_obat = ?");
            $stmtU->execute([$jumlahBeli, $cabangUser, $kodeObat]);

            $pdo->commit();

            if ($statusTrx === 'PENDING_APPROVAL') {
                $pesan = "Transaksi Faktur <strong>#{$noFaktur}</strong> berhasil disimpan. Karena mengandung <strong>Obat Keras</strong>, status otomatis menjadi <strong>PENDING_APPROVAL</strong> (Menunggu Validasi Apoteker).";
                $tipePesan = 'warning';
            } else {
                $pesan = "Transaksi POS Berhasil! Faktur <strong>#{$noFaktur}</strong> (Beli: {$jumlahBeli} unit) senilai Rp " . number_format($subtotal, 0, ',', '.') . " tersimpan.";
                $tipePesan = 'success';
            }
        } catch (Exception $ex) {
            $pdo->rollBack();
            $pesan = "Transaksi dibatalkan (ROLLBACK): " . $ex->getMessage();
            $tipePesan = 'danger';
        }
    }
}

// Data Pendukung Tampilan
$totalJenisObat = (int)$pdo->query("SELECT COUNT(*) FROM obat")->fetchColumn();
$totalCabang    = (int)$pdo->query("SELECT COUNT(*) FROM cabang")->fetchColumn();
$totalTrx       = (int)$pdo->query("SELECT COUNT(*) FROM penjualan WHERE kode_cabang = '{$cabangUser}'")->fetchColumn();
$stokKritis     = $pdo->query("SELECT * FROM v_stok_kritis WHERE kode_cabang = '{$cabangUser}'")->fetchAll();

$stokCabang = $pdo->prepare("
    SELECT o.kode_obat, o.nama_obat, o.harga_jual, o.satuan, o.stok_minimum, k.nama_kategori, k.wajib_apoteker, s.jumlah_stok, s.kode_cabang
    FROM stok s
    JOIN obat o ON s.kode_obat = o.kode_obat
    JOIN kategori_obat k ON o.kode_kategori = k.kode_kategori
    WHERE s.kode_cabang = ?
    ORDER BY o.nama_obat ASC
");
$stokCabang->execute([$cabangUser]);
$daftarStok = $stokCabang->fetchAll();

$dataCabangUser = $pdo->prepare("SELECT * FROM cabang WHERE kode_cabang = ?");
$dataCabangUser->execute([$cabangUser]);
$infoCabang = $dataCabangUser->fetch();

$riwayatTrxCabang = $pdo->prepare("
    SELECT p.*, u.nama_lengkap AS petugas 
    FROM penjualan p 
    JOIN pengguna u ON p.id_pengguna = u.id_pengguna 
    WHERE p.kode_cabang = ? 
    ORDER BY p.tanggal DESC
");
$riwayatTrxCabang->execute([$cabangUser]);
$semuaRiwayat = $riwayatTrxCabang->fetchAll();

$topTerlaris = $pdo->query("
    SELECT o.nama_obat, COALESCE(SUM(dp.jumlah), 0) AS total_terjual
    FROM obat o
    LEFT JOIN detail_penjualan dp ON o.kode_obat = dp.kode_obat
    GROUP BY o.kode_obat, o.nama_obat
    ORDER BY total_terjual DESC
    LIMIT 5
")->fetchAll();

$namaDepan = explode(' ', $currentUser['nama_lengkap'])[0];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Apotek Multi-Cabang | Dashboard Kasir</title>
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
        .nav-link:hover { background-color: #dceeff; color: #1659a8; }
        .nav-link.active { background: linear-gradient(90deg, #3b93f2, #2374d6); color: #fff; }
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
    <!-- SIDEBAR LENGKAP -->
    <aside class="sidebar">
        <div>
            <div class="text-center pb-2 mb-2 border-bottom">
                <div class="brand-cross"><i class="bi bi-shop"></i></div>
                <div class="fw-bold" style="color:#153e6c; font-size:0.9rem;">Apotek Multi-Cabang</div>
                <small class="text-primary" style="font-size:0.65rem;">Sehat Bersama Kami</small>
            </div>
            <nav class="nav flex-column nav-menu">
                <a class="nav-link <?= $page==='dashboard'?'active':'' ?>" href="?page=dashboard"><i class="bi bi-speedometer2"></i> Dashboard</a>
                <a class="nav-link <?= $page==='transaksi'?'active':'' ?>" href="?page=transaksi"><i class="bi bi-cart3"></i> Transaksi Kasir</a>
                <a class="nav-link <?= $page==='obat'?'active':'' ?>" href="?page=obat"><i class="bi bi-capsule"></i> Data Obat</a>
                <a class="nav-link <?= $page==='cabang'?'active':'' ?>" href="?page=cabang"><i class="bi bi-hospital"></i> Data Cabang</a>
                <a class="nav-link <?= $page==='laporan'?'active':'' ?>" href="?page=laporan"><i class="bi bi-bar-chart-fill"></i> Laporan</a>
                <a class="nav-link <?= $page==='pengaturan'?'active':'' ?>" href="?page=pengaturan"><i class="bi bi-gear-fill"></i> Pengaturan</a>
                <a class="nav-link text-danger mt-2" href="index.php?logout=1"><i class="bi bi-box-arrow-left"></i> Logout</a>
            </nav>
        </div>
        <div class="text-center p-2 bg-light rounded-2 text-muted" style="font-size:0.68rem;">
            DB: <strong><?= htmlspecialchars($config['db_name']) ?></strong><br>
            User: <code>app_kasir</code>
        </div>
    </aside>

    <!-- MAIN -->
    <main class="main-area">
        <div class="hero-banner">
            <div>
                <h6 class="fw-bold mb-0" style="color:#153e6c; font-size:0.9rem;">Halo, <?= htmlspecialchars($namaDepan) ?>!</h6>
                <small class="text-secondary" style="font-size:0.72rem;">Sistem Inventori Apotek &bull; Peran: <strong>Kasir</strong> &bull; Cabang: <?= htmlspecialchars($cabangUser) ?></small>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-white text-success border rounded-pill px-2 py-1" style="font-size:0.68rem;">
                    ● app_kasir
                </span>
                <div class="btn-group btn-group-sm">
                    <a href="?env=dev&page=<?= $page ?>" class="btn btn-sm <?= $env==='dev'?'btn-primary':'btn-outline-primary' ?>" style="font-size:0.68rem;">Dev</a>
                    <a href="?env=staging&page=<?= $page ?>" class="btn btn-sm <?= $env==='staging'?'btn-info text-white':'btn-outline-info' ?>" style="font-size:0.68rem;">Staging</a>
                    <a href="?env=prod&page=<?= $page ?>" class="btn btn-sm <?= $env==='prod'?'btn-warning text-dark':'btn-outline-warning' ?>" style="font-size:0.68rem;">Prod</a>
                </div>
            </div>
        </div>

        <?php if ($pesan): ?>
            <div class="alert alert-<?= $tipePesan ?> rounded-2 border-0 shadow-sm mb-2 py-1 px-3 small"><?= $pesan ?></div>
        <?php endif; ?>

        <!-- ==================== HALAMAN: DASHBOARD ==================== -->
        <?php if ($page === 'dashboard'): ?>
            <div class="row g-2 mb-2">
                <div class="col-md-3"><div class="soft-card py-2 px-3"><small class="text-muted">Total Jenis Obat</small><h6 class="fw-bold mb-0"><?= $totalJenisObat ?> item</h6></div></div>
                <div class="col-md-3"><div class="soft-card py-2 px-3"><small class="text-muted">Jumlah Cabang</small><h6 class="fw-bold mb-0"><?= $totalCabang ?> aktif</h6></div></div>
                <div class="col-md-3"><div class="soft-card py-2 px-3"><small class="text-muted">Total Transaksi Cabang</small><h6 class="fw-bold mb-0"><?= $totalTrx ?> faktur</h6></div></div>
                <div class="col-md-3"><div class="soft-card py-2 px-3"><small class="text-muted">Stok Kritis Cabang</small><h6 class="fw-bold mb-0 text-danger"><?= count($stokKritis) ?> alert</h6></div></div>
            </div>

            <div class="row g-2 mb-2">
                <div class="col-lg-5">
                    <div class="soft-card h-100">
                        <h6 class="fw-bold mb-2 text-primary" style="font-size:0.8rem;"><i class="bi bi-cart-check me-1"></i>Transaksi Kasir &amp; Validasi ACID</h6>
                        <form method="POST">
                            <input type="hidden" name="aksi" value="proses_transaksi">
                            <div class="mb-2">
                                <label class="form-label mb-1" style="font-size:0.7rem;">Petugas Bertugas</label>
                                <input type="text" class="form-control form-control-sm bg-light" value="<?= htmlspecialchars($currentUser['nama_lengkap']) ?> (Kasir)" readonly>
                            </div>
                            <div class="mb-2">
                                <label class="form-label mb-1" style="font-size:0.7rem;">Pilih Produk Obat (Cabang <?= htmlspecialchars($cabangUser) ?>)</label>
                                <select name="kode_obat" class="form-select form-select-sm" required>
                                    <option value="">-- Pilih Obat / Alkes --</option>
                                    <?php foreach ($daftarStok as $ds): ?>
                                        <option value="<?= $ds['kode_obat'] ?>">
                                            <?= htmlspecialchars($ds['nama_obat']) ?> (<?= $ds['nama_kategori'] ?>) — Rp <?= number_format($ds['harga_jual'], 0, ',', '.') ?> (Stok: <?= $ds['jumlah_stok'] ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label mb-1" style="font-size:0.7rem;">Jumlah Pembelian</label>
                                <input type="number" name="jumlah_pembelian" class="form-control form-control-sm" value="1" min="1" required>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm w-100 fw-bold py-2"><i class="bi bi-shield-lock me-1"></i> Proses Transaksi (Atomic POS)</button>
                        </form>
                    </div>
                </div>

                <div class="col-lg-7">
                    <div class="soft-card h-100">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="fw-bold mb-0 text-primary" style="font-size:0.8rem;">Stok Real-Time (<?= htmlspecialchars($cabangUser) ?>)</h6>
                        </div>
                        <div class="table-responsive" style="max-height: 220px;">
                            <table class="table table-hover mb-0">
                                <thead><tr><th>Cabang</th><th>Nama Obat</th><th>Kategori</th><th>Harga</th><th>Stok</th><th>Status</th></tr></thead>
                                <tbody>
                                    <?php foreach ($daftarStok as $ds): ?>
                                    <tr>
                                        <td><span class="badge-cabang"><?= $ds['kode_cabang'] ?></span></td>
                                        <td class="fw-semibold"><?= htmlspecialchars($ds['nama_obat']) ?></td>
                                        <td>
                                            <?php if ($ds['wajib_apoteker']): ?>
                                                <span class="badge bg-danger-subtle text-danger" style="font-size:0.65rem;">Keras</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary-subtle text-secondary" style="font-size:0.65rem;"><?= htmlspecialchars($ds['nama_kategori']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>Rp <?= number_format($ds['harga_jual'], 0, ',', '.') ?></td>
                                        <td class="fw-bold text-primary"><?= $ds['jumlah_stok'] ?></td>
                                        <td>
                                            <?php if ($ds['jumlah_stok'] <= $ds['stok_minimum']): ?>
                                                <span class="badge bg-danger text-white" style="font-size:0.65rem;">Kritis</span>
                                            <?php else: ?>
                                                <span class="badge bg-success text-white" style="font-size:0.65rem;">Tersedia</span>
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

            <div class="row g-2">
                <div class="col-lg-7">
                    <div class="soft-card">
                        <h6 class="fw-bold mb-2 text-primary" style="font-size:0.8rem;"><i class="bi bi-cpu me-1"></i>Pemantauan Resource Sistem</h6>
                        <div class="row text-center g-2 py-2">
                            <div class="col"><div class="p-2 bg-light rounded"><small class="text-muted d-block">CPU</small><strong class="text-primary">34.2%</strong></div></div>
                            <div class="col"><div class="p-2 bg-light rounded"><small class="text-muted d-block">RAM</small><strong class="text-primary">60.0%</strong></div></div>
                            <div class="col"><div class="p-2 bg-light rounded"><small class="text-muted d-block">Koneksi DB</small><strong class="text-primary">12 Th</strong></div></div>
                            <div class="col"><div class="p-2 bg-light rounded"><small class="text-muted d-block">Disk</small><strong class="text-primary">16.0%</strong></div></div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="soft-card">
                        <h6 class="fw-bold mb-2 text-primary" style="font-size:0.8rem;"><i class="bi bi-trophy me-1"></i>Top 5 Obat Terlaris</h6>
                        <ul class="list-group list-group-flush small">
                            <?php foreach ($topTerlaris as $idx => $tp): ?>
                            <li class="list-group-item bg-transparent d-flex justify-content-between align-items-center px-0 py-1">
                               <span><?= ($idx+1) ?>. <?= htmlspecialchars($tp['nama_obat']) ?></span>
                               <span class="badge bg-primary rounded-pill"><?= $tp['total_terjual'] ?> terjual</span>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>

        <!-- ==================== HALAMAN: TRANSAKSI KASIR ==================== -->
        <?php elseif ($page === 'transaksi'): ?>
            <div class="soft-card">
                <h6 class="fw-bold mb-3 text-primary"><i class="bi bi-cart3 me-1"></i>Modul Point of Sales (POS) &amp; Transaksi Kasir</h6>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead><tr><th>No Faktur</th><th>Waktu</th><th>Petugas</th><th>Total</th><th>Status Transaksi</th></tr></thead>
                        <tbody>
                            <?php foreach ($semuaRiwayat as $sr): ?>
                            <tr>
                                <td class="fw-bold text-primary"><?= htmlspecialchars($sr['no_faktur']) ?></td>
                                <td><?= htmlspecialchars($sr['tanggal']) ?></td>
                                <td><?= htmlspecialchars($sr['petugas']) ?></td>
                                <td class="fw-bold">Rp <?= number_format($sr['total'], 0, ',', '.') ?></td>
                                <td>
                                    <?php if ($sr['status_transaksi'] === 'APPROVED'): ?>
                                        <span class="badge bg-success">Selesai</span>
                                    <?php elseif ($sr['status_transaksi'] === 'PENDING_APPROVAL'): ?>
                                        <span class="badge bg-warning text-dark">Pending Resep (Apoteker)</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger"><?= htmlspecialchars($sr['status_transaksi']) ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <!-- ==================== HALAMAN: DATA OBAT ==================== -->
        <?php elseif ($page === 'obat'): ?>
            <div class="soft-card">
                <h6 class="fw-bold mb-3 text-primary"><i class="bi bi-capsule me-1"></i>Katalog &amp; Stok Obat Cabang <?= htmlspecialchars($cabangUser) ?></h6>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead><tr><th>Kode</th><th>Nama Obat</th><th>Kategori</th><th>Harga Jual</th><th>Stok Cabang</th><th>Status Min</th></tr></thead>
                        <tbody>
                            <?php foreach ($daftarStok as $ds): ?>
                            <tr>
                                <td><code><?= $ds['kode_obat'] ?></code></td>
                                <td class="fw-bold"><?= htmlspecialchars($ds['nama_obat']) ?></td>
                                <td><?= htmlspecialchars($ds['nama_kategori']) ?></td>
                                <td>Rp <?= number_format($ds['harga_jual'], 0, ',', '.') ?></td>
                                <td class="fw-bold text-primary"><?= $ds['jumlah_stok'] ?> <?= $ds['satuan'] ?></td>
                                <td>
                                    <?php if ($ds['jumlah_stok'] <= $ds['stok_minimum']): ?>
                                        <span class="badge bg-danger">Kritis</span>
                                    <?php else: ?>
                                        <span class="badge bg-success">Aman</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <!-- ==================== HALAMAN: DATA CABANG ==================== -->
        <?php elseif ($page === 'cabang'): ?>
            <div class="soft-card">
                <h6 class="fw-bold mb-3 text-primary"><i class="bi bi-hospital me-1"></i>Informasi Cabang Tempat Bertugas</h6>
                <?php if ($infoCabang): ?>
                    <ul class="list-group list-group-flush small">
                        <li class="list-group-item bg-transparent"><strong>Kode Cabang:</strong> <span class="badge-cabang"><?= htmlspecialchars($infoCabang['kode_cabang']) ?></span></li>
                        <li class="list-group-item bg-transparent"><strong>Nama Cabang:</strong> <?= htmlspecialchars($infoCabang['nama_cabang']) ?></li>
                        <li class="list-group-item bg-transparent"><strong>Alamat:</strong> <?= htmlspecialchars($infoCabang['alamat']) ?></li>
                        <li class="list-group-item bg-transparent"><strong>Telepon:</strong> <?= htmlspecialchars($infoCabang['telepon']) ?></li>
                    </ul>
                <?php endif; ?>
            </div>

        <!-- ==================== HALAMAN: LAPORAN ==================== -->
        <?php elseif ($page === 'laporan'): ?>
            <div class="soft-card">
                <h6 class="fw-bold mb-3 text-primary"><i class="bi bi-bar-chart-fill me-1"></i>Laporan Penjualan Cabang <?= htmlspecialchars($cabangUser) ?></h6>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead><tr><th>Faktur</th><th>Tanggal</th><th>Kasir</th><th>Total</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php foreach ($semuaRiwayat as $sr): ?>
                            <tr>
                                <td class="fw-bold text-primary"><?= htmlspecialchars($sr['no_faktur']) ?></td>
                                <td><?= htmlspecialchars($sr['tanggal']) ?></td>
                                <td><?= htmlspecialchars($sr['petugas']) ?></td>
                                <td class="fw-bold">Rp <?= number_format($sr['total'], 0, ',', '.') ?></td>
                                <td><span class="badge bg-secondary"><?= htmlspecialchars($sr['status_transaksi']) ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <!-- ==================== HALAMAN: PENGATURAN ==================== -->
        <?php elseif ($page === 'pengaturan'): ?>
            <div class="soft-card">
                <h6 class="fw-bold mb-3 text-primary"><i class="bi bi-gear-fill me-1"></i>Pengaturan &amp; Informasi Sistem Kasir</h6>
                <ul class="list-group list-group-flush small">
                    <li class="list-group-item bg-transparent"><strong>Environment:</strong> <?= strtoupper($env) ?></li>
                    <li class="list-group-item bg-transparent"><strong>Database:</strong> <code><?= htmlspecialchars($config['db_name']) ?></code></li>
                    <li class="list-group-item bg-transparent"><strong>Database User:</strong> <code>app_kasir</code> (Least Privilege)</li>
                    <li class="list-group-item bg-transparent"><strong>Cabang Terkunci:</strong> <?= htmlspecialchars($cabangUser) ?></li>
                </ul>
            </div>
        <?php endif; ?>
    </main>
</div>
</body>
</html>