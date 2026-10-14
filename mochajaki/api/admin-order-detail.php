<?php
/**
 * Everything the admin panel shows for one order (admin only).
 *
 * GET api/admin-order-detail.php?id=<order id>
 *
 * Item option text is rebuilt from the stored customizations, and "verified by"
 * is included so the panel can show who confirmed a payment and when.
 */
require __DIR__ . '/config.php';

require_admin();

$orderId = (int) ($_GET['id'] ?? 0);
if ($orderId < 1) {
    json_out(['error' => 'Invalid order.'], 422);
}

$stmt = $pdo->prepare(
    'SELECT o.*, u.full_name, u.email
     FROM orders o
     JOIN users u ON u.id = o.customer_id
     WHERE o.id = ?'
);
$stmt->execute([$orderId]);
$order = $stmt->fetch();

if (!$order) {
    json_out(['error' => 'Order not found.'], 404);
}

/* ── Items, with the product row when it still exists ─────────────────── */

$stmt = $pdo->prepare(
    'SELECT oi.id, oi.product_id, oi.product_name, oi.quantity, oi.unit_price, oi.line_total,
            CAST(oi.customizations AS CHAR) AS customizations_json,
            pr.name AS current_name, pr.category AS current_category, pr.price AS current_price
     FROM order_items oi
     LEFT JOIN products pr ON pr.id = oi.product_id
     WHERE oi.order_id = ?
     ORDER BY oi.id'
);
$stmt->execute([$orderId]);
$items = $stmt->fetchAll();

foreach ($items as &$item) {
    $custom = json_decode((string) ($item['customizations_json'] ?? '{}'), true);
    if (!is_array($custom)) {
        $custom = [];
    }

    // A deleted product has no rule set any more, so customization_text()
    // falls back to the generic labels and the raw stored values.
    $product = $item['current_name'] !== null ? [
        'name'     => (string) $item['current_name'],
        'category' => (string) $item['current_category'],
        'price'    => (float) $item['current_price'],
    ] : null;

    $item['customizations_text'] = customization_text($product, $custom);
    $item['customizations']      = $custom;
    unset($item['customizations_json']);
    $item['quantity']   = (int) $item['quantity'];
    $item['unit_price'] = (float) $item['unit_price'];
    $item['line_total'] = (float) $item['line_total'];
}
unset($item);

$order['items'] = $items;

/* ── Latest payment, including who verified it ────────────────────────── */

$stmt = $pdo->prepare(
    'SELECT p.id, p.method, p.amount, p.status, p.gateway_reference, p.payment_screenshot,
            p.created_at, p.verified_at, v.full_name AS verified_by_name
     FROM payments p
     LEFT JOIN users v ON v.id = p.verified_by
     WHERE p.order_id = ?
     ORDER BY p.id DESC
     LIMIT 1'
);
$stmt->execute([$orderId]);
$payment = $stmt->fetch() ?: null;

if ($payment) {
    $payment['id']                  = (int) $payment['id'];
    $payment['amount']              = (float) $payment['amount'];
    $payment['has_screenshot']      = $payment['payment_screenshot'] !== null;
    $payment['can_be_marked_paid']  = $payment['method'] === 'Cash'
        || trim((string) $payment['gateway_reference']) !== '';
    $payment['can_be_refunded']     = $payment['status'] === 'Paid';
    // Exactly what the panel may offer for this payment.
    $payment['actions']             = payment_actions($payment);
}

$order['payment'] = $payment;
$order['subtotal']     = (float) $order['subtotal'];
$order['delivery_fee'] = (float) $order['delivery_fee'];
$order['total']        = (float) $order['total'];

json_out(['order' => $order, 'statuses' => ORDER_STATUSES]);

/**
 * Which payment buttons the admin panel should show for this payment.
 * Mirrors the rules enforced by api/admin-payments.php.
 */
function payment_actions(array $payment): array
{
    $status  = (string) $payment['status'];
    $isCash  = $payment['method'] === 'Cash';
    $actions = [];

    if ($isCash) {
        if ($status !== 'Paid') {
            $actions[] = ['status' => 'Paid', 'label' => 'Mark cash as received', 'style' => 'primary'];
        }
    } else {
        if ($status !== 'Paid' && $status !== 'Refunded') {
            if (trim((string) $payment['gateway_reference']) !== '') {
                $actions[] = ['status' => 'Paid', 'label' => 'Confirm payment', 'style' => 'primary'];
            }
            $actions[] = ['status' => 'Failed', 'label' => 'Reject payment', 'style' => 'danger'];
        }
        if ($status === 'Paid') {
            $actions[] = ['status' => 'Refunded', 'label' => 'Mark refunded', 'style' => 'danger'];
        }
    }

    return $actions;
}
