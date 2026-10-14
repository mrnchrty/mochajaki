<?php
/**
 * Customer submits GCash/Maya payment details for one of their orders.
 * Multipart form post: order_id, reference, optional screenshot file.
 *
 * The payment goes back to 'Pending Verification' for an admin to check. It can
 * only be submitted while the payment is unpaid, and never for a cancelled
 * order, so a customer cannot undo an admin's verification.
 */
require __DIR__ . '/config.php';

$user = require_login();
method_is('POST');

$orderId   = (int) ($_POST['order_id'] ?? 0);
$reference = trim((string) ($_POST['reference'] ?? ''));

if ($orderId < 1) {
    json_out(['error' => 'Invalid order.'], 422);
}
if (mb_strlen($reference) < 4 || mb_strlen($reference) > 190) {
    json_out(['error' => 'Enter the reference number from your e-wallet receipt (4–190 characters).'], 422);
}

$stmt = $pdo->prepare(
    'SELECT o.id, o.status, p.id AS payment_id, p.method, p.status AS payment_status
     FROM orders o
     JOIN payments p ON p.order_id = o.id
     WHERE o.id = ? AND o.customer_id = ?
     ORDER BY p.id DESC
     LIMIT 1'
);
$stmt->execute([$orderId, $user['id']]);
$order = $stmt->fetch();

if (!$order) {
    json_out(['error' => 'Order not found.'], 404);
}
if (!in_array($order['method'], ONLINE_PAYMENT_METHODS, true)) {
    json_out(['error' => 'This order is paid in cash.'], 422);
}
if ($order['status'] === 'Cancelled') {
    json_out(['error' => 'This order was cancelled, so payment details can no longer be submitted.'], 409);
}
if (in_array((string) $order['payment_status'], ['Paid', 'Refunded'], true)) {
    json_out(['error' => 'This payment is already settled. Message the store if something looks wrong.'], 409);
}

/* ── Optional screenshot ─────────────────────────────────────────────── */

$storedPath = null;
if (isset($_FILES['screenshot']) && (int) $_FILES['screenshot']['error'] !== UPLOAD_ERR_NO_FILE) {
    $file = $_FILES['screenshot'];

    if ((int) $file['error'] !== UPLOAD_ERR_OK || (int) $file['size'] > 5 * 1024 * 1024) {
        json_out(['error' => 'The screenshot must be an image under 5 MB.'], 422);
    }

    // Trust the file's contents, not its name.
    $mime    = (new finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $extension  = $extensions[$mime] ?? null;

    if ($extension === null || @getimagesize((string) $file['tmp_name']) === false) {
        json_out(['error' => 'Upload a JPG, PNG or WebP image.'], 422);
    }

    $uploadDir = dirname(__DIR__) . '/assets/payments/uploads';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0750, true)) {
        json_out(['error' => 'Unable to store the screenshot.'], 500);
    }

    // Random name: the URL cannot be guessed, and the folder denies direct
    // access anyway (assets/payments/uploads/.htaccess) — admins read it
    // through api/admin-payment-proof.php.
    $fileName = 'payment-' . $order['payment_id'] . '-' . bin2hex(random_bytes(16)) . '.' . $extension;
    if (!move_uploaded_file((string) $file['tmp_name'], $uploadDir . '/' . $fileName)) {
        json_out(['error' => 'Unable to store the screenshot.'], 500);
    }

    $storedPath = 'assets/payments/uploads/' . $fileName;
}

/* ── Save ────────────────────────────────────────────────────────────── */

$stmt = $pdo->prepare(
    "UPDATE payments
     SET gateway_reference = ?, payment_screenshot = COALESCE(?, payment_screenshot),
         status = 'Pending Verification', verified_by = NULL, verified_at = NULL
     WHERE id = ? AND status NOT IN ('Paid', 'Refunded')"
);
$stmt->execute([$reference, $storedPath, $order['payment_id']]);

json_out(['ok' => true, 'payment_status' => 'Pending Verification']);
