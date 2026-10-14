<?php
declare(strict_types=1);

/**
 * Checks that your database matches what this code expects.
 *
 * Run it from the project folder:
 *   php tests/database.check.php
 *
 * No web server needed — it connects straight to MySQL with the settings in
 * api/config.php. Use it whenever the site says "Failed to fetch", shows a
 * database error, or behaves oddly after you copied new files in.
 *
 * It reports:
 *   • whether MySQL can be reached at all (and why not, if it cannot);
 *   • every table and column the application uses, marking anything missing;
 *   • which repair fits: database-upgrade.sql to keep your data, or
 *     database.sql for a fresh start.
 *
 * It never changes anything: only SELECT queries are run.
 */

require __DIR__ . '/../api/config.php';

/* ── What the application needs, table by table ──────────────────────────
   Keep this list in step with database.sql and database-upgrade.sql.       */
$expected = [
    'users' => [
        'id', 'full_name', 'email', 'password_hash', 'role', 'created_at',
    ],
    'products' => [
        'id', 'name', 'category', 'description', 'price', 'image_url', 'is_available', 'created_at',
    ],
    'orders' => [
        'id', 'order_code', 'customer_id', 'order_type', 'delivery_address',
        'contact_number', 'notes', 'subtotal', 'delivery_fee', 'total', 'status', 'created_at',
    ],
    'order_items' => [
        'id', 'order_id', 'product_id', 'product_name', 'quantity', 'unit_price', 'line_total', 'customizations',
    ],
    'payments' => [
        'id', 'order_id', 'method', 'amount', 'status', 'gateway_reference',
        'payment_screenshot', 'verified_by', 'verified_at', 'created_at',
    ],
    'login_attempts' => [
        'id', 'scope', 'email', 'ip', 'created_at',
    ],
];

/** Columns that older versions of database.sql did not have. */
$newest = ['login_attempts', 'orders.contact_number', 'orders.notes'];

echo "\nMochajaki — database check\n";
echo str_repeat('=', 52) . "\n\n";

/* ── Names exactly as MySQL stores them (case matters on Linux servers) ── */
$stmt = $pdo->query(
    'SELECT TABLE_NAME AS t, COLUMN_NAME AS c
       FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()'
);
$existing = [];
foreach ($stmt->fetchAll() as $row) {
    $existing[strtolower((string) $row['t'])][] = strtolower((string) $row['c']);
}

$missingTables = [];
$missingColumns = [];
$newestMissing = [];

foreach ($expected as $table => $columns) {
    $here = $existing[strtolower($table)] ?? null;

    if ($here === null) {
        $missingTables[] = $table;
        foreach ($columns as $column) {
            $newestMissing[] = "{$table}.{$column}";
        }
        echo "  [ MISSING ]  table {$table}\n";
        continue;
    }

    $absent = array_values(array_diff(array_map('strtolower', $columns), $here));
    if ($absent !== []) {
        $missingColumns[] = "{$table}(" . implode(', ', $absent) . ')';
        echo "  [ PARTIAL ]  table {$table} — missing: " . implode(', ', $absent) . "\n";
    } else {
        echo "  [    ok   ]  table {$table}\n";
    }

    foreach ($newest as $pair) {
        [$t, $c] = explode('.', $pair);
        if (strtolower($t) === strtolower($table) && in_array(strtolower($c), $absent, true)) {
            $newestMissing[] = $pair;
        }
    }
}

/* ── Are the starter rows there? ───────────────────────────────────────── */
// These two tables can be missing entirely on a brand-new database, so a
// failure here is a finding to report, not an error to crash on.
$productCount = null;
$adminCount = null;
try {
    $productCount = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
    $adminCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
} catch (Throwable $e) {
    // Reported as missing below.
}

echo "\nStarter data\n";
$showCount = static function (?int $count, string $emptyNote): void {
    if ($count === null) {
        echo "  cannot count — the table is missing\n";
        return;
    }
    echo '  ' . $count . ($count === 0 ? "  ({$emptyNote})\n" : "\n");
};
echo '  products: ';
$showCount($productCount, 'empty — the menu will look blank; re-import the products');
echo '  admins:   ';
$showCount($adminCount, 'no admin account — nobody can sign in to the admin panel');

/* ── Verdict ───────────────────────────────────────────────────────────── */
echo "\n" . str_repeat('-', 52) . "\n";

$healthy = $missingTables === []
    && $missingColumns === []
    && $productCount !== null && $productCount > 0
    && $adminCount !== null && $adminCount > 0;

if ($healthy) {
    echo "Everything the application asks for is present. If the site still\n";
    echo "complains, the problem is elsewhere (check the browser's Network tab).\n\n";
    exit(0);
}

echo "This database is out of date. Missing: \n\n";
foreach ($missingTables as $table) {
    echo "  • the whole table {$table}\n";
}
foreach ($missingColumns as $entry) {
    echo "  • columns in {$entry}\n";
}

echo "\nFix it with one of these:\n\n";
echo "  KEEP your existing orders and accounts:\n";
echo "    phpMyAdmin → open this database → Import → database-upgrade.sql\n";
echo "    (or) mysql --default-character-set=utf8mb4 -u root " . $dbName . " < database-upgrade.sql\n\n";
echo "  START FRESH (this DELETES all accounts, orders and payments):\n";
echo "    phpMyAdmin → Import → database.sql\n\n";

if ($newestMissing !== []) {
    echo "Note: " . implode(', ', array_unique($newestMissing)) . "\n";
    echo "are new in this version, which is why an older database.sql fails.\n\n";
}

exit(1);
