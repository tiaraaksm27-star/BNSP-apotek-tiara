<?php
// Pastikan session sudah aktif
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Tentukan Environment Aktif (Default: dev)
$env = $_SESSION['env'] ?? 'dev';
if (!in_array($env, ['dev', 'staging', 'prod'])) {
    $env = 'dev';
}

// 2. Load File Konfigurasi JSON Environment
$configFile = __DIR__ . "/config_{$env}.json";
if (!file_exists($configFile)) {
    // Buat otomatis jika belum ada
    $defaultCfg = [
        'environment' => strtoupper($env),
        'db_host'     => '127.0.0.1',
        'db_name'     => "db_apotek_{$env}",
        'log_level'   => 'DEBUG'
    ];
    file_put_contents($configFile, json_encode($defaultCfg, JSON_PRETTY_PRINT));
}
$config = json_decode(file_get_contents($configFile), true);

// 3. Fungsi Kredensial Database Berdasarkan Peran (Prinsip Least Privilege)
function getDbCredentialsByRole(?string $peran): array {
    return match ($peran) {
        'Admin Pusat' => ['user' => 'app_admin_pusat', 'pass' => 'AdminPusat#2026'],
        'Apoteker'    => ['user' => 'app_apoteker',    'pass' => 'Apoteker#2026'],
        'Kasir'       => ['user' => 'app_kasir',       'pass' => 'Kasir#2026'],
        default       => ['user' => 'app_admin_pusat', 'pass' => 'AdminPusat#2026'], // Fallback untuk auth login awal
    };
}

// 4. Inisialisasi Koneksi PDO (Gunakan kredensial sesuai peran user yang login, atau default admin untuk login)
$currentPeran = $_SESSION['user']['peran'] ?? 'Admin Pusat';
$dbCred = getDbCredentialsByRole($currentPeran);

try {
    $dsn = "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4";
    $pdo = new PDO($dsn, $dbCred['user'], $dbCred['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    die("<div style='font-family:sans-serif; padding:20px; color:#dc2626;'><h3>Koneksi Database Gagal:</h3> " . htmlspecialchars($e->getMessage()) . "</div>");
}
?>