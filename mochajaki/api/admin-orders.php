<?php
/**
 * Order management (admin only).
 *
 * GET   → every order with its items summary, plus best-seller totals.
 *         Two queries in total, no matter how many orders exist (the previous
 *         version ran one extra query per order on every dashboard refresh).
 * PATCH → updates one order's status.
 */
require __DIR__ . '/config.php';

require_admin();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    list_all_orders($pdo);
}
if ($method === 'PATCH') {
    update_order_status($pdo);
}
json_out(['error' => 'Method not allowed.'], 405);

/* ═══════════════════════════ GET ═══════════════════════════ */

function list_all_orders(PDO $pdo): never
{
    $ordersSql = <<<'SQL'
        SELECT o.id, o.order_code, o.total, o.subtotal, o.delivery_fee, o.delivery_address,
               o.contact_number, o.notes, o.status, o.order_type, o.created_at,
               u.full_name, u.email,
               p.method AS payment_method, p.status AS payment_status,
               COUNT(oi.id) AS item_lines,
               GROUP_CONCAT(CONCAT(oi.product_name, ' × ', oi.quantity) ORDER BY oi.id SEPARATOR ', ') AS items_summary
        FROM orders o
        JOIN users u ON u.id = o.customer_id
        LEFT JOIN payments p ON p.order_id = o.id
        LEFT JOIN order_items oi ON oi.order_id = o.id
        GROUP BY o.id, p.id
        ORDER BY o.id DESC
        SQL;

    $orders = $pdo->query($ordersSql)->fetchAll();
    foreach ($orders as &$order) {
        $order['id']           = (int) $order['id'];
        $order['total']        = (float) $order['total'];
        $order['subtotal']     = (float) $order['subtotal'];
        $order['delivery_fee'] = (float) $order['delivery_fee'];
        $order['item_lines']   = (int) $order['item_lines'];
    }
    unset($order);

    // Best sellers, cancelled orders excluded — one aggregate query.
    $bestSql = <<<'SQL'
        SELECT oi.product_name, SUM(oi.quantity) AS qty
        FROM order_items oi
        JOIN orders o ON o.id = oi.order_id
        WHERE o.status <> 'Cancelled'
        GROUP BY oi.product_name
        ORDER BY qty DESC, oi.product_name
        LIMIT 4
        SQL;

    $bestSellers = $pdo->query($bestSql)->fetchAll();
    foreach ($bestSellers as &$row) {
        $row['qty'] = (int) $row['qty'];
    }
    unset($row);

    json_out(['orders' => $orders, 'best_sellers' => $bestSellers, 'statuses' => ORDER_STATUSES]);
}

/* ═══════════════════════════ PATCH ═══════════════════════════ */

function update_order_status(PDO $pdo): never
{
    // Statuses that only make sense for one order type.
    $statusOnlyForType = ['Ready for Pickup' => 'Pickup', 'Out for Delivery' => 'Delivery'];

    $data   = body_json();
    $id     = (int) ($data['id'] ?? 0);
    $status = (string) ($data['status'] ?? '');

    if ($id < 1 || !in_array($status, ORDER_STATUSES, true)) {
        json_out(['error' => 'Invalid order or status.'], 422);
    }

    $stmt = $pdo->prepare('SELECT id, order_type FROM orders WHERE id = ?');
    $stmt->execute([$id]);
    $order = $stmt->fetch();
    if (!$order) {
        json_out(['error' => 'Order not found.'], 404);
    }

    // A Pickup order cannot go "Out for Delivery" and vice versa.
    if (isset($statusOnlyForType[$status]) && $statusOnlyForType[$status] !== $order['order_type']) {
        json_out([
            'error' => $status . ' can only be used on ' . $statusOnlyForType[$status] . ' orders.',
        ], 422);
    }

    $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([$status, $id]);

    json_out(['ok' => true, 'status' => $status]);
}
