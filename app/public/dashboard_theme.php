<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$user = requireLogin();
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
$activeTab = 'theme';
$pageTitle = 'Tema grafico';

$pdo = getDB();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    
    // Handle user profile theme selection
    $theme = $_POST['page_theme'] ?? 'colorful';
    if (array_key_exists($theme, PAGE_THEMES)) {
        $stmt = $pdo->prepare('UPDATE profiles SET page_theme = ? WHERE user_id = ?');
        $stmt->execute([$theme, $profile['id']]);
        $success = 'Tema della tua pagina aggiornato.';
        $user = currentUser();
        $profile = getActingProfile($user);
    }
}

// Sfondo rappresentativo di ciascun tema per il selezionatore a schermo intero — un unico
// CSS background (non le mini-anteprime composte usate nella lista qui sotto), riprendendo
// dove possibile gli stessi colori delle vere regole body.*-page in style.css.
$themePickerBackgrounds = [
    'colorful' => 'linear-gradient(135deg,#FFD6A5,#A0C4FF,#BDB2FF)',
    'rock' => 'linear-gradient(160deg,#1a1a1a,#0d0d0d)',
    'wave' => 'linear-gradient(160deg,#0b0b12,#1a1a2e)',
    'wave-light' => 'linear-gradient(160deg,#f3f2f8,#e2e2ee)',
    'wave-neon' => 'linear-gradient(160deg,#060609,#12121c)',
    'aurora' => 'linear-gradient(180deg,#131b2e 0%,#4a3157 45%,#d97a52 100%)',
    'plasma' => 'linear-gradient(160deg,#7b2ff7,#ff2fb0,#2f6bff)',
    'golden' => 'linear-gradient(180deg,#f3a35a,#8a4a3a,#2b1a1f)',
    'la-caraffa' => 'linear-gradient(135deg,#0a3d62,#1e5f8c)',
    'electric' => 'radial-gradient(ellipse at 50% -10%, rgba(108,92,231,0.45), transparent 55%), #0a0a0f',
    'circuit' => 'repeating-linear-gradient(45deg, transparent 0 10px, rgba(108,92,231,0.35) 10px 12px, transparent 12px 22px), repeating-linear-gradient(-45deg, transparent 0 10px, rgba(108,92,231,0.35) 10px 12px, transparent 12px 22px), #0b0b0f',
    'cosplay-blue' => '#AFC8FB',
    'cosplay-pink' => '#FBB8D3',
    'cosplay-mint' => '#A9EAD1',
    'cosplay-yellow' => '#FCE28B',
    'scifi-cyan' => 'radial-gradient(ellipse at 50% -10%, rgba(41,245,255,0.35), transparent 55%), linear-gradient(160deg, #060c16, #081824)',
    'scifi-magenta' => 'radial-gradient(ellipse at 50% -10%, rgba(255,61,240,0.35), transparent 55%), linear-gradient(160deg, #0c0616, #180a22)',
    'horror-blood' => 'radial-gradient(ellipse at 50% 25%, #241010, #0a0505 72%)',
    'horror-fog' => 'radial-gradient(ellipse at 50% 25%, #2a332e, #0e1210 72%)',
    'thriller-noir' => 'radial-gradient(ellipse at 50% -5%, #2c2c2c, #050505 68%)',
    'thriller-shadow' => 'radial-gradient(ellipse at 50% -5%, #1c2733, #05080c 68%)',
    'agent-gold' => 'linear-gradient(120deg, #0a0a0a 40%, #3a2f10 50%, #0a0a0a 60%)',
    'agent-silver' => 'linear-gradient(120deg, #0a0a0a 40%, #33383d 50%, #0a0a0a 60%)',
    'bunny' => 'linear-gradient(160deg, #FFF6F0 0%, #FFE1EC 55%, #FFD1E3 100%)',
    'zebra' => 'repeating-linear-gradient(125deg, #0a0a0a 0 10px, #f5f5f5 10px 18px, #0a0a0a 18px 24px, #f5f5f5 24px 40px, #0a0a0a 40px 46px, #f5f5f5 46px 64px)',
    'polka' => 'radial-gradient(circle, #fff 22%, transparent 24%) 0 0/34px 34px, #FFD23F',
    'meme67' => 'repeating-conic-gradient(from 0deg at 50% 10%, #1c1c1c 0deg 8deg, #111 8deg 16deg)',
    'napoli' => 'linear-gradient(180deg, #063b66 0%, #0f7ac2 45%, #2aa8e0 70%, #f2b552 100%)',
    'startrek' => 'radial-gradient(ellipse 60% 40% at 20% 15%, rgba(153,102,204,0.35), transparent 60%), radial-gradient(ellipse 50% 40% at 85% 75%, rgba(60,140,220,0.28), transparent 60%), linear-gradient(180deg, #05061a 0%, #0a0e2e 100%)',
    'galactic' => 'radial-gradient(circle at 20% 20%, rgba(123,60,255,0.45), transparent 45%), radial-gradient(circle at 80% 30%, rgba(41,245,255,0.35), transparent 50%), radial-gradient(circle at 50% 85%, rgba(80,40,180,0.4), transparent 55%), linear-gradient(180deg, #05040f 0%, #0a0820 100%)',
    'garden-anomaly' => 'radial-gradient(circle at 50% 35%, rgba(255,255,255,0.55), transparent 60%), linear-gradient(160deg,#eef1f5,#c9d2de)',
    'retro-disc' => 'radial-gradient(circle at 50% 15%, rgba(255,255,255,0.18), transparent 45%), linear-gradient(160deg, #4b2fd6 0%, #7b3ce0 100%)',
];

