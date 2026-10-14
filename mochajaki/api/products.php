<?php
/**
 * The public menu — only the items that are on sale.
 *
 * The admin panel reads the full list (including hidden items) from
 * api/admin-products.php, which belongs to the admin session.
 *
 * Each product carries its `option_spec` (sizes, flavours, add-ons and their
 * price effects) so the browser can build the order form and show the right
 * price without hardcoding anything. api/orders.php charges the order with the
 * very same spec.
 */
require __DIR__ . '/config.php';

$sql = 'SELECT id, name, category, description, price, image_url, is_available
        FROM products
        WHERE is_available = 1
        ORDER BY category, name';

$products = $pdo->query($sql)->fetchAll();

foreach ($products as &$product) {
    $product['id']           = (int) $product['id'];
    $product['price']        = (float) $product['price'];
    $product['is_available'] = (int) $product['is_available'];
    $product['option_spec']  = option_spec($product);
}
unset($product);

json_out([
    'products'     => $products,
    'delivery_fee' => DELIVERY_FEE,
    'payment'      => PAYMENT_METHODS,
]);
