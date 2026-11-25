<?php
require 'config.php';
require_once 'inventory.php';

if ($_SESSION['role'] !== 'pelanggan' || empty($_SESSION['id_pelanggan'])) {
    header("Location: index.php");
    exit;
}

$pesan = '';
$pesanTipe = 'success';
$qrisPath = __DIR__ . '/assets/qris-tokoku.png';
$qrisTersedia = is_file($qrisPath);
$qrisUrl = $qrisTersedia
    ? 'assets/qris-tokoku.png?v=' . (string)filemtime($qrisPath) . '-' . substr(hash_file('sha256', $qrisPath), 0, 12)
    : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        $pesan = 'Form sudah kedaluwarsa. Muat ulang halaman lalu coba kembali.';
        $pesanTipe = 'danger';
    } else {
        $metode = $_POST['metode_pembayaran'] ?? 'tunai';
        $konfirmasiQris = ($_POST['konfirmasi_qris'] ?? '') === '1';
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

        if (!$keranjang) {
            $pesan = 'Pilih minimal satu barang dan isi jumlah yang ingin dibeli.';
            $pesanTipe = 'warning';
        } elseif (!in_array($metode, ['tunai', 'qris'], true)) {
            $pesan = 'Pilih metode pembayaran yang tersedia.';
            $pesanTipe = 'warning';
        } elseif ($metode === 'qris' && !$qrisTersedia) {
            $pesan = 'Pembayaran QRIS belum tersedia. Silakan hubungi toko.';
            $pesanTipe = 'warning';
        } elseif ($metode === 'qris' && !$konfirmasiQris) {
            $pesan = 'Konfirmasikan bahwa pembayaran QRIS sudah dilakukan sebelum membuat pesanan.';
            $pesanTipe = 'warning';
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

                $idPelanggan = (int)$_SESSION['id_pelanggan'];
                $statusPembayaran = $metode === 'qris' ? 'menunggu_verifikasi' : 'terverifikasi';
                $stmt = $conn->prepare("INSERT INTO tbl_transaksi (tanggal, id_pelanggan, id_user, total_belanja, metode_pembayaran, status_pembayaran, persentase_pajak, total_pajak) VALUES (CURDATE(), ?, NULL, ?, ?, ?, ?, ?)");
                $stmt->bind_param("idssdd", $idPelanggan, $total, $metode, $statusPembayaran, $persentasePajak, $totalPajak);
                $stmt->execute();
                $idTransaksi = $conn->insert_id;
                $stmt->close();

                $stmt = $conn->prepare("INSERT INTO tbl_detail_transaksi (id_transaksi, nama_produk, barcode, jumlah, harga) VALUES (?, ?, ?, ?, ?)");
                foreach ($produkDipilih as $produk) {
                    $jumlah = $produk['jumlah'];
                    $harga = $produk['harga'];
                    $namaProduk = $produk['nama_produk'];
                    $barcode = $produk['barcode'];
                    $stmt->bind_param("issid", $idTransaksi, $namaProduk, $barcode, $jumlah, $harga);
                    $stmt->execute();
                }
                $stmt->close();
                $conn->commit();
                header("Location: belanja.php?berhasil=" . $idTransaksi . "#pesanan");
                exit;
            } catch (StokTidakCukupException $e) {
                $conn->rollback();
                $pesan = $e->getMessage();
                $pesanTipe = 'warning';
            } catch (mysqli_sql_exception $e) {
                $conn->rollback();
                throw $e;
            }
        }
    }
}

if (isset($_GET['berhasil']) && filter_var($_GET['berhasil'], FILTER_VALIDATE_INT)) {
    $idBerhasil = (int)$_GET['berhasil'];
    $stmt = $conn->prepare("SELECT id_transaksi, metode_pembayaran, status_pembayaran FROM tbl_transaksi WHERE id_transaksi = ? AND id_pelanggan = ?");
    $stmt->bind_param("ii", $idBerhasil, $_SESSION['id_pelanggan']);
    $stmt->execute();
    $pesananBerhasil = $stmt->get_result()->fetch_assoc();
    if ($pesananBerhasil) {
        $pesan = $pesananBerhasil['status_pembayaran'] === 'menunggu_verifikasi'
            ? "Pesanan #" . $idBerhasil . " dibuat. Menunggu kasir memverifikasi pembayaran QRIS."
            : "Pesanan berhasil dibuat. Nomor pesanan #" . $idBerhasil . ".";
    }
    $stmt->close();
}

