<?php
/**
 * Customer orders.
 *
 * GET  → the signed-in customer's orders, newest first
 * POST → places an order
 *
 * The browser sends product ids, quantities and the customer's choices; it
 * never sends prices. Everything is re-priced here from the current menu
 * (api/pricing.php) inside a transaction, so a tampered cart cannot change what
 * is charged and an unavailable item cannot be sold.
 */
require __DIR__ . '/config.php';

$user   = require_login();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    list_orders($pdo, $user);
}
if ($method === 'POST') {
    create_order($pdo, $user);
}
json_out(['error' => 'Method not allowed.'], 405);

/* ═══════════════════════════ GET ═══════════════════════════ */

function list_orders(PDO $pdo, array $user): never
{
    $sql = <<<'SQL'
        SELECT o.id, o.order_code, o.order_type, o.delivery_address, o.contact_number, o.notes,
               o.subtotal, o.delivery_fee, o.total, o.status, o.created_at,
               p.method AS payment_method, p.status AS payment_status, p.gateway_reference AS payment_reference,
               GROUP_CONCAT(CONCAT(oi.product_name, ' × ', oi.quantity) ORDER BY oi.id SEPARATOR ', ') AS items_summary
        FROM orders o
        LEFT JOIN order_items oi ON oi.order_id = o.id
        LEFT JOIN payments p ON p.order_id = o.id
        WHERE o.customer_id = ?
        GROUP BY o.id, p.id
        ORDER BY o.id DESC
        SQL;

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user['id']]);
    $orders = $stmt->fetchAll();

    foreach ($orders as &$order) {
        $order['id']            = (int) $order['id'];
        $order['subtotal']      = (float) $order['subtotal'];
        $order['delivery_fee']  = (float) $order['delivery_fee'];
        $order['total']         = (float) $order['total'];
        // The server decides whether "Submit payment details" may be offered.
        $order['can_submit_payment'] = in_array($order['payment_method'], ONLINE_PAYMENT_METHODS, true)
            && in_array((string) $order['payment_status'], ['Pending', 'Pending Verification', 'Failed'], true)
            && $order['status'] !== 'Cancelled';
    }
    unset($order);

    json_out(['orders' => $orders]);
}

/* ═══════════════════════════ POST ═══════════════════════════ */

