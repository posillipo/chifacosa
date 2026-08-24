<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
checkInstallation();

$pageTitle = null;
$pageDescription = 'Crea la tua pagina Link in Bio con timeline, blog, brani, eventi, menù e prenotazioni — tutto in un unico posto, personalizzabile con oltre 20 temi grafici.';

// Elenco delle funzioni reali della piattaforma, mostrato sulla Home — tenerlo qui invece che
// sparso nell'HTML rende facile aggiungerne una nuova in futuro senza toccare il markup sotto.
$homeFeatures = [
    ['icon' => 'fa-link', 'label' => 'Link in Bio', 'desc' => 'Tutti i tuoi link importanti in una sola pagina'],
    ['icon' => 'fa-stream', 'label' => 'Timeline', 'desc' => 'Pubblica aggiornamenti e resta in contatto con chi ti segue'],
    ['icon' => 'fa-newspaper', 'label' => 'Blog', 'desc' => 'Articoli con permalink dedicato, ottimizzati per la ricerca'],
    ['icon' => 'fa-music', 'label' => 'Brani', 'desc' => 'Vetrina dei tuoi brani preferiti collegata a Spotify'],
    ['icon' => 'fa-calendar-days', 'label' => 'Eventi', 'desc' => 'Calendario pubblico, condivisibile e sempre aggiornato'],
    ['icon' => 'fa-utensils', 'label' => 'Menù e Prenotazioni', 'desc' => 'Menù digitale e prenotazioni per locali e ristoranti'],
    ['icon' => 'fa-heart', 'label' => 'Follower', 'desc' => 'Chi ti segue riceve una notifica ad ogni tua novità'],
    ['icon' => 'fa-palette', 'label' => 'Oltre 20 temi grafici', 'desc' => 'Scegli lo stile che rispecchia la tua identità'],
];

include __DIR__ . '/_public_header.php';
?>

        <style>
            .hero {
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                padding: 60px 0 20px;
                text-align: center;
            }

            .hero-content {
                max-width: 680px;
            }

            .hero h1 {
                font-size: 56px;
                font-weight: 800;
                margin-bottom: 18px;
                letter-spacing: -1px;
                line-height: 1.1;
            }

            .hero-tagline {
                font-size: 19px;
                color: var(--text-secondary, #666);
                margin-bottom: 36px;
                line-height: 1.6;
            }

            .hero p.hero-cta-line {
                font-size: 15px;
                color: var(--text-secondary, #666);
                margin-bottom: 20px;
            }

            .hero-cta {
                display: flex;
                gap: 16px;
                justify-content: center;
                flex-wrap: wrap;
            }

            .feature-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
                gap: 16px;
                max-width: 980px;
                margin: 56px auto 0;
                text-align: left;
            }

            .feature-chip {
                display: flex;
                gap: 14px;
                align-items: flex-start;
            }

            .feature-chip .feature-icon {
                flex-shrink: 0;
                width: 42px;
                height: 42px;
                border-radius: 10px;
                background: rgba(0, 0, 0, 0.05);
                color: var(--primary-color, #0077DD);
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 17px;
            }

            .feature-chip strong {
                display: block;
                font-size: 15px;
                margin-bottom: 3px;
            }

            .feature-chip span {
                font-size: 13.5px;
                color: var(--text-secondary, #666);
                line-height: 1.5;
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
                    font-size: 38px;
                }

                .hero-tagline {
                    font-size: 16px;
                }
            }
        </style>

        <section class="hero">
            <div class="hero-content">
                <h1>Benvenuto a<br><span style="color: var(--primary-color, #0077DD);"><?= e(siteName()) ?></span></h1>
                <p class="hero-tagline">
                    Una sola pagina per raccogliere link, contenuti e prenotazioni: la tua vetrina
                    online, sempre aggiornata e personalizzabile — che tu sia un artista, un locale
                    o un professionista.
                </p>

                <?php $user = currentUser(); if ($user): ?>
                    <p class="hero-cta-line">Hai accesso! Vai alla tua dashboard per iniziare.</p>
                    <div class="hero-cta">
                        <a href="/dashboard.php" class="btn-primary">Vai alla Dashboard</a>
                    </div>
                <?php else: ?>
                    <p class="hero-cta-line">Crea il tuo profilo e condividi la tua storia online.</p>
                    <div class="hero-cta">
                        <a href="/login.php" class="btn-secondary">Accedi</a>
                        <a href="/register.php" class="btn-primary">Iscriviti Gratis</a>
                    </div>
                <?php endif; ?>
            </div>

            <div class="feature-grid">
                <?php foreach ($homeFeatures as $f): ?>
                    <div class="feature-chip">
                        <span class="feature-icon"><i class="fa-solid <?= e($f['icon']) ?>"></i></span>
                        <div>
                            <strong><?= e($f['label']) ?></strong>
                            <span><?= e($f['desc']) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </main>

    <footer>
        &copy; <?= date('Y') ?> <?= e(siteName()) ?> — <a href="/credits.php">Credits</a>
    </footer>

    <?= embedTrackingBodyEnd() ?>
</body>
</html>
