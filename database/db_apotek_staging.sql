-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Nov 13, 2026 at 07:52 PM
-- Server version: 8.4.3
-- PHP Version: 8.3.16

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `db_apotek_staging`
--

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_alokasi_batch_fefo` (IN `p_kode_cabang` VARCHAR(10), IN `p_kode_obat` VARCHAR(10), IN `p_jumlah` INT)   BEGIN
    DECLARE v_sisa INT DEFAULT p_jumlah;
    DECLARE v_id_batch INT;
    DECLARE v_jml_batch INT;
    DECLARE v_done INT DEFAULT 0;

    DECLARE cur_batch CURSOR FOR
        SELECT id_batch, jumlah_batch FROM batch_stok
        WHERE kode_cabang = p_kode_cabang AND kode_obat = p_kode_obat AND jumlah_batch > 0
        ORDER BY tanggal_kedaluwarsa ASC, id_batch ASC FOR UPDATE;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

    OPEN cur_batch;
    read_loop: LOOP
        FETCH cur_batch INTO v_id_batch, v_jml_batch;
        IF v_done = 1 OR v_sisa <= 0 THEN LEAVE read_loop; END IF;

        IF v_jml_batch >= v_sisa THEN
            UPDATE batch_stok SET jumlah_batch = jumlah_batch - v_sisa WHERE id_batch = v_id_batch;
            SET v_sisa = 0;
        ELSE
            UPDATE batch_stok SET jumlah_batch = 0 WHERE id_batch = v_id_batch;
            SET v_sisa = v_sisa - v_jml_batch;
        END IF;
    END LOOP;
    CLOSE cur_batch;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_proses_penjualan` (IN `p_no_faktur` VARCHAR(25), IN `p_username` VARCHAR(50), IN `p_kode_obat` VARCHAR(10), IN `p_jumlah` INT)   BEGIN
    DECLARE v_id_pengguna INT; DECLARE v_peran VARCHAR(20); DECLARE v_kode_cabang VARCHAR(10);
    DECLARE v_wajib_apoteker TINYINT(1); DECLARE v_harga DECIMAL(12,2); DECLARE v_stok_saat_ini INT;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
    START TRANSACTION;

    SELECT id_pengguna, peran, kode_cabang INTO v_id_pengguna, v_peran, v_kode_cabang 
    FROM pengguna WHERE username = p_username;
    IF v_id_pengguna IS NULL OR v_kode_cabang IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'DITOLAK: Pengguna tidak valid.';
    END IF;

    SELECT o.harga_jual, k.wajib_apoteker INTO v_harga, v_wajib_apoteker
    FROM obat o JOIN kategori_obat k ON o.kode_kategori = k.kode_kategori
    WHERE o.kode_obat = p_kode_obat;

    IF v_wajib_apoteker = 1 AND v_peran = 'Kasir' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'DITOLAK: Kasir tidak berwenang menjual Obat Keras!';
    END IF;

    SELECT jumlah_stok INTO v_stok_saat_ini FROM stok 
    WHERE kode_cabang = v_kode_cabang AND kode_obat = p_kode_obat FOR UPDATE;
    IF v_stok_saat_ini IS NULL OR v_stok_saat_ini < p_jumlah THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'DITOLAK: Stok cabang tidak mencukupi (ROLLBACK).';
    END IF;

    INSERT IGNORE INTO penjualan (no_faktur, kode_cabang, id_pengguna, total)
    VALUES (p_no_faktur, v_kode_cabang, v_id_pengguna, 0);
    INSERT INTO detail_penjualan (no_faktur, kode_obat, jumlah, harga, subtotal)
    VALUES (p_no_faktur, p_kode_obat, p_jumlah, v_harga, v_harga * p_jumlah);

    -- Alokasi FEFO
    CALL sp_alokasi_batch_fefo(v_kode_cabang, p_kode_obat, p_jumlah);

    UPDATE penjualan SET total = fn_hitung_total_faktur(p_no_faktur) WHERE no_faktur = p_no_faktur;
    COMMIT;