function create_order(PDO $pdo, array $user): never
{
    $data = body_json();

    $items      = $data['items'] ?? [];
    $orderType  = ($data['order_type'] ?? 'Pickup') === 'Delivery' ? 'Delivery' : 'Pickup';
    $address    = trim((string) ($data['address'] ?? ''));
    $contact    = trim((string) ($data['contact_number'] ?? ''));
    $notes      = trim((string) ($data['notes'] ?? ''));
    $payMethod  = (string) ($data['payment_method'] ?? 'Cash');

    if (!is_array($items) || $items === []) {
        json_out(['error' => 'Your cart is empty.'], 422);
    }
    if (count($items) > 50) {
        json_out(['error' => 'Too many cart items — please order 50 lines or fewer.'], 422);
    }
    if (!in_array($payMethod, PAYMENT_METHOD_NAMES, true)) {
        json_out(['error' => 'Select a valid payment method.'], 422);
    }
    if (mb_strlen($address) > 500) {
        json_out(['error' => 'The delivery address is too long (500 characters max).'], 422);
    }
    if (mb_strlen($notes) > 300) {
        json_out(['error' => 'Order notes are too long (300 characters max).'], 422);
    }
    if ($orderType === 'Delivery' && $address === '') {
        json_out(['error' => 'Delivery address is required.'], 422);
    }
    if ($contact !== '' && !valid_contact_number($contact)) {
        json_out(['error' => 'Enter a contact number with 7 to 15 digits.'], 422);
    }
    if ($orderType === 'Delivery' && $contact === '') {
        json_out(['error' => 'A contact number is required for delivery.'], 422);
    }

    try {
        $pdo->beginTransaction();

        $validated = [];
        $subtotal  = 0.0;
        $issues    = [];

        // FOR UPDATE keeps another order from selling the last of an item while
        // this one is being priced.
        $productStmt = $pdo->prepare('SELECT id, name, category, price FROM products WHERE id = ? AND is_available = 1 FOR UPDATE');

        foreach ($items as $line) {
            $productId = (int) ($line['product_id'] ?? 0);
            $quantity  = (int) ($line['quantity'] ?? 0);
            $custom    = $line['customizations'] ?? [];
            if (!is_array($custom)) {
                $custom = [];
            }

            if ($productId < 1 || $quantity < 1 || $quantity > 50) {
                $issues[] = 'Invalid cart item.';
                continue;
            }

            $productStmt->execute([$productId]);
            $product = $productStmt->fetch();
            if (!$product) {
                $issues[] = 'A selected item is no longer available.';
                continue;
            }

            $check = validate_customizations($product, $custom);
            if (!$check['ok']) {
                $issues[] = $product['name'] . ': ' . implode(' ', $check['errors']);
                continue;
            }

            $lineTotal   = round($check['unit'] * $quantity, 2);
            $subtotal    = round($subtotal + $lineTotal, 2);
            $validated[] = [
                'product' => $product,
                'qty'     => $quantity,
                'custom'  => $check['clean'],
                'unit'    => $check['unit'],
                'line'    => $lineTotal,
            ];
        }

        if ($issues !== []) {
            $issues = array_values(array_unique($issues));
            $pdo->rollBack();
            json_out(['error' => implode(' ', array_slice($issues, 0, 5)), 'issues' => $issues], 422);
        }

        $deliveryFee = delivery_fee_for($orderType);
        $total       = round($subtotal + $deliveryFee, 2);

        // Order code: MJK-YYMMDD-XXXXXXXX. Retried in the unlikely event of a clash.
        $orderId = 0;
        $code    = '';
        $insertOrder = $pdo->prepare(
            'INSERT INTO orders (order_code, customer_id, order_type, delivery_address, contact_number, notes,
                                 subtotal, delivery_fee, total, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'Order Placed\')'
        );

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $candidate = 'MJK-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
            try {
                $insertOrder->execute([
                    $candidate,
                    $user['id'],
                    $orderType,
                    $orderType === 'Delivery' ? $address : null,
                    $contact !== '' ? $contact : null,
                    $notes !== '' ? $notes : null,
                    $subtotal,
                    $deliveryFee,
                    $total,
                ]);
                $code    = $candidate;
                $orderId = (int) $pdo->lastInsertId();
                break;
            } catch (PDOException $e) {
                $isDuplicateCode = ($e->errorInfo[1] ?? 0) === 1062;   // MySQL: duplicate entry
                if (!$isDuplicateCode || $attempt === 3) {
                    throw $e;
                }
            }
        }
        if ($orderId < 1) {
            throw new RuntimeException('Could not reserve an order code.');
        }

        $insertItem = $pdo->prepare(
            'INSERT INTO order_items (order_id, product_id, product_name, quantity, unit_price, line_total, customizations)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($validated as $line) {
            $insertItem->execute([
                $orderId,
                $line['product']['id'],
                $line['product']['name'],
                $line['qty'],
                $line['unit'],
                $line['line'],
                json_encode($line['custom'], JSON_UNESCAPED_UNICODE),
            ]);
        }

        // One payment row per order. Cash stays 'Pending' until the shop marks it
        // received; e-wallets wait in 'Pending Verification' for an admin.
        $insertPayment = $pdo->prepare('INSERT INTO payments (order_id, method, amount, status) VALUES (?, ?, ?, ?)');
        $insertPayment->execute([
            $orderId,
            $payMethod,
            $total,
            $payMethod === 'Cash' ? 'Pending' : 'Pending Verification',
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_out(['error' => $e instanceof RuntimeException ? $e->getMessage() : 'Unable to place order.'], 422);
    }

    json_out([
        'ok'                 => true,
        'order_id'           => $orderId,
        'order_code'         => $code,
        'subtotal'           => $subtotal,
        'delivery_fee'       => $deliveryFee,
        'total'              => $total,
        'payment_method'     => $payMethod,
        'payment_status'     => $payMethod === 'Cash' ? 'Pending' : 'Pending Verification',
        'needs_payment_info' => $payMethod !== 'Cash',
    ], 201);
}

/* ═══════════════════════════ helpers ═══════════════════════════ */

/** Accepts 7–15 digits, optionally written with + ( ) - spaces or dots. */
function valid_contact_number(string $value): bool
{
    if (mb_strlen($value) > 30 || !preg_match('/^[0-9+()\-.\s]+$/', $value)) {
        return false;
    }
    $digits = preg_replace('/\D/', '', $value) ?? '';
    return strlen($digits) >= 7 && strlen($digits) <= 15;
}
