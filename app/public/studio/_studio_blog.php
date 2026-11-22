<?php
// Studio — pezzi di grafica condivisi dalle pagine del Blog (testata con schede, form articolo).
// Solo markup: stili in assets/studio.css, comportamenti in assets/studio-blog.js.

function studioBlogHead(string $active, array $counts = []): void {
    $tabs = [
        'hub'  => ['/dashboard_blog.php', 'fa-gauge-high', 'Panoramica', null],
        'posts' => ['/dashboard_blog_posts.php', 'fa-newspaper', 'Articoli', $counts['posts'] ?? null],
        'cats' => ['/dashboard_blog_categories.php', 'fa-folder', 'Categorie', $counts['cats'] ?? null],
        'tags' => ['/dashboard_blog_tags.php', 'fa-tags', 'Tag', $counts['tags'] ?? null],
    ];
    echo '<div class="blx-head"><nav class="blx-tabs" aria-label="Sezioni del Blog">';
    foreach ($tabs as $k => [$url, $ic, $label, $n]) {
        echo '<a href="' . e($url) . '" class="' . ($k === $active ? 'on' : '') . '"><i class="fa-solid ' . $ic . '"></i> ' . e($label)
            . ($n !== null ? ' <em>' . (int) $n . '</em>' : '') . '</a>';
    }
    echo '</nav><a class="blx-new" href="/dashboard_blog_new.php"><i class="fa-solid fa-plus"></i> <span>Nuovo articolo</span></a></div>';
}