include __DIR__ . '/_dash_header.php';
?>
<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js"></script>
  <?php if (!empty($message)): ?><div class="alert success"><?= e($message) ?></div><?php endif; ?>
  <?php if (!empty($error)): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
  <?php if (!empty($success)): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>

  <h2 style="font-size: 18px; color: #1a1a1a; margin-bottom: 15px;">👤 Tema della tua pagina pubblica</h2>

  <details class="help-box">
    <summary>ℹ️ Come funziona</summary>
    <p style="color:var(--text-muted)">
      Scegli l'aspetto grafico della tua pagina pubblica — il colore che hai scelto in
      "Profilo" resta il tuo accento personale in ogni tema, cambia solo lo stile generale
      (sfondo, forme, atmosfera).
    </p>
  </details>

  <div class="card theme-picker-trigger">
    <button type="button" id="open-theme-picker" class="btn" style="width:auto;">🎨 Apri il selezionatore animato</button>
    <p style="color:var(--text-muted);font-size:13px;margin:10px 0 0;">Oppure scegli dalla lista qui sotto.</p>
  </div>

  <div class="theme-picker hidden" id="theme-picker">
    <?php foreach (PAGE_THEMES as $key => $theme): ?>
      <?php $isSelected = ($profile['page_theme'] ?? 'colorful') === $key; ?>
      <div class="theme-picker__item" data-theme-key="<?= e($key) ?>">
        <div class="theme-picker__item-inner" style="background:<?= e($themePickerBackgrounds[$key] ?? '#333') ?>;">
          <span class="theme-picker__item-label"><?= e($theme['label']) ?></span>
          <?php if ($isSelected): ?><span class="theme-picker__item-check">✓</span><?php endif; ?>
        </div>
        <i></i>
      </div>
    <?php endforeach; ?>
    <button type="button" class="theme-picker__close" aria-label="Chiudi">✕</button>
  </div>
  <script src="<?= assetUrl('/assets/js/theme-picker.js') ?>" defer></script>

  <form method="post">
    <?= csrfField() ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;">
      <?php foreach (PAGE_THEMES as $key => $theme): ?>
        <?php $isSelected = ($profile['page_theme'] ?? 'colorful') === $key; ?>
        <label style="display:block;cursor:pointer;">
          <input type="radio" id="theme-radio-<?= e($key) ?>" name="page_theme" value="<?= e($key) ?>" <?= $isSelected ? 'checked' : '' ?> onchange="this.form.submit()" style="display:none;">
          <div class="card" style="border:2px solid <?= $isSelected ? 'var(--accent)' : 'transparent' ?>;text-align:center;">
            <?php if ($key === 'wave'): ?>
              <div style="background:linear-gradient(160deg,#0b0b12,#1a1a2e);border-radius:6px;padding:16px 10px;margin-bottom:10px;position:relative;overflow:hidden;">
                <div style="position:absolute;inset:0;background:repeating-linear-gradient(115deg, rgba(108,92,231,0.25) 0 2px, transparent 2px 14px);"></div>
                <div style="position:relative;width:40px;height:40px;border-radius:50%;background:#222;margin:0 auto 8px;border:2px solid #6C5CE7;"></div>
                <div style="position:relative;background:rgba(255,255,255,0.1);border-radius:6px;height:10px;margin-bottom:4px;"></div>
                <div style="position:relative;background:rgba(255,255,255,0.1);border-radius:6px;height:10px;"></div>
              </div>
            <?php elseif ($key === 'wave-light'): ?>
              <div style="background:linear-gradient(160deg,#f3f2f8,#e2e2ee);border-radius:6px;padding:16px 10px;margin-bottom:10px;position:relative;overflow:hidden;">
                <div style="position:absolute;inset:0;background:repeating-linear-gradient(115deg, rgba(34,34,59,0.08) 0 3px, transparent 3px 20px);"></div>
                <div style="position:relative;width:40px;height:40px;border-radius:50%;background:#fff;margin:0 auto 8px;border:2px solid #6C5CE7;"></div>
                <div style="position:relative;background:rgba(34,34,59,0.15);border-radius:6px;height:10px;margin-bottom:4px;"></div>
                <div style="position:relative;background:rgba(34,34,59,0.15);border-radius:6px;height:10px;"></div>
              </div>
            <?php elseif ($key === 'aurora'): ?>
              <div style="background:linear-gradient(180deg,#131b2e 0%,#4a3157 45%,#d97a52 100%);border-radius:6px;padding:16px 10px;margin-bottom:10px;">
                <div style="width:40px;height:40px;border-radius:50%;background:#222;margin:0 auto 8px;border:2px solid #fff;"></div>
                <div style="background:#e0654f;border-radius:8px;height:10px;margin-bottom:4px;box-shadow:0 2px 0 rgba(0,0,0,0.2);"></div>
                <div style="background:#e0654f;border-radius:8px;height:10px;box-shadow:0 2px 0 rgba(0,0,0,0.2);"></div>
              </div>
            <?php elseif ($key === 'plasma'): ?>
              <div style="background:linear-gradient(160deg,#7b2ff7,#ff2fb0,#2f6bff);border-radius:6px;padding:16px 10px;margin-bottom:10px;">
                <div style="width:40px;height:40px;border-radius:50%;background:#fff;margin:0 auto 8px;"></div>
                <div style="background:#ff8a3d;border-radius:999px;height:10px;margin-bottom:4px;"></div>
                <div style="background:#ff8a3d;border-radius:999px;height:10px;"></div>
              </div>
            <?php elseif ($key === 'golden'): ?>
              <div style="background:linear-gradient(180deg,#f3a35a,#8a4a3a,#2b1a1f);border-radius:6px;padding:16px 10px;margin-bottom:10px;">
                <div style="width:40px;height:40px;border-radius:50%;background:#fff;margin:0 auto 8px;"></div>
                <div style="background:#fff;border-radius:999px;height:10px;margin-bottom:4px;"></div>
                <div style="background:#fff;border-radius:999px;height:10px;"></div>
              </div>
            <?php elseif ($key === 'wave-neon'): ?>
              <div style="background:linear-gradient(160deg,#060609,#12121c);border-radius:6px;padding:16px 10px;margin-bottom:10px;position:relative;overflow:hidden;">
                <div style="position:absolute;inset:0;background:repeating-linear-gradient(90deg, rgba(108,92,231,0.3) 0 2px, transparent 2px 8px);"></div>
                <div style="position:relative;width:40px;height:40px;border-radius:50%;background:#111;margin:0 auto 8px;border:2px solid #6C5CE7;"></div>
                <div style="position:relative;background:rgba(255,255,255,0.08);border-radius:6px;height:10px;margin-bottom:4px;"></div>
                <div style="position:relative;background:rgba(255,255,255,0.08);border-radius:6px;height:10px;"></div>
              </div>
            <?php elseif ($key === 'rock'): ?>
              <div style="background:#131313;border-radius:6px;padding:16px 10px;margin-bottom:10px;">
                <div style="width:40px;height:40px;border-radius:6px;border:2px solid #6C5CE7;margin:0 auto 8px;background:#1a1a1a;"></div>
                <div style="background:#1c1c1c;border-left:3px solid #6C5CE7;border-radius:3px;height:10px;margin-bottom:4px;"></div>
                <div style="background:#1c1c1c;border-left:3px solid #6C5CE7;border-radius:3px;height:10px;"></div>
              </div>
            <?php elseif ($key === 'electric'): ?>
              <div style="background:radial-gradient(ellipse at 50% -10%, rgba(108,92,231,0.35), transparent 55%), #0a0a0f;border-radius:6px;padding:16px 10px;margin-bottom:10px;">
                <div style="width:40px;height:40px;border-radius:50%;background:#141420;margin:0 auto 8px;border:2px solid #6C5CE7;box-shadow:0 0 10px 1px rgba(108,92,231,0.7);"></div>
                <div style="border:1.5px solid #6C5CE7;border-radius:8px;height:10px;margin-bottom:4px;box-shadow:0 0 6px rgba(108,92,231,0.6);"></div>
                <div style="border:1.5px solid #6C5CE7;border-radius:8px;height:10px;box-shadow:0 0 6px rgba(108,92,231,0.6);"></div>
              </div>
            <?php elseif ($key === 'circuit'): ?>
              <div style="background:#0b0b0f;border-radius:6px;padding:16px 10px;margin-bottom:10px;position:relative;overflow:hidden;">
                <div style="position:absolute;inset:0;background:repeating-linear-gradient(45deg, transparent 0 10px, rgba(108,92,231,0.35) 10px 12px, transparent 12px 22px), repeating-linear-gradient(-45deg, transparent 0 10px, rgba(108,92,231,0.35) 10px 12px, transparent 12px 22px);"></div>
                <div style="position:relative;width:40px;height:40px;border-radius:50%;background:#141420;margin:0 auto 8px;border:2px solid #6C5CE7;"></div>
                <div style="position:relative;background:rgba(255,255,255,0.08);border-radius:6px;height:10px;margin-bottom:4px;"></div>
                <div style="position:relative;background:rgba(255,255,255,0.08);border-radius:6px;height:10px;"></div>
              </div>
            <?php elseif ($key === 'colorful'): ?>
              <div style="background:linear-gradient(160deg,#FFD6A5,#A0C4FF,#BDB2FF);border-radius:6px;padding:16px 10px;margin-bottom:10px;">
                <div style="width:40px;height:40px;border-radius:50%;background:#fff;margin:0 auto 8px;"></div>
                <div style="background:rgba(255,255,255,0.6);border-radius:999px;height:10px;margin-bottom:4px;"></div>
                <div style="background:rgba(255,255,255,0.6);border-radius:999px;height:10px;"></div>
              </div>
            <?php elseif (in_array($key, ['cosplay-blue', 'cosplay-pink', 'cosplay-mint', 'cosplay-yellow'], true)): ?>
              <?php $cosplayFill = ['cosplay-blue' => '#E1EBFF', 'cosplay-pink' => '#FFE1EF', 'cosplay-mint' => '#DFFBEF', 'cosplay-yellow' => '#FFF6D6'][$key]; ?>
              <div style="background:<?= e($themePickerBackgrounds[$key]) ?>;border-radius:6px;padding:16px 10px;margin-bottom:10px;">
                <div style="width:40px;height:40px;border-radius:50%;background:#fff;margin:0 auto 8px;border:2px solid #14141a;box-shadow:2px 2px 0 #14141a;"></div>
                <div style="background:<?= e($cosplayFill) ?>;border:2px solid #14141a;border-radius:999px;height:10px;margin-bottom:5px;box-shadow:2px 2px 0 #14141a;"></div>
                <div style="background:<?= e($cosplayFill) ?>;border:2px solid #14141a;border-radius:999px;height:10px;box-shadow:2px 2px 0 #14141a;"></div>
              </div>
            <?php elseif (in_array($key, ['scifi-cyan', 'scifi-magenta'], true)): ?>
              <?php $neon = $key === 'scifi-cyan' ? '#29f5ff' : '#ff3df0'; ?>
              <div style="background:<?= e($themePickerBackgrounds[$key]) ?>;border-radius:6px;padding:16px 10px;margin-bottom:10px;">
                <div style="width:40px;height:40px;border-radius:6px;background:#0a0f16;margin:0 auto 8px;border:2px solid <?= $neon ?>;box-shadow:0 0 10px <?= $neon ?>;"></div>
                <div style="border:1px solid <?= $neon ?>;border-radius:6px;height:10px;margin-bottom:4px;"></div>
                <div style="border:1px solid <?= $neon ?>;border-radius:6px;height:10px;"></div>
              </div>
            <?php elseif (in_array($key, ['horror-blood', 'horror-fog'], true)): ?>
              <?php $hacc = $key === 'horror-blood' ? '#8a1414' : '#3f5c4d'; ?>
              <div style="background:<?= e($themePickerBackgrounds[$key]) ?>;border-radius:6px;padding:16px 10px;margin-bottom:10px;">
                <div style="width:40px;height:40px;border-radius:3px;background:#141010;margin:0 auto 8px;border:2px solid <?= $hacc ?>;"></div>
                <div style="border:2px solid <?= $hacc ?>;border-radius:3px;height:10px;margin-bottom:4px;background:#141010;"></div>
                <div style="border:2px solid <?= $hacc ?>;border-radius:3px;height:10px;background:#141010;"></div>
              </div>
            <?php elseif (in_array($key, ['thriller-noir', 'thriller-shadow'], true)): ?>
              <?php $tacc = $key === 'thriller-noir' ? '#c11119' : '#3aa7c9'; ?>
              <div style="background:<?= e($themePickerBackgrounds[$key]) ?>;border-radius:6px;padding:16px 10px;margin-bottom:10px;">
                <div style="width:40px;height:40px;border-radius:50%;background:#141414;margin:0 auto 8px;border:2px solid rgba(255,255,255,0.3);"></div>
                <div style="border-left:4px solid <?= $tacc ?>;border-radius:6px;height:10px;margin-bottom:4px;background:#141414;"></div>
                <div style="border-left:4px solid <?= $tacc ?>;border-radius:6px;height:10px;background:#141414;"></div>
              </div>
            <?php elseif (in_array($key, ['agent-gold', 'agent-silver'], true)): ?>
              <?php $macc = $key === 'agent-gold' ? '#d4af37' : '#c7ccd1'; ?>
              <div style="background:<?= e($themePickerBackgrounds[$key]) ?>;border-radius:6px;padding:16px 10px;margin-bottom:10px;">
                <div style="width:40px;height:40px;border-radius:50%;background:#111;margin:0 auto 8px;border:2px solid <?= $macc ?>;"></div>
                <div style="border:1.5px solid <?= $macc ?>;border-radius:8px;height:10px;margin-bottom:4px;background:#111;"></div>
                <div style="border:1.5px solid <?= $macc ?>;border-radius:8px;height:10px;background:#111;"></div>
              </div>
            <?php elseif ($key === 'bunny'): ?>
              <div style="background:linear-gradient(160deg, #FFF6F0 0%, #FFE1EC 55%, #FFD1E3 100%);border-radius:6px;padding:22px 10px 16px;margin-bottom:10px;">
                <div style="position:relative;width:40px;height:40px;margin:0 auto 8px;">
                  <div style="position:absolute;top:-15px;left:-1px;width:11px;height:22px;border-radius:50% 50% 45% 45% / 65% 65% 35% 35%;background:#fff;border:1.5px solid #6b4a4a;transform:rotate(-22deg);"></div>
                  <div style="position:absolute;top:-15px;right:-1px;width:11px;height:22px;border-radius:50% 50% 45% 45% / 65% 65% 35% 35%;background:#fff;border:1.5px solid #6b4a4a;transform:rotate(22deg);"></div>
                  <div style="position:relative;width:40px;height:40px;border-radius:50%;background:#fff;border:2px solid #6b4a4a;"></div>
                </div>
                <div style="background:#fff;border:1.5px solid #FFC2D9;border-radius:999px;height:10px;margin-bottom:4px;"></div>
                <div style="background:#fff;border:1.5px solid #FFC2D9;border-radius:999px;height:10px;"></div>
              </div>
            <?php elseif ($key === 'zebra'): ?>
              <div style="background:<?= e($themePickerBackgrounds[$key]) ?>;border-radius:6px;padding:16px 10px;margin-bottom:10px;">
                <div style="width:40px;height:40px;border-radius:50%;background:#fff;margin:0 auto 8px;border:3px solid #0a0a0a;box-shadow:2px 2px 0 #FF1493;"></div>
                <div style="background:#fff;border:2px solid #0a0a0a;border-radius:999px;height:10px;margin-bottom:5px;box-shadow:2px 2px 0 #FF1493;"></div>
                <div style="background:#fff;border:2px solid #0a0a0a;border-radius:999px;height:10px;box-shadow:2px 2px 0 #FF1493;"></div>
              </div>
            <?php elseif ($key === 'polka'): ?>
              <div style="background:<?= e($themePickerBackgrounds[$key]) ?>;border-radius:6px;padding:16px 10px;margin-bottom:10px;">
                <div style="width:40px;height:40px;border-radius:50%;background:#fff;margin:0 auto 8px;border:3px solid #E63946;"></div>
                <div style="background:#fff;border:2px solid #E63946;border-radius:999px;height:10px;margin-bottom:5px;"></div>
                <div style="background:#fff;border:2px solid #E63946;border-radius:999px;height:10px;"></div>
              </div>
            <?php elseif ($key === 'meme67'): ?>
              <div style="background:<?= e($themePickerBackgrounds[$key]) ?>;border-radius:6px;padding:16px 10px;margin-bottom:10px;position:relative;overflow:hidden;">
                <span style="position:absolute;left:-6px;top:-14px;font-size:44px;font-weight:900;font-style:italic;color:rgba(255,122,0,0.3);">6</span>
                <span style="position:absolute;right:-8px;bottom:-18px;font-size:44px;font-weight:900;font-style:italic;color:rgba(57,255,20,0.25);">7</span>
                <div style="position:relative;width:40px;height:40px;border-radius:50%;background:#000;margin:0 auto 8px;border:2px solid #FF7A00;"></div>
                <div style="position:relative;background:#39FF14;border:2px solid #000;border-radius:999px;height:10px;margin-bottom:5px;"></div>
                <div style="position:relative;background:#39FF14;border:2px solid #000;border-radius:999px;height:10px;"></div>
              </div>
            <?php elseif ($key === 'napoli'): ?>
              <div style="background:<?= e($themePickerBackgrounds[$key]) ?>;border-radius:6px;padding:16px 10px;margin-bottom:10px;position:relative;overflow:hidden;">
                <div style="position:absolute;left:0;right:0;bottom:0;height:14px;background:rgba(6,20,35,0.65);clip-path:polygon(0% 100%,0% 60%,30% 35%,45% 55%,60% 10%,75% 45%,100% 55%,100% 100%);"></div>
                <div style="position:relative;width:40px;height:40px;border-radius:50%;background:#fff;margin:0 auto 8px;border:2px solid #FFD447;box-shadow:0 0 10px #FFD447;"></div>
                <div style="position:relative;background:rgba(255,255,255,0.85);border:1.5px solid #fff;border-radius:999px;height:10px;margin-bottom:4px;"></div>
                <div style="position:relative;background:rgba(255,255,255,0.85);border:1.5px solid #fff;border-radius:999px;height:10px;"></div>
              </div>
            <?php elseif ($key === 'startrek'): ?>
              <div style="background:<?= e($themePickerBackgrounds[$key]) ?>;border-radius:6px;padding:16px 10px;margin-bottom:10px;position:relative;overflow:hidden;">
                <span style="position:absolute;left:15%;top:20%;width:2px;height:2px;background:#fff;border-radius:50%;"></span>
                <span style="position:absolute;left:70%;top:15%;width:2px;height:2px;background:#fff;border-radius:50%;"></span>
                <span style="position:absolute;left:85%;top:55%;width:1.5px;height:1.5px;background:#fff;border-radius:50%;"></span>
                <span style="position:absolute;left:30%;top:70%;width:1.5px;height:1.5px;background:#fff;border-radius:50%;"></span>
                <div style="position:relative;width:40px;height:40px;border-radius:50%;background:#0a0e2e;margin:0 auto 8px;border:2px solid #6B8CFF;box-shadow:0 0 8px #6B8CFF;"></div>
                <div style="position:relative;background:#FF9F1C;border-radius:999px 8px 8px 999px;height:10px;margin-bottom:5px;"></div>
                <div style="position:relative;background:#FF9F1C;border-radius:999px 8px 8px 999px;height:10px;"></div>
              </div>
            <?php elseif ($key === 'galactic'): ?>
              <div style="background:<?= e($themePickerBackgrounds[$key]) ?>;border-radius:6px;padding:16px 10px;margin-bottom:10px;position:relative;overflow:hidden;">
                <span style="position:absolute;left:12%;top:25%;width:2px;height:6px;background:#fff;transform:rotate(20deg);"></span>
                <span style="position:absolute;left:75%;top:18%;width:2px;height:8px;background:#fff;transform:rotate(20deg);"></span>
                <span style="position:absolute;left:88%;top:60%;width:1.5px;height:5px;background:#fff;transform:rotate(20deg);"></span>
                <div style="position:relative;width:40px;height:40px;border-radius:50%;background:#0a0a1e;margin:0 auto 8px;border:2px solid #29f5ff;box-shadow:0 0 10px #29f5ff;"></div>
                <div style="position:relative;background:linear-gradient(90deg,#062f22,#12b57a);border:1px solid #3dffb0;border-radius:8px;height:10px;margin-bottom:5px;"></div>
                <div style="position:relative;background:linear-gradient(90deg,#2a0a3f,#a13fd6);border:1px solid #e39bff;border-radius:8px;height:10px;"></div>
              </div>
            <?php elseif ($key === 'garden-anomaly'): ?>
              <div style="background:<?= e($themePickerBackgrounds[$key]) ?>;border-radius:6px;padding:16px 10px;margin-bottom:10px;position:relative;overflow:hidden;">
                <div style="position:relative;width:40px;height:40px;border-radius:50%;margin:0 auto 8px;background:radial-gradient(circle at 35% 30%, rgba(255,255,255,0.9), rgba(150,190,230,0.35) 60%, rgba(100,140,190,0.25));border:1px solid rgba(255,255,255,0.7);box-shadow:0 0 10px rgba(120,160,220,0.5), inset 0 0 8px rgba(255,255,255,0.6);"></div>
                <div style="position:relative;background:#1f7cff;border-radius:999px;height:8px;width:8px;margin:0 auto 4px;"></div>
                <div style="position:relative;background:rgba(120,160,220,0.5);border-radius:999px;height:10px;"></div>
              </div>
            <?php elseif ($key === 'retro-disc'): ?>
              <div style="background:<?= e($themePickerBackgrounds[$key]) ?>;border-radius:6px;padding:16px 10px;margin-bottom:10px;position:relative;overflow:hidden;">
                <div style="position:relative;width:34px;height:34px;border-radius:50%;margin:0 auto 8px;background:conic-gradient(from 0deg,#fff,#9be7ff,#ffd6f0,#d8ffea,#fff);box-shadow:0 0 10px rgba(255,255,255,0.5);"></div>
                <div style="position:relative;background:#fff;border-radius:2px;height:9px;width:38px;margin:0 auto 4px;font:900 8px/9px Arial, sans-serif;text-align:center;color:#4b2fd6;letter-spacing:.05em;">DISCO</div>
                <div style="position:relative;background:rgba(255,255,255,0.35);border-radius:999px;height:8px;width:60%;margin:0 auto;"></div>
              </div>
            <?php endif; ?>
            <strong><?= e($theme['label']) ?></strong>
            <?php if ($isSelected): ?><span style="color:var(--accent);font-size:12px;font-weight:700;"> ✓ attivo</span><?php endif; ?>
            <p style="color:var(--text-muted);font-size:12.5px;margin:4px 0 0;"><?= e($theme['description']) ?></p>
          </div>
        </label>
      <?php endforeach; ?>
    </div>
  </form>
<?php include __DIR__ . '/_dash_footer.php'; ?>
