<?php
/** Returns the signed-in customer-area user, or 401 when nobody is signed in. */
require __DIR__ . '/config.php';

json_out(['user' => require_login()]);
