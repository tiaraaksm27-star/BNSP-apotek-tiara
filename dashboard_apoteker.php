<?php
session_start();

if (!isset($_SESSION['user']) || $_SESSION['user']['peran'] !== 'Apoteker') {
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
        'app_apoteker',
        'Apoteker#2026',
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
if (!in_array($page, ['dashboard', 'validasi', 'stok', 'laporan', 'pengaturan'])) {
    $page = 'dashboard';
}

// 1. Proses Approve / Reject Resep Obat Keras dari Kasir
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['no_faktur'], $_POST['status_approval'])) {
    $noFaktur   = $_POST['no_faktur'];
    $statusAppr = $_POST['status_approval'];

    if (in_array($statusAppr, ['APPROVED', 'REJECTED'], true)) {
        try {
            $pdo->beginTransaction();

            $stmtUpd = $pdo->prepare("UPDATE penjualan SET status_transaksi = ? WHERE no_faktur = ? AND kode_cabang = ? AND status_transaksi = 'PENDING_APPROVAL'");
            $stmtUpd->execute([$statusAppr, $noFaktur, $cabangUser]);

            if ($statusAppr === 'REJECTED' && $stmtUpd->rowCount() > 0) {
                $stmtItems = $pdo->prepare("SELECT kode_obat, jumlah FROM detail_penjualan WHERE no_faktur = ?");
                $stmtItems->execute([$noFaktur]);
                $stmtRestore = $pdo->prepare("UPDATE stok SET jumlah_stok = jumlah_stok + ? WHERE kode_cabang = ? AND kode_obat = ?");

                foreach ($stmtItems->fetchAll() as $it) {
                    $stmtRestore->execute([(int)$it['jumlah'], $cabangUser, $it['kode_obat']]);
                }
            }

            $pdo->commit();
            $pesan = "Status resep Faktur <strong>#" . htmlspecialchars($noFaktur) . "</strong> berhasil diubah menjadi <strong>{$statusAppr}</strong>.";
            $tipePesan = ($statusAppr === 'APPROVED') ? 'success' : 'warning';
        } catch (Exception $e) {
            $pdo->rollBack();
            $pesan = "Gagal memproses resep (Rollback): " . htmlspecialchars($e->getMessage());
            $tipePesan = 'danger';
        }
    }
}

