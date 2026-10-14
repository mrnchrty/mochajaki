<?php
/**
 * Menu management (admin only).
 *
 * GET    → every item, including the ones hidden from customers
 * POST   → add an item
 * PUT    → edit an item
 * PATCH  → { id, is_available } toggle availability
 * DELETE → { id } remove an item (past orders keep the item name and totals,
 *          because order_items.product_id is set to NULL instead of deleting
 *          history)
 *
 * Values are length-checked before they reach MySQL, so an over-long name or
 * image URL comes back as a clear message instead of a 500 error.
 */
require __DIR__ . '/config.php';

require_admin();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$data   = body_json();

if ($method === 'GET') {
    list_products($pdo);
}
if ($method === 'POST' || $method === 'PUT') {
    save_product($pdo, $data, $method === 'POST');
}
if ($method === 'PATCH') {
    set_availability($pdo, $data);
}
if ($method === 'DELETE') {
    delete_product($pdo, $data);
}
json_out(['error' => 'Method not allowed.'], 405);

/* ═══════════════════════════ read ═══════════════════════════ */

/**
 * Everything the menu screen needs: every product, available or not.
 *
 * This lives in the admin endpoints (rather than a `?all=1` flag on the public
 * products.php) because the session area is decided by the endpoint name: an
 * admin-* file reads the admin session, so the panel can list hidden items
 * without mixing the two sign-ins.
 */
function list_products(PDO $pdo): never
{
    $sql = 'SELECT id, name, category, description, price, image_url, is_available
            FROM products
            ORDER BY category, name';

    $products = $pdo->query($sql)->fetchAll();

    foreach ($products as &$product) {
        $product['id']           = (int) $product['id'];
        $product['price']        = (float) $product['price'];
        $product['is_available'] = (int) $product['is_available'];
    }
    unset($product);

    json_out(['products' => $products]);
}

/* ═══════════════════════════ create / update ═══════════════════════════ */

function save_product(PDO $pdo, array $data, bool $isNew): never
{
    $id     = (int) ($data['id'] ?? 0);
    $name     = trim((string) ($data['name'] ?? ''));
    $category = trim((string) ($data['category'] ?? ''));
    $description = trim((string) ($data['description'] ?? ''));
    $imageUrl = trim((string) ($data['image_url'] ?? ''));
    $price    = filter_var($data['price'] ?? null, FILTER_VALIDATE_FLOAT);

    if ($name === '' || mb_strlen($name) > 150) {
        json_out(['error' => 'Enter a product name of up to 150 characters.'], 422);
    }
    if ($category === '' || mb_strlen($category) > 80) {
        json_out(['error' => 'Enter a category of up to 80 characters.'], 422);
    }
    if (mb_strlen($description) > 2000) {
        json_out(['error' => 'The description is too long (2000 characters max).'], 422);
    }
    if ($imageUrl !== '' && (mb_strlen($imageUrl) > 500 || preg_match('/\s/', $imageUrl))) {
        json_out(['error' => 'Enter an image URL of up to 500 characters without spaces.'], 422);
    }
    // DECIMAL(10,2) can hold up to 99,999,999.99
    if ($price === false || $price <= 0 || $price > 99999999.99) {
        json_out(['error' => 'Enter a price between 0.01 and 99,999,999.99.'], 422);
    }

    if ($isNew) {
        $stmt = $pdo->prepare(
            'INSERT INTO products (name, category, description, price, image_url, is_available)
             VALUES (?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([$name, $category, $description, $price, $imageUrl !== '' ? $imageUrl : null]);
        json_out(['ok' => true, 'id' => (int) $pdo->lastInsertId()], 201);
    }

    if ($id < 1) {
        json_out(['error' => 'Invalid product.'], 422);
    }

    $stmt = $pdo->prepare('SELECT id FROM products WHERE id = ?');
    $stmt->execute([$id]);
    if (!$stmt->fetch()) {
        json_out(['error' => 'Product not found.'], 404);
    }

    $stmt = $pdo->prepare(
        'UPDATE products SET name = ?, category = ?, description = ?, price = ?, image_url = ? WHERE id = ?'
    );
    $stmt->execute([$name, $category, $description, $price, $imageUrl !== '' ? $imageUrl : null, $id]);

    json_out(['ok' => true]);
}

/* ═══════════════════════════ availability ═══════════════════════════ */

function set_availability(PDO $pdo, array $data): never
{
    $id = (int) ($data['id'] ?? 0);
    if ($id < 1) {
        json_out(['error' => 'Invalid product.'], 422);
    }

    $available = !empty($data['is_available']) ? 1 : 0;

    $stmt = $pdo->prepare('UPDATE products SET is_available = ? WHERE id = ?');
    $stmt->execute([$available, $id]);
    if ($stmt->rowCount() === 0) {
        // rowCount is 0 both when the product is missing and when the value did
        // not change, so confirm the product exists before reporting an error.
        $check = $pdo->prepare('SELECT id FROM products WHERE id = ?');
        $check->execute([$id]);
        if (!$check->fetch()) {
            json_out(['error' => 'Product not found.'], 404);
        }
    }

    json_out(['ok' => true, 'is_available' => $available]);
}

/* ═══════════════════════════ delete ═══════════════════════════ */

function delete_product(PDO $pdo, array $data): never
{
    $id = (int) ($data['id'] ?? 0);
    if ($id < 1) {
        json_out(['error' => 'Invalid product.'], 422);
    }

    // Past orders keep their line items: order_items.product_id becomes NULL
    // while product_name, unit_price and line_total stay untouched.
    $stmt = $pdo->prepare('DELETE FROM products WHERE id = ?');
    $stmt->execute([$id]);

    if ($stmt->rowCount() === 0) {
        json_out(['error' => 'Product not found.'], 404);
    }

    json_out(['ok' => true]);
}
