<?php
$host = "127.0.0.1";
$user = "root";
$pass = "";
$db   = "db_tokoku";
$port = 3306;
$tarifPajak = 11.0;

try {
    $conn = new mysqli($host, $user, $pass, $db);
    $conn->set_charset("utf8mb4");
} catch (mysqli_sql_exception $e) {
    error_log("TokoKu database connection failed: " . $e->getMessage());
    http_response_code(503);
    exit(
        '<!DOCTYPE html><html lang="id"><meta charset="UTF-8">' .
        '<meta name="viewport" content="width=device-width, initial-scale=1.0">' .
        '<title>Database tidak tersedia</title>' .
        '<body style="font-family: sans-serif; max-width: 640px; margin: 3rem auto; padding: 0 1rem;">' .
        '<h1>Database belum tersedia</h1>' .
        '<p>TokoKu tidak dapat terhubung ke MySQL di ' . htmlspecialchars($host, ENT_QUOTES, 'UTF-8') .
        ':3306. Buka XAMPP Control Panel, lalu klik <strong>Start</strong> pada MySQL.</p>' .
        '<p>Jika MySQL sudah berjalan, pastikan port MySQL di <code>config.php</code> sama dengan port pada XAMPP.</p>' .
        '</body></html>'
    );
}

$kolomKasirTersedia = false;
$kolomStatusPembayaranTersedia = false;
try {
    $kolomKasir = $conn->query("SHOW COLUMNS FROM tbl_transaksi LIKE 'id_user'");
    $kolomKasirTersedia = $kolomKasir && $kolomKasir->num_rows > 0;
    if (!$kolomKasirTersedia) {
        $conn->query("ALTER TABLE tbl_transaksi ADD COLUMN id_user INT NULL AFTER id_pelanggan");
        $kolomKasirTersedia = $conn->query("SHOW COLUMNS FROM tbl_transaksi LIKE 'id_user'");
        $kolomKasirTersedia = $kolomKasirTersedia && $kolomKasirTersedia->num_rows > 0;
    }
    if ($kolomKasirTersedia) {
        $constraint = $conn->query(
            "SELECT CONSTRAINT_NAME
             FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = 'tbl_transaksi'
               AND CONSTRAINT_TYPE = 'FOREIGN KEY'
               AND CONSTRAINT_NAME = 'fk_transaksi_kasir'"
        );
        if ($constraint && $constraint->num_rows === 0) {
            $conn->query(
                "ALTER TABLE tbl_transaksi
                 ADD CONSTRAINT fk_transaksi_kasir
                 FOREIGN KEY (id_user) REFERENCES tbl_user(id_user)
                 ON DELETE SET NULL"
            );
        }
    }

    $kolomStatus = $conn->query("SHOW COLUMNS FROM tbl_transaksi LIKE 'status_pembayaran'");
    if (!$kolomStatus || $kolomStatus->num_rows === 0) {
        $conn->query(
            "ALTER TABLE tbl_transaksi
             ADD COLUMN status_pembayaran ENUM('menunggu_verifikasi', 'terverifikasi')
             NOT NULL DEFAULT 'terverifikasi' AFTER metode_pembayaran"
        );
    }
    $kolomVerifikator = $conn->query("SHOW COLUMNS FROM tbl_transaksi LIKE 'diverifikasi_oleh'");
    if (!$kolomVerifikator || $kolomVerifikator->num_rows === 0) {
        $conn->query("ALTER TABLE tbl_transaksi ADD COLUMN diverifikasi_oleh INT NULL AFTER status_pembayaran");
    }
    $kolomWaktuVerifikasi = $conn->query("SHOW COLUMNS FROM tbl_transaksi LIKE 'diverifikasi_pada'");
    if (!$kolomWaktuVerifikasi || $kolomWaktuVerifikasi->num_rows === 0) {
        $conn->query("ALTER TABLE tbl_transaksi ADD COLUMN diverifikasi_pada DATETIME NULL AFTER diverifikasi_oleh");
    }
    $kolomStatusPembayaranTersedia =
        $conn->query("SHOW COLUMNS FROM tbl_transaksi LIKE 'status_pembayaran'")->num_rows > 0
        && $conn->query("SHOW COLUMNS FROM tbl_transaksi LIKE 'diverifikasi_oleh'")->num_rows > 0
        && $conn->query("SHOW COLUMNS FROM tbl_transaksi LIKE 'diverifikasi_pada'")->num_rows > 0;
} catch (mysqli_sql_exception $e) {
    error_log("TokoKu transaction schema setup failed: " . $e->getMessage());
}

function rupiah($n) {
    return "Rp " . number_format($n, 0, ',', '.');
}

/**
 * Bersihkan semua result set dari stored procedure
 * supaya tidak kena error "Commands out of sync"
 */
function clearResults($conn) {
    while ($conn->more_results() && $conn->next_result()) {;}
}

// Session & auto-protect
require_once __DIR__ . '/auth.php';

$halamanBebas = ['login.php', 'logout.php', 'daftar.php'];
$halamanIni   = basename($_SERVER['PHP_SELF']);
if (!in_array($halamanIni, $halamanBebas)) {
    cekLogin();

    $role = $_SESSION['role'] ?? '';
    if ($role === '' || $role === 'user') {
        $role = 'kasir';
        $_SESSION['role'] = $role;
    }

    $aksesRole = [
        'admin' => null,
        'kasir' => ['pelanggan.php', 'transaksi.php', 'detail_transaksi.php', 'produk.php', 'stok_produk.php', 'laporan.php', 'laporan_pdf.php', 'verifikasi_qris.php'],
        'pelanggan' => ['belanja.php', 'detail_transaksi.php', 'stok_produk.php'],
    ];

    if (!array_key_exists($role, $aksesRole)) {
        http_response_code(403);
        exit('Role akun tidak valid. Hubungi admin.');
    }

    if ($aksesRole[$role] !== null && !in_array($halamanIni, $aksesRole[$role], true)) {
        header("Location: " . halamanBerandaRole() . "?error=akses");
        exit;
    }

    if ($role === 'pelanggan' && empty($_SESSION['id_pelanggan'])) {
        http_response_code(403);
        exit('Akun pelanggan belum terhubung ke data pelanggan. Hubungi admin.');
    }

    $halamanPembayaran = [
        'belanja.php', 'transaksi.php', 'detail_transaksi.php',
        'index.php', 'pelanggan.php', 'laporan.php', 'laporan_pdf.php', 'verifikasi_qris.php',
    ];
    if (in_array($halamanIni, $halamanPembayaran, true) && !$kolomStatusPembayaranTersedia) {
        http_response_code(503);
        exit('Kolom verifikasi pembayaran belum tersedia. Jalankan upgrade_verifikasi_qris.sql.');
    }
}
?>