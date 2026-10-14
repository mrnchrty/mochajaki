<?php
/**
 * Customer list for the admin panel (admin only).
 * One query: order count and last order date per customer.
 */
require __DIR__ . '/config.php';

require_admin();

$sql = <<<'SQL'
    SELECT u.id, u.full_name, u.email, u.created_at,
           COUNT(o.id) AS order_count,
           COALESCE(SUM(CASE WHEN o.status <> 'Cancelled' THEN o.total ELSE 0 END), 0) AS ordered_total,
           MAX(o.created_at) AS last_order_at
    FROM users u
    LEFT JOIN orders o ON o.customer_id = u.id
    WHERE u.role = 'customer'
    GROUP BY u.id
    ORDER BY u.id DESC
    SQL;

$customers = $pdo->query($sql)->fetchAll();

foreach ($customers as &$customer) {
    $customer['id']            = (int) $customer['id'];
    $customer['order_count']   = (int) $customer['order_count'];
    $customer['ordered_total'] = (float) $customer['ordered_total'];
}
unset($customer);

json_out(['customers' => $customers]);
