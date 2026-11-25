<?php
require 'config.php';

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$id = $id && $id > 0 ? $id : 0;
$kasirId = filter_var($_GET['kasir'] ?? null, FILTER_VALIDATE_INT);
$kasirId = $kasirId && $kasirId > 0 ? $kasirId : 0;
$isKasir = ($_SESSION['role'] ?? '') === 'kasir';
if ($isKasir) {
    $kasirId = (int)($_SESSION['user_id'] ?? 0);
}
if ($kasirId > 0 && !$kolomKasirTersedia) {
    http_response_code(503);
    exit('Data kasir belum tersedia. Jalankan upgrade_kasir_transaksi.sql terlebih dahulu.');
}
$format = in_array($_GET['format'] ?? '', ['a4', 'f4', 'letter', 'legal'], true)
    ? $_GET['format'] : 'a4';
$orientasi = in_array($_GET['orientasi'] ?? '', ['portrait', 'landscape'], true)
    ? $_GET['orientasi'] : 'portrait';

if ($id <= 0 && $kasirId <= 0) {
    http_response_code(400);
    exit('Pilih pelanggan atau kasir.');
}

$pelanggan = null;
if ($id > 0) {
    $stmt = $conn->prepare(
        "SELECT id_pelanggan, nama_pelanggan, kota
         FROM tbl_pelanggan
         WHERE id_pelanggan = ?"
    );
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $pelanggan = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$pelanggan) {
        http_response_code(404);
        exit('Pelanggan tidak ditemukan.');
    }
}

if ($kolomKasirTersedia) {
    $sql = "SELECT t.id_transaksi, t.tanggal, t.total_belanja, t.metode_pembayaran,
                   p.nama_pelanggan,
                   COALESCE(u.nama, u.username, 'Tidak tercatat') AS nama_kasir
            FROM tbl_transaksi t
            LEFT JOIN tbl_pelanggan p ON p.id_pelanggan = t.id_pelanggan
            LEFT JOIN tbl_user u ON u.id_user = t.id_user";
} else {
    $sql = "SELECT t.id_transaksi, t.tanggal, t.total_belanja, t.metode_pembayaran,
                   p.nama_pelanggan,
                   'Tidak tercatat' AS nama_kasir
            FROM tbl_transaksi t
            LEFT JOIN tbl_pelanggan p ON p.id_pelanggan = t.id_pelanggan";
}
$conditions = [];
$types = '';
$values = [];
if ($id > 0) {
    $conditions[] = "t.id_pelanggan = ?";
    $types .= 'i';
    $values[] = $id;
}
if ($kasirId > 0 && $kolomKasirTersedia) {
    $conditions[] = "t.id_user = ?";
    $types .= 'i';
    $values[] = $kasirId;
}
if ($conditions) $sql .= " WHERE " . implode(" AND ", $conditions);
$sql .= $conditions ? " AND t.status_pembayaran = 'terverifikasi'" : " WHERE t.status_pembayaran = 'terverifikasi'";
$sql .= " ORDER BY t.tanggal DESC, t.id_transaksi DESC";
$stmt = $conn->prepare($sql);
if ($values) $stmt->bind_param($types, ...$values);
$stmt->execute();
$result = $stmt->get_result();
$rows = [];
$grandTotal = 0;
while ($row = $result->fetch_assoc()) {
    $rows[] = $row;
    $grandTotal += (float)$row['total_belanja'];
}
$stmt->close();

function pdfText($text) {
    $text = (string)$text;
    $encoded = function_exists('iconv')
        ? iconv('UTF-8', 'Windows-1252//TRANSLIT', $text)
        : $text;
    $encoded = $encoded === false ? $text : $encoded;
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $encoded);
}

function pdfDrawText(&$content, $text, $x, $y, $size = 10, $font = 'F1', $color = [0.18, 0.22, 0.19]) {
    $content .= implode(' ', $color) . " rg\n";
    $content .= "BT /{$font} {$size} Tf 1 0 0 1 {$x} {$y} Tm (" . pdfText($text) . ") Tj ET\n";
}

function pdfDrawRightText(&$content, $text, $right, $y, $size = 10, $font = 'F1', $color = [0.18, 0.22, 0.19]) {
    $text = (string)$text;
    $width = strlen(pdfText($text)) * $size * 0.52;
    pdfDrawText($content, $text, $right - $width, $y, $size, $font, $color);
}

function pdfFillRect(&$content, $x, $y, $width, $height, $color) {
    $content .= implode(' ', $color) . " rg\n{$x} {$y} {$width} {$height} re f\n";
}

function pdfShortText($text, $maxLength) {
    $text = (string)$text;
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($text, 'UTF-8') > $maxLength
            ? mb_substr($text, 0, $maxLength - 1, 'UTF-8') . '…'
            : $text;
    }

    return strlen($text) > $maxLength
        ? substr($text, 0, $maxLength - 3) . '...'
        : $text;
}

function pdfLine(&$content, $x1, $y1, $x2, $y2, $width = 0.5, $color = [0.82, 0.85, 0.81]) {
    $content .= implode(' ', $color) . " RG\n{$width} w {$x1} {$y1} m {$x2} {$y2} l S\n";
}

