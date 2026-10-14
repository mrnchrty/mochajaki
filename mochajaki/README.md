# Mochajaki Food Ordering System

A small food-ordering site: customers browse the menu, build a cart, choose
pickup or delivery and place an order; staff manage the menu, watch incoming
orders and confirm e-wallet payments from the admin panel.

## Requirements

- **PHP 8.1 or newer.** The API uses the `never` return type, which does not
  exist before 8.1.
- **Extensions:** `pdo_mysql`, `mbstring` (text length limits) and `fileinfo`
  (checking that a payment screenshot really is an image). All three ship with
  XAMPP and are enabled by default.
- **MySQL 5.7 or newer / MariaDB 10.2 or newer** — the schema stores product
  customizations in a `JSON` column.
- Apache with `AllowOverride All` (XAMPP's default) so the `.htaccess` files
  are read.

## Setup (XAMPP)

1. Copy the `mochajaki` folder into `C:\xampp\htdocs\`.
2. Start **Apache** and **MySQL** from the XAMPP Control Panel.
3. Import the database. Either open `http://localhost/phpmyadmin`, choose
   *Import* and pick `database.sql`, **or** run it from a terminal:

   ```
   mysql --default-character-set=utf8mb4 -u root < database.sql
   ```

   The `--default-character-set=utf8mb4` flag matters: without it the peso sign
   in the product descriptions can arrive as `?`. The script starts with
   `CREATE DATABASE`/`USE`, so it does not need a database to be selected
   first. (Importing it again drops and recreates every table — use it for a
   fresh install only.)
4. If your MySQL setup differs from a default XAMPP install (user `root`, blank
   password), set `DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASS` at the top of
   `api/config.php`.
5. Open `http://localhost/mochajaki/customer/` for customers and
   `http://localhost/mochajaki/admin/` for administrators.

## Default admin

- Email: `admin@mochajaki.local`
- Password: `ChangeMe123!`

The password is stored as a PHP `password_hash()` digest, never in plain text.
Change it after the first sign-in (**My account → Change password**) if this is
deployed anywhere beyond a local class project — the credentials are written in
this file, so anyone who can read the file can sign in.

## Prices and the delivery fee

`api/pricing.php` is the single source of truth for every amount:

| Setting | What it controls |
| --- | --- |
| `DELIVERY_FEE` | charge added to **Delivery** orders only (currently `0.00` — set it once the owner confirms the fee) |
| `DRINK_SIZE_SURCHARGE` | 22 oz drinks |
| `EXTRA_RICE_FEE`, `ADDON_PRICES`, `BULDAK_ADDON_PRICES` | extra rice and add-ons such as pearl, cheese, bacon |
| `WINGS_PIECES_EXTRA` | extra chicken pieces on the wings meals |
| `MILKSHAKE_FLAVORS`, `SODA_FLAVORS`, `MILK_TEA_FLAVORS`, `MILK_TEA_SWEETNESS`, `WINGS_FLAVORS`, `RICE_TYPES`, `COMBO_SIDES`, `TAKOYAKI_VARIANTS` | the choice lists offered per product category |
| `PAYMENT_METHODS`, `ONLINE_PAYMENT_METHODS` | payment options, QR image paths and helper label |

The customer page renders the choices described by that file, the browser adds
the same numbers while the customer shops, and `api/orders.php` re-adds them on
the server before saving — so editing a price in one place cannot make the cart
and the stored order disagree. After changing a price, run the tests:

```
php tests/pricing.test.php
```

## Payment QR images

Add the store's official QR codes here:

- `assets/payments/gcash-qr.png`
- `assets/payments/maya-qr.png`

Paths live in `PAYMENT_METHODS` in `api/pricing.php`. Until an image exists the
checkout is not broken: it tells the customer to ask the store for the account
details instead of showing a broken picture.

The system does **not** verify e-wallet transactions automatically. The customer
submits a reference number and an optional screenshot, and an administrator
confirms or rejects the payment from the order view. Until then the payment
stays **Pending Verification**, and a rejected payment can be refunded and
re-checked.

## Security notes

- Payment screenshots are stored in `assets/payments/uploads/`, which is blocked
  from the web by its `.htaccess`. Administrators view them through
  `api/admin-payment-proof.php`, which checks for an admin session first. If Apache
  ignores `.htaccess` files on your setup, move the uploads folder outside the
  web root and update `$uploadDir` in `api/payment-submit.php`.
- Customer and admin sign-ins use **separate sessions** (`mj_customer` and
  `mj_admin`), so signing out of one does not sign out the other. The session is
  chosen by the endpoint (`admin-*.php` versus everything else) — never by a
  request header.
- Every request that changes something must be a same-site request carrying
  `X-Requested-With: XMLHttpRequest`; anything else is refused, which shuts out
  cross-site (CSRF) submissions.
- Failed sign-ins, registrations and password changes are throttled per e-mail
  address and IP (8 failures per 15 minutes).
- The `.htaccess` in the project root sends a Content-Security-Policy that
  allows scripts only from this site. Keep it that way: add behaviour only in
  `customer.js` / `admin.js` and never an inline `onclick=` handler, or the
  browser will refuse to run it.
- Product prices, delivery fees and totals are always recalculated on the
  server; nothing the browser sends about money is trusted.

## Notes

- Customers can review their orders, see their totals and change their password
  on the **My account** page (`api/change-password.php`).
- Login and registration live in one place: the account page on the customer
  site.
- An order's choices (size, flavour, add-ons) are stored in
  `order_items.customizations` as JSON, together with a human-readable copy for
  receipts.
- Order codes look like `MJK-260101-9F3C1A2B` (date plus a random part).
- `tests/pricing.test.php` is the only test file; it needs no database and no
  web server, so it is safe to run before every commit.