// 2. Proses Penjualan Langsung oleh Apoteker
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'jual_langsung') {
    $kodeObat = $_POST['kode_obat'] ?? '';
    $jumlahBeli = (int)($_POST['jumlah_pembelian'] ?? 0);

    if ($kodeObat && $jumlahBeli > 0) {
        try {
            $pdo->beginTransaction();

            $stmtCek = $pdo->prepare("
                SELECT o.nama_obat, o.harga_jual, s.jumlah_stok 
                FROM obat o 
                JOIN stok s ON o.kode_obat = s.kode_obat 
                WHERE s.kode_cabang = ? AND o.kode_obat = ?
            ");
            $stmtCek->execute([$cabangUser, $kodeObat]);
            $item = $stmtCek->fetch();

            if (!$item || (int)$item['jumlah_stok'] < $jumlahBeli) {
                throw new Exception("Stok obat di cabang {$cabangUser} tidak mencukupi (Sisa: " . ($item['jumlah_stok'] ?? 0) . " unit).");
            }

            $noFaktur = "INV-" . date("ymdHis");
            $hargaJual = (float)$item['harga_jual'];
            $subtotal = $hargaJual * $jumlahBeli;

            $stmtP = $pdo->prepare("INSERT INTO penjualan (no_faktur, tanggal, kode_cabang, id_pengguna, total, status_transaksi) VALUES (?, NOW(), ?, ?, ?, 'APPROVED')");
            $stmtP->execute([$noFaktur, $cabangUser, $idPengguna, $subtotal]);

            $stmtD = $pdo->prepare("INSERT INTO detail_penjualan (no_faktur, kode_obat, jumlah, harga, subtotal) VALUES (?, ?, ?, ?, ?)");
            $stmtD->execute([$noFaktur, $kodeObat, $jumlahBeli, $hargaJual, $subtotal]);

            $stmtU = $pdo->prepare("UPDATE stok SET jumlah_stok = jumlah_stok - ? WHERE kode_cabang = ? AND kode_obat = ?");
            $stmtU->execute([$jumlahBeli, $cabangUser, $kodeObat]);

            $pdo->commit();
            $pesan = "Penjualan langsung berhasil! Faktur <strong>#{$noFaktur}</strong> ({$item['nama_obat']} x {$jumlahBeli}) tersimpan.";
            $tipePesan = 'success';
        } catch (Exception $e) {
            $pdo->rollBack();
            $pesan = "Transaksi dibatalkan (ROLLBACK): " . $e->getMessage();
            $tipePesan = 'danger';
        }
    }
}

// Data Pendukung Tampilan
$totalJenisObat = (int)$pdo->query("SELECT COUNT(*) FROM obat")->fetchColumn();
$stokKritis = $pdo->query("SELECT * FROM v_stok_kritis WHERE kode_cabang = '{$cabangUser}'")->fetchAll();

$stmtAntrean = $pdo->prepare("
    SELECT p.no_faktur, p.tanggal, o.nama_obat, dp.jumlah, u.nama_lengkap AS kasir 
    FROM penjualan p
    JOIN detail_penjualan dp ON p.no_faktur = dp.no_faktur
    JOIN obat o ON dp.kode_obat = o.kode_obat
    JOIN kategori_obat k ON o.kode_kategori = k.kode_kategori
    JOIN pengguna u ON p.id_pengguna = u.id_pengguna
    WHERE p.kode_cabang = ? AND p.status_transaksi = 'PENDING_APPROVAL' AND k.wajib_apoteker = 1
");
$stmtAntrean->execute([$cabangUser]);
$antreanResep = $stmtAntrean->fetchAll();

$stmtStok = $pdo->prepare("
    SELECT o.kode_obat, o.nama_obat, o.harga_jual, o.stok_minimum, o.satuan, k.nama_kategori, k.wajib_apoteker, s.jumlah_stok, s.nomor_batch, s.tanggal_kedaluwarsa
    FROM stok s
    JOIN obat o ON s.kode_obat = o.kode_obat
    JOIN kategori_obat k ON o.kode_kategori = k.kode_kategori
    WHERE s.kode_cabang = ?
    ORDER BY s.tanggal_kedaluwarsa ASC
");
$stmtStok->execute([$cabangUser]);
$stokCabang = $stmtStok->fetchAll();

$stmtRiwayat = $pdo->prepare("
    SELECT p.*, u.nama_lengkap AS petugas 
    FROM penjualan p 
    JOIN pengguna u ON p.id_pengguna = u.id_pengguna 
    WHERE p.kode_cabang = ? 
    ORDER BY p.tanggal DESC
");
$stmtRiwayat->execute([$cabangUser]);
$riwayatCabang = $stmtRiwayat->fetchAll();

$namaDepan = explode(' ', $currentUser['nama_lengkap'])[0];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Apotek Multi-Cabang | Dashboard Apoteker</title>
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
    <!-- SIDEBAR APOTEKER -->
    <aside class="sidebar">
        <div>
            <div class="text-center pb-2 mb-2 border-bottom">
                <div class="brand-cross"><i class="bi bi-capsule-pill"></i></div>
                <div class="fw-bold" style="color:#153e6c; font-size:0.9rem;">Apotek Multi-Cabang</div>
                <small class="text-primary fw-bold" style="font-size:0.65rem;">Panel Apoteker</small>
            </div>
            <nav class="nav flex-column nav-menu">
                <a class="nav-link <?= $page==='dashboard'?'active':'' ?>" href="?page=dashboard"><i class="bi bi-speedometer2"></i> Dashboard</a>
                <a class="nav-link <?= $page==='validasi'?'active':'' ?>" href="?page=validasi"><i class="bi bi-clipboard2-pulse-fill"></i> Validasi Resep Keras</a>
                <a class="nav-link <?= $page==='stok'?'active':'' ?>" href="?page=stok"><i class="bi bi-box-seam"></i> Stok &amp; FEFO</a>
                <a class="nav-link <?= $page==='laporan'?'active':'' ?>" href="?page=laporan"><i class="bi bi-bar-chart-fill"></i> Laporan Cabang</a>
                <a class="nav-link <?= $page==='pengaturan'?'active':'' ?>" href="?page=pengaturan"><i class="bi bi-gear-fill"></i> Pengaturan</a>
                <a class="nav-link text-danger mt-2" href="index.php?logout=1"><i class="bi bi-box-arrow-left"></i> Logout</a>
            </nav>
        </div>
        <div class="text-center p-2 bg-light rounded-2 text-muted" style="font-size:0.68rem;">
            DB: <strong><?= htmlspecialchars($config['db_name']) ?></strong><br>
            User: <code>app_apoteker</code>
        </div>
    </aside>

    <!-- MAIN -->
    <main class="main-area">
        <div class="hero-banner">
            <div>
                <h6 class="fw-bold mb-0" style="color:#153e6c; font-size:0.9rem;">Halo, <?= htmlspecialchars($namaDepan) ?>!</h6>
                <small class="text-secondary" style="font-size:0.72rem;">Penanggung Jawab Apotek &bull; Peran: <strong>Apoteker</strong> &bull; Cabang Terkunci: <?= htmlspecialchars($cabangUser) ?></small>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-white text-primary border rounded-pill px-2 py-1" style="font-size:0.68rem;">
                    ● app_apoteker
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
                <div class="col-md-4"><div class="soft-card py-2 px-3"><small class="text-muted">Total Katalog Obat</small><h6 class="fw-bold mb-0"><?= $totalJenisObat ?> item</h6></div></div>
                <div class="col-md-4"><div class="soft-card py-2 px-3"><small class="text-muted">Antrean Resep Keras</small><h6 class="fw-bold mb-0 text-danger"><?= count($antreanResep) ?> pending</h6></div></div>
                <div class="col-md-4"><div class="soft-card py-2 px-3"><small class="text-muted">Stok Kritis Cabang</small><h6 class="fw-bold mb-0 text-warning"><?= count($stokKritis) ?> alert</h6></div></div>
            </div>

            <div class="row g-2 mb-2">
                <div class="col-lg-5">
                    <div class="soft-card h-100">
                        <h6 class="fw-bold mb-2 text-primary" style="font-size:0.8rem;"><i class="bi bi-cart-plus me-1"></i>Penjualan Langsung Apoteker (Termasuk Obat Keras)</h6>
                        <form method="POST">
                            <input type="hidden" name="aksi" value="jual_langsung">
                            <div class="mb-2">
                                <label class="form-label mb-1" style="font-size:0.7rem;">Pilih Obat Cabang <?= htmlspecialchars($cabangUser) ?></label>
                                <select name="kode_obat" class="form-select form-select-sm" required>
                                    <option value="">-- Pilih Obat --</option>
                                    <?php foreach ($stokCabang as $sc): ?>
                                        <option value="<?= $sc['kode_obat'] ?>">
                                            <?= htmlspecialchars($sc['nama_obat']) ?> (Stok: <?= $sc['jumlah_stok'] ?>) <?= $sc['wajib_apoteker'] ? '[KERAS]' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label mb-1" style="font-size:0.7rem;">Jumlah Beli</label>
                                <input type="number" name="jumlah_pembelian" class="form-control form-control-sm" value="1" min="1" required>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm w-100 fw-bold py-2"><i class="bi bi-shield-check me-1"></i> Proses Transaksi Aman (Atomic)</button>
                        </form>
                    </div>
                </div>

                <div class="col-lg-7">
                    <div class="soft-card h-100">
                        <h6 class="fw-bold mb-2 text-primary" style="font-size:0.8rem;"><i class="bi bi-clipboard2-pulse me-1"></i>Antrean Resep Menunggu Persetujuan</h6>
                        <div class="table-responsive" style="max-height: 220px;">
                            <table class="table table-hover mb-0">
                                <thead><tr><th>Faktur</th><th>Kasir</th><th>Obat Keras</th><th>Jumlah</th><th>Aksi</th></tr></thead>
                                <tbody>
                                    <?php if (empty($antreanResep)): ?>
                                        <tr><td colspan="5" class="text-center text-muted py-3">Tidak ada antrean resep obat keras saat ini.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($antreanResep as $ar): ?>
                                        <tr>
                                            <td class="fw-bold text-primary"><?= htmlspecialchars($ar['no_faktur']) ?></td>
                                            <td><?= htmlspecialchars($ar['kasir']) ?></td>
                                            <td class="fw-bold text-danger"><?= htmlspecialchars($ar['nama_obat']) ?></td>
                                            <td><?= $ar['jumlah'] ?> unit</td>
                                            <td>
                                                <form method="POST" class="d-flex gap-1">
                                                    <input type="hidden" name="no_faktur" value="<?= htmlspecialchars($ar['no_faktur']) ?>">
                                                    <button type="submit" name="status_approval" value="APPROVED" class="btn btn-success btn-sm py-0 px-1" style="font-size:0.65rem;">Approve</button>
                                                    <button type="submit" name="status_approval" value="REJECTED" class="btn btn-danger btn-sm py-0 px-1" style="font-size:0.65rem;">Reject</button>
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
            </div>

            <!-- Tabel Riwayat Transaksi Cabang Ditambahkan di Bawah Agar Tidak Kosong -->
            <div class="soft-card">
                <h6 class="fw-bold mb-2 text-primary" style="font-size:0.8rem;"><i class="bi bi-clock-history me-1"></i>Riwayat Transaksi Cabang <?= htmlspecialchars($cabangUser) ?></h6>
                <div class="table-responsive" style="max-height: 200px;">
                    <table class="table table-hover mb-0">
                        <thead><tr><th>No Faktur</th><th>Waktu</th><th>Petugas</th><th>Total</th><th>Status Transaksi</th></tr></thead>
                        <tbody>
                            <?php if (empty($riwayatCabang)): ?>
                                <tr><td colspan="5" class="text-center text-muted py-2">Belum ada riwayat transaksi di cabang ini.</td></tr>
                            <?php else: ?>
                                <?php foreach ($riwayatCabang as $rc): ?>
                                <tr>
                                    <td class="fw-bold text-primary"><?= htmlspecialchars($rc['no_faktur']) ?></td>
                                    <td><?= htmlspecialchars($rc['tanggal']) ?></td>
                                    <td><?= htmlspecialchars($rc['petugas']) ?></td>
                                    <td class="fw-bold">Rp <?= number_format($rc['total'], 0, ',', '.') ?></td>
                                    <td>
                                        <?php if ($rc['status_transaksi'] === 'APPROVED'): ?>
                                            <span class="badge bg-success">Selesai / Approved</span>
                                        <?php elseif ($rc['status_transaksi'] === 'PENDING_APPROVAL'): ?>
                                            <span class="badge bg-warning text-dark">Pending Resep</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger"><?= htmlspecialchars($rc['status_transaksi']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <!-- ==================== HALAMAN: VALIDASI RESEP ==================== -->
        <?php elseif ($page === 'validasi'): ?>
            <div class="soft-card">
                <h6 class="fw-bold mb-3 text-primary"><i class="bi bi-clipboard2-pulse-fill me-1"></i>Manajemen Antrean Persetujuan Resep Obat Keras</h6>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead><tr><th>No Faktur</th><th>Waktu</th><th>Kasir Pemohon</th><th>Nama Obat Keras</th><th>Jumlah</th><th>Aksi Apoteker</th></tr></thead>
                        <tbody>
                            <?php if (empty($antreanResep)): ?>
                                <tr><td colspan="6" class="text-center text-muted py-4">Tidak ada antrean resep obat keras yang memerlukan persetujuan di cabang <?= htmlspecialchars($cabangUser) ?>.</td></tr>
                            <?php else: ?>
                                <?php foreach ($antreanResep as $ar): ?>
                                <tr>
                                    <td class="fw-bold text-primary"><?= htmlspecialchars($ar['no_faktur']) ?></td>
                                    <td><?= htmlspecialchars($ar['tanggal']) ?></td>
                                    <td><?= htmlspecialchars($ar['kasir']) ?></td>
                                    <td class="fw-bold text-danger"><?= htmlspecialchars($ar['nama_obat']) ?></td>
                                    <td><?= $ar['jumlah'] ?> unit</td>
                                    <td>
                                        <form method="POST" class="d-flex gap-2">
                                            <input type="hidden" name="no_faktur" value="<?= htmlspecialchars($ar['no_faktur']) ?>">
                                            <button type="submit" name="status_approval" value="APPROVED" class="btn btn-success btn-sm fw-bold"><i class="bi bi-check-lg me-1"></i> Approve</button>
                                            <button type="submit" name="status_approval" value="REJECTED" class="btn btn-danger btn-sm fw-bold"><i class="bi bi-x-lg me-1"></i> Reject</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <!-- ==================== HALAMAN: STOK & FEFO ==================== -->
        <?php elseif ($page === 'stok'): ?>
            <div class="soft-card">
                <h6 class="fw-bold mb-3 text-primary"><i class="bi bi-box-seam me-1"></i>Pemantauan Stok &amp; Aturan FEFO (First Expired First Out) Cabang <?= htmlspecialchars($cabangUser) ?></h6>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead><tr><th>Kode</th><th>Nama Obat</th><th>Kategori</th><th>No Batch</th><th>Expired Date</th><th>Stok</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php foreach ($stokCabang as $sc): ?>
                            <tr>
                                <td><code><?= $sc['kode_obat'] ?></code></td>
                                <td class="fw-bold"><?= htmlspecialchars($sc['nama_obat']) ?></td>
                                <td>
                                    <?php if ($sc['wajib_apoteker']): ?>
                                        <span class="badge bg-danger-subtle text-danger">Obat Keras</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary-subtle text-secondary"><?= htmlspecialchars($sc['nama_kategori']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><code><?= htmlspecialchars($sc['nomor_batch'] ?? 'BATCH-001') ?></code></td>
                                <td class="fw-semibold text-danger"><?= htmlspecialchars($sc['tanggal_kedaluwarsa'] ?? '-') ?></td>
                                <td class="fw-bold text-primary"><?= $sc['jumlah_stok'] ?> <?= $sc['satuan'] ?></td>
                                <td>
                                    <?php if ($sc['jumlah_stok'] <= $sc['stok_minimum']): ?>
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

        <!-- ==================== HALAMAN: LAPORAN ==================== -->
        <?php elseif ($page === 'laporan'): ?>
            <div class="soft-card">
                <h6 class="fw-bold mb-3 text-primary"><i class="bi bi-bar-chart-fill me-1"></i>Riwayat &amp; Laporan Transaksi Cabang</h6>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead><tr><th>Faktur</th><th>Tanggal</th><th>Petugas</th><th>Total</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php foreach ($riwayatCabang as $rc): ?>
                            <tr>
                                <td class="fw-bold text-primary"><?= htmlspecialchars($rc['no_faktur']) ?></td>
                                <td><?= htmlspecialchars($rc['tanggal']) ?></td>
                                <td><?= htmlspecialchars($rc['petugas']) ?></td>
                                <td class="fw-bold">Rp <?= number_format($rc['total'], 0, ',', '.') ?></td>
                                <td><span class="badge bg-secondary"><?= htmlspecialchars($rc['status_transaksi']) ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <!-- ==================== HALAMAN: PENGATURAN ==================== -->
        <?php elseif ($page === 'pengaturan'): ?>
            <div class="soft-card">
                <h6 class="fw-bold mb-3 text-primary"><i class="bi bi-gear-fill me-1"></i>Pengaturan Akun Apoteker</h6>
                <ul class="list-group list-group-flush small">
                    <li class="list-group-item bg-transparent"><strong>Environment:</strong> <?= strtoupper($env) ?></li>
                    <li class="list-group-item bg-transparent"><strong>Database:</strong> <code><?= htmlspecialchars($config['db_name']) ?></code></li>
                    <li class="list-group-item bg-transparent"><strong>Database User:</strong> <code>app_apoteker</code> (Least Privilege)</li>
                    <li class="list-group-item bg-transparent"><strong>Cabang Terkunci:</strong> <?= htmlspecialchars($cabangUser) ?></li>
                </ul>
            </div>
        <?php endif; ?>
    </main>
</div>
</body>
</html>