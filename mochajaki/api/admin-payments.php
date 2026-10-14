<?php
/**
 * Payment verification (admin only).
 *
 * PATCH body: { order_id, status }  with status = Paid | Failed | Refunded
 *
 *   Cash orders    → 'Paid' records that the money was handed over (there was
 *                    previously no way to do this: cash stayed pending for ever)
 *   E-wallet      → 'Paid' needs a reference number the customer submitted,
 *                    'Failed' rejects it, 'Refunded' reverses a confirmed one
 *
 * Every change records who did it and when (payments.verified_by/verified_at).
 */
require __DIR__ . '/config.php';

$admin = require_admin();
method_is('PATCH');

$data   = body_json();
$orderId = (int) ($data['order_id'] ?? 0);
$status  = (string) ($data['status'] ?? '');

if ($orderId < 1 || !in_array($status, ['Paid', 'Failed', 'Refunded'], true)) {
    json_out(['error' => 'Invalid payment update.'], 422);
}

$stmt = $pdo->prepare(
    'SELECT p.id, p.method, p.status, p.gateway_reference
     FROM payments p
     WHERE p.order_id = ?
     ORDER BY p.id DESC
     LIMIT 1'
);
$stmt->execute([$orderId]);
$payment = $stmt->fetch();

if (!$payment) {
    json_out(['error' => 'No payment record for this order.'], 404);
}

$method         = (string) $payment['method'];
$currentStatus  = (string) $payment['status'];
$hasReference   = trim((string) $payment['gateway_reference']) !== '';

if ($method === 'Cash') {
    if ($status !== 'Paid') {
        json_out(['error' => 'Cash payments can only be marked as received.'], 422);
    }
} elseif ($status === 'Paid' && !$hasReference) {
    json_out(['error' => 'The customer has not submitted a reference number yet.'], 422);
} elseif ($status === 'Refunded' && $currentStatus !== 'Paid') {
    json_out(['error' => 'Only a confirmed payment can be refunded.'], 422);
}

$stmt = $pdo->prepare('UPDATE payments SET status = ?, verified_by = ?, verified_at = NOW() WHERE id = ?');
$stmt->execute([$status, $admin['id'], $payment['id']]);

json_out([
    'ok'      => true,
    'status'  => $status,
    'message' => match ($status) {
        'Paid'     => $method === 'Cash' ? 'Cash marked as received.' : 'Payment confirmed.',
        'Failed'   => 'Payment rejected. The customer can submit a new reference.',
        'Refunded' => 'Payment marked as refunded.',
    },
]);