$produkResult = $conn->query("SELECT id_produk, nama_produk, harga, stok, gambar FROM tbl_produk WHERE aktif = 1 ORDER BY nama_produk");
$jumlahProdukKatalog = (int)$conn->query("SELECT COUNT(*) AS n FROM tbl_produk WHERE aktif = 1")->fetch_assoc()['n'];
$daftarProduk = [];
while ($item = $produkResult->fetch_assoc()) {
    $item['kategori'] = match (true) {
        stripos($item['nama_produk'], 'buku') !== false => 'Buku',
        stripos($item['nama_produk'], 'tas') !== false,
        stripos($item['nama_produk'], 'sepatu') !== false => 'Perlengkapan',
        default => 'Alat Tulis',
    };
    $item['ikon'] = match (true) {
        stripos($item['nama_produk'], 'buku') !== false => 'bi-journal-richtext',
        stripos($item['nama_produk'], 'pensil') !== false => 'bi-pencil-fill',
        stripos($item['nama_produk'], 'pulpen') !== false => 'bi-pen-fill',
        stripos($item['nama_produk'], 'penghapus') !== false => 'bi-eraser-fill',
        stripos($item['nama_produk'], 'penggaris') !== false => 'bi-rulers',
        stripos($item['nama_produk'], 'tas') !== false => 'bi-backpack-fill',
        stripos($item['nama_produk'], 'sepatu') !== false => 'bi-boots',
        default => 'bi-box-seam',
    };
    $daftarProduk[] = $item;
}
$kategoriProduk = array_values(array_unique(array_column($daftarProduk, 'kategori')));
$stmt = $conn->prepare("SELECT id_transaksi, tanggal, total_belanja, metode_pembayaran, status_pembayaran FROM tbl_transaksi WHERE id_pelanggan = ? ORDER BY tanggal DESC, id_transaksi DESC");
$stmt->bind_param("i", $_SESSION['id_pelanggan']);
$stmt->execute();
$pesanan = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Belanja - TokoKu</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body class="shop-page">
<?php include 'navbar.php'; ?>
<main class="shop-container">
    <section class="shop-hero">
        <div class="hero-copy">
            <span class="hero-kicker"><i class="bi bi-stars"></i> TOKOKU OFFICIAL</span>
            <h1>Temukan kebutuhan<br>favoritmu di <span>TokoKu</span></h1>
            <p>Belanja mudah, pilihan lengkap, harga bersahabat.</p>
            <a class="hero-cta" href="#produk">Jelajahi produk <i class="bi bi-arrow-right"></i></a>
        </div>
        <div class="hero-art" aria-hidden="true">
            <span class="hero-sun"></span>
            <i class="bi bi-flower1 hero-flower"></i>
            <i class="bi bi-leaf hero-leaf hero-leaf-one"></i>
            <i class="bi bi-leaf hero-leaf hero-leaf-two"></i>
            <i class="bi bi-bag-heart hero-bag"></i>
            <i class="bi bi-journal-richtext hero-book"></i>
            <i class="bi bi-pencil-fill hero-pencil"></i>
        </div>
        <label class="shop-search">
            <i class="bi bi-search"></i>
            <input id="productSearch" type="search" placeholder="Cari barang yang kamu butuhkan..." autocomplete="off">
        </label>
    </section>

    <?php if ($pesan !== ''): ?>
        <div class="alert alert-<?= htmlspecialchars($pesanTipe) ?> shop-alert"><?= htmlspecialchars($pesan) ?></div>
    <?php endif; ?>

    <section class="category-section" aria-label="Kategori produk">
        <div class="product-section-heading">
            <div>
                <span class="section-eyebrow">JELAJAHI KATEGORI</span>
                <h2>Belanja sesuai kebutuhan</h2>
            </div>
            <button type="button" class="category-reset" data-category="all">Lihat semua <i class="bi bi-arrow-right"></i></button>
        </div>
        <div class="category-list">
            <?php foreach ($kategoriProduk as $kategori): ?>
                <?php
                $ikonKategori = match ($kategori) {
                    'Buku' => 'bi-journal-bookmark-fill',
                    'Perlengkapan' => 'bi-backpack-fill',
                    default => 'bi-pencil-fill',
                };
                ?>
                <button type="button" class="category-card" data-category="<?= htmlspecialchars($kategori, ENT_QUOTES, 'UTF-8') ?>">
                    <span class="category-icon"><i class="bi <?= htmlspecialchars($ikonKategori) ?>"></i></span>
                    <strong><?= htmlspecialchars($kategori) ?></strong>
                </button>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="product-section" id="produk">
        <div class="product-section-heading">
            <div>
                <span class="section-eyebrow">PILIHAN TOKOKU</span>
                <h2>Produk terbaik <span>(<?= count($daftarProduk) ?>)</span></h2>
            </div>
            <a href="#pesanan" class="orders-shortcut"><i class="bi bi-receipt"></i> Pesanan saya</a>
        </div>

        <?php if ($daftarProduk): ?>
            <div class="product-list" id="productList">
                <?php foreach ($daftarProduk as $item): ?>
                    <article class="product-card" data-product-id="<?= (int)$item['id_produk'] ?>" data-product-name="<?= htmlspecialchars($item['nama_produk'], ENT_QUOTES, 'UTF-8') ?>" data-product-category="<?= htmlspecialchars($item['kategori'], ENT_QUOTES, 'UTF-8') ?>">
                        <div class="product-card-art">
                            <span class="product-card-badge">TOKOKU</span>
                            <button type="button" class="wishlist-button" aria-label="Produk favorit"><i class="bi bi-heart"></i></button>
                            <?php if (!empty($item['gambar'])): ?>
                                <img class="product-card-image" src="<?= htmlspecialchars($item['gambar'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($item['nama_produk'], ENT_QUOTES, 'UTF-8') ?>" loading="lazy">
                            <?php else: ?>
                                <span class="product-art-orbit"></span>
                                <i class="bi <?= htmlspecialchars($item['ikon']) ?> product-card-icon" aria-hidden="true"></i>
                            <?php endif; ?>
                        </div>
                        <div class="product-card-info">
                            <span class="product-card-category"><?= htmlspecialchars($item['kategori']) ?></span>
                            <h3><?= htmlspecialchars($item['nama_produk']) ?></h3>
                            <div class="product-card-price"><?= rupiah($item['harga']) ?></div>
                            <span class="product-stock" data-stock-label><?= (int)$item['stok'] > 0 ? '<i class="bi bi-box-seam"></i> Stok tersedia: ' . number_format((int)$item['stok'], 0, ',', '.') : '<i class="bi bi-x-circle"></i> Stok habis' ?></span>
                            <button
                                type="button"
                                class="buy-button"
                                data-product-id="<?= (int)$item['id_produk'] ?>"
                                data-product-name="<?= htmlspecialchars($item['nama_produk'], ENT_QUOTES, 'UTF-8') ?>"
                                data-product-price="<?= htmlspecialchars((string)$item['harga'], ENT_QUOTES, 'UTF-8') ?>"
                                data-product-stock="<?= (int)$item['stok'] ?>"
                                <?= (int)$item['stok'] < 1 ? 'disabled' : '' ?>
                            ><?= (int)$item['stok'] < 1 ? '<i class="bi bi-x-circle"></i> Stok habis' : '<i class="bi bi-bag-plus"></i> Tambah ke keranjang' ?></button>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-products">
                <i class="bi bi-basket"></i>
                <p><?= $jumlahProdukKatalog > 0 ? 'Stok barang sedang habis. Silakan cek kembali nanti.' : 'Belum ada barang yang tersedia di katalog.' ?></p>
            </div>
        <?php endif; ?>
        <p class="empty-search d-none" id="emptySearch">Barang tidak ditemukan. Coba kata kunci lain.</p>
    </section>

    <section class="shop-promo">
        <div class="promo-copy">
            <span class="section-eyebrow">PILIHAN UNTUKMU</span>
            <h2>Belanja kebutuhan<br>jadi lebih mudah.</h2>
            <p>Pilih produk favoritmu dan pesan langsung dari TokoKu.</p>
            <a href="#produk">Mulai belanja <i class="bi bi-arrow-right"></i></a>
        </div>
        <div class="promo-art" aria-hidden="true">
            <i class="bi bi-backpack-fill promo-bag"></i>
            <i class="bi bi-journal-bookmark-fill promo-book"></i>
            <i class="bi bi-pencil-fill promo-pencil"></i>
            <span></span>
        </div>
    </section>

    <section class="shop-benefits" aria-label="Keunggulan TokoKu">
        <div><i class="bi bi-patch-check"></i><span><strong>Produk pilihan</strong><small>Barang dari katalog resmi</small></span></div>
        <div><i class="bi bi-lightning-charge"></i><span><strong>Belanja praktis</strong><small>Pesan dengan beberapa klik</small></span></div>
        <div><i class="bi bi-shield-check"></i><span><strong>Pesanan tercatat</strong><small>Pantau riwayat kapan saja</small></span></div>
    </section>

    <section id="pesanan" class="orders-section">
        <div class="product-section-heading">
            <div>
                <span class="section-eyebrow">RIWAYAT BELANJA</span>
                <h2>Pesanan Saya</h2>
            </div>
        </div>
        <div class="orders-card">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>Nomor</th><th>Tanggal</th><th>Pembayaran</th><th>Status</th><th class="text-end">Total</th><th class="text-center">Detail</th></tr></thead>
                    <tbody>
                    <?php if ($pesanan->num_rows > 0): ?>
                        <?php while ($row = $pesanan->fetch_assoc()): ?>
                            <tr>
                                <td><strong>#<?= (int)$row['id_transaksi'] ?></strong></td>
                                <td><?= date('d/m/Y', strtotime($row['tanggal'])) ?></td>
                                <td><?= htmlspecialchars(strtoupper($row['metode_pembayaran'])) ?></td>
                                <td>
                                    <?php if ($row['status_pembayaran'] === 'menunggu_verifikasi'): ?>
                                        <span class="badge text-bg-warning">Menunggu verifikasi</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-success">Terverifikasi</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end"><?= rupiah($row['total_belanja']) ?></td>
                                <td class="text-center"><a class="order-detail-button" href="detail_transaksi.php?id=<?= (int)$row['id_transaksi'] ?>">Lihat</a></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">Belum ada pesanan.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</main>

