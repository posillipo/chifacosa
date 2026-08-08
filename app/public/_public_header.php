<?php
header("Content-Type: text/html; charset=utf-8");
/**
 * Header centralizzato per tutte le pagine pubbliche del sito
 * Include il tema globale del sito via CSS variables
 *
 * Uso: <?php include __DIR__ . '/_public_header.php'; ?>
 */

require_once __DIR__ . '/../src/functions.php';

$site_name = siteName();

// Ottieni l'utente corrente (se loggato)
$user = currentUser();
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? e($pageTitle) . ' — ' . e($site_name) : e($site_name) ?></title>
    <?php if (isset($pageDescription)): ?>
        <meta name="description" content="<?= e($pageDescription) ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
    
    <!-- Tema globale del sito -->
    <?= embedThemeCSS() ?>
    
    <!-- Script di tracking -->
    <?= embedPrivacyScript() ?>
    <?= embedTrackingHead() ?>
    <?= embedGoogleAnalytics() ?>
    
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: #f8f8f8;
            color: var(--text-primary, #1a1a1a);
            line-height: 1.6;
        }

        /* Navbar Pubblica */
        .public-navbar {
            background: white;
            border-bottom: 1px solid #eee;
            padding: 20px 40px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            max-width: 1200px;
            margin: 0 auto;
            width: 100%;
        }

        .public-navbar .logo {
            font-size: 24px;
            font-weight: 800;
            letter-spacing: -0.5px;
            color: var(--deep-color, #003D99);
        }

        .public-navbar nav {
            display: flex;
            gap: 32px;
            align-items: center;
        }

        .public-navbar a {
            text-decoration: none;
            color: var(--text-primary, #1a1a1a);
            font-weight: 500;
            font-size: 15px;
            transition: color 0.2s;
        }

        .public-navbar a:hover {
            color: var(--primary-color, #0077DD);
        }

        /* Button Styles */
        .btn-primary {
            background: var(--primary-color, #0077DD);
            color: white;
            padding: 12px 28px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            font-size: 15px;
            border: none;
            cursor: pointer;
            transition: background 0.2s, transform 0.1s;
            display: inline-block;
        }

        .btn-primary:hover {
            opacity: 0.9;
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: transparent;
            color: var(--primary-color, #0077DD);
            padding: 12px 28px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            font-size: 15px;
            border: 2px solid var(--primary-color, #0077DD);
            cursor: pointer;
            transition: all 0.2s;
            display: inline-block;
        }

        .btn-secondary:hover {
            background: var(--primary-color, #0077DD);
            color: white;
        }

        /* Link Styles */
        a {
            color: var(--primary-color, #0077DD);
            text-decoration: none;
            transition: color 0.2s;
        }

        a:hover {
            color: var(--deep-color, #003D99);
        }

        /* Form Styles */
        input:focus,
        textarea:focus,
        select:focus {
            outline: none;
            border-color: var(--primary-color, #0077DD) !important;
            box-shadow: 0 0 0 3px rgba(0, 119, 221, 0.1) !important;
        }

        /* Card Styles */
        .card {
            background: white;
            border: 1px solid #eee;
            border-radius: 8px;
            padding: 20px;
            transition: all 0.2s;
        }

        .card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        /* Alert Styles */
        .alert {
            padding: 15px 20px;
            border-radius: 6px;
            margin-bottom: 20px;
            border-left: 4px solid;
        }

        .alert.success {
            background: rgba(76, 175, 80, 0.1);
            color: var(--success-color, #4CAF50);
            border-left-color: var(--success-color, #4CAF50);
        }

        .alert.error {
            background: rgba(244, 67, 54, 0.1);
            color: var(--error-color, #F44336);
            border-left-color: var(--error-color, #F44336);
        }

        .alert.info {
            background: rgba(0, 119, 221, 0.1);
            color: var(--primary-color, #0077DD);
            border-left-color: var(--primary-color, #0077DD);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .public-navbar {
                flex-direction: column;
                gap: 20px;
                padding: 20px 24px;
            }

            .public-navbar nav {
                width: 100%;
                justify-content: center;
                flex-wrap: wrap;
            }

            .btn-primary, .btn-secondary {
                width: 100%;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <?= embedTrackingBodyStart() ?>

    <header class="public-navbar">
        <div class="logo"><?= e($site_name) ?></div>
        <nav>
            <a href="/">Home</a>
            <?php if ($user): ?>
                <a href="/dashboard.php">Dashboard</a>
                <a href="/logout.php">Esci</a>
            <?php else: ?>
                <a href="/login.php">Accedi</a>
                <a href="/register.php" class="btn-primary">Iscriviti</a>
            <?php endif; ?>
        </nav>
    </header>

    <main style="max-width: 1200px; margin: 0 auto; padding: 40px 20px;">
