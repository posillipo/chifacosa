<?php
// Pagine pubbliche del Contest a tema AdminLTE (profili con page_theme "adminlte-profile"):
// elenco delle edizioni, pagina di un'edizione, pagina di un brano. Stessa impaginazione delle altre
// pagine AdminLTE: scheda del profilo a sinistra, contenuto al centro, schede laterali a destra
// (su telefono le schede si ripetono dentro la colonna centrale). Il motore (voti, gruppi, sponsor)
// è quello di sempre: qui cambia solo come si mostra.

// Schiarisce/scurisce un colore esadecimale (factor < 1 scurisce, > 1 schiarisce).
function contestShade(string $hex, float $factor): string {
    $hex = ltrim($hex ?: '#6C5CE7', '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
        $hex = '6C5CE7';
    }
    $out = '#';
    foreach ([0, 2, 4] as $i) {
        $v = hexdec(substr($hex, $i, 2));
        $v = $factor < 1 ? (int) round($v * $factor) : (int) round($v + (255 - $v) * ($factor - 1));
        $out .= str_pad(dechex(max(0, min(255, $v))), 2, '0', STR_PAD_LEFT);
    }
    return $out;
}

function contestAlHead(array $artist, string $title, string $extraHead = ''): string {
    $accent = $artist['theme_color'] ?: '#6C5CE7';
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> — <?= e($artist['display_name']) ?> — <?= e(siteName()) ?></title>
<?= $extraHead ?>
<?= adminLteAssetLinks() ?>
<style>
  .ct-accent { color: <?= e($accent) ?>; }
  .ct-btn { background: <?= e($accent) ?>; color: <?= e(getContrastTextColor($artist['theme_color'])) ?>; border: 0; font-weight: 700; }
  .ct-btn:hover, .ct-btn:focus { background: <?= e(contestShade($accent, .85)) ?>; color: <?= e(getContrastTextColor($artist['theme_color'])) ?>; }
  .ct-btn-soft { background: <?= e(contestShade($accent, 1.88)) ?>; color: <?= e(contestShade($accent, .7)) ?>; border: 0; font-weight: 700; }
  .ct-pill { background: <?= e($accent) ?>; color: <?= e(getContrastTextColor($artist['theme_color'])) ?>; font-weight: 700; }
  .ct-pill-soft { background: <?= e(contestShade($accent, 1.88)) ?>; color: <?= e(contestShade($accent, .7)) ?>; font-weight: 700; }
  .ct-bar { background: <?= e($accent) ?>; }
  .ct-cover { width: 56px; height: 56px; border-radius: 10px; object-fit: cover; flex: none; background: <?= e($accent) ?>; color: #fff; display: grid; place-items: center; font-size: 24px; overflow: hidden; }
  .ct-cover img { width: 100%; height: 100%; object-fit: cover; }
  .ct-podium .card { transition: transform .15s ease; }
  .ct-podium a { text-decoration: none; color: inherit; }
  .ct-medal { font-size: 28px; line-height: 1; }
  .ct-gold { border: 2px solid #f5b82e; box-shadow: 0 6px 18px rgba(245,184,46,.35); }
  .ct-silver { border: 2px solid #b8bec7; }
  .ct-bronze { border: 2px solid #cd7f32; }
  .ct-logo { width: 92px; height: 92px; border-radius: 50%; background: #fff; border: 4px solid #fff; box-shadow: 0 6px 18px rgba(0,0,0,.2); display: inline-flex; align-items: center; justify-content: center; overflow: hidden; font-size: 38px; }
  .ct-logo img { width: 100%; height: 100%; object-fit: contain; }
  .ct-step { flex: 1; text-align: center; font-size: 10.5px; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; color: var(--bs-secondary-color); }
  .ct-step.now { color: <?= e(contestShade($accent, .75)) ?>; }
</style>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<?php
    return ob_get_clean();
}

// Scheletro comune: nav, breadcrumb, colonna sinistra, schede a destra, contenuto centrale, script.
function contestAlShell(array $artist, string $head, string $breadcrumbCurrent, array $trail, string $rightCards, string $center, string $scripts = ''): string {
    $slug = $artist['slug'];
    ob_start();
    echo $head;
    ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'contest') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], $breadcrumbCurrent, $trail) ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug, $rightCards, true) ?>
          <div class="col-lg-6 order-1 order-lg-2 adminlte-main-col">
            <?= $center ?>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
<?= $scripts ?>
</body>
</html>
<?php
    return ob_get_clean();
}

function contestAlLogo(array $c): string {
    return !empty($c['logo_path']) ? '<img src="/' . e($c['logo_path']) . '" alt="' . e($c['name']) . '">' : '🎸';
}

// Scheda "eroe" dell'edizione: fascia nel colore del profilo, logo che sporge, fase, barra di avanzamento, conto alla rovescia.
function contestAlHeroCard(array $artist, array $c, array $ph, string $range, int $entriesCount, string $classes = ''): string {
    $accent = $artist['theme_color'] ?: '#6C5CE7';
    $steps = ['Candidature', 'Votazioni', 'Vincitori'];
    ob_start();
    ?>
<div class="card mb-3 overflow-hidden <?= e($classes) ?>">
  <div style="height:84px;background:linear-gradient(135deg,<?= e($accent) ?>,<?= e(contestShade($accent, .6)) ?>);"></div>
  <div class="card-body text-center pt-0" style="margin-top:-48px;">
    <span class="ct-logo"><?= contestAlLogo($c) ?></span>
    <h2 class="h5 mt-2 mb-1"><?= e($c['name']) ?></h2>
    <div class="d-flex flex-wrap gap-1 justify-content-center mb-3">
      <span class="badge rounded-pill ct-pill"><?= in_array($c['status'], ['open', 'voting'], true) ? '● ' : '' ?><?= e($ph['label']) ?></span>
      <?php if ($range): ?><span class="badge rounded-pill ct-pill-soft"><?= e($range) ?></span><?php endif; ?>
      <span class="badge rounded-pill ct-pill-soft"><?= $entriesCount ?> brani</span>
    </div>
    <div class="d-flex gap-1">
      <?php foreach ($steps as $i => $label): $on = $i <= $ph['step']; ?>
        <div class="ct-step <?= $i === $ph['step'] ? 'now' : '' ?>">
          <div class="progress mb-1" style="height:5px;"><div class="progress-bar <?= $on ? 'ct-bar' : '' ?>" style="width:<?= $on ? 100 : 0 ?>%"></div></div>
          <?= e($label) ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php if ($ph['target'] && $ph['target'] > time()): ?>
      <div class="small text-secondary mt-3"><?= e($ph['countdown']) ?> <strong class="fs-6 text-body" data-ct-countdown="<?= (int) $ph['target'] ?>">…</strong></div>
    <?php endif; ?>
  </div>
</div>
<?php
    return ob_get_clean();
}

function contestAlSponsorsCard(array $sponsors, string $classes = ''): string {
    if (!$sponsors) {
        return '';
    }
    ob_start();
    ?>
<div class="card mb-3 <?= e($classes) ?>">
  <div class="card-header fw-bold"><i class="bi bi-stars me-1"></i>Sponsor</div>
  <div class="card-body d-flex flex-wrap gap-3 justify-content-center align-items-center">
    <?php foreach ($sponsors as $sp): $inner = !empty($sp['logo_path']) ? '<img src="/' . e($sp['logo_path']) . '" alt="' . e($sp['name']) . '" style="max-height:44px;max-width:120px;object-fit:contain;">' : '<strong class="small">' . e($sp['name']) . '</strong>'; ?>
      <?php if (!empty($sp['website_url'])): ?><a href="<?= e($sp['website_url']) ?>" target="_blank" rel="noopener sponsored" title="<?= e($sp['name']) ?>"><?= $inner ?></a>
      <?php else: ?><span title="<?= e($sp['name']) ?>"><?= $inner ?></span><?php endif; ?>
    <?php endforeach; ?>
  </div>
</div>
<?php
    return ob_get_clean();
}

function contestAlSponsorBadge(array $en): string {
    if (empty($en['sponsor_name'])) {
        return '';
    }
    $logo = !empty($en['sponsor_logo']) ? '<img src="/' . e($en['sponsor_logo']) . '" alt="' . e($en['sponsor_name']) . '" style="height:18px;max-width:70px;object-fit:contain;vertical-align:middle;">' : '<span class="badge ct-pill-soft">' . e($en['sponsor_name']) . '</span>';
    return '<div class="small text-secondary mt-1">Sostenuto da ' . $logo . '</div>';
}

function contestAlCover(array $en): string {
    return '<div class="ct-cover">' . (!empty($en['image_url']) ? '<img src="' . e($en['image_url']) . '" alt="" loading="lazy">' : '<i class="bi bi-music-note-beamed"></i>') . '</div>';
}

// JS: conto alla rovescia + ricerca del brano senza ricaricare la pagina (con markup Bootstrap).
function contestAlScripts(): string {
    return <<<'JS'
<script>
(function () {
  document.querySelectorAll('[data-ct-countdown]').forEach(function (cd) {
    var target = parseInt(cd.getAttribute('data-ct-countdown'), 10) * 1000;
    var tick = function () {
      var s = Math.max(0, Math.floor((target - Date.now()) / 1000));
      var d = Math.floor(s / 86400), h = Math.floor(s % 86400 / 3600), m = Math.floor(s % 3600 / 60);
      cd.textContent = d + 'g ' + h + 'h ' + m + 'm';
    };
    tick(); setInterval(tick, 30000);
  });
  var form = document.getElementById('track-search');
  if (!form || !window.fetch) { return; }
  var box = document.getElementById('track-results');
  var holder = document.getElementById('csrf-holder');
  var input = form.querySelector('input[name="q"]');
  var button = form.querySelector('button');
  var seq = 0;
  function note(text) {
    box.textContent = '';
    var p = document.createElement('p');
    p.className = 'text-secondary small mt-2 mb-0';
    p.textContent = text;
    box.appendChild(p);
  }
  function render(items) {
    box.textContent = '';
    if (!items.length) { note('Nessun brano trovato.'); return; }
    items.forEach(function (t) {
      var f = document.createElement('form');
      f.method = 'post';
      f.className = 'd-flex align-items-center gap-2 mt-2';
      f.innerHTML = holder.innerHTML;
      var h = document.createElement('input');
      h.type = 'hidden'; h.name = 'track'; h.value = t.id;
      f.appendChild(h);
      if (t.image) {
        var img = document.createElement('img');
        img.src = t.image; img.alt = '';
        img.style.cssText = 'width:48px;height:48px;border-radius:8px;object-fit:cover;';
        f.appendChild(img);
      }
      var span = document.createElement('span');
      span.className = 'flex-grow-1';
      var strong = document.createElement('strong');
      strong.textContent = t.name;
      var small = document.createElement('small');
      small.className = 'text-secondary';
      small.textContent = t.artist;
      span.appendChild(strong);
      span.appendChild(document.createElement('br'));
      span.appendChild(small);
      f.appendChild(span);
      var b = document.createElement('button');
      b.className = 'btn btn-sm ct-btn'; b.type = 'submit'; b.textContent = 'Candida';
      f.appendChild(b);
      box.appendChild(f);
    });
  }
  form.addEventListener('submit', function (ev) {
    var q = input.value.trim();
    if (q.length < 2) { return; }
    ev.preventDefault();
    var mine = ++seq;
    note('Ricerca in corso…');
    button.disabled = true;
    fetch(location.pathname + '?search=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) { if (mine === seq) { render(d.results || []); } })
      .catch(function () { if (mine === seq) { note('Ricerca non riuscita: riprova.'); } })
      .then(function () { button.disabled = false; });
  });
})();
</script>
JS;
}

// ---------------------------------------------------------------------------------------------
// Elenco delle edizioni — /profilo/contest
// ---------------------------------------------------------------------------------------------
function renderAdminLteContestListPage(array $artist, array $contests): string {
    $slug = $artist['slug'];
    $head = contestAlHead($artist, 'Contest');
    ob_start();
    ?>
<div class="card mb-3 overflow-hidden">
  <div class="card-body text-center py-4" style="background:linear-gradient(135deg,<?= e($artist['theme_color'] ?: '#6C5CE7') ?>,<?= e(contestShade($artist['theme_color'] ?: '#6C5CE7', .6)) ?>);color:#fff;">
    <div class="display-6">🏆</div>
    <h1 class="h4 mt-1 mb-1">Contest di <?= e($artist['display_name']) ?></h1>
    <p class="mb-0 opacity-75">Gare a voti tra i brani delle band emergenti: scegli un'edizione, ascolta, candida il tuo brano o vota.</p>
  </div>
</div>
<?php foreach ($contests as $c): $ph = contestPhaseInfo($c); $range = contestDateRange($c['voting_start_at'] ?: $c['candidatures_open_at'], $c['voting_end_at'] ?: $c['candidatures_close_at']); ?>
  <a href="<?= e(contestUrl($c)) ?>" class="card mb-3 text-decoration-none text-reset">
    <div class="card-body d-flex align-items-center gap-3">
      <span class="ct-logo" style="width:64px;height:64px;font-size:28px;border-width:2px;box-shadow:none;border-color:#eee;"><?= contestAlLogo($c) ?></span>
      <div class="flex-grow-1 min-w-0">
        <h2 class="h6 mb-1"><?= e($c['name']) ?></h2>
        <div class="d-flex flex-wrap gap-1 align-items-center">
          <span class="badge rounded-pill ct-pill"><?= in_array($c['status'], ['open', 'voting'], true) ? '● ' : '' ?><?= e($ph['label']) ?></span>
          <?php if ($range): ?><span class="small text-secondary"><?= e($range) ?></span><?php endif; ?>
          <span class="small text-secondary">· <?= (int) $c['entries_count'] ?> brani</span>
        </div>
      </div>
      <i class="bi bi-chevron-right text-secondary"></i>
    </div>
  </a>
<?php endforeach; ?>
<?php if (!$contests): ?>
  <div class="card"><div class="card-body text-secondary">Nessun contest in programma al momento.</div></div>
<?php endif; ?>
<p><a href="/<?= e($slug) ?>">← Torna alla pagina di <?= e($artist['display_name']) ?></a></p>
<?php
    $center = ob_get_clean();
    return contestAlShell($artist, $head, 'Contest', [], '', $center, contestAlScripts());
}

// ---------------------------------------------------------------------------------------------
// Pagina di un'edizione — /profilo/contest/edizione
// $v: message, error, voteMessage, voteError, myProfile, myEntry, entries, sponsors, ranking,
// votingOpen, candOpen, ph, range, q, results, contact, isDraftPreview, listHref, og
// ---------------------------------------------------------------------------------------------
function renderAdminLteContestPage(array $artist, array $c, array $v): string {
    $slug = $artist['slug'];
    $entries = $v['entries'];
    $head = contestAlHead($artist, $c['name'], $v['og']);
    $hero = contestAlHeroCard($artist, $c, $v['ph'], $v['range'], count($entries));
    $sponsorsCard = contestAlSponsorsCard($v['sponsors']);
    $rightCards = contestAlHeroCard($artist, $c, $v['ph'], $v['range'], count($entries), 'd-none d-md-block')
        . contestAlSponsorsCard($v['sponsors'], 'd-none d-md-block');

    ob_start();
    // Hero ripetuto su telefono
    echo '<div class="d-md-none">' . $hero . '</div>';
    if ($v['isDraftPreview']): ?>
      <div class="alert alert-warning">Bozza: questa pagina la vedi solo tu (amministratore o proprietario del profilo).</div>
    <?php endif;
    foreach (['message' => 'success', 'error' => 'danger', 'voteMessage' => 'success', 'voteError' => 'danger'] as $key => $cls):
        if (!empty($v[$key])): ?><div class="alert alert-<?= $cls ?>"><?= e($v[$key]) ?></div><?php endif;
    endforeach; ?>

    <?php if ($c['description']): ?>
      <div class="card mb-3"><div class="card-body"><?= nl2br(e($c['description'])) ?></div></div>
    <?php endif; ?>

    <div class="d-flex flex-wrap gap-2 mb-3">
      <?php if ($v['candOpen']): ?><a href="#candidati" class="btn ct-btn"><i class="bi bi-mic-fill me-1"></i>Candida il tuo brano</a><?php endif; ?>
      <?php if ($v['votingOpen'] && $v['ranking']): ?><a href="#classifiche" class="btn ct-btn"><i class="bi bi-hand-thumbs-up-fill me-1"></i>Vota ora</a><?php endif; ?>
      <?php if ($v['ranking']): ?><a href="#classifiche" class="btn ct-btn-soft"><i class="bi bi-trophy-fill me-1"></i>Classifiche</a><?php endif; ?>
      <?php if ($c['regulation']): ?><a href="#reg" class="btn ct-btn-soft"><i class="bi bi-file-text me-1"></i>Regolamento</a><?php endif; ?>
    </div>

    <?php if ($v['candOpen']): ?>
      <div class="card mb-3" id="candidati">
        <div class="card-header fw-bold"><i class="bi bi-mic-fill me-1"></i>Candida il tuo brano</div>
        <div class="card-body">
          <p class="text-secondary small">Il brano deve essere pubblicato su Spotify, uscito da non più di <?= (int) $c['max_track_age_months'] ?> mesi rispetto all'inizio del festival. La candidatura resta in attesa finché non viene approvata. Un solo brano per artista.</p>
          <?php if (!$v['myProfile']): ?>
            <a class="btn ct-btn" href="/login.php">Accedi per candidarti</a>
            <?php if ($v['contact']): ?><p class="small mt-2 mb-0">Non hai un profilo? Scrivi a <a href="mailto:<?= e($v['contact']) ?>"><?= e($v['contact']) ?></a>.</p><?php endif; ?>
          <?php elseif ($v['myEntry']): $me = $v['myEntry']; ?>
            <p class="mb-0">Hai candidato <strong><?= e($me['track_name']) ?></strong> — stato: <strong><?= $me['status'] === 'approved' ? 'in gara ✔' : ($me['status'] === 'rejected' ? 'non approvata' : 'in attesa di approvazione') ?></strong>.</p>
          <?php else: ?>
            <form method="get" id="track-search" class="d-flex gap-2">
              <input type="text" name="q" class="form-control" value="<?= e($v['q']) ?>" placeholder="Cerca il tuo brano su Spotify (titolo e artista)" autocomplete="off">
              <button class="btn ct-btn" type="submit">Cerca</button>
            </form>
            <div id="csrf-holder" hidden><?= csrfField() ?></div>
            <div id="track-results" aria-live="polite">
              <?php foreach ($v['results'] as $t): ?>
                <form method="post" class="d-flex align-items-center gap-2 mt-2">
                  <?= csrfField() ?>
                  <input type="hidden" name="track" value="<?= e($t['id']) ?>">
                  <?php if ($t['image']): ?><img src="<?= e($t['image']) ?>" alt="" style="width:48px;height:48px;border-radius:8px;object-fit:cover;"><?php endif; ?>
                  <span class="flex-grow-1"><strong><?= e($t['name']) ?></strong><br><small class="text-secondary"><?= e($t['artist_name']) ?></small></span>
                  <button class="btn btn-sm ct-btn" type="submit">Candida</button>
                </form>
              <?php endforeach; ?>
            </div>
            <form method="post" class="mt-3">
              <?= csrfField() ?>
              <label class="form-label small fw-bold">Oppure incolla il link del brano</label>
              <div class="d-flex gap-2">
                <input type="text" name="track" class="form-control" placeholder="https://open.spotify.com/track/...">
                <button class="btn ct-btn-soft" type="submit">Candida</button>
              </div>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($v['ranking']): ?>
      <?php $firstGroup = true; foreach ($v['ranking'] as $gkey => $g):
        $rows = $g['rows']; $top = (int) ($rows[0]['votes'] ?? 0);
        $medals = ['🥇', '🥈', '🥉'];
        $podiumOrder = count($rows) >= 3 ? [1, 0, 2] : []; ?>
        <div class="d-flex align-items-center gap-2 mb-2 mt-4" <?= $firstGroup ? 'id="classifiche"' : '' ?>>
          <h2 class="h5 mb-0"><?= e($g['label']) ?></h2>
          <span class="badge rounded-pill ct-pill-soft"><?= count($rows) ?> brani</span>
        </div>
        <?php $firstGroup = false; ?>

        <?php if ($podiumOrder): ?>
          <div class="row g-2 align-items-end mb-3 ct-podium">
            <?php foreach ($podiumOrder as $pi): $en = $rows[$pi]; $cls = ['ct-gold', 'ct-silver', 'ct-bronze'][$pi]; ?>
              <div class="col-4">
                <a href="<?= e(contestEntryUrl($c, (int) $en['id'])) ?>">
                  <div class="card h-100 text-center <?= $cls ?>" style="<?= $pi === 0 ? '' : 'margin-top:28px;' ?>">
                    <div class="card-body p-2">
                      <div class="ct-medal mb-1"><?= $medals[$pi] ?></div>
                      <div class="ratio ratio-1x1 mb-2" style="border-radius:10px;overflow:hidden;background:#eee;">
                        <?php if (!empty($en['image_url'])): ?><img src="<?= e($en['image_url']) ?>" alt="" style="object-fit:cover;"><?php endif; ?>
                      </div>
                      <div class="fw-bold small text-truncate"><?= e($en['track_name']) ?></div>
                      <div class="text-secondary small text-truncate"><?= e($en['band_name']) ?></div>
                      <div class="fs-5 fw-bold mt-1"><?= (int) $en['votes'] ?></div>
                      <div class="text-secondary" style="font-size:11px;">voti</div>
                    </div>
                  </div>
                </a>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php foreach ($rows as $i => $en): $pct = $top > 0 ? round($en['votes'] / $top * 100) : 0; ?>
          <div class="card mb-2" id="brano-<?= (int) $en['id'] ?>">
            <div class="card-body p-2 px-3">
              <div class="d-flex align-items-center gap-3">
                <div class="text-center fw-bold" style="width:26px;<?= $i === 0 ? 'color:#e0a100;font-size:20px;' : 'color:var(--bs-secondary-color);' ?>"><?= $i + 1 ?></div>
                <?= contestAlCover($en) ?>
                <div class="flex-grow-1 min-w-0">
                  <div class="fw-bold text-truncate"><a href="<?= e(contestEntryUrl($c, (int) $en['id'])) ?>" class="text-reset text-decoration-none"><?= e($en['track_name']) ?></a>
                    <?php if ($c['status'] === 'closed' && $top > 0 && (int) $en['votes'] === $top): ?><span class="badge text-bg-warning ms-1">🏆 Vincitore</span><?php endif; ?></div>
                  <div class="text-secondary small text-truncate"><?= e($en['band_name']) ?><?php if ($en['track_url']): ?> · <a href="<?= e($en['track_url']) ?>" target="_blank" rel="noopener">Spotify</a><?php endif; ?></div>
                  <?= contestAlSponsorBadge($en) ?>
                </div>
                <div class="text-end">
                  <div class="fs-5 fw-bold lh-1"><?= (int) $en['votes'] ?></div>
                  <div class="text-secondary" style="font-size:11px;">voti</div>
                </div>
                <?php if ($v['votingOpen']): ?>
                  <button class="btn btn-sm ct-btn" type="button" data-bs-toggle="collapse" data-bs-target="#vote-<?= (int) $en['id'] ?>">Vota</button>
                <?php endif; ?>
              </div>
              <div class="progress mt-2" style="height:6px;"><div class="progress-bar ct-bar" style="width:<?= (int) $pct ?>%"></div></div>
              <?php if ($v['votingOpen']): ?>
                <div class="collapse mt-2" id="vote-<?= (int) $en['id'] ?>">
                  <form method="post" class="d-flex gap-2">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="vote">
                    <input type="hidden" name="entry_id" value="<?= (int) $en['id'] ?>">
                    <input type="email" name="email" class="form-control form-control-sm" placeholder="tuo.indirizzo@gmail.com" required>
                    <button class="btn btn-sm ct-btn" type="submit">Conferma</button>
                  </form>
                  <div class="form-text">Serve un indirizzo Gmail: riceverai una email con il link per confermare il voto (un voto per persona in questa classifica).</div>
                </div>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endforeach; ?>
    <?php elseif ($entries): ?>
      <h2 class="h5 mb-2 mt-4" id="brani">Brani in gara <span class="badge rounded-pill ct-pill-soft"><?= count($entries) ?></span></h2>
      <div class="row g-2">
        <?php foreach ($entries as $en): ?>
          <div class="col-sm-6">
            <a href="<?= e(contestEntryUrl($c, (int) $en['id'])) ?>" class="card h-100 text-decoration-none text-reset">
              <div class="card-body p-2 d-flex align-items-center gap-2">
                <?= contestAlCover($en) ?>
                <div class="min-w-0">
                  <div class="fw-bold text-truncate"><?= e($en['track_name']) ?></div>
                  <div class="text-secondary small text-truncate"><?= e($en['band_name']) ?></div>
                  <?= contestAlSponsorBadge($en) ?>
                </div>
              </div>
            </a>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="d-md-none mt-3"><?= $sponsorsCard ?></div>

    <?php if ($c['regulation']): ?>
      <details class="card mt-3 mb-3" id="reg">
        <summary class="card-header fw-bold" style="cursor:pointer;"><i class="bi bi-file-text me-1"></i>Regolamento</summary>
        <div class="card-body small"><?= nl2br(e($c['regulation'])) ?></div>
      </details>
    <?php endif; ?>

    <p><a href="<?= e($v['listHref']) ?>">← Tutti i contest di <?= e($artist['display_name']) ?></a></p>
<?php
    $center = ob_get_clean();
    return contestAlShell($artist, $head, $c['name'], ['Contest' => $v['listHref']], $rightCards, $center, contestAlScripts());
}

// ---------------------------------------------------------------------------------------------
// Pagina di un brano — /profilo/contest/edizione/brano/id
// ---------------------------------------------------------------------------------------------
function renderAdminLteContestEntryPage(array $artist, array $c, array $en): string {
    $slug = $artist['slug'];
    $listHref = '/' . $slug . '/contest';
    $contestHref = contestUrl($c);
    $artistImg = $en['artist_avatar'] ?: $en['image_url'];
    $artistImgSrc = $artistImg ? (str_starts_with($artistImg, 'http') ? $artistImg : '/' . $artistImg) : null;
    $og = '<meta property="og:title" content="' . e($en['track_name'] . ' — ' . $en['band_name']) . '">'
        . '<meta property="og:description" content="' . e($c['name'] . ($en['sponsor_name'] ? ' · sostenuto da ' . $en['sponsor_name'] : '')) . '">'
        . '<meta property="og:url" content="' . e(siteUrl(contestEntryUrl($c, (int) $en['id']))) . '">'
        . ($en['image_url'] ? '<meta property="og:image" content="' . e($en['image_url']) . '">' : '');
    $head = contestAlHead($artist, $en['track_name'] . ' — ' . $en['band_name'], $og);

    // Altri brani in gara, con la stessa griglia di miniature usata per gli "Altri video".
    $stmt = getDB()->prepare("SELECT id, track_name, band_name, image_url FROM contest_entries WHERE contest_id = ? AND status = 'approved' AND id <> ? ORDER BY approved_at ASC, id ASC LIMIT 8");
    $stmt->execute([(int) $c['id'], (int) $en['id']]);
    $tiles = [];
    foreach ($stmt->fetchAll() as $o) {
        $tiles[] = [
            'url' => contestEntryUrl($c, (int) $o['id']),
            'image' => $o['image_url'] ?: '',
            'placeholder_icon' => 'bi-music-note-beamed',
            'title' => $o['track_name'],
            'date' => $o['band_name'],
        ];
    }
    $moreHtml = $tiles ? renderAdminLteGridCarousel('Altri brani in gara', 'bi-music-note-beamed', $contestHref, 'Tutto il contest', $tiles, 'square') : '';

    $pairHtml = '<div class="card mb-3"><div class="card-body text-center">'
        . '<div class="d-flex flex-wrap gap-3 justify-content-center align-items-center">'
        . '<div>' . ($artistImgSrc ? '<img src="' . e($artistImgSrc) . '" alt="' . e($en['band_name']) . '" style="width:120px;height:120px;border-radius:14px;object-fit:cover;">' : '')
        . '<div class="fw-bold mt-1">' . e($en['band_name']) . '</div></div>';
    if ($en['sponsor_name']) {
        $logo = $en['sponsor_logo'] ? '<img src="/' . e($en['sponsor_logo']) . '" alt="' . e($en['sponsor_name']) . '" style="max-width:150px;max-height:100px;object-fit:contain;">' : '<strong class="fs-5">' . e($en['sponsor_name']) . '</strong>';
        $pairHtml .= '<div class="fs-2 text-secondary">×</div><div>'
            . ($en['sponsor_url'] ? '<a href="' . e($en['sponsor_url']) . '" target="_blank" rel="noopener sponsored">' . $logo . '</a>' : $logo)
            . '<div class="small text-secondary mt-1">Sponsor</div></div>';
    }
    $pairHtml .= '</div></div></div>';
    $rightCards = '<div class="d-none d-md-block">' . $pairHtml . '</div>';

    ob_start();
    ?>
    <article class="card mb-3">
      <div class="card-header d-flex align-items-center">
        <div class="user-block mb-0">
          <img src="<?= e(adminLteAvatarUrl($artist)) ?>" alt="<?= e($artist['display_name']) ?>" class="rounded-circle">
          <span class="username"><a href="/<?= e($slug) ?>"><?= e($artist['display_name']) ?></a> <small class="fw-normal text-secondary">· <?= e($c['name']) ?></small></span>
          <span class="description">Brano in gara</span>
        </div>
      </div>
      <div class="d-md-none"><?= $pairHtml ?></div>
      <div class="card-body">
        <div class="d-flex align-items-center gap-3 mb-3">
          <?= contestAlCover($en) ?>
          <div>
            <h1 class="h3 mb-0"><?= e($en['track_name']) ?></h1>
            <div class="text-secondary"><?= e($en['band_name']) ?></div>
          </div>
        </div>
        <?php if (!empty($en['group_key']) && $en['group_key'] !== 'single'): ?>
          <p class="mb-3"><span class="badge rounded-pill ct-pill-soft"><?= e(CONTEST_GROUP_LABELS[$en['group_key']] ?? '') ?></span></p>
        <?php endif; ?>
        <div class="d-flex flex-wrap gap-2">
          <?php if ($en['track_url']): ?><a class="btn ct-btn" href="<?= e($en['track_url']) ?>" target="_blank" rel="noopener"><i class="bi bi-play-circle-fill me-1"></i>Ascolta su Spotify</a><?php endif; ?>
          <?php if ($c['status'] === 'voting'): ?><a class="btn ct-btn-soft" href="<?= e($contestHref) ?>#brano-<?= (int) $en['id'] ?>"><i class="bi bi-hand-thumbs-up-fill me-1"></i>Vota questo brano</a><?php endif; ?>
        </div>
      </div>
      <div class="card-footer small text-secondary">
        <i class="bi bi-share me-1"></i><strong>Condividi</strong> · <span class="user-select-all"><?= e(siteUrl(contestEntryUrl($c, (int) $en['id']))) ?></span>
      </div>
    </article>

    <?= $moreHtml ?>

    <p><a href="<?= e($contestHref) ?>">← <?= e($c['name']) ?></a></p>
<?php
    $center = ob_get_clean();
    return contestAlShell($artist, $head, $en['track_name'], ['Contest' => $listHref, $c['name'] => $contestHref], $rightCards, $center);
}
