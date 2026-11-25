<?php
require 'config.php';

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$id = $id && $id > 0 ? $id : 0;
$role = $_SESSION['role'] ?? '';
$isPelanggan = $role === 'pelanggan';
$isKasir = $role === 'kasir';
if ($isKasir && !$kolomKasirTersedia) {
    http_response_code(503);
    exit('Data kasir belum tersedia. Muat ulang halaman setelah database diperbarui.');
}
$sql = "SELECT v.*, t.metode_pembayaran, t.status_pembayaran, t.persentase_pajak, t.total_pajak
        FROM view_transaksi_pelanggan v
        INNER JOIN tbl_transaksi t ON t.id_transaksi = v.id_transaksi
        WHERE v.id_transaksi = ?";

if ($isPelanggan) {
    $sql .= " AND t.id_pelanggan = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $id, $_SESSION['id_pelanggan']);
} elseif ($isKasir) {
    $sql .= " AND (t.id_user = ? OR (t.metode_pembayaran = 'qris' AND t.status_pembayaran = 'menunggu_verifikasi'))";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $id, $_SESSION['user_id']);
} else {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
}

$stmt->execute();
$trx = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$trx) {
    http_response_code(404);
    exit('Transaksi tidak ditemukan.');
}

$stmt = $conn->prepare(
    "SELECT nama_produk, barcode, jumlah, harga
     FROM tbl_detail_transaksi
     WHERE id_transaksi = ?
     ORDER BY id_detail"
);
$stmt->bind_param("i", $id);
$stmt->execute();
$resultDetail = $stmt->get_result();
$items = [];
$jumlahItem = 0;
$subtotalItem = 0;
while ($item = $resultDetail->fetch_assoc()) {
    $item['subtotal'] = (float)$item['jumlah'] * (float)$item['harga'];
    $jumlahItem += (int)$item['jumlah'];
    $subtotalItem += $item['subtotal'];
    $items[] = $item;
}
$stmt->close();

$metodePembayaran = strtoupper($trx['metode_pembayaran'] ?? 'tunai');
$statusPembayaran = $trx['status_pembayaran'] ?? 'terverifikasi';
$persentasePajak = (float)($trx['persentase_pajak'] ?? 0);
$totalPajak = (float)($trx['total_pajak'] ?? 0);
$tanggalTransaksi = date('d/m/Y', strtotime($trx['tanggal']));
$tinggiStrukMm = 57 + (count($items) * 13);
$tinggiStrukMm += max(0, (int)ceil(strlen((string)$trx['nama_pelanggan']) / 28) - 1) * 2;
$tinggiStrukMm += max(0, (int)ceil(strlen((string)($trx['kota'] ?? '')) / 28) - 1) * 2;
foreach ($items as $item) {
    $tinggiStrukMm += max(0, (int)ceil(strlen((string)$item['nama_produk']) / 34) - 1) * 2;
}
$kembaliKe = $isPelanggan ? 'belanja.php#pesanan' : ($isKasir ? 'laporan.php' : 'transaksi.php');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detail Transaksi #<?= (int)$trx['id_transaksi'] ?> - TokoKu</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css?v=<?= (int)filemtime(__DIR__ . '/style.css') ?>">
    <style>
        @media print {
            @page { size: 80mm <?= $tinggiStrukMm ?>mm; margin: 0; }
        }
    </style>
</head>
<body class="transaction-detail-page">
<?php include 'navbar.php'; ?>

