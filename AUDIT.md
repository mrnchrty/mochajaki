# Mochajaki — full-system audit

**Scope:** everything in `mochajaki-system-improved.zip` (24 files: 14 PHP endpoints, 2 HTML pages, 2 JS bundles, 2 CSS files, `database.sql`, README, asset placeholders).
**Unzipped copy for review/patching:** `./mochajaki/` (the archive itself is untouched).

## How this was checked

| Check | Tool / method | Result |
|---|---|---|
| PHP syntax, all 14 API files | `php-parser` (npm), full AST parse | ✅ clean |
| JS syntax, both bundles | `node --check` | ✅ clean |
| DOM contract: every `$('id')` in JS vs `id="…"` in HTML | script cross-match (104 ids) | ✅ all resolve (3 are injected at runtime) |
| CSS contract: classes used vs classes defined | script cross-match (163 classes) | ✅ only 2 dead classes |
| API contract: fetch paths/payload keys vs `$_GET/$_POST/body_json` keys | manual matrix, 16 endpoints | ✅ all match |
| **Pricing parity**: client (`customer.js`) vs server (`orders.php`) for all 78 seeded products, every default + 22 oz + wings-pieces + add-on path | replicated both algorithms in Python | ✅ agrees everywhere (see B2/B3 for the caveat) |
| Seeded admin login works | `bcrypt.checkpw` against `database.sql` hash | ✅ `admin@mochajaki.local` / `ChangeMe123!` is correct (cost 12) |
| Output escaping / XSS | every one of ~90 template interpolations in both bundles inspected | ✅ all data goes through `esc()` / `escapeHTML()` / `textContent` |
| SQL injection | every endpoint reviewed for string-built SQL | ✅ none found (all prepared, ids cast to int) |

Runtime testing against a live PHP+MySQL was **not** possible in this sandbox (no PHP/MySQL available and package mirrors are unreachable), so anything marked *static* below is from code reading + logic replication, not from a live request.

---

## Top issues (fix these first)

| # | Severity | Issue |
|---|---|---|
| A2 | 🔴 High | `crypto.randomUUID()` in the cart breaks "Add to cart" on any non-HTTPS origin (LAN IP demo) |
| A3 | 🔴 High | Admin is never sent back to the login screen when the session expires (string mismatch) |
| A5 | 🔴 High | Cash payments can never be marked paid; "Refunded" is unreachable dead state |
| A4 | 🔴 High | Delivery fee is hardcoded in 3 places, is charged on Pickup, and the cart shows ₱0.00 |
| A1 | 🟠 Medium | Payment QR images referenced by the UI don't exist in the package |
| B1 | 🟠 Medium | A customer can un-verify a **Paid** payment / submit payment for a **Cancelled** order |
| A6 | 🟠 Medium | `admin-products.php` has no length validation → uncaught PDOException (HTTP 500) |
| B2 | 🟠 Medium | Wings / milkshake / soda prices are hardcoded in **two** places, overriding admin price edits |
| C1 | 🟠 Medium | Payment screenshots are web-served in a browsable folder (customer PII) |
| F1 | 🟠 Medium | App requires **PHP ≥ 8.1** (`:never`); README doesn't say so → hard crash on older XAMPP |
| D1 | 🟡 Low | Admin dashboard does 1 + N queries for N orders (no pagination anywhere) |

---

## A. Things that break in a real deployment

**A1 — 🟠 The GCash/Maya QR images don't exist.**
`customer/index.html` embeds `../assets/payments/gcash-qr.png` and `maya-qr.png`; the package ships only `README.txt` in that folder. `onerror` hides each broken image, so at checkout the customer sees two empty labelled boxes ("GCash QR" / "Maya QR") exactly where they're supposed to scan and pay. The README tells you to add them, but nothing in the system warns you they're missing.