function rupiahPdf($value) {
    return 'Rp ' . number_format((float)$value, 0, ',', '.');
}

$ukuranPortrait = [
    'a4' => [595.28, 841.89],
    'f4' => [595.28, 935.43],
    'letter' => [612, 792],
    'legal' => [612, 1008],
];
[$pageWidth, $pageHeight] = $ukuranPortrait[$format];
if ($orientasi === 'landscape') {
    [$pageWidth, $pageHeight] = [$pageHeight, $pageWidth];
}
$namaKasirPdf = 'Semua kasir';
if ($kasirId > 0) {
    $stmtKasir = $conn->prepare("SELECT nama, username FROM tbl_user WHERE id_user = ?");
    $stmtKasir->bind_param("i", $kasirId);
    $stmtKasir->execute();
    $dataKasir = $stmtKasir->get_result()->fetch_assoc();
    $stmtKasir->close();
    $namaKasirPdf = $dataKasir
        ? ($dataKasir['nama'] ?: $dataKasir['username'])
        : ('Kasir #' . $kasirId);
}
$namaPelangganPdf = $pelanggan ? $pelanggan['nama_pelanggan'] : 'Semua pelanggan';
$rowHeight = $orientasi === 'landscape' ? 17 : 20;
$rowStart = $pageHeight - 240;
$barisPerHalaman = max(1, (int)floor(($rowStart - 84) / $rowHeight));
$pages = array_chunk($rows, $barisPerHalaman);
if (!$pages) {
    $pages = [[]];
}

