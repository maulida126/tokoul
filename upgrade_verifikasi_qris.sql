ALTER TABLE tbl_transaksi
    ADD COLUMN IF NOT EXISTS status_pembayaran
        ENUM('menunggu_verifikasi', 'terverifikasi') NOT NULL DEFAULT 'terverifikasi'
        AFTER metode_pembayaran,
    ADD COLUMN IF NOT EXISTS diverifikasi_oleh INT NULL AFTER status_pembayaran,
    ADD COLUMN IF NOT EXISTS diverifikasi_pada DATETIME NULL AFTER diverifikasi_oleh;