**A2 — 🔴 "Add to cart" silently does nothing on a non-secure origin (static).**
`customer/customer.js` → `openProduct()` → `cart.push({key:crypto.randomUUID(), …})`. `crypto.randomUUID()` is a **secure-context-only** API. It works on `http://localhost` (XAMPP default) but is `undefined` on `http://192.168.x.x/…` — i.e. the moment you demo the project from a phone on the LAN, the handler throws a `TypeError`, the cart never updates, no toast appears, and only the browser console shows why.
```js
const uid = () => crypto.randomUUID ? crypto.randomUUID()
            : 'k' + Date.now().toString(36) + Math.random().toString(36).slice(2);
```

**A3 — 🔴 Expired admin sessions are handled wrongly (static).**
`api/config.php` → `require_login()` responds `{'error':'Please sign in to continue.'}` (401).
`admin/admin.js` → `refreshAll()` catch block:
```js
if(e.message.toLowerCase().includes('administrator')||e.message.toLowerCase().includes('log in')){ …show login… } else alert(e.message)
```
`"please sign in to continue."` contains **"sign in"**, not "log in". So when the admin session times out, the admin keeps staring at a stale dashboard, gets an `alert("Please sign in to continue.")` on every action, and must work out to reload the page. The role message (`Administrator access required.`) does match, so only the expiry path is broken. Also note `startApp()` silently swallows the same error at boot.

**A4 — 🔴 Delivery fee is inconsistent in three places and applied to Pickup.**
- `api/orders.php`: `$deliveryFee=0.00; $total=$subtotal+$deliveryFee;` — a hardcoded constant with **no `$type==='Delivery'` check**, so the day you follow the README and set a fee, Pickup orders are charged it too.
- `customer/customer.js`: `function fee(){return 0}` — an independent hardcoded copy; the README only tells you to edit `orders.php`.
- Consequence: set the fee to ₱30 and the cart says ₱Total₀ while the created order and the `payments.amount` row say ₱Total₀+30. The customer only discovers the real total on the Orders page, after the order exists. There is no confirmation step showing the server-calculated total.
- Fix: expose the fee from the API (or a `settings` row), use one source, and skip it for Pickup.

**A5 — 🔴 Cash can never be marked paid; refunds are a dead feature.**
`api/admin-payments.php` rejects Cash outright (`if(!$p||$p['method']==='Cash') → 'No online payment to verify.'`), and nothing else ever writes `payments.status`. So every cash order displays **"Cash · Pending"** forever in the admin tables, on the customer's order card, and in the dashboard filter — there is no way to record that the money was collected. The same endpoint only accepts `Paid`/`Failed`, while the schema enum and the admin payment filter both offer **`Refunded`**, which no code path can ever set (dead filter option, and no refund flow at all).

**A6 — 🟠 `admin-products.php` is the one endpoint that can 500 on ordinary input (static).**
It is the only write endpoint with no length validation (`register.php` checks 120 chars, `orders.php` guards the cart, etc.):
```php
'INSERT INTO products(name,category,description,price,image_url) VALUES(?,?,?,?,?)'
```
`name` is `VARCHAR(150)`, `category VARCHAR(80)`, `image_url VARCHAR(500)`; `price` is `DECIMAL(10,2)`. A 160-char product name or a 520-char image URL raises a `PDOException` that nothing catches → HTTP 500 with PHP's error output (XAMPP ships `display_errors=On`), and the admin UI shows the useless "Unexpected server response." `image_url` is also accepted without any URL validation.

**A7 — 🟠 `orders.php` delivery address is unvalidated.**
`delivery_address` is `VARCHAR(500)` and the checkout `<textarea>` has no `maxlength`. A long address triggers a `PDOException`, which the generic catch turns into `'Unable to place order.'` — a legitimate customer sees an unexplainable failure. (Also: the app collects no phone/contact number for delivery at all, which a real rider needs.)

---

## B. Correctness / state-machine problems

**B1 — 🟠 Payment state can be reset by the customer (static).**
`api/payment-submit.php`:
```sql
UPDATE payments SET gateway_reference=?, payment_screenshot=COALESCE(?,payment_screenshot),
 status='Pending Verification', verified_by=NULL, verified_at=NULL WHERE id=?
```
No guard on the current payment status and no guard on the order status. The UI hides the "Submit payment details" button once `Paid`, but a crafted request flips an **already-verified Paid payment back to Pending Verification** and wipes the verifier trail, and payment details can be submitted for a **Cancelled** order (client-side check only). Intended retry-after-reject works, but it should be explicitly whitelisted (`status <> 'Paid'`, `o.status <> 'Cancelled'`).

