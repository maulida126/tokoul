<?php
require 'config.php';
require_once 'inventory.php';
$filter = trim((string)($_GET['pelanggan'] ?? ''));
$pesan = '';
$isAdmin = $_SESSION['role'] === 'admin';
$qrisPath = __DIR__ . '/assets/qris-tokoku.png';
$qrisTersedia = is_file($qrisPath);
if ($filter !== '' && !ctype_digit($filter)) {
    $filter = '';
}

function simpanGambarQris(array $file, string &$error): bool
{
    $uploadError = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($uploadError === UPLOAD_ERR_NO_FILE) {
        $error = 'Pilih file gambar QRIS terlebih dahulu.';
        return false;
    }
    if ($uploadError !== UPLOAD_ERR_OK) {
        $error = 'Gambar QRIS gagal diunggah. Coba pilih file kembali.';
        return false;
    }

    $temporaryPath = $file['tmp_name'] ?? '';
    $fileSize = $file['size'] ?? null;
    if ((!is_int($fileSize) && !(is_string($fileSize) && ctype_digit($fileSize)))
        || (int)$fileSize > 5 * 1024 * 1024) {
        $error = 'Ukuran gambar maksimal 5 MB.';
        return false;
    }
    if (!is_string($temporaryPath) || !is_uploaded_file($temporaryPath)) {
        $error = 'File gambar QRIS tidak valid.';
        return false;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    if ($finfo->file($temporaryPath) !== 'image/png') {
        $error = 'Format gambar QRIS harus PNG.';
        return false;
    }
    $imageInfo = getimagesize($temporaryPath);
    if ($imageInfo === false || ($imageInfo['mime'] ?? '') !== 'image/png') {
        $error = 'File yang dipilih bukan gambar PNG yang valid.';
        return false;
    }

    if (!move_uploaded_file($temporaryPath, __DIR__ . '/assets/qris-tokoku.png')) {
        $error = 'Gambar QRIS tidak dapat disimpan. Periksa izin tulis folder assets.';
        return false;
    }

    return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'unggah_qris') {
    if (!validCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        $pesan = 'Form sudah kedaluwarsa. Muat ulang halaman lalu coba kembali.';
    } elseif (!$isAdmin) {
        http_response_code(403);
        $pesan = 'Hanya admin yang dapat mengganti gambar QRIS.';
    } else {
        $errorUnggah = '';
        $fileQris = $_FILES['gambar_qris'] ?? [];
        if (is_array($fileQris) && simpanGambarQris($fileQris, $errorUnggah)) {
            header('Location: transaksi.php?qris_diperbarui=1');
            exit;
        }
        $pesan = $errorUnggah !== '' ? $errorUnggah : 'Pilih gambar QRIS yang valid.';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        $pesan = 'Form sudah kedaluwarsa. Muat ulang halaman lalu coba kembali.';
    } else {
        $idPelanggan = filter_var($_POST['id_pelanggan'] ?? null, FILTER_VALIDATE_INT);
        $metode = $_POST['metode_pembayaran'] ?? '';
        $konfirmasiQrisKasir = ($_POST['konfirmasi_qris_kasir'] ?? '') === '1';
        $metodeValid = ['tunai', 'transfer', 'qris'];
        $jumlahProduk = $_POST['jumlah'] ?? [];
        $keranjang = [];
        if (is_array($jumlahProduk)) {
            foreach ($jumlahProduk as $id => $jumlah) {
                $id = filter_var($id, FILTER_VALIDATE_INT);
                $jumlah = filter_var($jumlah, FILTER_VALIDATE_INT);
                if ($id && $jumlah !== false && $jumlah > 0 && $jumlah <= 2147483647) {
                    $keranjang[$id] = $jumlah;
                }
            }
        }

        if ($metode === 'qris' && !$qrisTersedia) {
            $pesan = 'Pembayaran QRIS belum dapat digunakan. Admin perlu memasang gambar QRIS resmi toko.';
        } elseif ($metode === 'qris' && !$konfirmasiQrisKasir) {
            $pesan = 'Pastikan pembayaran QRIS sudah masuk, lalu centang konfirmasi kasir.';
        } elseif (!$idPelanggan || !in_array($metode, $metodeValid, true) || !$keranjang) {
            $pesan = 'Pilih pelanggan, metode pembayaran, dan minimal satu barang.';
        } else {
            $stmt = $conn->prepare("SELECT id_pelanggan FROM tbl_pelanggan WHERE id_pelanggan = ?");
            $stmt->bind_param("i", $idPelanggan);
            $stmt->execute();
            $pelangganAda = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$pelangganAda) {
                $pesan = 'Pelanggan tidak ditemukan.';
            } else {
                try {
                    $conn->begin_transaction();
                    $produkDipilih = ambilDanKurangiStok($conn, $keranjang);
                    $subtotal = 0;
                    foreach ($produkDipilih as $produk) {
                        $subtotal += (float)$produk['harga'] * $produk['jumlah'];
                    }
                    $persentasePajak = (float)$tarifPajak;
                    $totalPajak = round($subtotal * $persentasePajak / 100);
                    $total = $subtotal + $totalPajak;

                    $idKasir = (int)($_SESSION['user_id'] ?? 0);
                    $statusPembayaran = 'terverifikasi';
                    $verifikator = $metode === 'qris' ? $idKasir : null;
                    $waktuVerifikasi = $metode === 'qris' ? date('Y-m-d H:i:s') : null;
                    $stmt = $conn->prepare("INSERT INTO tbl_transaksi (tanggal, id_pelanggan, id_user, total_belanja, metode_pembayaran, status_pembayaran, diverifikasi_oleh, diverifikasi_pada, persentase_pajak, total_pajak) VALUES (CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("iidssissdd", $idPelanggan, $idKasir, $total, $metode, $statusPembayaran, $verifikator, $waktuVerifikasi, $persentasePajak, $totalPajak);
                    $stmt->execute();
                    $idTransaksi = $conn->insert_id;
                    $stmt->close();

                    $stmt = $conn->prepare("INSERT INTO tbl_detail_transaksi (id_transaksi, nama_produk, barcode, jumlah, harga) VALUES (?, ?, ?, ?, ?)");
                    foreach ($produkDipilih as $produk) {
                        $namaProduk = $produk['nama_produk'];
                        $barcode = $produk['barcode'];
                        $jumlah = $produk['jumlah'];
                        $harga = $produk['harga'];
                        $stmt->bind_param("issid", $idTransaksi, $namaProduk, $barcode, $jumlah, $harga);
                        $stmt->execute();
                    }
                    $stmt->close();
                    $conn->commit();
                    header("Location: transaksi.php?dibuat=" . $idTransaksi);
                    exit;
                } catch (StokTidakCukupException $e) {
                    $conn->rollback();
                    $pesan = $e->getMessage();
                } catch (mysqli_sql_exception $e) {
                    $conn->rollback();
                    throw $e;
                }
            }
        }
    }
}

if (isset($_GET['dibuat']) && filter_var($_GET['dibuat'], FILTER_VALIDATE_INT)) {
    $pesan = 'Pembayaran berhasil dicatat untuk transaksi #' . (int)$_GET['dibuat'] . '.';
}
if (isset($_GET['qris_diperbarui']) && $_GET['qris_diperbarui'] === '1' && $isAdmin) {
    $pesan = 'Gambar QRIS berhasil diperbarui.';
}
$qrisTersedia = is_file($qrisPath);
$qrisUrl = $qrisTersedia
    ? 'assets/qris-tokoku.png?v=' . (string)filemtime($qrisPath) . '-' . substr(hash_file('sha256', $qrisPath), 0, 12)
    : '';

$sql = "SELECT t.id_transaksi, t.tanggal, t.total_belanja, t.metode_pembayaran, t.status_pembayaran,
               p.nama_pelanggan, p.kota
        FROM tbl_transaksi t
        INNER JOIN tbl_pelanggan p ON p.id_pelanggan = t.id_pelanggan";
if ($filter !== '' && ctype_digit($filter)) {
    $stmt = $conn->prepare($sql . " WHERE t.id_pelanggan = ? ORDER BY t.tanggal DESC, t.id_transaksi DESC");
    $idFilter = (int)$filter;
    $stmt->bind_param("i", $idFilter);
} else {
    $stmt = $conn->prepare($sql . " ORDER BY t.tanggal DESC, t.id_transaksi DESC");
}
$stmt->execute();
$data = $stmt->get_result();
$transactionCount = $data->num_rows;
$stmt->close();
$summarySql = "SELECT COALESCE(SUM(total_belanja), 0) AS total FROM tbl_transaksi WHERE status_pembayaran = 'terverifikasi'";
if ($filter !== '') {
    $summaryStmt = $conn->prepare($summarySql . " AND id_pelanggan = ?");
    $summaryStmt->bind_param("i", $idFilter);
} else {
    $summaryStmt = $conn->prepare($summarySql);
}
$summaryStmt->execute();
$totalOmzet = $summaryStmt->get_result()->fetch_assoc()['total'];
$summaryStmt->close();
$daftarPelanggan = $conn->query("SELECT id_pelanggan, nama_pelanggan, kota FROM tbl_pelanggan ORDER BY nama_pelanggan");
$daftarProduk = $conn->query("SELECT id_produk, nama_produk, harga, stok, gambar, barcode FROM tbl_produk WHERE aktif = 1 ORDER BY nama_produk");
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaksi - TokoKu</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css?v=<?= (int)filemtime(__DIR__ . '/style.css') ?>">
</head>
<body class="management-page transaction-page">
<?php include 'navbar.php'; ?>

<main class="management-container">
    <header class="management-heading">
        <div>
            <span class="management-eyebrow"><i class="bi bi-credit-card"></i> AKTIVITAS TOKO</span>
            <h1><?= $isAdmin ? 'Transaksi' : 'Pembayaran' ?></h1>
            <p><?= $isAdmin ? 'Pantau transaksi dan aktivitas pembayaran toko.' : 'Pilih pelanggan dan barang untuk mencatat pembayaran.' ?></p>
        </div>
        <span class="management-count"><i class="bi bi-receipt"></i> <?= number_format($transactionCount, 0, ',', '.') ?> transaksi</span>
    </header>

    <?php if ($filter !== ''): ?>
        <div class="active-filter"><i class="bi bi-funnel"></i> Filter pelanggan #<?= htmlspecialchars($filter) ?><a href="transaksi.php"><i class="bi bi-x-lg"></i> Hapus filter</a></div>
    <?php endif; ?>

    <?php if ($isAdmin): ?>
    <section class="transaction-stats">
        <div><span class="transaction-stat-icon"><i class="bi bi-receipt-cutoff"></i></span><span><small>Transaksi ditemukan</small><strong><?= number_format($transactionCount, 0, ',', '.') ?></strong></span></div>
        <div><span class="transaction-stat-icon is-green"><i class="bi bi-cash-stack"></i></span><span><small>Total penjualan</small><strong><?= rupiah($totalOmzet) ?></strong></span></div>
    </section>
    <?php endif; ?>

    <?php if ($pesan !== ''): ?>
        <div class="management-alert <?= isset($_GET['dibuat']) || isset($_GET['qris_diperbarui']) ? 'is-success' : '' ?>"><?= htmlspecialchars($pesan) ?></div>
    <?php endif; ?>

    <?php if ($isAdmin || $_SESSION['role'] === 'kasir'): ?>
    <section class="management-panel payment-panel">
        <div class="management-panel-heading"><span class="management-panel-icon is-orange"><i class="bi bi-credit-card"></i></span><div><h2>Proses pembayaran</h2><p>Pastikan pelanggan dan jumlah barang sudah benar.</p></div></div>
        <div class="payment-form-body">
            <?php if ($isAdmin): ?>
                <form method="post" enctype="multipart/form-data" class="qris-upload-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="aksi" value="unggah_qris">
                    <div>
                        <label for="gambarQris" class="form-label">Ganti gambar QRIS (PNG, maksimal 5 MB)</label>
                        <input id="gambarQris" type="file" name="gambar_qris" class="form-control" accept="image/png" required>
                    </div>
                    <button type="submit" class="management-outline-button"><i class="bi bi-upload"></i> Simpan QRIS</button>
                </form>
            <?php endif; ?>
            <?php if ($daftarPelanggan && $daftarPelanggan->num_rows > 0 && $daftarProduk && $daftarProduk->num_rows > 0): ?>
            <form method="post" class="checkout-management-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label for="id_pelanggan" class="form-label">Pilih pelanggan</label>
                        <select id="id_pelanggan" name="id_pelanggan" class="form-select" required>
                            <option value="">-- Pilih pelanggan --</option>
                            <?php while ($p = $daftarPelanggan->fetch_assoc()): ?>
                                <option value="<?= (int)$p['id_pelanggan'] ?>" <?= (string)$filter === (string)$p['id_pelanggan'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($p['nama_pelanggan'] . ' - ' . $p['kota']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label for="metode_pembayaran" class="form-label">Metode pembayaran</label>
                        <select id="metode_pembayaran" name="metode_pembayaran" class="form-select" required>
                            <option value="tunai">Tunai</option>
                            <option value="transfer">Transfer</option>
                            <option value="qris">QRIS</option>
                        </select>
                    </div>
                </div>
                <section class="qris-payment-panel" id="qrisPaymentPanel" hidden aria-live="polite">
                    <div class="qris-payment-copy">
                        <span class="qris-payment-icon"><i class="bi bi-qr-code-scan"></i></span>
                        <div>
                            <strong>Scan QRIS untuk membayar</strong>
                            <p>Pelanggan memindai kode dengan aplikasi pembayaran, lalu kasir memastikan pembayaran berhasil sebelum mencatat transaksi.</p>
                            <span class="qris-payment-amount">Total pembayaran: <strong id="qrisPaymentAmount">Rp 0</strong></span>
                        </div>
                    </div>
                    <?php if ($qrisTersedia): ?>
                        <img class="qris-merchant-image" src="<?= htmlspecialchars($qrisUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Kode QRIS resmi TokoKu">
                    <?php else: ?>
                        <div class="qris-image-missing">
                            <i class="bi bi-image"></i>
                            <strong>Gambar QRIS belum tersedia</strong>
                            <span>Tambahkan gambar QRIS resmi toko sebagai <code>assets/qris-tokoku.png</code>.</span>
                        </div>
                    <?php endif; ?>
                    <label class="customer-qris-confirmation">
                        <input id="cashierQrisConfirmation" type="checkbox" name="konfirmasi_qris_kasir" value="1">
                        <span>Saya sudah memeriksa dan memastikan pembayaran QRIS masuk.</span>
                    </label>
                </section>
                <div class="cashier-shop-layout">
                    <section class="cashier-catalog">
                        <div class="cashier-section-heading">
                            <span><i class="bi bi-shop"></i></span>
                            <div><h3>Pilih barang</h3><p>Tambahkan satu atau beberapa barang ke keranjang.</p></div>
                        </div>
                        <div class="cashier-barcode-scan">
                            <label for="cashierBarcodeInput">Scan barcode barang</label>
                            <div>
                                <i class="bi bi-upc-scan" aria-hidden="true"></i>
                                <input id="cashierBarcodeInput" type="text" inputmode="none" autocomplete="off" placeholder="Arahkan scanner, lalu scan barcode" aria-describedby="cashierBarcodeStatus">
                            </div>
                            <small id="cashierBarcodeStatus" aria-live="polite">Scanner USB siap. Produk yang terbaca akan masuk ke keranjang.</small>
                        </div>
                        <div class="management-alert stock-sync-notice" id="stockSyncNotice" hidden></div>
                        <div class="cashier-product-list">
                        <?php while ($item = $daftarProduk->fetch_assoc()): ?>
                            <article class="cashier-product" data-cashier-product="<?= (int)$item['id_produk'] ?>"
                                data-product-name="<?= htmlspecialchars($item['nama_produk'], ENT_QUOTES, 'UTF-8') ?>"
                                data-product-price="<?= htmlspecialchars((string)$item['harga'], ENT_QUOTES, 'UTF-8') ?>"
                                data-product-stock="<?= (int)$item['stok'] ?>"
                                data-product-barcode="<?= htmlspecialchars($item['barcode'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                <?php if (!empty($item['gambar'])): ?>
                                    <img class="cashier-product-image" src="<?= htmlspecialchars($item['gambar'], ENT_QUOTES, 'UTF-8') ?>" alt="" loading="lazy">
                                <?php else: ?>
                                    <span class="cashier-product-icon"><i class="bi bi-box-seam"></i></span>
                                <?php endif; ?>
                                <span class="cashier-product-info">
                                    <strong><?= htmlspecialchars($item['nama_produk']) ?></strong>
                                    <small class="cashier-stock <?= (int)$item['stok'] === 0 ? 'is-empty' : '' ?>" data-stock-label="<?= (int)$item['id_produk'] ?>">
                                        <?= (int)$item['stok'] > 0 ? 'Stok ' . number_format((int)$item['stok'], 0, ',', '.') : 'Stok habis' ?>
                                    </small>
                                </span>
                                <strong class="cashier-product-price"><?= rupiah($item['harga']) ?></strong>
                                <button type="button" class="cashier-add-button" data-add-product="<?= (int)$item['id_produk'] ?>" <?= (int)$item['stok'] < 1 ? 'disabled' : '' ?> aria-label="Tambah <?= htmlspecialchars($item['nama_produk'], ENT_QUOTES, 'UTF-8') ?> ke keranjang">
                                    <i class="bi bi-plus-lg"></i> Tambah
                                </button>
                            </article>
                        <?php endwhile; ?>
                        </div>
                    </section>

                    <aside class="cashier-cart" aria-labelledby="cashierCartTitle">
                        <div class="cashier-section-heading">
                            <span><i class="bi bi-basket2"></i></span>
                            <div><h3 id="cashierCartTitle">Keranjang <span class="cashier-cart-count" id="cashierCartCount">0</span></h3><p>Atur jumlah barang yang akan dibeli.</p></div>
                        </div>
                        <div class="cashier-cart-items" id="cashierCartItems">
                            <div class="cashier-cart-empty"><i class="bi bi-bag"></i><strong>Keranjang masih kosong</strong><span>Pilih barang dari katalog.</span></div>
                        </div>
                        <div id="cashierCartFields"></div>
                        <div class="cashier-cart-breakdown">
                            <div><span>Subtotal barang</span><strong id="cashierCartSubtotal">Rp 0</strong></div>
                            <div><span>Pajak (<?= number_format($tarifPajak, 0, ',', '.') ?>%)</span><strong id="cashierCartTax">Rp 0</strong></div>
                            <div class="cashier-cart-total"><span>Total pembayaran</span><strong id="cashierCartTotal">Rp 0</strong></div>
                        </div>
                        <button class="management-primary-button cashier-submit-button" id="cashierSubmit" disabled><i class="bi bi-check-circle"></i> Catat pembayaran</button>
                    </aside>
                </div>
            </form>
            <?php else: ?>
                <div class="alert alert-warning mb-0">Tambahkan pelanggan dan barang katalog sebelum memproses pembayaran.</div>
            <?php endif; ?>
        </div>
    </section>
    <?php endif; ?>

    <section class="management-panel transaction-history-panel">
        <div class="management-panel-heading">
            <span class="management-panel-icon"><i class="bi bi-clock-history"></i></span>
            <div><h2>Riwayat transaksi</h2><p>Transaksi terbaru yang tercatat di TokoKu.</p></div>
        </div>
        <form class="management-search transaction-filter" method="get">
            <i class="bi bi-search"></i>
            <input type="number" min="1" name="pelanggan" value="<?= htmlspecialchars($filter) ?>" placeholder="Filter berdasarkan ID pelanggan">
            <button aria-label="Filter transaksi"><i class="bi bi-arrow-right"></i></button>
        </form>
        <div class="management-table-wrap">
                <table class="table management-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Transaksi</th><th>Tanggal</th><th>Pelanggan</th><th>Pembayaran</th><th>Status</th>
                            <th class="text-end">Total</th><th class="text-end">Detail</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($data && $data->num_rows > 0): ?>
                        <?php while ($row = $data->fetch_assoc()): ?>
                        <tr>
                            <td><strong class="transaction-number">#<?= (int)$row['id_transaksi'] ?></strong></td>
                            <td><?= htmlspecialchars(date('d/m/Y', strtotime($row['tanggal']))) ?></td>
                            <td><span class="management-person"><span class="management-avatar"><?= htmlspecialchars(strtoupper(substr($row['nama_pelanggan'], 0, 1))) ?></span><span><strong><?= htmlspecialchars($row['nama_pelanggan']) ?></strong><small><?= htmlspecialchars($row['kota']) ?></small></span></span></td>
                            <td><span class="management-payment"><?= htmlspecialchars(strtoupper($row['metode_pembayaran'] ?? 'tunai')) ?></span></td>
                            <td>
                                <?php if ($row['status_pembayaran'] === 'menunggu_verifikasi'): ?>
                                    <span class="badge text-bg-warning">Menunggu verifikasi</span>
                                <?php else: ?>
                                    <span class="badge text-bg-success">Terverifikasi</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end management-money"><?= rupiah($row['total_belanja']) ?></td>
                            <td class="text-end">
                                <a href="detail_transaksi.php?id=<?= (int)$row['id_transaksi'] ?>" class="management-detail-link">
                                    <i class="bi bi-arrow-up-right"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="7"><div class="management-empty"><i class="bi bi-receipt"></i><strong>Belum ada transaksi</strong><span>Transaksi yang dibuat akan muncul di sini.</span></div></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
    </section>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(() => {
    const form = document.querySelector('.checkout-management-form');
    if (!form) return;

    const cart = new Map();
    const cartItems = document.getElementById('cashierCartItems');
    const cartFields = document.getElementById('cashierCartFields');
    const cartCount = document.getElementById('cashierCartCount');
    const cartSubtotal = document.getElementById('cashierCartSubtotal');
    const cartTax = document.getElementById('cashierCartTax');
    const cartTotal = document.getElementById('cashierCartTotal');
    const submitButton = document.getElementById('cashierSubmit');
    const barcodeInput = document.getElementById('cashierBarcodeInput');
    const barcodeStatus = document.getElementById('cashierBarcodeStatus');
    const metodePembayaran = document.getElementById('metode_pembayaran');
    const qrisPanel = document.getElementById('qrisPaymentPanel');
    const qrisAmount = document.getElementById('qrisPaymentAmount');
    const qrisConfirmation = document.getElementById('cashierQrisConfirmation');
    const qrisAvailable = <?= $qrisTersedia ? 'true' : 'false' ?>;
    const tarifPajak = <?= json_encode((float)$tarifPajak) ?>;
    const formatRupiah = value => new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        maximumFractionDigits: 0
    }).format(value);

    function renderCart() {
        cartItems.replaceChildren();
        cartFields.replaceChildren();
        let subtotal = 0;
        let units = 0;

        for (const [id, product] of cart) {
            const lineTotal = product.price * product.quantity;
            subtotal += lineTotal;
            units += product.quantity;

            const row = document.createElement('div');
            row.className = 'cashier-cart-row';
            const description = document.createElement('span');
            description.className = 'cashier-cart-description';
            const name = document.createElement('strong');
            name.textContent = product.name;
            const linePrice = document.createElement('small');
            linePrice.textContent = formatRupiah(lineTotal);
            description.append(name, linePrice);

            const controls = document.createElement('span');
            controls.className = 'cashier-quantity-control';
            const minus = document.createElement('button');
            minus.type = 'button';
            minus.textContent = '−';
            minus.setAttribute('aria-label', `Kurangi ${product.name}`);
            minus.addEventListener('click', () => changeQuantity(id, -1));
            const quantity = document.createElement('strong');
            quantity.textContent = product.quantity;
            const plus = document.createElement('button');
            plus.type = 'button';
            plus.textContent = '+';
            plus.disabled = product.quantity >= product.stock;
            plus.setAttribute('aria-label', `Tambah ${product.name}`);
            plus.addEventListener('click', () => changeQuantity(id, 1));
            controls.append(minus, quantity, plus);
            row.append(description, controls);
            cartItems.append(row);

            const field = document.createElement('input');
            field.type = 'hidden';
            field.name = `jumlah[${id}]`;
            field.value = product.quantity;
            cartFields.append(field);
        }

        if (cart.size === 0) {
            const empty = document.createElement('div');
            empty.className = 'cashier-cart-empty';
            empty.innerHTML = '<i class="bi bi-bag"></i><strong>Keranjang masih kosong</strong><span>Pilih barang dari katalog.</span>';
            cartItems.append(empty);
        }

        const totalPajak = Math.round(subtotal * tarifPajak / 100);
        const total = subtotal + totalPajak;
        cartCount.textContent = units;
        cartSubtotal.textContent = formatRupiah(subtotal);
        cartTax.textContent = formatRupiah(totalPajak);
        cartTotal.textContent = formatRupiah(total);
        qrisAmount.textContent = formatRupiah(total);
        submitButton.disabled = cart.size === 0
            || (metodePembayaran.value === 'qris' && (!qrisAvailable || !qrisConfirmation.checked));
    }

    function updatePaymentMethod() {
        const memakaiQris = metodePembayaran.value === 'qris';
        qrisPanel.hidden = !memakaiQris;
        qrisConfirmation.required = memakaiQris;
        if (!memakaiQris) qrisConfirmation.checked = false;
        submitButton.disabled = cart.size === 0
            || (memakaiQris && (!qrisAvailable || !qrisConfirmation.checked));
        submitButton.title = metodePembayaran.value === 'qris' && !qrisAvailable
            ? 'Pembayaran QRIS belum tersedia sampai admin memasang gambar QRIS resmi.'
            : '';
    }

    function changeQuantity(id, amount) {
        const product = cart.get(id);
        if (!product) return;
        product.quantity = Math.min(product.stock, product.quantity + amount);
        if (product.quantity <= 0) cart.delete(id);
        renderCart();
    }

    function addProductToCart(id) {
        const card = document.querySelector(`[data-cashier-product="${CSS.escape(id)}"]`);
        if (!card) return false;
        const product = cart.get(id) || {
            name: card.dataset.productName,
            price: Number(card.dataset.productPrice),
            stock: Number(card.dataset.productStock),
            barcode: card.dataset.productBarcode,
            quantity: 0
        };
        if (product.quantity >= product.stock) return false;
        product.quantity += 1;
        cart.set(id, product);
        renderCart();
        return true;
    }

    document.querySelectorAll('[data-add-product]').forEach(button => {
        button.addEventListener('click', () => {
            addProductToCart(button.dataset.addProduct);
        });
    });

    barcodeInput.addEventListener('keydown', event => {
        if (event.key !== 'Enter') return;
        event.preventDefault();
        const scannedBarcode = barcodeInput.value.trim().toUpperCase();
        const card = [...document.querySelectorAll('[data-cashier-product]')]
            .find(item => item.dataset.productBarcode.toUpperCase() === scannedBarcode);

        if (!card) {
            barcodeStatus.textContent = `Barcode "${scannedBarcode}" tidak ditemukan.`;
            barcodeStatus.classList.add('is-error');
        } else if (!addProductToCart(card.dataset.cashierProduct)) {
            barcodeStatus.textContent = `Stok ${card.dataset.productName} habis.`;
            barcodeStatus.classList.add('is-error');
        } else {
            barcodeStatus.textContent = `${card.dataset.productName} ditambahkan ke keranjang.`;
            barcodeStatus.classList.remove('is-error');
        }

        barcodeInput.value = '';
        barcodeInput.focus();
    });

    metodePembayaran.addEventListener('change', updatePaymentMethod);
    qrisConfirmation.addEventListener('change', renderCart);
    document.addEventListener('tokoku:stock-updated', event => {
        event.detail.produk.forEach(item => {
            const id = String(item.id);
            const card = document.querySelector(`[data-cashier-product="${CSS.escape(id)}"]`);
            if (card) {
                card.dataset.productStock = item.stok;
                const addButton = card.querySelector('[data-add-product]');
                addButton.disabled = item.stok < 1;
                const stockLabel = card.querySelector('[data-stock-label]');
                stockLabel.textContent = item.stok > 0
                    ? `Stok ${new Intl.NumberFormat('id-ID').format(item.stok)}`
                    : 'Stok habis';
                stockLabel.classList.toggle('is-empty', item.stok < 1);
            }

            const product = cart.get(id);
            if (!product) return;
            product.stock = item.stok;
            if (product.quantity > product.stock) {
                product.quantity = product.stock;
                if (product.quantity < 1) cart.delete(id);
                const notice = document.getElementById('stockSyncNotice');
                notice.textContent = `Keranjang disesuaikan dengan stok terbaru untuk ${item.nama}.`;
                notice.hidden = false;
            }
        });
        renderCart();
    });

    renderCart();
    updatePaymentMethod();
    barcodeInput.focus();
})();
</script>
<script src="stock-sync.js"></script>
</body>
</html>