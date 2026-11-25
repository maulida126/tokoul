# TokoKu

TokoKu adalah aplikasi manajemen toko berbasis PHP dan MySQL/MariaDB. Aplikasi menyediakan dashboard admin, katalog belanja pelanggan, pengelolaan produk dan stok, pembayaran kasir, verifikasi QRIS, serta laporan transaksi per kasir.

## Fitur

- Tiga role akun: admin, kasir, dan pelanggan.
- Dashboard admin menampilkan ringkasan penjualan terverifikasi, transaksi terbaru, pelanggan, dan produk terlaris.
- Admin mengelola akun pengguna, pelanggan, produk, foto produk, stok, dan gambar QRIS.
- Kasir memproses transaksi tunai, transfer, dan QRIS dengan keranjang multi-produk, serta dapat menambah pelanggan dan stok produk.
- Pemindaian barcode produk menggunakan scanner USB tersedia di halaman pembayaran kasir.
- Pelanggan dapat melihat katalog, berbelanja, dan memantau status serta rincian pesanan miliknya.
- Stok diperiksa ulang di server dan dikurangi saat pesanan dicatat. Informasi stok pada katalog dan halaman inventaris disinkronkan secara berkala.
- Transaksi baru menghitung pajak menggunakan tarif pada `$tarifPajak` di `config.php` (default 11%).
- Laporan admin dapat difilter per kasir. Kasir melihat laporan transaksi miliknya sendiri; transaksi yang belum terverifikasi tidak masuk ke total penjualan.
- PDF laporan dapat dipratinjau di browser sebelum diunduh. Detail transaksi dapat dicetak sebagai struk thermal 80 mm.

## Teknologi dan Persyaratan

- PHP 8.x dengan ekstensi `mysqli` dan `fileinfo`.
- MySQL atau MariaDB.
- Apache, misalnya melalui XAMPP atau Laragon.
- Browser modern.
- Koneksi internet untuk Bootstrap dan Bootstrap Icons yang dimuat dari CDN.

## Instalasi di XAMPP

1. Salin proyek ke folder `htdocs`, misalnya `C:\xampp\htdocs\tokoku-main`. Sesuaikan alamat folder dan URL jika lokasi XAMPP berbeda.
2. Jalankan **Apache** dan **MySQL** dari XAMPP Control Panel.
3. Buka `http://localhost/phpmyadmin`, lalu buat database `db_tokoku`.
4. Pilih database `db_tokoku`, buka tab **Import**, dan impor `db_tokoku (2).sql`. Impor dump ini satu kali untuk instalasi baru karena berisi data contoh.
5. Jalankan `upgrade_user.sql` untuk memastikan tabel pengguna dan akun admin awal tersedia. Kredensial bawaan: username `admin`, password `admin123`. Ganti password bawaan setelah login.
6. Jalankan `upgrade_pelanggan.sql` satu kali untuk menyiapkan relasi akun pelanggan, tabel produk, dan detail transaksi yang dibutuhkan aplikasi.
7. Jalankan `upgrade_barcode.sql` setelah `upgrade_pelanggan.sql` agar barcode produk dan barcode pada detail transaksi tersedia.
8. Untuk database lama, jalankan hanya migrasi yang kolom atau tabelnya belum tersedia:
   - `upgrade_metode_pembayaran.sql` untuk kolom metode pembayaran.
   - `upgrade_pajak.sql` untuk kolom pajak transaksi.
   - `upgrade_stok.sql` untuk kolom stok produk.
   - `upgrade_gambar_produk.sql` untuk kolom foto produk.
   - `upgrade_kasir_transaksi.sql` untuk mencatat kasir pada transaksi.
   - `upgrade_verifikasi_qris.sql` untuk status dan waktu verifikasi pembayaran QRIS.

   `config.php` mencoba menambahkan kolom kasir dan verifikasi secara otomatis. Jika database tidak mengizinkan perubahan skema otomatis, impor migrasi yang sesuai melalui phpMyAdmin. Jangan menjalankan migrasi yang menambahkan kolom atau constraint yang sudah ada. Jangan impor ulang dump untuk memperbaiki database yang sudah berisi transaksi karena data dapat tertimpa.
9. Buka `http://localhost/tokoku-main/login.php`, lalu masuk sebagai admin.

## Konfigurasi

Pengaturan koneksi database dan tarif pajak berada di `config.php`:

```php
$host = "127.0.0.1";
$user = "root";
$pass = "";
$db   = "db_tokoku";
$port = 3306;
$tarifPajak = 11.0;
```

Sesuaikan pengaturan tersebut dengan konfigurasi MySQL lokal. Akun admin dapat membuat akun admin, kasir, dan pelanggan melalui menu **Pengguna**. Password akun baru minimal 8 karakter.

## Role dan Hak Akses

