# Mochajaki Food Ordering System

## Setup (XAMPP)
1. Copy the `mochajaki` folder into `C:\xampp\htdocs\`.
2. Start **Apache** and **MySQL** from XAMPP Control Panel.
3. Open `http://localhost/phpmyadmin` and import `database.sql`.
4. Open `api/config.php`. The included database settings match a default XAMPP install (`root`, blank password). Change them if your MySQL setup differs.
5. Open `http://localhost/mochajaki/customer/` for customers or `http://localhost/mochajaki/admin/` for administrators.

## Default admin
- Email: `admin@mochajaki.local`
- Password: `ChangeMe123!`

The password is stored as a PHP password hash in the database. Change this password after first login if this is deployed beyond a local class project.

## Payment QR images
Place the official payment QR images here:
- `assets/payments/gcash-qr.png`
- `assets/payments/maya-qr.png`

The system does not automatically verify e-wallet transactions. Customers submit the reference number and optional screenshot; an admin checks the payment and confirms or rejects it. Payment stays **Pending Verification** until an administrator confirms it.

## Delivery fee
The delivery fee is currently **₱0.00** because no confirmed fee was provided. Update the server-side `$deliveryFee` value in `api/orders.php` after the business confirms its delivery charge.

## Notes
- Customer and admin sign-ins use separate sessions, so signing out of one no longer signs out the other.
- Customers can view order stats and change their password from the **My account** page (`api/change-password.php`).
- Login and registration live in one place: the account page on the customer site.
- Product prices are recalculated on the server, not trusted from the browser.
- Customer orders are scoped to the signed-in customer; admin APIs require an admin session.
- Product customizations are stored in `order_items.customizations` as JSON.
- PHP PDO MySQL and the `fileinfo` extension are required.
- `database.sql` drops and recreates these tables. Import it only for a fresh install because reimporting resets existing data.
