<?php require_once __DIR__ . '/_studio_boot.php'; /* GENERATO da scripts/make-studio.py: non modificare a mano */ ?>
<?php
session_start();
require_once __DIR__ . '/../../src/functions.php';
$user = requireLogin();
header('Location: /dashboard_timeline.php');
exit;
