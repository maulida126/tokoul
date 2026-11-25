ALTER TABLE tbl_transaksi
  ADD COLUMN IF NOT EXISTS metode_pembayaran ENUM('tunai', 'transfer', 'qris') NOT NULL DEFAULT 'tunai';
