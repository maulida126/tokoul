<?php
require 'config.php';

if (!in_array($_SESSION['role'] ?? '', ['admin', 'kasir'], true)) {
    http_response_code(403);
    exit('Akun ini tidak berwenang memverifikasi pembayaran.');
}

$pesan = '';
$isSukses = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        $pesan = 'Form sudah kedaluwarsa. Muat ulang halaman lalu coba kembali.';
    } else {
        $idTransaksi = filter_var($_POST['id_transaksi'] ?? null, FILTER_VALIDATE_INT);
        if (!$idTransaksi || $idTransaksi < 1) {
            $pesan = 'Transaksi yang dipilih tidak valid.';
        } else {
            $idVerifikator = (int)($_SESSION['user_id'] ?? 0);
            $stmt = $conn->prepare(
                "UPDATE tbl_transaksi
                 SET status_pembayaran = 'terverifikasi',
                     diverifikasi_oleh = ?,
                     diverifikasi_pada = NOW(),
                     id_user = COALESCE(id_user, ?)
                 WHERE id_transaksi = ?
                   AND metode_pembayaran = 'qris'
                   AND status_pembayaran = 'menunggu_verifikasi'"
            );
            $stmt->bind_param("iii", $idVerifikator, $idVerifikator, $idTransaksi);
            $stmt->execute();
            $berhasilVerifikasi = $stmt->affected_rows === 1;
            $stmt->close();

            if ($berhasilVerifikasi) {
                header('Location: verifikasi_qris.php?diverifikasi=' . $idTransaksi);
                exit;
            }
            $pesan = 'Transaksi tidak ditemukan atau sudah diverifikasi sebelumnya.';
        }
    }
}

if (isset($_GET['diverifikasi'])) {
    $idTransaksi = filter_var($_GET['diverifikasi'], FILTER_VALIDATE_INT);
    if ($idTransaksi && $idTransaksi > 0) {
        $idVerifikator = (int)($_SESSION['user_id'] ?? 0);
        $stmt = $conn->prepare(
            "SELECT id_transaksi FROM tbl_transaksi
             WHERE id_transaksi = ?
               AND metode_pembayaran = 'qris'
               AND status_pembayaran = 'terverifikasi'
               AND diverifikasi_oleh = ?"
        );
        $stmt->bind_param("ii", $idTransaksi, $idVerifikator);
        $stmt->execute();
        $isSukses = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($isSukses) {
            $pesan = 'Pembayaran QRIS transaksi #' . $idTransaksi . ' berhasil diverifikasi.';
        }
    }
}

$transaksiMenunggu = $conn->query(
    "SELECT t.id_transaksi, t.tanggal, t.total_belanja,
            p.nama_pelanggan, p.kota
     FROM tbl_transaksi t
     LEFT JOIN tbl_pelanggan p ON p.id_pelanggan = t.id_pelanggan
     WHERE t.metode_pembayaran = 'qris'
       AND t.status_pembayaran = 'menunggu_verifikasi'
     ORDER BY t.tanggal ASC, t.id_transaksi ASC"
);
$jumlahMenunggu = $transaksiMenunggu->num_rows;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifikasi QRIS - TokoKu</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css?v=<?= (int)filemtime(__DIR__ . '/style.css') ?>">
</head>
<body class="management-page">
<?php include 'navbar.php'; ?>

<main class="management-container">
    <header class="management-heading">
        <div>
            <span class="management-eyebrow"><i class="bi bi-qr-code-scan"></i> PEMBAYARAN DIGITAL</span>
            <h1>Verifikasi QRIS</h1>
            <p>Periksa dana masuk di aplikasi atau mutasi toko sebelum mengonfirmasi pembayaran.</p>
        </div>
        <span class="management-count"><i class="bi bi-hourglass-split"></i> <?= number_format($jumlahMenunggu, 0, ',', '.') ?> menunggu</span>
    </header>

    <?php if ($pesan !== ''): ?>
        <div class="management-alert <?= $isSukses ? 'is-success' : '' ?>"><?= htmlspecialchars($pesan) ?></div>
    <?php endif; ?>

    <section class="management-panel transaction-history-panel">
        <div class="management-panel-heading">
            <span class="management-panel-icon"><i class="bi bi-receipt"></i></span>
            <div>
                <h2>Pesanan menunggu verifikasi</h2>
                <p>Verifikasi hanya setelah transaksi QRIS benar-benar terlihat masuk.</p>
            </div>
        </div>
        <div class="management-table-wrap">
            <table class="table management-table align-middle mb-0">
                <thead>
                    <tr><th>Transaksi</th><th>Tanggal</th><th>Pelanggan</th><th class="text-end">Total</th><th class="text-end">Tindakan</th></tr>
                </thead>
                <tbody>
                <?php if ($jumlahMenunggu > 0): ?>
                    <?php while ($row = $transaksiMenunggu->fetch_assoc()): ?>
                        <tr>
                            <td><strong class="transaction-number">#<?= (int)$row['id_transaksi'] ?></strong></td>
                            <td><?= htmlspecialchars(date('d/m/Y', strtotime($row['tanggal']))) ?></td>
                            <td>
                                <span class="management-person">
                                    <span class="management-avatar"><i class="bi bi-person"></i></span>
                                    <span>
                                        <strong><?= htmlspecialchars($row['nama_pelanggan'] ?? 'Pelanggan') ?></strong>
                                        <small><?= htmlspecialchars($row['kota'] ?? '') ?></small>
                                    </span>
                                </span>
                            </td>
                            <td class="text-end management-money"><?= rupiah($row['total_belanja']) ?></td>
                            <td class="text-end">
                                <a href="detail_transaksi.php?id=<?= (int)$row['id_transaksi'] ?>" class="management-detail-link" aria-label="Rincian transaksi #<?= (int)$row['id_transaksi'] ?>">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                                    <input type="hidden" name="id_transaksi" value="<?= (int)$row['id_transaksi'] ?>">
                                    <button type="submit" class="management-primary-button btn-sm ms-1" onclick="return confirm('Pastikan pembayaran QRIS transaksi #<?= (int)$row['id_transaksi'] ?> sudah masuk sebelum memverifikasi.');">
                                        Verifikasi
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5">
                            <div class="management-empty">
                                <i class="bi bi-check2-circle"></i>
                                <strong>Tidak ada pembayaran yang menunggu</strong>
                                <span>Pesanan QRIS baru akan muncul di sini untuk diperiksa.</span>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>
