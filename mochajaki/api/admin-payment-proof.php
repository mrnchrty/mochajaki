<?php
/**
 * Streams a payment screenshot to a signed-in administrator.
 *
 * Screenshots contain personal information (names, e-wallet numbers), so the
 * uploads folder denies direct web access and admins read the file through this
 * endpoint instead. The stored path is re-checked against the uploads folder so
 * a tampered database value cannot read files elsewhere.
 *
 * GET api/admin-payment-proof.php?id=<payment id>
 */
require __DIR__ . '/config.php';

require_admin();

$paymentId = (int) ($_GET['id'] ?? 0);
if ($paymentId < 1) {
    json_out(['error' => 'Invalid payment.'], 422);
}

$stmt = $pdo->prepare('SELECT payment_screenshot FROM payments WHERE id = ?');
$stmt->execute([$paymentId]);
$storedPath = (string) ($stmt->fetchColumn() ?: '');

if ($storedPath === '') {
    json_out(['error' => 'No screenshot was uploaded for this payment.'], 404);
}

$uploadsDir = realpath(dirname(__DIR__) . '/assets/payments/uploads');
$filePath   = realpath(dirname(__DIR__) . '/' . ltrim($storedPath, '/'));

$insideUploads = $uploadsDir !== false
    && $filePath !== false
    && str_starts_with($filePath, $uploadsDir . DIRECTORY_SEPARATOR);

if (!$insideUploads) {
    json_out(['error' => 'Screenshot not found.'], 404);
}

$mimeTypes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
$mimeType  = $mimeTypes[strtolower((string) pathinfo($filePath, PATHINFO_EXTENSION))] ?? null;

if ($mimeType === null) {
    json_out(['error' => 'Unsupported screenshot format.'], 415);
}

// Replace the JSON headers set by config.php with the image ones.
header('Content-Type: ' . $mimeType);
header('Content-Disposition: inline; filename="payment-proof-' . $paymentId . '"');
header('Content-Length: ' . (string) filesize($filePath));
header('Cache-Control: private, no-store');

readfile($filePath);
exit;
