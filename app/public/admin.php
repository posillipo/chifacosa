<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
requireAdmin();
header('Location: /admin_dashboard.php');
exit;