| Role | Hak akses utama |
|---|---|
| Admin | Dashboard, pengguna, pelanggan, produk dan stok, transaksi, verifikasi QRIS, serta laporan yang dapat difilter per kasir. |
| Kasir | Pembayaran, tambah pelanggan, tambah stok produk, antrean verifikasi QRIS, dan laporan transaksi milik akun kasir tersebut. Kasir tidak dapat membuat produk baru atau mengganti foto produk. |
| Pelanggan | Katalog dan checkout, riwayat pesanan, status pembayaran, serta detail transaksi miliknya sendiri. |

Pelanggan dapat mendaftar dari halaman **Daftar Pelanggan**. Pendaftaran membuat akun login yang terhubung dengan data pelanggan.

## Produk, Stok, Foto, dan Barcode

- Admin dapat menambah produk dengan nama, harga, stok awal, dan foto opsional; admin juga dapat mengganti foto serta mencari produk berdasarkan nama atau ID.
- Kasir dapat menambah stok produk yang sudah terdaftar.
- Foto produk berformat JPG, PNG, atau WEBP, maksimal 5 MB. File disimpan di `assets/product-images/`; pastikan Apache memiliki izin tulis ke folder tersebut.
- Jika stok produk pada database lama belum benar, perbarui dari menu **Produk & Stok** sebelum menjual barang.
- Barcode menggunakan Code 128 dengan awalan `TK`. Admin dapat mencetak label barcode, lalu kasir memindai kode dengan scanner USB yang mengirim tombol Enter setelah membaca kode.
- Pembelian memeriksa ketersediaan stok di server sebelum menyimpan transaksi dan mengurangi stok.

## Pembayaran dan Verifikasi QRIS

Gambar QRIS statis toko berada di `assets/qris-tokoku.png`. Admin dapat menggantinya dari halaman **Transaksi** dengan file PNG maksimal 5 MB. Folder `assets/` harus dapat ditulis oleh Apache.

Alur pembayaran QRIS pelanggan:

1. Pelanggan memilih QRIS, memindai kode, lalu mengirim pesanan.
2. Pesanan tercatat dengan status **Menunggu verifikasi** dan dapat dilihat pelanggan pada riwayat pesanan.
3. Kasir membuka menu **Verifikasi QRIS** pada sidebar, memeriksa dana masuk melalui aplikasi atau mutasi merchant, lalu memilih **Verifikasi**.
4. Setelah dikonfirmasi, status berubah menjadi **Terverifikasi** dan transaksi masuk ke laporan kasir yang memverifikasinya.

Untuk transaksi QRIS yang dicatat langsung oleh kasir, kasir harus memastikan dana masuk dan mencentang konfirmasi sebelum menyimpan transaksi. Pembayaran non-QRIS yang dicatat kasir langsung berstatus terverifikasi.

Verifikasi QRIS dilakukan manual oleh kasir; aplikasi tidak terhubung ke API bank atau penyedia pembayaran dan tidak memverifikasi dana secara otomatis. Jangan menekan **Verifikasi** sebelum pembayaran terlihat masuk.

## Laporan dan Cetak

- Admin membuka **Laporan**, memilih kasir, lalu melihat rincian transaksi kasir tersebut.
- Kasir membuka **Laporan Saya** untuk melihat transaksi yang dibuat atau diverifikasi melalui akunnya.
- Transaksi QRIS yang masih menunggu verifikasi tidak dihitung dalam total penjualan dan laporan.
- Gunakan **Pratinjau PDF** untuk memeriksa laporan di browser sebelum mengunduhnya. Ukuran kertas dan orientasi dapat dipilih pada filter laporan.
- Detail transaksi dapat dicetak sebagai struk 80 mm. Pada pengaturan cetak browser, pilih kertas thermal **80 mm**, skala **100%**, margin **None/Tidak ada**, dan matikan **Headers and footers**.

## Troubleshooting

- **Database belum tersedia:** pastikan MySQL aktif dan koneksi di `config.php` sesuai.
- **Tabel `tbl_user` tidak ditemukan:** impor `upgrade_user.sql` ke database `db_tokoku`.
- **Tabel produk atau detail transaksi belum tersedia:** jalankan `upgrade_pelanggan.sql` satu kali.
- **Kolom metode, pajak, stok, gambar, barcode, kasir, atau status pembayaran tidak ditemukan:** impor migrasi terkait yang belum diterapkan. Untuk error kolom kasir atau verifikasi, lihat `upgrade_kasir_transaksi.sql` dan `upgrade_verifikasi_qris.sql`.
- **Foto produk tidak tersimpan:** pastikan ekstensi `fileinfo` aktif, format dan ukuran file sesuai, dan Apache dapat menulis ke `assets/product-images/`.
- **Gambar QRIS tidak dapat diganti atau tidak tampil:** pastikan file PNG maksimal 5 MB dan Apache dapat mengakses folder `assets/`.
- **Transaksi QRIS belum terverifikasi:** kasir harus memastikan dana masuk dan mengonfirmasi pesanan dari menu **Verifikasi QRIS**.
- **Struk tidak pas pada kertas:** periksa ukuran kertas, skala, margin, serta pengaturan driver printer.