// Form articolo: $mode 'new' | 'edit'. $d: profile, post (array|null), categories, albums, selected (ids), tagList (tag già usati).
function studioBlogForm(string $mode, array $d): void {
    $post = $d['post'] ?? [];
    $profile = $d['profile'];
    $edit = $mode === 'edit';
    $hasVideo = $edit && !empty($post['cover_video_path']);
    $hasImg = $edit && !empty($post['cover_path']) && !$hasVideo;
    $sched = $edit && !empty($post['published_at']) && strtotime($post['published_at']) > time();
    ?>
  <form method="post" enctype="multipart/form-data" class="blx-form" id="blx-form">
    <?= csrfField() ?>
    <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int) $post['id'] ?>"><?php endif; ?>
    <input type="hidden" name="tz_offset_minutes" value="">

    <div class="blx-main">
      <input type="text" name="title" class="blx-title" placeholder="Titolo dell'articolo" required value="<?= e($post['title'] ?? '') ?>" maxlength="200">
      <div class="blx-editor">
        <textarea name="content" id="blog-ai-testo" rows="<?= $edit ? 16 : 12 ?>" required placeholder="Scrivi qui il tuo articolo..."><?= e($post['content'] ?? '') ?></textarea>
        <div class="blx-editor-bar">
          <button type="button" class="tlx-chip" id="blog-ai-caption-toggle"><i class="fa-solid fa-wand-magic-sparkles"></i> Scrivi con l'AI</button>
          <span class="blx-stats" id="blx-stats">0 parole · 0 min di lettura</span>
        </div>
      </div>
      <div id="blog-ai-caption-panel" class="tlx-ai" hidden>
        <label for="blog-ai-caption-keywords">Parole chiave o istruzioni per l'AI</label>
        <textarea id="blog-ai-caption-keywords" rows="2" placeholder="es. articolo sul nuovo album, tono entusiasta"></textarea>
        <div class="tlx-row">
          <button type="button" class="btn small" id="blog-ai-caption-generate">Genera testo</button>
          <button type="button" class="btn small secondary" id="blog-ai-caption-cancel">Annulla</button>
        </div>
        <p id="blog-ai-caption-status" class="tlx-hint"></p>
      </div>
    </div>

    <aside class="blx-side">
      <section class="blx-box">
        <h3>Copertina</h3>
        <?php if ($hasVideo): ?>
          <video class="blx-cur" src="/<?= e($post['cover_video_path']) ?>" <?= !empty($post['cover_path']) ? 'poster="/' . e($post['cover_path']) . '"' : '' ?> controls preload="none"></video>
        <?php elseif ($hasImg): ?>
          <img class="blx-cur" src="/<?= e($post['cover_path']) ?>" alt="">
        <?php endif; ?>
        <div class="blx-coverprev" id="blx-coverprev" hidden></div>
        <label class="tlx-drop blx-drop" id="blx-drop" for="blx-cover">
          <i class="fa-regular fa-image"></i>
          <span><b><?= ($hasVideo || $hasImg) ? 'Cambia copertina' : 'Aggiungi copertina' ?></b><br><small>Immagine quadrata (jpg, png, webp). Serve anche per l'anteprima quando condividi il link.</small></span>
          <input type="file" name="cover" id="blx-cover" accept="image/*">
        </label>
        <details class="tlx-adv"><summary>Oppure un video di copertina</summary>
          <input type="file" name="cover_video" accept="video/mp4,video/quicktime,video/webm,.m4v">
          <p class="tlx-hint">MP4, MOV o WebM, fino a 200 MB. Sostituisce l'immagine.</p>
        </details>
        <?php if ($hasVideo || $hasImg): ?>
          <label class="tlx-switch blx-rm"><input type="checkbox" name="remove_cover" value="1"><i></i><span><?= $hasVideo ? 'Rimuovi il video attuale' : 'Rimuovi la copertina attuale' ?></span></label>
        <?php endif; ?>
      </section>

      <section class="blx-box">
        <h3>Pubblicazione</h3>
        <?php if ($edit): ?>
          <?php if ($sched): ?><p class="stc-status stc-s-warn"><i class="fa-regular fa-clock"></i> Programmato</p><?php else: ?><p class="stc-status stc-s-ok"><i class="fa-solid fa-circle-check"></i> Pubblicato</p><?php endif; ?>
          <label for="blx-when">Data di pubblicazione</label>
          <input type="datetime-local" name="published_at" id="blx-when" value="<?= e(localDateTimeInputValue($post['published_at'], $profile)) ?>">
          <p class="tlx-hint">Mettila nel futuro per (ri)programmare l'articolo.</p>
        <?php else: ?>
          <div class="tlx-seg" role="radiogroup">
            <label><input type="radio" name="when" value="now" checked><span><i class="fa-solid fa-bolt"></i> Subito</span></label>
            <label><input type="radio" name="when" value="later"><span><i class="fa-regular fa-clock"></i> Programma</span></label>
          </div>
          <div class="tlx-when" id="blx-whenbox" hidden>
            <label for="blx-when">Data e ora</label>
            <input type="datetime-local" name="published_at" id="blx-when">
          </div>
        <?php endif; ?>
        <label class="tlx-switch"><input type="checkbox" name="in_feed" value="1" <?= (!$edit || (int) ($post['in_feed'] ?? 1)) ? 'checked' : '' ?>><i></i><span>Mostra nel Feed</span></label>
      </section>

      <section class="blx-box">
        <h3>Organizzazione</h3>
        <?php if ($d['categories']): ?>
          <span class="tlx-lbl">Categorie</span>
          <div class="blx-pills">
            <?php foreach ($d['categories'] as $cat): ?>
              <label><input type="checkbox" name="category_ids[]" value="<?= (int) $cat['id'] ?>" <?= in_array((int) $cat['id'], $d['selected'], true) ? 'checked' : '' ?>><span><?= e($cat['name']) ?></span></label>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="tlx-hint">Non hai ancora categorie: <a href="/dashboard_blog_categories.php">creane una</a>.</p>
        <?php endif; ?>
        <label for="blx-tags">Tag <small>(separati da virgola)</small></label>
        <input type="text" name="tags" id="blx-tags" value="<?= e($post['tags'] ?? '') ?>" placeholder="es. concerti, novità, napoli" autocomplete="off">
        <?php if (!empty($d['tagList'])): ?>
          <div class="blx-sugg" id="blx-sugg" aria-label="Tag già usati">
            <?php foreach ($d['tagList'] as $t): ?><button type="button" data-tag="<?= e($t) ?>">#<?= e($t) ?></button><?php endforeach; ?>
          </div>
        <?php endif; ?>
        <label for="blx-album">Album collegato</label>
        <select name="album_id" id="blx-album">
          <option value="">— Nessun album —</option>
          <?php foreach ($d['albums'] as $al): ?>
            <option value="<?= (int) $al['id'] ?>" <?= (int) ($post['album_id'] ?? 0) === (int) $al['id'] ? 'selected' : '' ?>><?= e($al['title']) ?></option>
          <?php endforeach; ?>
        </select>
      </section>
    </aside>

    <div class="blx-bar">
      <a href="/dashboard_blog_posts.php" class="btn secondary">Annulla</a>
      <button type="submit" class="tlx-submit blx-submit" id="blx-submit"><i class="fa-solid fa-paper-plane"></i> <span><?= $edit ? 'Salva modifiche' : 'Pubblica' ?></span></button>
    </div>
  </form>
  <script src="/studio/assets/studio-blog.js?v=<?= (int) @filemtime(__DIR__ . '/assets/studio-blog.js') ?>" defer></script>
<?php
}
