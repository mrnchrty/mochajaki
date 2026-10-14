<?php
/** Returns the signed-in administrator, or 401/403 for anyone else. */
require __DIR__ . '/config.php';

json_out(['user' => require_admin()]);
