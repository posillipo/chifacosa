<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
checkInstallation();

$pageTitle = null;
$pageDescription = 'Benvenuto';

include __DIR__ . '/_public_header.php';
?>

        <style>
            .hero {
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 60vh;
                text-align: center;
            }

            .hero-content {
                max-width: 600px;
            }

            .hero h1 {
                font-size: 64px;
                font-weight: 800;
                margin-bottom: 24px;
                letter-spacing: -1px;
                line-height: 1.1;
            }

            .hero p {
                font-size: 18px;
                color: var(--text-secondary, #666);
                margin-bottom: 40px;
                line-height: 1.7;
            }

            .hero-cta {
                display: flex;
                gap: 16px;
                justify-content: center;
                flex-wrap: wrap;
            }

            footer {
                text-align: center;
                padding: 40px 24px;
                color: #999;
                font-size: 13px;
                border-top: 1px solid #eee;
                margin-top: auto;
            }

            @media (max-width: 768px) {
                .hero h1 {
                    font-size: 42px;
                }

                .hero p {
                    font-size: 16px;
                }
            }
        </style>

        <section class="hero">
            <div class="hero-content">
                <h1>Benvenuto a<br><span style="color: var(--primary-color, #0077DD);"><?= e(siteName()) ?></span></h1>
                
                <?php $user = currentUser(); if ($user): ?>
                    <p>Hai accesso! Vai alla tua dashboard per iniziare.</p>
                    <div class="hero-cta">
                        <a href="/dashboard.php" class="btn-primary">Vai alla Dashboard</a>
                    </div>
                <?php else: ?>
                    <p>Crea il tuo profilo e condividi la tua storia online.</p>
                    <div class="hero-cta">
                        <a href="/login.php" class="btn-secondary">Accedi</a>
                        <a href="/register.php" class="btn-primary">Iscriviti Gratis</a>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </main>

    <footer>
        &copy; <?= date('Y') ?> <?= e(siteName()) ?> — <a href="/credits.php">Credits</a>
    </footer>

    <?= embedTrackingBodyEnd() ?>
</body>
</html>