<main class="transaction-detail-container">
    <a href="<?= htmlspecialchars($kembaliKe) ?>" class="detail-back-link">
        <i class="bi bi-arrow-left"></i>
        <span><?= $isPelanggan ? 'Kembali ke pesanan' : ($isKasir ? 'Kembali ke laporan saya' : 'Kembali ke transaksi') ?></span>
    </a>

    <section class="detail-heading">
        <div>
            <span class="detail-eyebrow"><i class="bi bi-receipt-cutoff"></i> RINCIAN PESANAN</span>
            <h1>Detail transaksi</h1>
            <p>Informasi lengkap barang dan pembayaran.</p>
        </div>
        <button type="button" class="detail-print-button" onclick="window.print()">
            <i class="bi bi-printer"></i><span>Cetak</span>
        </button>
    </section>
    <p class="print-settings-help">Agar tulisan localhost tidak tercetak, matikan opsi <strong>Headers and footers</strong> pada pengaturan cetak browser.</p>

    <section class="detail-receipt">
        <header class="receipt-header">
            <div class="receipt-brand">
                <span class="receipt-brand-icon"><i class="bi bi-shop-window"></i></span>
                <span><strong>TokoKu</strong><small>Terima kasih sudah berbelanja</small></span>
            </div>
            <div class="receipt-number">
                <span>NO. TRANSAKSI</span>
                <strong>#<?= (int)$trx['id_transaksi'] ?></strong>
                <small class="receipt-status">
                    <i class="bi <?= $statusPembayaran === 'menunggu_verifikasi' ? 'bi-hourglass-split' : 'bi-check-circle-fill' ?>"></i>
                    <?= $statusPembayaran === 'menunggu_verifikasi' ? 'Menunggu verifikasi pembayaran' : 'Pembayaran terverifikasi' ?>
                </small>
            </div>
        </header>

        <div class="receipt-meta">
            <div class="receipt-meta-item">
                <span class="receipt-meta-icon"><i class="bi bi-person"></i></span>
                <span><small>Pelanggan</small><strong><?= htmlspecialchars($trx['nama_pelanggan']) ?></strong></span>
            </div>
            <div class="receipt-meta-item">
                <span class="receipt-meta-icon"><i class="bi bi-geo-alt"></i></span>
                <span><small>Kota</small><strong><?= htmlspecialchars($trx['kota'] ?? '-') ?></strong></span>
            </div>
            <div class="receipt-meta-item">
                <span class="receipt-meta-icon"><i class="bi bi-calendar3"></i></span>
                <span><small>Tanggal transaksi</small><strong><?= htmlspecialchars($tanggalTransaksi) ?></strong></span>
            </div>
            <div class="receipt-meta-item">
                <span class="receipt-meta-icon"><i class="bi bi-credit-card"></i></span>
                <span><small>Metode pembayaran</small><strong><?= htmlspecialchars($metodePembayaran) ?></strong></span>
            </div>
        </div>

        <div class="receipt-items-heading">
            <div><span class="detail-eyebrow">RINCIAN BARANG</span><h2>Barang pesanan</h2></div>
            <span class="receipt-item-count"><?= count($items) ?> produk <span>·</span> <?= $jumlahItem ?> item</span>
        </div>

        <div class="receipt-table-wrap">
            <table class="table receipt-table align-middle mb-0">
                <thead>
                    <tr><th class="receipt-number-cell">No.</th><th>Nama produk</th><th class="text-center">Jumlah</th><th class="text-end">Harga</th><th class="text-end">Subtotal</th></tr>
                </thead>
                <tbody>
                <?php if ($items): ?>
                    <?php foreach ($items as $index => $item): ?>
                        <tr>
                            <td class="receipt-number-cell"><?= $index + 1 ?></td>
                            <td>
                                <span class="receipt-product">
                                    <span class="receipt-product-icon"><i class="bi bi-box-seam"></i></span>
                                    <span class="receipt-product-info">
                                        <strong><?= htmlspecialchars($item['nama_produk'] ?? 'Produk') ?></strong>
                                        <?php if (!empty($item['barcode'])): ?>
                                            <small>Barcode: <?= htmlspecialchars($item['barcode']) ?></small>
                                            <svg class="receipt-barcode" data-receipt-barcode="<?= htmlspecialchars($item['barcode'], ENT_QUOTES, 'UTF-8') ?>" aria-label="Barcode <?= htmlspecialchars($item['barcode'], ENT_QUOTES, 'UTF-8') ?>"></svg>
                                        <?php endif; ?>
                                    </span>
                                </span>
                            </td>
                            <td class="text-center"><span class="receipt-quantity">× <?= (int)$item['jumlah'] ?></span></td>
                            <td class="text-end"><?= rupiah($item['harga']) ?></td>
                            <td class="text-end fw-semibold"><?= rupiah($item['subtotal']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="5" class="receipt-empty">Rincian produk tidak tersedia untuk transaksi ini.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <footer class="receipt-total">
            <div class="receipt-total-note">
                <i class="bi bi-shield-check"></i>
                <span>Total pembayaran sudah termasuk pajak.</span>
            </div>
            <div class="receipt-total-values">
                <?php if ($items): ?>
                    <div><span>Subtotal barang</span><strong><?= rupiah($subtotalItem) ?></strong></div>
                <?php endif; ?>
                <div><span>Pajak (<?= number_format($persentasePajak, 0, ',', '.') ?>%)</span><strong><?= rupiah($totalPajak) ?></strong></div>
                <div class="receipt-grand-total"><span>Total pembayaran</span><strong><?= rupiah($trx['total_belanja']) ?></strong></div>
            </div>
        </footer>
    </section>

    <p class="receipt-thanks"><i class="bi bi-heart-fill"></i> Terima kasih telah memilih TokoKu</p>

    <section class="print-ticket" aria-label="Ringkasan transaksi untuk cetak">
        <header class="print-ticket-header">
            <span class="print-ticket-brand-icon"><i class="bi bi-shop-window"></i></span>
            <strong>TokoKu</strong>
            <span>STRUK PEMBAYARAN</span>
        </header>
        <div class="print-ticket-store-note">Terima kasih sudah berbelanja</div>
        <div class="print-ticket-rule"></div>
        <div class="print-ticket-number">#<?= (int)$trx['id_transaksi'] ?></div>
        <div class="print-ticket-meta">
            <div><span>Tanggal</span><strong><?= htmlspecialchars($tanggalTransaksi) ?></strong></div>
            <div><span>Pelanggan</span><strong><?= htmlspecialchars($trx['nama_pelanggan']) ?></strong></div>
            <div><span>Kota</span><strong><?= htmlspecialchars($trx['kota'] ?? '-') ?></strong></div>
            <div><span>Pembayaran</span><strong><?= htmlspecialchars(ucfirst(strtolower($metodePembayaran))) ?></strong></div>
        </div>
        <div class="print-ticket-rule"></div>
        <div class="print-ticket-items-heading"><span>PRODUK / BARCODE</span><span>SUBTOTAL</span></div>
        <div class="print-ticket-items">
            <?php foreach ($items as $item): ?>
                <div class="print-ticket-item">
                    <div class="print-ticket-product">
                        <div class="print-ticket-item-main">
                            <strong><?= htmlspecialchars($item['nama_produk'] ?? 'Produk') ?> × <?= (int)$item['jumlah'] ?></strong>
                            <span><?= rupiah($item['subtotal']) ?></span>
                        </div>
                        <?php if (!empty($item['barcode'])): ?>
                            <svg class="print-ticket-barcode" data-receipt-barcode="<?= htmlspecialchars($item['barcode'], ENT_QUOTES, 'UTF-8') ?>" aria-label="Barcode <?= htmlspecialchars($item['barcode'], ENT_QUOTES, 'UTF-8') ?>"></svg>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="print-ticket-rule"></div>
        <div class="print-ticket-row"><span>Jumlah barang</span><strong><?= count($items) ?> jenis / <?= $jumlahItem ?> pcs</strong></div>
        <?php if ($items): ?>
            <div class="print-ticket-row"><span>Subtotal</span><strong><?= rupiah($subtotalItem) ?></strong></div>
        <?php endif; ?>
        <div class="print-ticket-row"><span>Pajak (<?= number_format($persentasePajak, 0, ',', '.') ?>%)</span><strong><?= rupiah($totalPajak) ?></strong></div>
        <div class="print-ticket-total"><span>TOTAL BAYAR</span><strong><?= rupiah($trx['total_belanja']) ?></strong></div>
        <div class="print-ticket-rule"></div>
        <footer class="print-ticket-footer">
            <strong>Terima kasih telah berbelanja!</strong>
            <span>Simpan struk ini sebagai bukti pembayaran.</span>
        </footer>
    </section>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<script>
document.querySelectorAll('[data-receipt-barcode]').forEach(barcode => {
    if (typeof JsBarcode === 'function') {
        JsBarcode(barcode, barcode.dataset.receiptBarcode, {
            format: 'CODE128',
            displayValue: false,
            lineColor: '#111',
            width: 1.6,
            height: 34,
            margin: 0
        });
    }
});
</script>
</body>
</html>
