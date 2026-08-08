<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$user = requireLogin();
header('Location: /dashboard_timeline.php');
exit;
