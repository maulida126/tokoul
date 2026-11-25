<?php
require 'config.php';

$kasirId = filter_var($_GET['kasir'] ?? null, FILTER_VALIDATE_INT);
$kasirId = $kasirId && $kasirId > 0 ? $kasirId : 0;
$isKasir = ($_SESSION['role'] ?? '') === 'kasir';
if ($isKasir) {
    $kasirId = (int)($_SESSION['user_id'] ?? 0);
}
$rows = [];
$jumlahTransaksi = 0;
$grandTotal = 0;
$formatKertas = in_array($_GET['format'] ?? '', ['a4', 'f4', 'letter', 'legal'], true)
    ? $_GET['format'] : 'a4';
$orientasi = in_array($_GET['orientasi'] ?? '', ['portrait', 'landscape'], true)
    ? $_GET['orientasi'] : 'portrait';
if ($orientasi === 'landscape') {
    $formatKertas = in_array($formatKertas, ['a4', 'f4', 'letter', 'legal'], true)
        ? $formatKertas : 'a4';
}

$namaUkuran = [
    'a4' => 'A4',
    'f4' => 'F4',
    'letter' => 'Letter',
    'legal' => 'Legal',
];

$kasir = $conn->query(
    "SELECT id_user, nama, username
     FROM tbl_user
     WHERE role = 'kasir'
     ORDER BY nama, username"
);

$namaKasirDipilih = '';
if ($kasirId > 0) {
    $stmt = $conn->prepare("SELECT nama, username FROM tbl_user WHERE id_user = ? AND role = 'kasir'");
    $stmt->bind_param("i", $kasirId);
    $stmt->execute();
    $kasirTerpilih = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($kasirTerpilih) {
        $namaKasirDipilih = $kasirTerpilih['nama'] ?: $kasirTerpilih['username'];
    } elseif (!$isKasir) {
        $kasirId = 0;
    }
}

if ($kasirId > 0 && $kolomKasirTersedia) {
    if ($kolomKasirTersedia) {
        $sql = "SELECT t.id_transaksi, t.tanggal, t.total_belanja, t.metode_pembayaran,
                       p.nama_pelanggan, p.kota,
                       COALESCE(u.nama, u.username, 'Tidak tercatat') AS nama_kasir
                FROM tbl_transaksi t
                LEFT JOIN tbl_pelanggan p ON p.id_pelanggan = t.id_pelanggan
                LEFT JOIN tbl_user u ON u.id_user = t.id_user";
    } else {
        $sql = "SELECT t.id_transaksi, t.tanggal, t.total_belanja, t.metode_pembayaran,
                       p.nama_pelanggan, p.kota,
                       'Tidak tercatat' AS nama_kasir
                FROM tbl_transaksi t
                LEFT JOIN tbl_pelanggan p ON p.id_pelanggan = t.id_pelanggan";
    }
    $sql .= " WHERE t.id_user = ? AND t.status_pembayaran = 'terverifikasi'";
    $sql .= " ORDER BY t.tanggal DESC, t.id_transaksi DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $kasirId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
        $grandTotal += (float)$row['total_belanja'];
    }
    $stmt->close();
    $jumlahTransaksi = count($rows);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan - TokoKu</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <?php
    $cssUkuran = ['a4' => 'A4', 'f4' => '210mm 330mm', 'letter' => 'Letter', 'legal' => 'Legal'];
    $ukuranCetak = $cssUkuran[$formatKertas] . ' ' . $orientasi;
    ?>
    <style>
        @media print {
            @page { size: <?= htmlspecialchars($ukuranCetak) ?>; margin: 12mm; }
        }
    </style>
</head>
<body class="management-page report-page">
<?php include 'navbar.php'; ?>

