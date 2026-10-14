<?php
declare(strict_types=1);

/**
 * Checks the menu rules and prices in api/pricing.php.
 *
 * Run it from the project folder:
 *   php tests/pricing.test.php
 *
 * No database or web server needed — this is the file the browser and the
 * server both take their numbers from, so if these checks pass, the price the
 * customer sees is the price the order records.
 */

require __DIR__ . '/../api/pricing.php';

$passed = 0;
$failed = 0;

/** Checks a value and prints ✔/✘. */
function check(string $label, mixed $actual, mixed $expected): void
{
    global $passed, $failed;

    if ($actual === $expected) {
        $passed++;
        echo "  ✔ {$label}\n";
        return;
    }

    $failed++;
    $shown = static fn(mixed $v): string => is_bool($v) ? ($v ? 'true' : 'false') : var_export($v, true);
    echo "  ✘ {$label}\n      expected: {$shown($expected)}\n      actual:   {$shown($actual)}\n";
}

/** A product row as the database would return it. */
function product(string $name, string $category, float $price): array
{
    return ['id' => 1, 'name' => $name, 'category' => $category, 'price' => $price];
}

echo "\nMochajaki pricing rules\n=======================\n";

/* ── Milkshake ─────────────────────────────────────────────────────────── */
echo "\nMilkshake (₱40 on the menu, 22 oz costs ₱10 more)\n";
$milkshake = product('Milkshake', 'Milkshake', 40.00);

check('16 oz with a flavour', validate_customizations($milkshake, ['size' => '16 oz', 'flavor' => 'Buko'])['unit'], 40.0);
check('22 oz with a flavour', validate_customizations($milkshake, ['size' => '22 oz', 'flavor' => 'Buko'])['unit'], 50.0);
check('22 oz + pearl + nata', validate_customizations($milkshake, ['size' => '22 oz', 'flavor' => 'Buko', 'pearl' => true, 'nata' => true])['unit'], 70.0);
check('missing flavour is rejected', validate_customizations($milkshake, ['size' => '22 oz'])['ok'], false);
check('missing size is rejected', validate_customizations($milkshake, ['flavor' => 'Buko'])['ok'], false);
check('a flavour outside the list is rejected', validate_customizations($milkshake, ['size' => '16 oz', 'flavor' => 'Unicorn'])['ok'], false);

/* ── Fruit soda ────────────────────────────────────────────────────────── */
echo "\nFruit Soda (₱40, nata ₱10)\n";
$soda = product('Fruit Soda', 'Fruit Soda', 40.00);
check('22 oz + nata', validate_customizations($soda, ['size' => '22 oz', 'flavor' => 'Apple', 'nata' => true])['unit'], 60.0);

/* ── Chicken wings ─────────────────────────────────────────────────────── */
echo "\nChicken Wings with Rice (₱108 = 3 pieces, extra rice ₱15)\n";
$wings = product('Chicken Wings with Rice', 'Chicken Wings', 108.00);
$wingsBase = ['pieces' => '3 pieces', 'flavor' => 'Teriyaki', 'rice_type' => 'Plain'];
check('3 pieces', validate_customizations($wings, $wingsBase)['unit'], 108.0);
check('6 pieces', validate_customizations($wings, ['pieces' => '6 pieces'] + $wingsBase)['unit'], 158.0);
check('8 pieces', validate_customizations($wings, ['pieces' => '8 pieces'] + $wingsBase)['unit'], 198.0);
check('12 pieces', validate_customizations($wings, ['pieces' => '12 pieces'] + $wingsBase)['unit'], 308.0);
check('12 pieces + extra rice', validate_customizations($wings, ['pieces' => '12 pieces', 'extra_rice' => true] + $wingsBase)['unit'], 323.0);
check('rice type is required', validate_customizations($wings, ['pieces' => '3 pieces', 'flavor' => 'Teriyaki'])['ok'], false);

/* ── Burgers and buldak ────────────────────────────────────────────────── */
echo "\nAdd-ons\n";
$burger = product('Single Burger (CDO Patty)', 'Meryenda Meal', 30.00);
check('egg + cheese + ham + bacon', validate_customizations($burger, ['add_egg' => true, 'add_cheese' => true, 'add_ham' => true, 'add_bacon' => true])['unit'], 30.0 + 15 + 10 + 15 + 25);
check('a burger needs no choices', validate_customizations($burger, [])['ok'], true);
check('pearl is not on the burger menu, so it is ignored', validate_customizations($burger, ['pearl' => true])['unit'], 30.0);
check('pearl is not stored for a burger', array_key_exists('pearl', validate_customizations($burger, ['pearl' => true])['clean']), false);

