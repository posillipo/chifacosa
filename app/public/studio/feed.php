<?php
require_once __DIR__ . '/_studio.php';
$c = studioBoot();
$feed = studioFeed($c, 0);
studioHead($c, 'Feed', 'feed');
?>
<div class="st-pagehead">
  <h1>Feed</h1>
  <button type="button" class="st-btn" id="st-new" data-open="st-newdlg"><i class="fa-solid fa-plus"></i><span>Nuovo contenuto</span></button>
</div>
<div class="st-toolbar">
  <div class="st-tabs" role="tablist" id="st-tabs">
    <button type="button" class="active" role="tab" data-tab="tutti">Tutti</button>
    <button type="button" role="tab" data-tab="articolo">Articoli</button>
    <button type="button" role="tab" data-tab="video">Video</button>
    <button type="button" role="tab" data-tab="aggiornamento">Aggiornamenti</button>
  </div>
  <div class="st-toolright">
    <label class="st-filter"><i class="fa-solid fa-magnifying-glass"></i><input type="search" id="st-q" placeholder="Cerca per titolo o autore" aria-label="Cerca nel Feed"></label>
    <div class="st-seg" id="st-view" role="group" aria-label="Vista">
      <button type="button" data-view="list" class="active" title="Lista"><i class="fa-solid fa-list"></i></button>
      <button type="button" data-view="card" title="Card"><i class="fa-solid fa-table-cells-large"></i></button>
    </div>
  </div>
</div>

<div class="st-feed list" id="st-feed" data-next="<?= count($feed) ?>" data-done="<?= count($feed) < STUDIO_PAGE_SIZE ? '1' : '0' ?>">
  <?php foreach ($feed as $item): ?><?= studioCard($item, $c['profile']['slug']) ?><?php endforeach; ?>
</div>
<div class="st-empty" id="st-empty" <?= $feed ? 'hidden' : '' ?>>
  <i class="fa-regular fa-folder-open"></i>
  <p>Nessun contenuto in questa vista</p>
  <button type="button" class="st-btn" data-open="st-newdlg"><i class="fa-solid fa-plus"></i><span>Nuovo contenuto</span></button>
</div>
<div class="st-skel" id="st-skel" hidden><div></div><div></div><div></div></div>
<div id="st-sentinel" style="height:1px"></div>

<dialog class="st-dlg" id="st-newdlg" aria-labelledby="st-newdlg-t">
  <h2 id="st-newdlg-t">Nuovo contenuto</h2>
  <p class="st-muted">Che cosa vuoi pubblicare?</p>
  <a class="st-choice" href="/dashboard_blog_new.php"><i class="fa-solid fa-newspaper"></i><span><b>Articolo</b><small>Un testo completo per il blog</small></span></a>
  <a class="st-choice" href="/dashboard_videos.php"><i class="fa-solid fa-circle-play"></i><span><b>Video</b><small>Carica uno o più video</small></span></a>
  <a class="st-choice" href="/dashboard_post.php"><i class="fa-solid fa-comment"></i><span><b>Aggiornamento</b><small>Un pensiero o un annuncio breve</small></span></a>
  <button type="button" class="st-btn st-btn-ghost" data-close>Annulla</button>
</dialog>
<?php
studioFoot(studioAside($c));