END$$

--
-- Functions
--
CREATE DEFINER=`root`@`localhost` FUNCTION `fn_hitung_total_faktur` (`p_no_faktur` VARCHAR(25)) RETURNS DECIMAL(14,2) DETERMINISTIC READS SQL DATA BEGIN
    DECLARE v_total DECIMAL(14,2);
    SELECT COALESCE(SUM(subtotal),0) INTO v_total FROM detail_penjualan WHERE no_faktur = p_no_faktur;
    RETURN v_total;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `batch_stok`
--

CREATE TABLE `batch_stok` (
  `id_batch` int NOT NULL,
  `kode_cabang` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `kode_obat` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `no_batch` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tanggal_kedaluwarsa` date NOT NULL,
  `jumlah_batch` int NOT NULL
) ;

--
-- Dumping data for table `batch_stok`
--

INSERT INTO `batch_stok` (`id_batch`, `kode_cabang`, `kode_obat`, `no_batch`, `tanggal_kedaluwarsa`, `jumlah_batch`) VALUES
(1, 'CBG-JKT', 'OBT-001', 'BATCH-PCT-2026A', '2026-12-15', 20),
(2, 'CBG-JKT', 'OBT-001', 'BATCH-PCT-2027B', '2027-08-20', 30),
(3, 'CBG-JKT', 'OBT-002', 'BATCH-VTC-2027A', '2027-03-10', 30),
(4, 'CBG-JKT', 'OBT-003', 'BATCH-OBH-2026A', '2026-11-25', 8),
(5, 'CBG-JKT', 'OBT-006', 'BATCH-AMX-2026A', '2026-11-30', 10),
(6, 'CBG-JKT', 'OBT-006', 'BATCH-AMX-2027A', '2027-06-10', 15);

-- --------------------------------------------------------

--
-- Table structure for table `cabang`
--

CREATE TABLE `cabang` (
  `kode_cabang` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nama_cabang` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `alamat` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `telepon` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `cabang`
--

INSERT INTO `cabang` (`kode_cabang`, `nama_cabang`, `alamat`, `telepon`) VALUES
('CBG-BGR', 'Apotek Sehat Bogor', 'Jl. Pajajaran No. 22, Bogor', '0251-5550102'),
('CBG-BKS', 'Apotek Sehat Bekasi', 'Jl. Ahmad Yani No. 88, Bekasi', '021-5550105'),
('CBG-DPK', 'Apotek Sehat Depok', 'Jl. Margonda Raya No. 45, Depok', '021-5550103'),
('CBG-JKT', 'Apotek Sehat Jakarta Pusat', 'Jl. Sudirman No. 10, Jakarta', '021-5550101'),
('CBG-TGR', 'Apotek Sehat Tangerang', 'Jl. Daan Mogot No. 12, Tangerang', '021-5550104');

-- --------------------------------------------------------

--
-- Table structure for table `detail_penjualan`
--

CREATE TABLE `detail_penjualan` (
  `id_detail` int NOT NULL,
  `no_faktur` varchar(25) COLLATE utf8mb4_unicode_ci NOT NULL,
  `kode_obat` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `jumlah` int NOT NULL,
  `harga` decimal(12,2) NOT NULL,
  `subtotal` decimal(14,2) NOT NULL
) ;

--
-- Dumping data for table `detail_penjualan`
--

INSERT INTO `detail_penjualan` (`id_detail`, `no_faktur`, `kode_obat`, `jumlah`, `harga`, `subtotal`) VALUES
(1, 'INV-20260901-001', 'OBT-001', 5, 8000.00, 40000.00),
(2, 'INV-20260905-002', 'OBT-006', 2, 15000.00, 30000.00),
(3, 'INV-20260910-003', 'OBT-001', 10, 8000.00, 80000.00),
(4, 'INV-20260915-004', 'OBT-002', 2, 25000.00, 50000.00),
(5, 'INV-20260920-005', 'OBT-003', 2, 18000.00, 36000.00);

--
-- Triggers `detail_penjualan`
--
DELIMITER $$
CREATE TRIGGER `trg_kurangi_stok_setelah_jual` AFTER INSERT ON `detail_penjualan` FOR EACH ROW BEGIN
    DECLARE v_cabang VARCHAR(10);
    SELECT kode_cabang INTO v_cabang FROM penjualan WHERE no_faktur = NEW.no_faktur;
    UPDATE stok SET jumlah_stok = jumlah_stok - NEW.jumlah 
    WHERE kode_cabang = v_cabang AND kode_obat = NEW.kode_obat;
    INSERT INTO log_aktivitas (keterangan) 
    VALUES (CONCAT('[STAGING FEFO] Faktur ', NEW.no_faktur, ' | Stok ', NEW.kode_obat, ' dipotong FEFO'));
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `kategori_obat`
--

CREATE TABLE `kategori_obat` (
  `kode_kategori` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nama_kategori` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `wajib_apoteker` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 = hanya boleh dijual Apoteker'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `kategori_obat`
--

INSERT INTO `kategori_obat` (`kode_kategori`, `nama_kategori`, `wajib_apoteker`) VALUES
('KAT-01', 'Obat Bebas', 0),
('KAT-02', 'Obat Bebas Terbatas', 0),
('KAT-03', 'Obat Keras', 1),
('KAT-04', 'Alat Kesehatan', 0);

-- --------------------------------------------------------

--
-- Table structure for table `log_aktivitas`
--

CREATE TABLE `log_aktivitas` (
  `id_log` int NOT NULL,
  `waktu` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `keterangan` text COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `log_aktivitas`
--

INSERT INTO `log_aktivitas` (`id_log`, `waktu`, `keterangan`) VALUES
(1, '2026-11-14 01:36:28', 'Inisialisasi sistem basis data 3NF selesai.');

-- --------------------------------------------------------

--
-- Table structure for table `obat`
--

CREATE TABLE `obat` (
  `kode_obat` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `kode_kategori` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nama_obat` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `satuan` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `harga_jual` decimal(12,2) NOT NULL,
  `stok_minimum` int NOT NULL DEFAULT '10'
) ;

--
-- Dumping data for table `obat`
--

INSERT INTO `obat` (`kode_obat`, `kode_kategori`, `nama_obat`, `satuan`, `harga_jual`, `stok_minimum`) VALUES
('OBT-001', 'KAT-01', 'Paracetamol 500mg', 'Strip', 8000.00, 15),
('OBT-002', 'KAT-01', 'Vitamin C 500mg', 'Botol', 25000.00, 10),
('OBT-003', 'KAT-02', 'OBH Combi Batuk Flu', 'Botol', 18000.00, 10),
('OBT-004', 'KAT-02', 'CTM 4mg Strip', 'Strip', 15000.00, 12),
('OBT-005', 'KAT-01', 'Antasida Doen Tablet', 'Strip', 6000.00, 15),
('OBT-006', 'KAT-03', 'Amoxicillin 500mg', 'Strip', 15000.00, 12),
('OBT-007', 'KAT-03', 'Amlodipine 5mg', 'Strip', 22000.00, 10),
('OBT-008', 'KAT-01', 'Betadine Solution 30ml', 'Botol', 28000.00, 10),
('OBT-009', 'KAT-04', 'Masker Medis 3-Ply (Box)', 'Box', 35000.00, 10),
('OBT-010', 'KAT-04', 'Termometer Digital', 'Unit', 45000.00, 5);

-- --------------------------------------------------------

--
-- Table structure for table `pengguna`
--

CREATE TABLE `pengguna` (
  `id_pengguna` int NOT NULL,
  `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nama_lengkap` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `peran` enum('Admin Pusat','Apoteker','Kasir') COLLATE utf8mb4_unicode_ci NOT NULL,
  `kode_cabang` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `pengguna`
--

INSERT INTO `pengguna` (`id_pengguna`, `username`, `password_hash`, `nama_lengkap`, `peran`, `kode_cabang`) VALUES
(1, 'admin_pusat', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Budi Santoso', 'Admin Pusat', NULL),
(2, 'apoteker_jkt', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Apt. Rina Wijaya', 'Apoteker', 'CBG-JKT'),
(3, 'kasir_jakarta', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Siti Aminah', 'Kasir', 'CBG-JKT');

-- --------------------------------------------------------

--
-- Table structure for table `penjualan`
--

CREATE TABLE `penjualan` (
  `no_faktur` varchar(25) COLLATE utf8mb4_unicode_ci NOT NULL,
  `kode_cabang` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `id_pengguna` int NOT NULL,
  `tanggal` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `total` decimal(14,2) NOT NULL DEFAULT '0.00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `penjualan`
--

INSERT INTO `penjualan` (`no_faktur`, `kode_cabang`, `id_pengguna`, `tanggal`, `total`) VALUES
('INV-20260901-001', 'CBG-JKT', 3, '2026-09-01 09:15:00', 40000.00),
('INV-20260905-002', 'CBG-JKT', 2, '2026-09-05 14:20:00', 30000.00),
('INV-20260910-003', 'CBG-BGR', 1, '2026-09-10 17:45:00', 80000.00),
('INV-20260915-004', 'CBG-DPK', 1, '2026-09-15 18:30:00', 50000.00),
('INV-20260920-005', 'CBG-JKT', 3, '2026-09-20 19:10:00', 36000.00);

-- --------------------------------------------------------

--
-- Table structure for table `stok`
--

CREATE TABLE `stok` (
  `id_stok` int NOT NULL,
  `kode_cabang` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `kode_obat` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `jumlah_stok` int NOT NULL DEFAULT '0'
) ;

--
-- Dumping data for table `stok`
--

INSERT INTO `stok` (`id_stok`, `kode_cabang`, `kode_obat`, `jumlah_stok`) VALUES
(1, 'CBG-JKT', 'OBT-001', 50),
(2, 'CBG-JKT', 'OBT-002', 30),
(3, 'CBG-JKT', 'OBT-003', 8),
(4, 'CBG-JKT', 'OBT-004', 30),
(5, 'CBG-JKT', 'OBT-005', 45),
(6, 'CBG-JKT', 'OBT-006', 25),
(7, 'CBG-JKT', 'OBT-007', 18),
(8, 'CBG-JKT', 'OBT-008', 22),
(9, 'CBG-JKT', 'OBT-009', 40),
(10, 'CBG-JKT', 'OBT-010', 12),
(11, 'CBG-BGR', 'OBT-001', 40),
(12, 'CBG-BGR', 'OBT-002', 25),
(13, 'CBG-BGR', 'OBT-006', 20),
(14, 'CBG-BGR', 'OBT-009', 30),
(15, 'CBG-DPK', 'OBT-001', 35),
(16, 'CBG-DPK', 'OBT-002', 15),
(17, 'CBG-DPK', 'OBT-005', 20),
(18, 'CBG-TGR', 'OBT-001', 35),
(19, 'CBG-BKS', 'OBT-001', 50);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_stok_kritis`
-- (See below for the actual view)
--
CREATE TABLE `v_stok_kritis` (
`jumlah_stok` int
,`kode_cabang` varchar(10)
,`kode_obat` varchar(10)
,`nama_cabang` varchar(100)
,`nama_obat` varchar(100)
,`stok_minimum` int
);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `batch_stok`
--
ALTER TABLE `batch_stok`
  ADD PRIMARY KEY (`id_batch`),
  ADD KEY `idx_batch_fefo` (`kode_cabang`,`kode_obat`,`tanggal_kedaluwarsa`,`id_batch`),
  ADD KEY `fk_batch_obat` (`kode_obat`);

--
-- Indexes for table `cabang`
--
ALTER TABLE `cabang`
  ADD PRIMARY KEY (`kode_cabang`);

--
-- Indexes for table `detail_penjualan`
--
ALTER TABLE `detail_penjualan`
  ADD PRIMARY KEY (`id_detail`),
  ADD KEY `fk_detail_faktur` (`no_faktur`),
  ADD KEY `fk_detail_obat` (`kode_obat`);

--
-- Indexes for table `kategori_obat`
--
ALTER TABLE `kategori_obat`
  ADD PRIMARY KEY (`kode_kategori`);

--
-- Indexes for table `log_aktivitas`
--
ALTER TABLE `log_aktivitas`
  ADD PRIMARY KEY (`id_log`);

--
-- Indexes for table `obat`
--
ALTER TABLE `obat`
  ADD PRIMARY KEY (`kode_obat`),
  ADD KEY `fk_obat_kategori` (`kode_kategori`),
  ADD KEY `idx_obat_nama` (`nama_obat`);

--
-- Indexes for table `pengguna`
--
ALTER TABLE `pengguna`
  ADD PRIMARY KEY (`id_pengguna`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `fk_pengguna_cabang` (`kode_cabang`);

--
-- Indexes for table `penjualan`
--
ALTER TABLE `penjualan`
  ADD PRIMARY KEY (`no_faktur`),
  ADD KEY `fk_penjualan_cabang` (`kode_cabang`),
  ADD KEY `fk_penjualan_pengguna` (`id_pengguna`),
  ADD KEY `idx_penjualan_tanggal` (`tanggal`);

--
-- Indexes for table `stok`
--
ALTER TABLE `stok`
  ADD PRIMARY KEY (`id_stok`),
  ADD UNIQUE KEY `uk_cabang_obat` (`kode_cabang`,`kode_obat`),
  ADD KEY `fk_stok_obat` (`kode_obat`),
  ADD KEY `idx_stok_cabang_obat` (`kode_cabang`,`kode_obat`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `batch_stok`
--
ALTER TABLE `batch_stok`
  MODIFY `id_batch` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `detail_penjualan`
--
ALTER TABLE `detail_penjualan`
  MODIFY `id_detail` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `log_aktivitas`
--
ALTER TABLE `log_aktivitas`
  MODIFY `id_log` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `pengguna`
--
ALTER TABLE `pengguna`
  MODIFY `id_pengguna` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `stok`
--
ALTER TABLE `stok`
  MODIFY `id_stok` int NOT NULL AUTO_INCREMENT;

-- --------------------------------------------------------

--
-- Structure for view `v_stok_kritis`
--
DROP TABLE IF EXISTS `v_stok_kritis`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_stok_kritis`  AS SELECT `s`.`kode_cabang` AS `kode_cabang`, `c`.`nama_cabang` AS `nama_cabang`, `o`.`kode_obat` AS `kode_obat`, `o`.`nama_obat` AS `nama_obat`, `s`.`jumlah_stok` AS `jumlah_stok`, `o`.`stok_minimum` AS `stok_minimum` FROM ((`stok` `s` join `cabang` `c` on((`s`.`kode_cabang` = `c`.`kode_cabang`))) join `obat` `o` on((`s`.`kode_obat` = `o`.`kode_obat`))) WHERE (`s`.`jumlah_stok` <= `o`.`stok_minimum`) ;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `batch_stok`
--
ALTER TABLE `batch_stok`
  ADD CONSTRAINT `fk_batch_cabang` FOREIGN KEY (`kode_cabang`) REFERENCES `cabang` (`kode_cabang`),
  ADD CONSTRAINT `fk_batch_obat` FOREIGN KEY (`kode_obat`) REFERENCES `obat` (`kode_obat`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