<button type="button" class="floating-cart" id="openCart" data-bs-toggle="modal" data-bs-target="#cartModal">
    <span class="cart-icon"><i class="bi bi-bag"></i><b id="cartCount">0</b></span>
    <span class="cart-label">Lihat Keranjang</span>
    <i class="bi bi-chevron-up"></i>
</button>

<div class="modal fade cart-modal" id="cartModal" tabindex="-1" aria-labelledby="cartTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <span class="section-eyebrow">TOKOKU OFFICIAL</span>
                    <h2 class="modal-title" id="cartTitle">Keranjang <span id="cartTitleCount">(0)</span></h2>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">
                <div id="cartItems" class="cart-items">
                    <p class="empty-cart">Keranjangmu masih kosong. Yuk pilih barang!</p>
                </div>
                <div class="cart-price-breakdown">
                    <div><span>Subtotal barang</span><strong id="cartSubtotal">Rp 0</strong></div>
                    <div><span>Pajak (<?= number_format($tarifPajak, 0, ',', '.') ?>%)</span><strong id="cartTax">Rp 0</strong></div>
                </div>
                <div class="cart-total">
                    <span>Total belanja</span>
                    <strong id="cartTotal">Rp 0</strong>
                </div>
                <div class="customer-payment-option">
                    <label for="customerPaymentMethod">Metode pembayaran</label>
                    <select id="customerPaymentMethod" name="metode_pembayaran" class="form-select" form="checkoutForm" required>
                        <option value="tunai">Tunai</option>
                        <option value="qris" <?= !$qrisTersedia ? 'disabled' : '' ?>>QRIS</option>
                    </select>
                </div>
                <section class="qris-payment-panel customer-qris-panel" id="customerQrisPanel" hidden aria-live="polite">
                    <div class="qris-payment-copy">
                        <span class="qris-payment-icon"><i class="bi bi-qr-code-scan"></i></span>
                        <div>
                            <strong>Bayar dengan QRIS melalui DANA</strong>
                            <p>Pindai QRIS dan pastikan pembayaran berhasil. Setelah pesanan dikirim, kasir akan memeriksa pembayaran sebelum statusnya terverifikasi.</p>
                            <span class="qris-payment-amount">Total pembayaran: <strong id="customerQrisAmount">Rp 0</strong></span>
                        </div>
                    </div>
                    <?php if ($qrisTersedia): ?>
                        <img class="qris-merchant-image" src="<?= htmlspecialchars($qrisUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Gambar QRIS TokoKu lengkap">
                    <?php else: ?>
                        <div class="qris-image-missing">
                            <i class="bi bi-image"></i>
                            <strong>Gambar QRIS belum tersedia</strong>
                            <span>Silakan hubungi toko untuk metode pembayaran lain.</span>
                        </div>
                    <?php endif; ?>
                    <label class="customer-qris-confirmation">
                        <input id="customerQrisConfirmation" type="checkbox" name="konfirmasi_qris" value="1" form="checkoutForm">
                        <span>Saya sudah membayar sesuai total menggunakan QRIS.</span>
                    </label>
                </section>
            </div>
            <div class="modal-footer">
                <button type="button" class="continue-shopping" data-bs-dismiss="modal">Lanjut belanja</button>
                <form method="post" id="checkoutForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <div id="cartFields"></div>
                    <button class="checkout-button" id="checkoutButton" disabled><i class="bi bi-bag-check"></i> Buat Pesanan</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(() => {
    const cart = new Map();
    const cartItems = document.getElementById('cartItems');
    const cartFields = document.getElementById('cartFields');
    const countLabel = document.getElementById('cartCount');
    const titleCount = document.getElementById('cartTitleCount');
    const subtotalLabel = document.getElementById('cartSubtotal');
    const taxLabel = document.getElementById('cartTax');
    const totalLabel = document.getElementById('cartTotal');
    const checkoutButton = document.getElementById('checkoutButton');
    const paymentMethod = document.getElementById('customerPaymentMethod');
    const qrisPanel = document.getElementById('customerQrisPanel');
    const qrisAmount = document.getElementById('customerQrisAmount');
    const qrisConfirmation = document.getElementById('customerQrisConfirmation');
    const cartModal = new bootstrap.Modal(document.getElementById('cartModal'));
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
        let count = 0;

        for (const [id, product] of cart) {
            const lineTotal = product.price * product.quantity;
            subtotal += lineTotal;
            count += product.quantity;

            const row = document.createElement('div');
            row.className = 'cart-item';
            const info = document.createElement('div');
            info.className = 'cart-item-info';
            const name = document.createElement('strong');
            name.textContent = product.name;
            const price = document.createElement('span');
            price.textContent = formatRupiah(product.price);
            info.append(name, price);

            const controls = document.createElement('div');
            controls.className = 'quantity-control';
            const minus = document.createElement('button');
            minus.type = 'button';
            minus.textContent = '−';
            minus.setAttribute('aria-label', 'Kurangi jumlah');
            minus.addEventListener('click', () => changeQuantity(id, -1));
            const quantity = document.createElement('span');
            quantity.textContent = product.quantity;
            const plus = document.createElement('button');
            plus.type = 'button';
            plus.textContent = '+';
            plus.setAttribute('aria-label', 'Tambah jumlah');
            plus.disabled = product.quantity >= product.stock;
            plus.addEventListener('click', () => changeQuantity(id, 1));
            controls.append(minus, quantity, plus);
            row.append(info, controls);
            cartItems.append(row);

            const field = document.createElement('input');
            field.type = 'hidden';
            field.name = `jumlah[${id}]`;
            field.value = product.quantity;
            cartFields.append(field);
        }

        if (count === 0) {
            const empty = document.createElement('p');
            empty.className = 'empty-cart';
            empty.textContent = 'Keranjangmu masih kosong. Yuk pilih barang!';
            cartItems.append(empty);
        }

        countLabel.textContent = count;
        titleCount.textContent = `(${count})`;
        const totalPajak = Math.round(subtotal * tarifPajak / 100);
        const total = subtotal + totalPajak;
        subtotalLabel.textContent = formatRupiah(subtotal);
        taxLabel.textContent = formatRupiah(totalPajak);
        totalLabel.textContent = formatRupiah(total);
        qrisAmount.textContent = formatRupiah(total);
        updateCheckoutAvailability(count);
    }

    function updateCheckoutAvailability(count = cart.size) {
        const qrisBelumDibayar = paymentMethod.value === 'qris' && !qrisConfirmation.checked;
        const qrisTidakTersedia = paymentMethod.value === 'qris' && !qrisAvailable;
        checkoutButton.disabled = count === 0 || qrisBelumDibayar || qrisTidakTersedia;
    }

    function updatePaymentMethod() {
        const memakaiQris = paymentMethod.value === 'qris';
        qrisPanel.hidden = !memakaiQris;
        qrisConfirmation.required = memakaiQris;
        qrisConfirmation.checked = false;
        updateCheckoutAvailability();
    }

    function changeQuantity(id, amount) {
        const product = cart.get(id);
        if (!product) return;
        product.quantity = Math.min(product.stock, product.quantity + amount);
        if (product.quantity <= 0) cart.delete(id);
        renderCart();
    }

    let activeCategory = 'all';
    const searchInput = document.getElementById('productSearch');

    function filterProducts() {
        const query = searchInput.value.trim().toLocaleLowerCase('id');
        let visible = 0;
        document.querySelectorAll('.product-card').forEach(card => {
            const matchesName = card.dataset.productName.toLocaleLowerCase('id').includes(query);
            const matchesCategory = activeCategory === 'all' || card.dataset.productCategory === activeCategory;
            const matches = matchesName && matchesCategory;
            card.classList.toggle('d-none', !matches);
            if (matches) visible += 1;
        });
        document.getElementById('emptySearch').classList.toggle('d-none', visible !== 0);
    }

    document.querySelectorAll('.category-card').forEach(button => {
        button.addEventListener('click', () => {
            activeCategory = button.dataset.category;
            document.querySelectorAll('.category-card').forEach(category => {
                category.classList.toggle('is-active', category === button);
            });
            filterProducts();
            document.getElementById('produk').scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });

    document.querySelector('.category-reset').addEventListener('click', () => {
        activeCategory = 'all';
        searchInput.value = '';
        document.querySelectorAll('.category-card').forEach(category => category.classList.remove('is-active'));
        filterProducts();
    });

    searchInput.addEventListener('input', filterProducts);
    paymentMethod.addEventListener('change', updatePaymentMethod);
    qrisConfirmation.addEventListener('change', () => updateCheckoutAvailability());

    document.querySelectorAll('.buy-button').forEach(button => {
        button.addEventListener('click', () => {
            const id = button.dataset.productId;
            const product = cart.get(id) || {
                name: button.dataset.productName,
                price: Number(button.dataset.productPrice),
                stock: Number(button.dataset.productStock),
                quantity: 0
            };
            if (product.quantity >= product.stock) return;
            product.quantity += 1;
            cart.set(id, product);
            renderCart();
            cartModal.show();
        });
    });

    document.addEventListener('tokoku:stock-updated', event => {
        event.detail.produk.forEach(item => {
            const product = cart.get(String(item.id));
            if (!product) return;
            product.stock = item.stok;
            if (product.quantity > product.stock) {
                product.quantity = product.stock;
                if (product.quantity < 1) cart.delete(String(item.id));
            }
        });
        renderCart();
    });

    renderCart();
    updatePaymentMethod();
})();
</script>
<script src="stock-sync.js"></script>
</body>
</html>
