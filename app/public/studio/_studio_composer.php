<?php
// Studio — "Crea aggiornamento": pulsante "A cosa stai pensando?" + popup di inserimento (testo e foto),
// con un secondo pannello "Opzioni del post" (privacy, programmazione, Feed, link personalizzato).
// Il modulo invia gli stessi campi della Timeline originale (dashboard_post.php, azione "add").
// Stili in assets/studio.css (.cmp-*), comportamenti in assets/studio-composer.js.

function studioComposer(array $profile, array $opt = []): void {
    static $done = false;
    if ($done) { return; }
    $done = true;
    $name = trim((string) ($profile['display_name'] ?? ''));
    $first = $name !== '' ? strtok($name, ' ') : '';
    $avatar = !empty($profile['avatar_path']) ? '/' . ltrim($profile['avatar_path'], '/') : '';
    $initial = e(mb_strtoupper(mb_substr($name ?: 'C', 0, 1)));
    $av = $avatar ? '<img src="' . e($avatar) . '" alt="">' : $initial;
    $guid = (string) ($profile['custom_feed_guid'] ?? '');
    ?>
  <button type="button" class="cmp-trigger" data-cmp-open>
    <span class="tlx-av"><?= $av ?></span>
    <span class="cmp-trigger-t">A cosa stai pensando<?= $first ? ', ' . e($first) : '' ?>?</span>
    <span class="cmp-trigger-i"><i class="fa-regular fa-image"></i></span>
  </button>

  <dialog class="cmp" id="cmp" aria-labelledby="cmp-title" data-open="<?= !empty($opt['open']) ? 1 : 0 ?>">
    <form method="post" action="/studio/dashboard_post.php" enctype="multipart/form-data" class="cmp-form" id="tlx-form">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="tz_offset_minutes" value="">
      <input type="hidden" name="image_thumb_data" id="post-image-thumb-data">

      <section class="cmp-panel" data-panel="main">
        <header class="cmp-head">
          <h2 id="cmp-title">Crea aggiornamento</h2>
          <button type="button" class="cmp-x" data-cmp-close aria-label="Chiudi"><i class="fa-solid fa-xmark"></i></button>
        </header>
        <div class="cmp-body">
          <div class="cmp-who">
            <span class="tlx-av"><?= $av ?></span>
            <div>
              <b><?= e($name) ?></b>
              <button type="button" class="cmp-pill" data-cmp-opts id="cmp-vis"><i class="fa-solid fa-earth-europe"></i> <span>Pubblico</span> <i class="fa-solid fa-caret-down"></i></button>
            </div>
          </div>
          <div class="tlx-field">
            <textarea name="testo" id="ai-testo" rows="5" maxlength="2000" placeholder="Scrivilo qui..."><?= e($opt['testo'] ?? '') ?></textarea>
            <span class="tlx-count" id="tlx-count">0</span>
          </div>
          <div class="cmp-sum" id="cmp-sum" hidden></div>
          <div id="tlx-photos" class="tlx-photos" hidden></div>
          <div id="ai-caption-panel" class="tlx-ai" hidden>
            <label for="ai-caption-keywords">Parole chiave o istruzioni per l'AI</label>
            <textarea id="ai-caption-keywords" rows="2" placeholder="es. annuncio nuovo concerto sabato 14 a Milano"></textarea>
            <div class="tlx-row">
              <button type="button" class="btn small" id="ai-caption-generate">Genera testo</button>
              <button type="button" class="btn small secondary" id="ai-caption-cancel">Annulla</button>
            </div>
            <p id="ai-caption-status" class="tlx-hint"></p>
          </div>
          <div class="cmp-add">
            <span>Aggiungi al tuo post</span>
            <div class="cmp-add-i">
              <label for="post-image-input" class="cmp-ic cmp-ic-photo" title="Foto"><i class="fa-regular fa-images"></i></label>
              <button type="button" class="cmp-ic cmp-ic-clock" data-cmp-opts title="Programma"><i class="fa-regular fa-clock"></i></button>
              <button type="button" class="cmp-ic cmp-ic-ai" id="ai-caption-toggle" title="Scrivi con l'AI"><i class="fa-solid fa-wand-magic-sparkles"></i></button>
              <button type="button" class="cmp-ic" data-cmp-opts title="Altre opzioni"><i class="fa-solid fa-ellipsis"></i></button>
            </div>
          </div>
          <input type="file" name="images[]" id="post-image-input" accept="image/*" multiple hidden>
          <p class="tlx-hint cmp-note">Fino a 10 foto. La prima è la copertina nel Feed, le altre formano il carosello. Puoi anche trascinarle qui o incollarle.</p>
        </div>
        <footer class="cmp-foot"><button type="submit" class="tlx-submit" id="tlx-submit"><i class="fa-solid fa-paper-plane"></i> <span>Pubblica</span></button></footer>
      </section>

      <section class="cmp-panel" data-panel="opts" hidden>
        <header class="cmp-head">
          <button type="button" class="cmp-x cmp-back" data-cmp-back aria-label="Indietro"><i class="fa-solid fa-arrow-left"></i></button>
          <h2>Opzioni del post</h2>
          <span></span>
        </header>
        <div class="cmp-body">
          <div class="cmp-opt">
            <span class="cmp-opt-ic"><i class="fa-solid fa-earth-europe"></i></span>
            <div class="cmp-opt-b"><b>Chi lo vede</b>
              <div class="tlx-seg" role="radiogroup">
                <label><input type="radio" name="visibility" value="public" checked><span>Pubblico</span></label>
                <label><input type="radio" name="visibility" value="private"><span><i class="fa-solid fa-lock"></i> Solo io</span></label>
              </div>
            </div>
          </div>
          <div class="cmp-opt">
            <span class="cmp-opt-ic"><i class="fa-regular fa-clock"></i></span>
            <div class="cmp-opt-b"><b>Quando</b>
              <div class="tlx-seg" role="radiogroup">
                <label><input type="radio" name="when" value="now" checked><span><i class="fa-solid fa-bolt"></i> Subito</span></label>
                <label><input type="radio" name="when" value="later"><span>Programma</span></label>
              </div>
              <div class="tlx-when" id="tlx-when" hidden>
                <input type="datetime-local" name="publish_at" id="tlx-publish-at" aria-label="Data e ora di pubblicazione">
              </div>
            </div>
          </div>
          <div class="cmp-opt">
            <span class="cmp-opt-ic"><i class="fa-solid fa-list"></i></span>
            <div class="cmp-opt-b"><label class="tlx-switch"><input type="checkbox" name="in_feed" value="1" checked><i></i><span>Mostra nel Feed</span></label></div>
          </div>
          <div class="cmp-opt">
            <span class="cmp-opt-ic"><i class="fa-solid fa-link"></i></span>
            <div class="cmp-opt-b"><b>Link personalizzato per il feed</b>
              <input type="url" name="custom_feed_guid" id="tlx-guid" value="<?= e($guid) ?>" placeholder="https://...">
              <p class="tlx-hint"><?php if (!empty($profile['custom_feed_guid_since'])): ?>Attivo dal <?= e(formatLocalDateTime($profile['custom_feed_guid_since'], $profile)) ?>.<?php else: ?>Lascia vuoto per usare sempre la pagina normale.<?php endif; ?></p>
            </div>
          </div>
        </div>
        <footer class="cmp-foot"><button type="button" class="tlx-submit cmp-done" data-cmp-back>Fatto</button></footer>
      </section>
    </form>
  </dialog>
  <script src="/studio/assets/studio-composer.js?v=<?= (int) @filemtime(__DIR__ . '/assets/studio-composer.js') ?>" defer></script>
<?php
}
