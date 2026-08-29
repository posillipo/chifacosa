<?php
require_once __DIR__ . '/../src/functions.php';

header('Content-Type: text/plain; charset=UTF-8');
?>
User-agent: *
Disallow: /login.php
Disallow: /register.php
Disallow: /logout.php
Disallow: /dashboard
Disallow: /admin
Disallow: /reset_password.php
Disallow: /forgot_password.php
Disallow: /verify.php
Disallow: /resend_verification.php
Disallow: /login_otp_request.php
Disallow: /login_otp_verify.php

Sitemap: <?= siteUrl('/sitemap.xml') ?>