**B2 — 🟠 Prices hardcoded in two places override the database (verified).**
Both sides re-derive prices for two families instead of reading `products.price`:

| Family | Hardcoded rule | Lives in |
|---|---|---|
| Chicken Wings | pieces → 108 / 158 / 198 / 308 | `customer.js` **and** `orders.php` |
| Milkshake, Fruit Soda | size → 40 / 50 (`in_array($p['category'],[…])`) | `customer.js` **and** `orders.php` |

I replicated both algorithms for all 78 seeded products: they agree today, so it *works*, but any admin price edit for those items is silently ignored at checkout (the menu card and the modal show the admin's price, the order charges the hardcoded one), and the next edit to only one of the two copies desynchronises the cart from the order.

**B3 — 🟠 Cart prices are frozen while the server re-prices (static).**
The cart lives in `localStorage` with the price captured at add time; `orders.php` recalculates from the live DB. If the admin raises a price or disables an item, the cart total and the order total diverge — the customer is charged the new price with no warning and no confirmation screen. (Unavailable items at least fail loudly: "A selected item is no longer available.")

**B4 — 🟠 "Add extra rice" is free.**
The wings modal offers `<input name="extra_rice">`, `customer.js` sets `custom.extra_rice=true` but lists no rate for it, and `orders.php` never adds a charge for `extra_rice`. It is accepted, stored in `customizations`, and never billed.

**B5 — 🟠 Milk tea is a dead end if it's ever re-added (latent, static).**
`customer.js` milk-tea branch: `selectField('Flavor','flavor',drinkFlavors['Milk Tea'],true)` — **`drinkFlavors` has no `Milk Tea` key** (only `Milkshake` and `Fruit Soda`), so the required flavor dropdown renders with zero options and blocks submission; the branch also collects **no size**, while `orders.php` requires `size` *and* `sweetness` for anything matching `/milk tea/`. Any Milk Tea product an admin adds is unorderable. (`selectField` already defends with `opts=opts||[]`, which is why it fails silently instead of throwing.)

**B6 — Milkshake/soda validation is skipped for combos, but enforced in the UI.**
`orders.php` uses `preg_match('/milkshake/i', …) && !preg_match('/combo/i', …)`, so for an item like "A2 Burger & Milkshake" (category `Combos`) the server never requires `flavor` — while the client marks the "Milkshake flavor" select `required`. Client-stricter-only, so no customer harm, but the rule is inverted relative to intent.

**B7 — "Plain Rice" and "Java Rice" ask you to choose a rice type.**
Both are category `Rice Meal`, and both the client (`isRice`) and server (`/rice meal|silog|with rice/`) key off that, so the ₱20/₱25 rice *sides* present a "Rice type: Plain/Java" dropdown (and so does every silog, which is at least arguable). The matching is substring-based on `category + name`, so it also silently changes behaviour for any future product whose name happens to contain "silog" or "with rice".

**B8 — "Needs attention" count relies on operator precedence.**
```js
orders.filter(o=>['Order Placed'].includes(o.status) || ['Pending Verification','Pending'].includes(o.payment_status) && o.payment_method!=='Cash').length
```
`&&` binds tighter, so it's `A || (B && C)`: every `Order Placed` order counts (even if already paid), plus every non-cash order with a pending payment in *any* state — including `Cancelled` orders whose payment is still pending verification. Reads like an unintended result; add parentheses and decide the rule.

**B9 — Warehouse/kitchen statuses aren't validated against the order type.**
`admin-orders.php` PATCH accepts any of the 7 statuses for any order, so a Pickup order can be put "Out for Delivery" and a Delivery order "Ready for Pickup". The customer sees the nonsense status verbatim.

**B10 — Silent no-ops on bad ids.**
`admin-orders.php` (status) and `admin-products.php` (update/toggle/delete) return `{ok:true}` without checking `rowCount()`, so a missing/deleted id reports success.

**B11 — Order code collisions are unhandled.**
`'MJK-'.date('ymd').'-'.strtoupper(bin2hex(random_bytes(3)))` → 3 bytes of entropy (~16.7 M combos/day) behind a `UNIQUE` column, with no retry loop; a collision surfaces as "Unable to place order." Use 4–5 bytes and/or retry.

**B12 — Paid → Failed is possible, but there's no refund path.**
`admin-payments.php` happily flips an already-`Paid` payment to `Failed` (verified_by/verified_at overwritten), and `Refunded` (present in the enum and in the filter dropdown) can never be set.

---

## C. Security & privacy

Overall the app is in decent shape here — see section G for what I verified as *not* vulnerable. The real findings:

**C1 — 🟠 Payment screenshots are publicly served without any access control.**
`payment-submit.php` writes to `assets/payments/uploads/payment-<id>-<random>.png` inside the web root, and `admin-order-detail.php` links to it as a plain static URL. Nothing authenticates those files: anyone with the URL (or with the directory listing) sees a customer's proof of payment — often a photo showing full name, GCash number and reference. XAMPP's default `httpd.conf` keeps `Options Indexes` enabled for `htdocs`, so `…/mochajaki/assets/payments/uploads/` is **browsable**. Minimum fix: an `.htaccess` with `Options -Indexes`, ideally move uploads outside the docroot and serve them through an authenticated endpoint.

**C2 — 🟠 No brute-force protection.**
`login.php` has no throttling, lockout, delay or CAPTCHA, and the seeded admin credentials are published in the README and in `database.sql` (`ChangeMe123!` — I verified the hash matches, so it does work). Unlimited attempts against a known email is the cheapest way into this app.

**C3 — Session name is chosen by a client-supplied header.**
`api/config.php`: `$area=(($_SERVER['HTTP_X_AREA']??'')==='admin')?'admin':'customer'; session_name('mj_'.$area);` I could not build an exploit (authorisation still comes from `$_SESSION['user']`, and cross-origin requests can't set custom headers), but the storage area should be derived from the request path, not trusted from a header.

**C4 — No CSRF tokens.**
State-changing endpoints (place order, change status, verify payment, delete product, change password) rely entirely on `SameSite=Lax` plus the fact that cross-site JSON POSTs need a CORS preflight (which the API never grants). That holds today; it becomes a real hole the moment the session cookie is relaxed (e.g. `SameSite=None` for a future embed/mobile client) or a form-encoded alias endpoint is added.

**C5 — Session/password hygiene gaps.**
`change-password.php` does not regenerate the session id, does not revoke other sessions, and has no throttling; nothing limits new-password reuse. Registration enforces only "≥ 8 characters".

**C6 — Missing hardening headers.**
No `Cache-Control: no-store` on session-bearing JSON (`me.php`, `orders.php`), so intermediate/browser caches may retain authenticated payloads; no CSP (hard to add now because both UIs are built on inline `onclick=` handlers). `X-Content-Type-Options: nosniff` *is* set — good.

**C7 — Low: user enumeration timing.**
`password_verify()` only runs when the email exists, so response time leaks whether an account exists. Cosmetic at this scale.

---

## D. Performance & scale

**D1 — 🟡 N+1 in the main admin read path.** `admin-orders.php` GET runs one query for all orders, then **one extra query per order** for its items (`$q=$pdo->prepare('SELECT product_name,quantity FROM order_items WHERE order_id=?')` inside `foreach`). `refreshAll()` fires that on every load *and* after every status update/verify action. 500 orders = 501 queries per dashboard refresh. One `GROUP_CONCAT`/`WHERE order_id IN (…)` pass fixes it.

**D2 — No pagination anywhere.** Every list endpoint returns the whole table (`orders`, `customers`, `products`), the admin renders it into a single unpaginated table, and `renderDashboard()` recomputes the best-seller map and status histogram over the full array on each render. Fine for a demo, quadratic-feeling for a real canteen after a semester of orders.

**D3 — Low.** `renderDashboard()` also re-derives everything on every `refreshAll()`, including the status bars; no caching or incremental update.

---

## E. UX / polish

- **E1** After a cash order, `checkout-message` is written **after** navigating to the Orders view, so "Order MJK-… placed successfully." is never seen (the online path at least opens the payment modal).
- **E2** The payment modal closes only via ×, while the product modal also closes on backdrop click — inconsistent; neither handles Escape or traps focus.
- **E3** Dashboard "Recorded sales" sums every non-cancelled order **including unpaid and Failed ones** (and cash orders that were never collected); the customer's "Total spent" has the same problem — both overstate money actually received.
- **E4** `payments.verified_by` / `verified_at` are written but never displayed, so no audit trail is visible in the UI.
- **E5** Every cash order shows `Cash · Pending` forever (see A5).
- **E6** `admin-products.php` supports `DELETE` but the admin UI has no delete button; the Customers table shows a bare order count with no way to open that customer's orders.
- **E7** The sidebar avatar/name for the signed-in admin is hardcoded (`M`, "Administrator") and doesn't use `currentUser`.
- **E8** Missing order fields a food business needs: notes/instructions, contact number for delivery, pickup time, order-level cancel (customers can't cancel; admins can only set status).
- **E9** Dead CSS/markup drift: `.admin-section` and `.account-surface` are used in markup but never defined; `.auth-surface` and `.field-grid` are defined but unused (`.field-grid` only appears inside `.modal .field-grid`, and no element uses it). `[hidden]{display:none!important}` **is** present in both stylesheets, so view/section toggling works correctly.
- **E10** `menu-pop` doesn't close on Escape or outside focus; minor a11y.
- **E11** Dead code in `customer.js`: `pendingOrder` is assigned but never read, and `isDrink(p)` is defined but never called (it also overlaps `isRice`/`isWings`/`isCombo`, which is where the option-matrix bugs B5/B6/B7 come from).

---

## F. Docs & environment mismatches

- **F1 — 🟠 Undocumented PHP ≥ 8.1 requirement.** `api/config.php` declares `function json_out(array $data,int $status=200):never` — the `never` return type exists only in PHP **8.1+**. The README only requires "PDO MySQL and fileinfo". On XAMPP with PHP 8.0 or 7.4, *every* API request dies at parse time with a fatal error, which looks exactly like "the site is broken" with no hint. Either state the version in the README or drop `:never` for compatibility.
- **F2** `mb_strlen()` is used in `register.php` and `payment-submit.php`; `mbstring` isn't mentioned in the README (normally enabled, but registration breaks if it's off).
- **F3** The README's delivery-fee instruction is incomplete/incorrect (three copies of the value, fee also applied to Pickup) — see A4.
- **F4** `database.sql` has no `SET NAMES utf8mb4;`. Columns are `utf8mb4`/`utf8mb4_unicode_ci`, and the seed data contains a typographic apostrophe ("Finger Lick’n Good"); importing via the `mysql` CLI without `--default-character-set=utf8mb4` can mangle it. (phpMyAdmin handles it correctly.)
- **F5** `order_items.customizations JSON` needs MySQL ≥ 5.7 / MariaDB ≥ 10.2 — fine on any current XAMPP, unstated.
- **F6** `assets/payments/README.txt` and `uploads/README.txt` are the only files in those folders; the QR images the UI expects were never shipped (A1).
- **F7** The default admin password is a documented constant and works (`ChangeMe123!`, bcrypt cost 12 — verified). Change it before any non-local deploy, and note that the "imporing `database.sql` resets data" warning means it comes back on every re-import.

---

## G. What's actually solid (verified, don't "fix" these)

- **No SQL injection:** every query is a prepared statement, ids are cast to `int`, PDO runs with `EMULATE_PREPARES=false`.
- **No XSS found:** all ~90 dynamic interpolations in both bundles are escaped (`esc`/`escapeHTML`) or written with `textContent`; inline handlers only ever receive numbers, hardcoded strings or escaped values.
- **Prices are genuinely recalculated server-side** (the README claim is true for the money that matters: add-ons, quantities, size/pieces variants), and my replication of both algorithms agrees for all 78 seeded products on every default path — cart total == order total today.
- **Auth/session design is sound:** separate `mj_customer` / `mj_admin` cookie names, `HttpOnly` + `SameSite=Lax` + HTTPS-aware `Secure`, `session_regenerate_id(true)` on login and registration, admin endpoints gated by `require_admin()`, customer order queries scoped by `WHERE o.customer_id=?`, `password_hash`/`password_verify` (bcrypt), and no password hash ever leaves the server (`clean_user`).
- **Order creation is transactional**: `beginTransaction` + `FOR UPDATE` re-reads the live price, per-line qty is capped at 50 and the cart at 50 lines, failures roll back, and availability is re-checked at commit time.
- **Upload handling is careful**: 5 MB cap, `finfo` MIME sniffing, extension whitelist (jpg/png/webp), `getimagesize` cross-check, random filename, `mkdir` fallback — the only gap is *where* the files land (C1).
- **Admin/API error handling** is consistent and friendly on every endpoint — except `admin-products.php` (A6).
- Both bundles pass `node --check`, all 14 PHP files parse cleanly, and `[hidden]` is correctly honoured in both stylesheets.

---

## Suggested fix order

1. **A2** `randomUUID` fallback → one line, unblocks LAN demos.
2. **A3** match `'sign in'` in the admin error handler → one line.
3. **A5 + B1 + B12** payment lifecycle: allow recording cash as Paid, guard `payment-submit.php` (`status <> 'Paid'`, order not Cancelled), and either implement or remove `Refunded`.
4. **A4 + B2** single source of truth for money: serve delivery fee + variant prices from the API/DB, use them in the client, keep Pickup at zero, and add a confirmation step showing the server-calculated total.
5. **A6 + A7** length/URL validation on the two unvalidated write paths.
6. **A1** ship or generate the QR images (or replace the block with "ask the admin for the QR").
7. **C1 + C2** `Options -Indexes` (or a moved uploads dir) and login throttling.
8. **F1** document PHP ≥ 8.1 (or drop `:never`).
9. **D1** collapse the N+1 in `admin-orders.php`.
10. **B5, B7, B4, E1–E11** clean-up pass on the option matrix, free "extra rice", dead code/CSS and messaging.

---

# Resolution log

Everything above has since been fixed; this section records **how** each item
was closed so the fixes can be checked. The code was rewritten in a readable,
commented style (multi-line PHP/JS, no behaviour changes beyond the fixes
themselves); the archive `mochajaki-system-improved.zip` is untouched.

## What was changed

| Area | Files | What changed |
|---|---|---|
| Money, one source of truth | **new** `api/pricing.php` | All option prices, flavour lists, the delivery fee and the receipt text live in one tested file. `api/orders.php` charges with `price_for()`; `api/products.php` sends the same spec to the browser. Fixes **A4**, **B2**, **B4-B7**, **F3**. |
| Order/payment lifecycle | `api/orders.php`, `api/payment-submit.php`, `api/admin-payments.php` | Cash orders can be recorded as Paid; GCash/Maya need a reference and go to *Pending Verification*; a **Paid** payment can no longer be re-submitted by the customer; Refunded is reachable (Paid → Refunded); every transition stamps `verified_by`/`verified_at`. Fixes **A5**, **B1**, **B12**, **E5**. |
| Validation | `api/admin-products.php`, `api/orders.php`, `api/register.php`, `api/change-password.php` | Length/format limits on names, categories, addresses, notes, contact numbers and passwords; friendly 422 messages instead of PDOExceptions and HTTP 500. Fixes **A6**, **A7**, **C5**. |
| Sessions & request security | `api/config.php`, **new** `api/admin-login.php`/`admin-logout.php`/`admin-me.php`, `.htaccess` | Separate `mj_customer`/`mj_admin` sessions chosen by endpoint name (never by a request header); every write must carry `X-Requested-With: XMLHttpRequest` and a same-host Origin/Referer; `no-store` + `nosniff` headers; CSP with `script-src 'self'`. Fixes **C3**, **C4**, **C6**. |
| Screenshots | `assets/payments/uploads/.htaccess`, **new** `api/admin-payment-proof.php` | The uploads folder denies direct access and lists nothing; admins stream an image through a session-checked endpoint that re-validates the stored path. Fixes **C1**. |
| Sign-in throttling | `api/config.php` (+ `login_attempts` table), `api/login.php`, `api/register.php`, `api/change-password.php` | 8 failures per 15 minutes per e-mail + IP, and one bcrypt round is spent even for unknown addresses, so timing no longer reveals which accounts exist. Fixes **C2**, **C7**. |
| Admin dashboard | `admin/admin.js`, `admin/index.html` | Session expiry returns to the sign-in screen (401/403 aware); no more "needs attention" double counting; sales count only non-cancelled **paid** orders; verification trail is shown; delete/toggle/edit buttons exist; the customer table drills into that customer's orders; hidden items are listed via the admin endpoint. Fixes **A3**, **B8**, **E3**, **E4**, **E6**, **E7**, **E9**. |
| N+1 queries | `api/admin-orders.php` | Orders, their line counts and the best sellers come from single aggregate queries. Fixes **D1**. |
| Docs | `README.md`, `database.sql`, `assets/payments/*` | PHP ≥ 8.1 + mbstring + fileinfo + MySQL 5.7 stated, utf8mb4 import command, delivery fee/option prices point at `api/pricing.php`, uploads folder is not web-served, `tests/pricing.test.php` documents itself. Fixes **F1**, **F2**, **F4**, **F5**, **F7**. |

## Verification actually run

| Check | How | Result |
|---|---|---|
| All 19 PHP endpoints parse | `token_get_all(..., TOKEN_PARSE)` on PHP 8.4 (wasm) | ✅ 19/19 clean |
| Menu prices | `php tests/pricing.test.php` | ✅ 36 passed, 0 failed |
| Browser price == server price | extracted `unitPrice()` from `customer.js` vs `validate_customizations()` on PHP, 285 option combinations × 78 seeded products | ✅ 0 mismatches (31 invalid combinations correctly refused) |
| Both front-ends, end to end | real `customer.js`/`admin.js` driven in jsdom against API-shaped fixtures: sign-in, cart with options, pickup vs delivery totals, order placement, payment upload, order detail, payment confirm, menu CRUD, customer drill-down, session expiry | ✅ 46/46 checks |
| SQL | every statement in `api/*.php` and `database.sql` parsed as MySQL (node-sql-parser) | ✅ 15/15 runtime statements, 18/19 schema statements — the one "failure" is `SET NAMES utf8mb4`, which the parser does not implement but MySQL does |
| Endpoint/session alignment | script: every `require_admin()` endpoint must be an `admin-*.php` file, and each page may only call endpoints of its own area | ✅ all aligned (this check caught and fixed two mismatches: the screenshot endpoint and the admin product list) |
| CSS/DOM contract | every class used in HTML/JS has a rule; every id the JS queries exists | ✅ 140 classes, 0 gaps |
| CSP safety | no inline `on*=` handler, no inline `<script>`, no `@import`/remote `url()` in either page or stylesheet | ✅ |

**Not verifiable here:** no PHP+MySQL stack and no reachable package mirrors in
the sandbox, so DB-backed endpoints were exercised through their front-ends in
jsdom and through the pure-PHP logic that both sides share, never against a live
database. A 10-minute XAMPP smoke test closes that gap:

1. Sign in as `admin@mochajaki.local` / `ChangeMe123!`.
2. Place a pickup order with a 22 oz milkshake; check the total shows the size
   surcharge and no delivery fee.
3. Place a delivery order; the address and contact fields must appear and the
   fee must be added.
4. Submit a GCash reference with a screenshot, then open the order in the admin
   panel, view the screenshot and confirm the payment.

## Still open (owner action, not a code bug)

- **A1** — the store's own `gcash-qr.png` / `maya-qr.png` cannot be generated for
  you; until they are added, checkout shows a "ask the store for the account
  details" note instead of a broken image.
- **D2** — no pagination. Both lists are complete and correct, just unpaginated;
  fine for a class project, worth revisiting if orders grow into the thousands.
- `DELIVERY_FEE` stays `0.00` until the owner confirms the charge.
