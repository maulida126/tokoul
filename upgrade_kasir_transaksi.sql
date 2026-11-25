ALTER TABLE tbl_transaksi
    ADD COLUMN id_user INT NULL AFTER id_pelanggan;

ALTER TABLE tbl_transaksi
    ADD CONSTRAINT fk_transaksi_kasir
    FOREIGN KEY (id_user) REFERENCES tbl_user(id_user)
    ON DELETE SET NULL;
