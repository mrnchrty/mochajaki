<?php
declare(strict_types=1);

/**
 * Mochajaki — menu rules, options and money.
 *
 * This is the ONE place where prices and customer choices are defined.
 * The browser no longer hardcodes any price: api/products.php sends the option
 * spec produced here, the customer page renders it and adds up the same
 * numbers, and api/orders.php charges the order with price_for(). If the two
 * ever disagree the customer would see a different total in the cart than in
 * the order, so keeping a single source of truth matters.
 *
 * Deliberately free of sessions, database and HTTP code so it can be unit
 * tested on its own: see tests/pricing.test.php
 *
 * PHP 8.1+ (uses const arrays, str_contains, match-free but typed signatures).
 */

/* ════════════════════════════ 1. Owner settings ═══════════════════════════
   Everything a shop owner may want to change lives in this block.           */

/** Delivery charge added to Delivery orders only (₱). Set 0.00 while unconfirmed. */
const DELIVERY_FEE = 0.00;

/** A 22 oz drink costs this much more than the price stored on the product row. */
const DRINK_SIZE_SURCHARGE = 10.00;

/** Price of "Add extra rice" on Chicken Wings with Rice. Set 0.00 to make it free. */
const EXTRA_RICE_FEE = 15.00;

/** Add-on prices charged on top of the product's listed price. */
const ADDON_PRICES = [
    'pearl'       => 10.00,
    'nata'        => 10.00,
    'add_egg'     => 15.00,
    'add_cheese'  => 10.00,
    'add_ham'     => 15.00,
    'add_bacon'   => 25.00,
    'add_seaweed' => 23.00,
];

/** Buldak items price egg, bacon and seaweed differently. */
const BULDAK_ADDON_PRICES = [
    'add_egg'     => 20.00,
    'add_bacon'   => 30.00,
    'add_seaweed' => 23.00,
];

/** Chicken wings: charged on top of the listed price, which is the 3-piece price. */
const WINGS_PIECES_EXTRA = [
    '3 pieces'  => 0.00,
    '6 pieces'  => 50.00,
    '8 pieces'  => 90.00,
    '12 pieces' => 200.00,
];

/** Choice lists shown to customers. Add or remove freely — no code depends on the wording. */
const MILKSHAKE_FLAVORS = [
    'Choco Kisses', 'Cookies & Cream', 'Buko', 'Strawberry', 'Double Dutch', 'Melon',
    'Bukopandan', 'Avocado', 'Black Forest', 'Mango', 'Ube Macapuno', 'Leche Flan',
];
const SODA_FLAVORS = [
    'Lychee Blue', 'Lemonade', 'Strawberry Green', 'Apple', 'Wintermelon', 'Blueberry',
];
const MILK_TEA_FLAVORS = [
    'Classic', 'Wintermelon', 'Okinawa', 'Taro', 'Matcha', 'Hokkaido', 'Brown Sugar',
];
const MILK_TEA_SWEETNESS = ['0%', '25%', '50%', '75%', '100%'];
const WINGS_FLAVORS = ['Spicy Buffalo', 'Garlic Parmesan', 'Teriyaki', 'Sweet & Spicy', 'Hickory BBQ'];
const RICE_TYPES = ['Plain', 'Java'];
const COMBO_SIDES = ['Fries', '6 pcs Cheese Stick'];
const TAKOYAKI_VARIANTS = ['Ham', 'Cheese', 'Vegetable', 'Spam', 'Bacon', 'Crab'];

/**
 * Products that are rice *sides*, not rice meals — listing them frees them from
 * the "choose Plain or Java rice" question. Matched against the product name.
 */
const RICE_SIDE_NAMES = ['plain rice', 'java rice'];

/**
 * E-wallet display details for the checkout page.
 * `qr`    — page-relative path to the official QR image (see assets/payments/README.txt)
 * `label` — account name / number to show when no QR image is installed
 * If a QR image cannot be loaded and `label` is empty, the cart shows
 * "ask the store for the account details" instead of an empty box.
 */
const PAYMENT_METHODS = [
    'GCash' => ['qr' => '../assets/payments/gcash-qr.png', 'label' => ''],
    'Maya'  => ['qr' => '../assets/payments/maya-qr.png',  'label' => ''],
];

/** Cash is always offered; these are the e-wallet choices. */
const ONLINE_PAYMENT_METHODS = ['GCash', 'Maya'];
const PAYMENT_METHOD_NAMES = ['Cash', 'GCash', 'Maya'];

/**
 * Human labels for stored customization keys. Only used when the product row is
 * gone (deleted items) or when a key is no longer part of the option spec, so
 * old orders still read nicely.
 */