<main class="management-container">
    <header class="management-heading report-heading">
        <div>
            <span class="management-eyebrow"><i class="bi bi-file-earmark-bar-graph"></i> ANALISIS TOKO</span>
            <h1>Laporan transaksi</h1>
            <p>Lihat transaksi dan total penjualan berdasarkan kasir.</p>
        </div>
        <?php if ($kasirId > 0 && $jumlahTransaksi > 0): ?>
            <div class="report-actions no-print">
                <a href="laporan_pdf.php?kasir=<?= (int)$kasirId ?>&format=<?= htmlspecialchars($formatKertas) ?>&orientasi=<?= htmlspecialchars($orientasi) ?>" class="management-outline-button" target="_blank" rel="noopener"><i class="bi bi-eye"></i> Pratinjau PDF</a>
                <button type="button" class="management-outline-button" onclick="window.print()"><i class="bi bi-printer"></i> Cetak rekap</button>
            </div>
        <?php endif; ?>
    </header>

    <section class="management-panel report-filter-panel no-print">
        <div class="management-panel-heading">
            <span class="management-panel-icon"><i class="bi bi-funnel"></i></span>
            <div>
                <h2><?= $isKasir ? 'Laporan kasir Anda' : 'Filter berdasarkan kasir' ?></h2>
                <p><?= $isKasir ? 'Laporan ini hanya menampilkan transaksi akun Anda.' : 'Pilih satu kasir untuk melihat laporan dan rincian transaksinya.' ?></p>
            </div>
        </div>
        <form method="get" class="report-filter-form <?= $isKasir ? 'is-cashier' : 'is-admin' ?>">
            <?php if (!$isKasir): ?>
                <div class="report-filter-field">
                    <label for="report-cashier">Kasir</label>
                    <select id="report-cashier" name="kasir" class="form-select" required>
                        <option value="" <?= $kasirId === 0 ? 'selected' : '' ?> disabled>Pilih kasir</option>
                        <?php while ($k = $kasir->fetch_assoc()):
                            $namaKasir = $k['nama'] ?: $k['username'];
                        ?>
                            <option value="<?= (int)$k['id_user'] ?>" <?= $kasirId === (int)$k['id_user'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($namaKasir) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="report-filter-field">
                <label for="report-format">Ukuran kertas</label>
                <select id="report-format" name="format" class="form-select">
                    <?php foreach ($namaUkuran as $kode => $label): ?>
                        <option value="<?= htmlspecialchars($kode) ?>" <?= $formatKertas === $kode ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="report-filter-field">
                <label for="report-orientation">Orientasi</label>
                <select id="report-orientation" name="orientasi" class="form-select">
                    <option value="portrait" <?= $orientasi === 'portrait' ? 'selected' : '' ?>>Portrait (tegak)</option>
                    <option value="landscape" <?= $orientasi === 'landscape' ? 'selected' : '' ?>>Landscape (mendatar)</option>
                </select>
            </div>
            <button class="management-primary-button report-filter-submit"><i class="bi bi-bar-chart-line"></i> Tampilkan laporan</button>
        </form>
    </section>

    <?php if ($kasirId > 0 && !$kolomKasirTersedia): ?>
        <div class="management-alert">Kolom data kasir belum dapat dibuat. Jalankan migrasi database <code>upgrade_kasir_transaksi.sql</code>, lalu muat ulang halaman.</div>
    <?php elseif ($kasirId === 0): ?>
        <div class="management-alert">Pilih kasir untuk melihat laporan transaksi masing-masing kasir.</div>
    <?php elseif ($kasirId > 0): ?>
        <header class="print-report-header">
            <div class="print-report-brand">
                <span><i class="bi bi-shop-window"></i></span>
                <div><strong>TOKOKU</strong><small>Manajemen Toko</small></div>
            </div>
            <div class="print-report-title">
                <strong>LAPORAN TRANSAKSI</strong>
                <small>Dicetak <?= date('d/m/Y H:i') ?></small>
            </div>
        </header>
        <section class="report-summary">
            <div class="report-customer-card">
                <span class="management-avatar"><i class="bi bi-person"></i></span>
                <span>
                    <small>LAPORAN KASIR</small>
                    <strong><?= htmlspecialchars($namaKasirDipilih ?: 'Kasir #' . (int)$kasirId) ?></strong>
                    <em><i class="bi bi-filter"></i> Rincian transaksi kasir ini</em>
                </span>
            </div>
            <div class="report-metric"><small>Jumlah transaksi</small><strong><?= number_format($jumlahTransaksi, 0, ',', '.') ?></strong></div>
            <div class="report-metric is-total"><small>Total belanja</small><strong><?= rupiah($grandTotal) ?></strong></div>
        </section>

        <section class="management-panel report-results">
            <div class="management-panel-heading">
                <span class="management-panel-icon"><i class="bi bi-receipt"></i></span>
                <div><h2>Rincian transaksi</h2><p><?= $jumlahTransaksi ? 'Daftar transaksi milik kasir yang dipilih.' : 'Belum ada transaksi untuk kasir ini.' ?></p></div>
            </div>
            <div class="management-table-wrap">
                <table class="table management-table align-middle mb-0">
                    <thead><tr><th>Transaksi</th><th>Tanggal</th><th>Pelanggan</th><th>Kasir</th><th>Metode pembayaran</th><th class="text-end">Total belanja</th><th class="text-end no-print">Detail</th></tr></thead>
                    <tbody>
                    <?php if ($rows): ?>
                        <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><strong class="transaction-number">#<?= (int)$row['id_transaksi'] ?></strong></td>
                            <td><?= htmlspecialchars(date('d/m/Y', strtotime($row['tanggal']))) ?></td>
                            <td><?= htmlspecialchars($row['nama_pelanggan'] ?: 'Belanja pelanggan') ?></td>
                            <td><?= htmlspecialchars($row['nama_kasir']) ?></td>
                            <td><span class="management-payment"><?= htmlspecialchars(strtoupper($row['metode_pembayaran'] ?? 'tunai')) ?></span></td>
                            <td class="text-end management-money"><?= rupiah($row['total_belanja']) ?></td>
                            <td class="text-end no-print"><a href="detail_transaksi.php?id=<?= (int)$row['id_transaksi'] ?>" class="management-detail-link" aria-label="Detail transaksi #<?= (int)$row['id_transaksi'] ?>"><i class="bi bi-arrow-up-right"></i></a></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="7"><div class="management-empty"><i class="bi bi-receipt"></i><strong>Belum ada transaksi</strong><span>Transaksi sesuai filter akan ditampilkan di sini.</span></div></td></tr>
                    <?php endif; ?>
                    </tbody>
                    <?php if ($rows): ?>
                    <tfoot><tr><th colspan="5" class="text-end">Total keseluruhan</th><th class="text-end management-money"><?= rupiah($grandTotal) ?></th><th class="no-print"></th></tr></tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </section>
        <footer class="print-report-footer">
            <div><span>Mengetahui,</span><strong></strong><small>Nama & tanda tangan</small></div>
            <div><span>Petugas,</span><strong></strong><small><?= htmlspecialchars($_SESSION['nama_lengkap'] ?? $_SESSION['username'] ?? 'Pengguna') ?></small></div>
        </footer>
    <?php endif; ?>
</main>
</body>
</html>