$objects = [];
$objects[1] = '';
$objects[2] = '';
$objects[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
$objects[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

$pageIds = [];
$nextObject = 5;
foreach ($pages as $pageIndex => $pageRows) {
    $contentId = $nextObject++;
    $pageId = $nextObject++;
    $pageIds[] = $pageId;

    $content = '';
    $margin = 42;
    $right = $pageWidth - $margin;
    $usableWidth = $right - $margin;
    $headerBottom = $pageHeight - 96;
    pdfFillRect($content, 0, $headerBottom, $pageWidth, 96, [0.16, 0.31, 0.22]);
    pdfFillRect($content, $margin, $pageHeight - 75, 43, 43, [1, 1, 1]);
    pdfDrawText($content, 'T', $margin + 13, $pageHeight - 62, 22, 'F2', [0.16, 0.31, 0.22]);
    pdfDrawText($content, 'TOKOKU', $margin + 56, $pageHeight - 48, 20, 'F2', [1, 1, 1]);
    pdfDrawText($content, 'MANAJEMEN TOKO', $margin + 57, $pageHeight - 66, 8, 'F2', [0.86, 0.92, 0.86]);
    pdfDrawRightText($content, 'LAPORAN TRANSAKSI', $right, $pageHeight - 48, 12, 'F2', [1, 1, 1]);
    pdfDrawRightText($content, 'Dicetak ' . date('d/m/Y H:i'), $right, $pageHeight - 67, 8, 'F1', [0.86, 0.92, 0.86]);

    $cardY = $pageHeight - 184;
    pdfFillRect($content, $margin, $cardY, $usableWidth, 72, [0.96, 0.97, 0.95]);
    pdfDrawText($content, 'FILTER LAPORAN', $margin + 14, $cardY + 53, 7, 'F2', [0.39, 0.48, 0.40]);
    pdfDrawText($content, 'Pelanggan: ' . pdfShortText($namaPelangganPdf, 34), $margin + 14, $cardY + 35, 9, 'F2');
    pdfDrawText($content, 'Kasir: ' . pdfShortText($namaKasirPdf, 34), $margin + 14, $cardY + 18, 9);
    $summaryX = $margin + $usableWidth * 0.62;
    pdfDrawText($content, 'JUMLAH TRANSAKSI', $summaryX, $cardY + 53, 7, 'F2', [0.39, 0.48, 0.40]);
    pdfDrawText($content, number_format(count($rows), 0, ',', '.') . ' transaksi', $summaryX, $cardY + 34, 10, 'F2');
    pdfDrawText($content, 'TOTAL PENJUALAN', $summaryX, $cardY + 17, 7, 'F2', [0.39, 0.48, 0.40]);
    pdfDrawText($content, rupiahPdf($grandTotal), $summaryX + 82, $cardY + 17, 9, 'F2', [0.16, 0.39, 0.24]);

    $headerBaseline = $pageHeight - 212;
    $positions = [
        $margin + 8,
        $margin + $usableWidth * 0.09,
        $margin + $usableWidth * 0.21,
        $margin + $usableWidth * 0.49,
        $margin + $usableWidth * 0.72,
        $margin + $usableWidth * 0.84,
    ];
    pdfFillRect($content, $margin, $headerBaseline - 8, $usableWidth, 24, [0.16, 0.31, 0.22]);
    pdfDrawText($content, 'NO', $positions[0], $headerBaseline, 7, 'F2', [1, 1, 1]);
    pdfDrawText($content, 'TANGGAL', $positions[1], $headerBaseline, 7, 'F2', [1, 1, 1]);
    pdfDrawText($content, 'PELANGGAN', $positions[2], $headerBaseline, 7, 'F2', [1, 1, 1]);
    pdfDrawText($content, 'KASIR', $positions[3], $headerBaseline, 7, 'F2', [1, 1, 1]);
    pdfDrawText($content, 'METODE', $positions[4], $headerBaseline, 7, 'F2', [1, 1, 1]);
    pdfDrawText($content, 'TOTAL', $positions[5], $headerBaseline, 7, 'F2', [1, 1, 1]);

    $y = $rowStart;
    foreach ($pageRows as $offset => $row) {
        $number = $pageIndex * $barisPerHalaman + $offset + 1;
        if ($offset % 2 === 1) {
            pdfFillRect($content, $margin, $y - 6, $usableWidth, $rowHeight, [0.97, 0.98, 0.97]);
        }
        pdfDrawText($content, (string)$number, $positions[0], $y, 8);
        pdfDrawText($content, date('d/m/Y', strtotime($row['tanggal'])), $positions[1], $y, 8);
        pdfDrawText($content, pdfShortText($row['nama_pelanggan'] ?: 'Belanja pelanggan', 20), $positions[2], $y, 8);
        pdfDrawText($content, pdfShortText($row['nama_kasir'], 16), $positions[3], $y, 8);
        pdfDrawText($content, strtoupper($row['metode_pembayaran'] ?? 'TUNAI'), $positions[4], $y, 8);
        pdfDrawRightText($content, rupiahPdf($row['total_belanja']), $right - 6, $y, 8, 'F1', [0.16, 0.39, 0.24]);
        $y -= $rowHeight;
    }
    $summaryBaseline = max(72, $y - 14);

    if ($pageIndex === count($pages) - 1) {
        pdfFillRect($content, $margin, $summaryBaseline - 9, $usableWidth, 26, [0.94, 0.97, 0.93]);
        $grandTotalText = rupiahPdf($grandTotal);
        $totalLabel = 'TOTAL KESELURUHAN :';
        $totalLabelWidth = strlen(pdfText($totalLabel)) * 9 * 0.52;
        $totalValueWidth = strlen(pdfText($grandTotalText)) * 10 * 0.52;
        $totalGroupGap = 24;
        $totalGroupWidth = $totalLabelWidth + $totalGroupGap + $totalValueWidth;
        $totalGroupStart = ($pageWidth - $totalGroupWidth) / 2;
        pdfDrawText($content, $totalLabel, $totalGroupStart, $summaryBaseline - 1, 9, 'F2');
        pdfDrawText($content, $grandTotalText, $totalGroupStart + $totalLabelWidth + $totalGroupGap, $summaryBaseline - 1, 10, 'F2', [0.16, 0.39, 0.24]);
    } else {
        pdfLine($content, $margin, $summaryBaseline + 12, $right, $summaryBaseline + 12, 0.7);
    }

    pdfLine($content, $margin, 52, $right, 52, 0.4);
    pdfDrawText($content, 'TOKOKU  |  Laporan transaksi', $margin, 36, 7, 'F1', [0.48, 0.52, 0.48]);
    pdfDrawRightText($content, 'Halaman ' . ($pageIndex + 1) . ' dari ' . count($pages), $right, 36, 7, 'F1', [0.48, 0.52, 0.48]);

    $objects[$contentId] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream";
    $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 " .
        number_format($pageWidth, 2, '.', '') . " " . number_format($pageHeight, 2, '.', '') .
        "] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents {$contentId} 0 R >>";
}

$objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
$objects[2] = "<< /Type /Pages /Kids [" . implode(' 0 R ', $pageIds) . " 0 R] /Count " . count($pageIds) . " >>";

$pdf = "%PDF-1.4\n";
$offsets = [0];
for ($i = 1; $i <= max(array_keys($objects)); $i++) {
    if (!isset($objects[$i])) {
        continue;
    }
    $offsets[$i] = strlen($pdf);
    $pdf .= "{$i} 0 obj\n" . $objects[$i] . "\nendobj\n";
}

$xref = strlen($pdf);
$objectCount = max(array_keys($objects));
$pdf .= "xref\n0 " . ($objectCount + 1) . "\n";
$pdf .= "0000000000 65535 f \n";
for ($i = 1; $i <= $objectCount; $i++) {
    $pdf .= isset($offsets[$i])
        ? sprintf("%010d 00000 n \n", $offsets[$i])
        : "0000000000 00000 f \n";
}
$pdf .= "trailer\n<< /Size " . ($objectCount + 1) . " /Root 1 0 R >>\n";
$pdf .= "startxref\n{$xref}\n%%EOF";

$safeNameSource = $pelanggan ? $pelanggan['nama_pelanggan'] : ($kasirId > 0 ? 'kasir-' . $kasirId : 'semua');
$safeName = preg_replace('/[^A-Za-z0-9_-]+/', '-', $safeNameSource);
$safeName = trim($safeName ?: 'pelanggan', '-');

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="laporan-' . $safeName . '-' . date('Y-m-d') . '.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