const CUSTOMIZATION_LABELS = [
    'flavor' => 'Flavor', 'sweetness' => 'Sweetness', 'size' => 'Size', 'rice_type' => 'Rice type',
    'pieces' => 'Chicken pieces', 'extra_rice' => 'Add extra rice', 'pearl' => 'Add pearl',
    'nata' => 'Add nata', 'side' => 'Side', 'variant' => 'Variant', 'add_egg' => 'Add egg',
    'add_cheese' => 'Add cheese', 'add_ham' => 'Add ham', 'add_bacon' => 'Add bacon',
    'add_seaweed' => 'Add seaweed',
];

/* ═══════════════════════════ 2. Spec building ═══════════════════════════ */

/**
 * Builds a select field descriptor.
 *
 * @param array<int|string, float|string> $options Either ['A','B'] for free choices
 *                                                or ['A' => 0.0, 'B' => 10.0] with a price delta.
 */
function field_select(string $key, string $label, array $options, bool $required = true): array
{
    $values = [];
    foreach ($options as $value => $delta) {
        if (is_int($value)) {
            $values[] = ['value' => (string) $delta, 'delta' => 0.0];
        } else {
            $values[] = ['value' => (string) $value, 'delta' => (float) $delta];
        }
    }
    return ['key' => $key, 'label' => $label, 'type' => 'select', 'required' => $required, 'options' => $values];
}

/** Builds a checkbox add-on descriptor (charged when checked). */
function field_addon(string $key, string $label, float $price): array
{
    return ['key' => $key, 'label' => $label, 'type' => 'checkbox', 'price' => $price];
}

/**
 * The options a product offers. Rules are additive so an item can be, say, a
 * combo that also takes burger add-ons — exactly like the original UI.
 *
 * @param array{name:string,category:string,price:float|string} $product
 * @return array{fields:array,addons:array}
 */
function option_spec(array $product): array
{
    $name = strtolower(trim((string) ($product['name'] ?? '')));
    $cat  = strtolower(trim((string) ($product['category'] ?? '')));
    $hay  = $cat . ' ' . $name;

    $is_combo     = str_contains($hay, 'combo');
    $is_wings     = str_contains($hay, 'chicken wings');
    $is_rice_side = in_array($name, RICE_SIDE_NAMES, true);
    $is_rice_meal = str_contains($cat, 'rice meal') || str_contains($hay, 'silog') || str_contains($hay, 'with rice');

    $fields = [];
    $addons = [];

    /* Drinks — non-combo drinks choose a size and a flavour. */
    if (!$is_combo) {
        if (str_contains($hay, 'milkshake')) {
            $fields[] = field_select('size', 'Size', ['16 oz' => 0.0, '22 oz' => DRINK_SIZE_SURCHARGE]);
            $fields[] = field_select('flavor', 'Flavor', MILKSHAKE_FLAVORS);
            $addons[] = field_addon('pearl', 'Add pearl', ADDON_PRICES['pearl']);
            $addons[] = field_addon('nata', 'Add nata', ADDON_PRICES['nata']);
        } elseif (str_contains($hay, 'fruit soda')) {
            $fields[] = field_select('size', 'Size', ['16 oz' => 0.0, '22 oz' => DRINK_SIZE_SURCHARGE]);
            $fields[] = field_select('flavor', 'Flavor', SODA_FLAVORS);
            $addons[] = field_addon('nata', 'Add nata', ADDON_PRICES['nata']);
        } elseif (str_contains($hay, 'milk tea')) {
            $fields[] = field_select('size', 'Size', ['16 oz' => 0.0, '22 oz' => DRINK_SIZE_SURCHARGE]);
            $fields[] = field_select('flavor', 'Flavor', MILK_TEA_FLAVORS);
            $fields[] = field_select('sweetness', 'Sweetness level', MILK_TEA_SWEETNESS);
        }
    } else {
        $fields[] = field_select('side', 'Side choice', COMBO_SIDES);
        if (str_contains($name, 'milkshake')) {
            $fields[] = field_select('flavor', 'Milkshake flavor', MILKSHAKE_FLAVORS);
        }
        if (str_contains($name, 'fruit soda')) {
            $fields[] = field_select('flavor', 'Fruit soda flavor', SODA_FLAVORS);
        }
    }

    /* Chicken wings choose pieces (priced), flavour and rice; other rice meals just rice. */
    if ($is_wings) {
        $fields[] = field_select('pieces', 'Chicken pieces', WINGS_PIECES_EXTRA);
        $fields[] = field_select('flavor', 'Flavor', WINGS_FLAVORS);
        $fields[] = field_select('rice_type', 'Rice type', RICE_TYPES);
        $addons[] = field_addon('extra_rice', 'Add extra rice', EXTRA_RICE_FEE);
    } elseif ($is_rice_meal && !$is_rice_side) {
        $fields[] = field_select('rice_type', 'Rice type', RICE_TYPES);
    }

    if (str_contains($hay, 'takoyaki')) {
        $fields[] = field_select('variant', 'Variant', TAKOYAKI_VARIANTS);
    }

    if (str_contains($hay, 'buldak')) {
        $addons[] = field_addon('add_egg', 'Add egg', BULDAK_ADDON_PRICES['add_egg']);
        $addons[] = field_addon('add_bacon', 'Add bacon', BULDAK_ADDON_PRICES['add_bacon']);
        $addons[] = field_addon('add_seaweed', 'Add seaweed', BULDAK_ADDON_PRICES['add_seaweed']);
    } elseif (
        (str_contains($hay, 'burger') || str_contains($hay, 'sandwich'))
        && !preg_match('/hotdog|footlong|hungarian/', $hay)
    ) {
        $addons[] = field_addon('add_egg', 'Egg', ADDON_PRICES['add_egg']);
        $addons[] = field_addon('add_cheese', 'Cheese', ADDON_PRICES['add_cheese']);
        $addons[] = field_addon('add_ham', 'Ham', ADDON_PRICES['add_ham']);
        $addons[] = field_addon('add_bacon', 'Bacon', ADDON_PRICES['add_bacon']);
    }

    return ['fields' => $fields, 'addons' => $addons];
}

