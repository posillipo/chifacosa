#!/usr/bin/env python3
"""Genera la copia "Studio" della dashboard in app/public/studio/ a partire da app/public/dashboard*.php.

Le pagine originali NON vengono toccate. Ogni pagina copiata:
  - include all'inizio studio/_studio_boot.php (riscrittura dei collegamenti, stile nuovo, noindex);
  - usa le stesse funzioni (../../src), la stessa testata/piè (../_dash_header.php, ../_dash_footer.php) e gli stessi upload (../uploads).
Rilanciare lo script rigenera tutte le pagine (utile dopo modifiche alla dashboard originale):  python3 scripts/make-studio.py
Non modificare a mano i file generati: personalizza assets/studio.css o aggiungi un file in studio/_overrides/NOME.php
(se esiste, al posto della pagina generata viene copiato quello).
"""
import glob, os, re, shutil, sys

root = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..')
pub = os.path.join(root, 'app', 'public')
dst = os.path.join(pub, 'studio')
over = os.path.join(dst, '_overrides')
os.makedirs(dst, exist_ok=True)

# elimina le pagine generate in precedenza (restano boot, assets e overrides)
for f in glob.glob(os.path.join(dst, 'dashboard*.php')):
    os.remove(f)

HEADER = "<?php require_once __DIR__ . '/_studio_boot.php'; /* GENERATO da scripts/make-studio.py: non modificare a mano */ ?>"
n = 0
for src in sorted(glob.glob(os.path.join(pub, 'dashboard*.php'))):
    name = os.path.basename(src)
    ov = os.path.join(over, name)
    if os.path.exists(ov):
        shutil.copyfile(ov, os.path.join(dst, name))
        n += 1
        continue
    s = open(src, encoding='utf-8').read()
    s = s.replace("__DIR__ . '/../src/", "__DIR__ . '/../../src/").replace('__DIR__ . "/../src/', '__DIR__ . "/../../src/')
    s = s.replace("__DIR__ . '/_dash_header.php'", "__DIR__ . '/../_dash_header.php'").replace("__DIR__ . '/_dash_footer.php'", "__DIR__ . '/../_dash_footer.php'")
    s = s.replace("__DIR__ . '/uploads/", "__DIR__ . '/../uploads/")
    # schede del Feed di Studio (stato e azioni): vedi studio/_studio_feed.php
    s = s.replace('renderDashboardTimelineItem(', 'studioRenderTimelineItem(').replace('renderDashboardTimelineCard(', 'studioRenderTimelineCard(')
    if name == 'dashboard_timeline.php':
        # pulsante "A cosa stai pensando?" + popup di inserimento in cima al Feed
        s = s.replace('<div class="dtf-head">', '<?php studioComposer($profile, []); ?>\n  <div class="dtf-head">', 1)
    left = [m for m in re.findall(r"__DIR__ \. ['\"][^'\"]*['\"]", s) if not (m.startswith("__DIR__ . '/../") or m.startswith('__DIR__ . "/../'))]
    if left:
        print('ATTENZIONE', name, 'ha altri percorsi relativi a __DIR__:', set(left), file=sys.stderr)
    # la prima riga "<?php" apre il file: l'inclusione del boot va subito prima
    if s.startswith('<?php'):
        s = HEADER + "\n" + s
    open(os.path.join(dst, name), 'w', encoding='utf-8').write(s)
    n += 1

# ingresso: porta alla dashboard copiata
open(os.path.join(dst, 'index.php'), 'w').write("<?php header('Location: /studio/dashboard.php'); exit;\n")
print('Pagine generate:', n)
