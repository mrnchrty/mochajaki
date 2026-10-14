<?php
/** Signs out of the admin session only. */
require __DIR__ . '/config.php';
method_is('POST');

destroy_current_session();
json_out(['ok' => true]);