/* ═══════════════════════════ 3. Validation ═════════════════════════════ */

/**
 * Validates a customer's choices for one product and works out the unit price.
 * Unknown keys are dropped, required choices must be present and must come from
 * the spec, so a tampered request cannot invent an option or a price.
 *
 * @return array{ok:bool,errors:array<int,string>,clean:array<string,mixed>,unit:float,spec:array}
 */
function validate_customizations(array $product, array $raw): array
{
    $spec   = option_spec($product);
    $clean  = [];
    $errors = [];

    foreach ($spec['fields'] as $field) {
        $value = $raw[$field['key']] ?? null;
        $value = is_bool($value) ? '' : trim((string) (is_scalar($value) ? $value : ''));
        $allowed = array_map(static fn(array $o): string => (string) $o['value'], $field['options']);

        if ($value === '') {
            if ($field['required']) {
                $errors[] = 'Choose ' . strtolower($field['label']) . '.';
            }
            continue;
        }
        if (!in_array($value, $allowed, true)) {
            $errors[] = 'Choose a valid ' . strtolower($field['label']) . '.';
            continue;
        }
        $clean[$field['key']] = $value;
    }

    foreach ($spec['addons'] as $addon) {
        if (!empty($raw[$addon['key']])) {
            $clean[$addon['key']] = true;
        }
    }

    return [
        'ok'     => $errors === [],
        'errors' => $errors,
        'clean'  => $clean,
        'unit'   => price_for($product, $spec, $clean),
        'spec'   => $spec,
    ];
}

/** Unit price = listed price + chosen size/pieces deltas + checked add-ons. */
function price_for(array $product, array $spec, array $custom): float
{
    $unit = (float) $product['price'];

    foreach ($spec['fields'] as $field) {
        $chosen = $custom[$field['key']] ?? null;
        if ($chosen === null) {
            continue;
        }
        foreach ($field['options'] as $option) {
            if ((string) $option['value'] === (string) $chosen) {
                $unit += (float) $option['delta'];
            }
        }
    }

    foreach ($spec['addons'] as $addon) {
        if (!empty($custom[$addon['key']])) {
            $unit += (float) $addon['price'];
        }
    }

    return round($unit, 2);
}

/** Delivery charge for an order type — zero for Pickup. */
function delivery_fee_for(string $orderType): float
{
    return $orderType === 'Delivery' ? DELIVERY_FEE : 0.00;
}

/* ═══════════════════════════ 4. Display ════════════════════════════════ */

/**
 * Turns stored customizations into "Rice type: Plain · Add pearl" for the
 * customer's cart, the admin order view and the order summary.
 * Works for deleted products too, falling back to CUSTOMIZATION_LABELS.
 */
function customization_text(?array $product, array $raw): string
{
    $labels = [];
    if ($product !== null) {
        foreach (option_spec($product) as $group) {
            foreach ($group as $item) {
                $labels[$item['key']] = $item['label'];
            }
        }
    }

    $parts = [];
    foreach ($raw as $key => $value) {
        if ($value === null || $value === '' || $value === false || $value === []) {
            continue;
        }
        $key    = (string) $key;
        $label  = $labels[$key] ?? CUSTOMIZATION_LABELS[$key] ?? ucwords(str_replace('_', ' ', $key));
        $isFlag = $value === true || $value === 1 || $value === 'true' || $value === 'on';
        $parts[] = $isFlag ? $label : $label . ': ' . (is_scalar($value) ? (string) $value : json_encode($value));
    }

    return implode(' · ', $parts);
}
