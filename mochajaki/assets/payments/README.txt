Add your official payment QR codes here:

  gcash-qr.png   — the store's GCash QR code
  maya-qr.png    — the store's Maya QR code

Paths are configured in api/pricing.php (PAYMENT_METHODS) and must stay relative
to the customer page, i.e. `../assets/payments/gcash-qr.png`.

Until an image exists the checkout does not show a broken picture: it tells the
customer to ask the store for the account details. You can also put a short
account name/number in the `label` value of PAYMENT_METHODS, which is shown in
place of the QR code (or next to it).
