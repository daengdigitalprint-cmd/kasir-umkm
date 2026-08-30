-- ============================================================
-- 1. Pilih database (ganti jika nama DB berbeda)
-- ============================================================
-- Ganti 'kasirumkm' dengan nama database yang benar
USE `kasirumkm`;

-- ============================================================
-- 2. Tabel operational_expenses (Biaya Operasional)
-- ============================================================
CREATE TABLE IF NOT EXISTS `operational_expenses` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `store_id` bigint unsigned NOT NULL,
    `user_id` bigint unsigned NOT NULL,
    `category` varchar(50) NOT NULL,
    `description` varchar(255) NOT NULL,
    `amount` decimal(15,2) NOT NULL,
    `expense_date` date NOT NULL,
    `payment_method` varchar(20) NOT NULL DEFAULT 'cash',
    `notes` text NULL,
    `journal_entry_id` bigint unsigned NULL,
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_store_date` (`store_id`, `expense_date`),
    KEY `idx_store_category` (`store_id`, `category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 3. Tambah kolom payment ke tabel purchases
--    (skip baris yang sudah ada — cek dulu dengan DESC purchases)
-- ============================================================
-- Cek dulu: DESC purchases;
-- Kalau kolom is_paid belum ada, jalankan semua di bawah ini:

-- 3a. is_paid (default true = lunas)
ALTER TABLE `purchases`
    ADD COLUMN `is_paid` tinyint(1) NOT NULL DEFAULT 1 AFTER `notes`;

-- 3b. payment_method (cash / transfer / ewallet)
ALTER TABLE `purchases`
    ADD COLUMN `payment_method` varchar(20) NULL DEFAULT NULL AFTER `is_paid`;

-- 3c. amount_paid (sudah dibayar berapa)
ALTER TABLE `purchases`
    ADD COLUMN `amount_paid` decimal(15,2) NOT NULL DEFAULT 0 AFTER `payment_method`;

-- 3d. due_date (jatuh tempo, nullable)
ALTER TABLE `purchases`
    ADD COLUMN `due_date` date NULL DEFAULT NULL AFTER `amount_paid`;
