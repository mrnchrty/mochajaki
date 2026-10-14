Payment screenshots uploaded by customers are stored here.

This folder is NOT served by the web server (see .htaccess in this folder) —
administrators open a screenshot through api/admin-payment-proof.php, which verifies
the admin session before sending the image.

Keep this folder writable by the web server (Apache on XAMPP runs as the user
who started it, so the default permissions are normally fine).