$buldak = product('Buldak Cheese', 'Buldak', 89.00);
check('buldak egg is ₱20 and bacon ₱30', validate_customizations($buldak, ['add_egg' => true, 'add_bacon' => true])['unit'], 139.0);

/* ── Rice meals and rice sides ─────────────────────────────────────────── */
echo "\nRice\n";
$silog = product('Chicken Silog', 'Rice Meal', 110.00);
check('silog asks for a rice type', validate_customizations($silog, ['rice_type' => 'Java'])['ok'], true);
check('silog without a rice type is rejected', validate_customizations($silog, [])['ok'], false);

$plainRice = product('Plain Rice', 'Rice Meal', 20.00);
check('Plain Rice offers no options', option_spec($plainRice)['fields'], []);
check('Java Rice offers no options', option_spec(product('Java Rice', 'Rice Meal', 25.00))['fields'], []);

/* ── Combos ────────────────────────────────────────────────────────────── */
echo "\nCombos\n";
$burgerCombo = product('A1 Burger Combo', 'Combos', 100.00);
$comboFields = array_column(option_spec($burgerCombo)['fields'], 'key');
check('a burger combo asks for a side', in_array('side', $comboFields, true), true);
check('a burger combo also takes burger add-ons', array_column(option_spec($burgerCombo)['addons'], 'key'), ['add_egg', 'add_cheese', 'add_ham', 'add_bacon']);
check('side + egg', validate_customizations($burgerCombo, ['side' => 'Fries', 'add_egg' => true])['unit'], 115.0);
check('a combo without a side is rejected', validate_customizations($burgerCombo, [])['ok'], false);

$shakeCombo = product('A2 Burger & Milkshake', 'Combos', 105.00);
check('a milkshake combo asks for the flavour', in_array('flavor', array_column(option_spec($shakeCombo)['fields'], 'key'), true), true);

$footlongCombo = product('D3 Footlong & Coke Float', 'Combos', 105.00);
check('a footlong combo has no burger add-ons', option_spec($footlongCombo)['addons'], []);

/* ── Takoyaki ──────────────────────────────────────────────────────────── */
echo "\nTakoyaki and standard items\n";
check('takoyaki asks for a variant', array_column(option_spec(product('Takoyaki Ham 4 pcs', 'Takoyaki', 40.00))['fields'], 'key'), ['variant']);
check('fries have no options', option_spec(product('Fries Large', 'Pica-Pica', 50.00)), ['fields' => [], 'addons' => []]);

/* ── Prices follow the menu, not hardcoded numbers ─────────────────────── */
echo "\nAdmin price changes are respected\n";
check('a ₱60 milkshake is ₱70 at 22 oz', validate_customizations(product('Milkshake', 'Milkshake', 60.00), ['size' => '22 oz', 'flavor' => 'Buko'])['unit'], 70.0);
check('a ₱120 wings plate is ₱170 at 6 pieces', validate_customizations(product('Chicken Wings with Rice', 'Chicken Wings', 120.00), ['pieces' => '6 pieces', 'flavor' => 'Teriyaki', 'rice_type' => 'Plain'])['unit'], 170.0);

/* ── Delivery fee ──────────────────────────────────────────────────────── */
echo "\nDelivery fee\n";
check('pickup pays no delivery fee', delivery_fee_for('Pickup'), 0.0);
check('delivery pays the configured fee', delivery_fee_for('Delivery'), DELIVERY_FEE);

/* ── Display text ──────────────────────────────────────────────────────── */
echo "\nOrder text\n";
check(
    'options read like a receipt line',
    customization_text($milkshake, ['size' => '22 oz', 'flavor' => 'Buko', 'pearl' => true]),
    'Size: 22 oz · Flavor: Buko · Add pearl'
);
check(
    'a deleted product still reads sensibly',
    customization_text(null, ['rice_type' => 'Java', 'extra_rice' => true]),
    'Rice type: Java · Add extra rice'
);

/* ── Result ───────────────────────────────────────────────────────────── */
echo "\n";
echo str_repeat('-', 46) . "\n";
echo "  {$passed} passed, {$failed} failed\n\n";

exit($failed === 0 ? 0 : 1);
