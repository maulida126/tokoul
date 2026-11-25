ALTER TABLE tbl_produk
  ADD COLUMN IF NOT EXISTS barcode VARCHAR(32) DEFAULT NULL;

UPDATE tbl_produk
SET barcode = CONCAT('TK', LPAD(id_produk, 8, '0'))
WHERE barcode IS NULL OR barcode = '';

ALTER TABLE tbl_produk
  ADD UNIQUE KEY IF NOT EXISTS uq_tbl_produk_barcode (barcode);

ALTER TABLE tbl_detail_transaksi
  ADD COLUMN IF NOT EXISTS barcode VARCHAR(32) DEFAULT NULL AFTER nama_produk;

UPDATE tbl_detail_transaksi d
INNER JOIN tbl_produk p ON p.nama_produk = d.nama_produk
SET d.barcode = p.barcode
WHERE d.barcode IS NULL;
