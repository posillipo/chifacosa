<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
clearRememberToken();
session_destroy();
header('Location: /');
exit;
