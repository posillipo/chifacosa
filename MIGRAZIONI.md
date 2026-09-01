# Registro migrazioni database

Elenco cronologico di tutte le modifiche allo schema applicate finora in produzione (già
eseguite). Utile come riferimento per sapere cosa contiene il database attuale, e come modello
per le prossime migrazioni.

Procedura standard per ogni voce: Portainer → Containers → `myband_db` → Console →
`mysql -u chifacosa_user -p chifacosa` (password: vedi `ADMIN_SETUP.md`), poi incolla il comando.

## 1. Ruolo admin (`is_admin` su `users`)
```sql
ALTER TABLE users ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0;
```

## 2. Permalink SEO per il blog (`slug` su `blog_posts`)
```sql
ALTER TABLE blog_posts ADD COLUMN slug VARCHAR(180) NOT NULL DEFAULT '';
ALTER TABLE blog_posts ADD COLUMN excerpt VARCHAR(300);
ALTER TABLE blog_posts ADD UNIQUE KEY uniq_user_slug (user_id, slug);
```

## 3. Verifica email + tracking GTM/Pixel
```sql
ALTER TABLE users
  ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN verification_token VARCHAR(64) DEFAULT NULL,
  ADD COLUMN verification_expires DATETIME DEFAULT NULL;

INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES
  ('gtm_head_script', ''), ('gtm_body_script', ''), ('fb_pixel_script', '');
```

## 4. Configurazione SMTP da admin
```sql
INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES
  ('smtp_host', ''), ('smtp_port', '587'), ('smtp_user', ''), ('smtp_pass', ''),
  ('smtp_secure', 'tls'), ('smtp_from', ''), ('smtp_from_name', 'CHI FA COSA');
```

## 5. Opzione verifica certificato SSL
```sql
INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES ('smtp_verify_cert', '1');
```

## 6. Google Analytics
```sql
INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES ('ga_measurement_id', '');
```

## 7. Icona "sito web personale" sui link
```sql
ALTER TABLE links ADD COLUMN is_website_icon TINYINT(1) NOT NULL DEFAULT 0;
```

## 8. Tema dashboard band manager (scuro/chiaro)
```sql
ALTER TABLE profiles ADD COLUMN dashboard_theme VARCHAR(10) NOT NULL DEFAULT 'dark';
```

## 9. Login persistente "ricordami"
```sql
CREATE TABLE IF NOT EXISTS remember_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    selector VARCHAR(24) NOT NULL UNIQUE,
    validator_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
```

## 10. URL Privacy Policy per il footer pubblico
```sql
INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES ('privacy_policy_url', '');
```

## 11. Integrazione Spotify (collegamento artista + credenziali API)
```sql
ALTER TABLE profiles
  ADD COLUMN spotify_artist_id VARCHAR(50) DEFAULT NULL,
  ADD COLUMN spotify_artist_name VARCHAR(200) DEFAULT NULL;

INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES
  ('spotify_client_id', ''), ('spotify_client_secret', ''),
  ('spotify_app_token', ''), ('spotify_app_token_expires', '');
```

## 12. Segui via email
```sql
CREATE TABLE IF NOT EXISTS followers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    email VARCHAR(190) NOT NULL,
    verified TINYINT(1) NOT NULL DEFAULT 0,
    token VARCHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_email (user_id, email),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
```

## 13. Import dati legacy (vecchio myband.it)
```sql
ALTER TABLE users
  ADD COLUMN legacy_gestore_id INT DEFAULT NULL,
  ADD COLUMN legacy_band_id INT DEFAULT NULL,
  ADD COLUMN legacy_stato VARCHAR(20) DEFAULT NULL;

ALTER TABLE profiles
  ADD COLUMN genere VARCHAR(100) DEFAULT NULL,
  ADD COLUMN citta VARCHAR(100) DEFAULT NULL,
  ADD COLUMN provincia VARCHAR(50) DEFAULT NULL,
  ADD COLUMN telefono VARCHAR(50) DEFAULT NULL;
```
Dopo questo comando, l'importazione vera e propria (1.835 record) si esegue da
**Area Admin → Import legacy**, non via SQL diretto — legge il CSV incluso nel codice e crea gli
account con `is_active = 0` (bloccati, non pubblici, finché non deciso come attivarli).

## 14. Copertina brani audio
```sql
ALTER TABLE audio_tracks ADD COLUMN cover_path VARCHAR(255) DEFAULT NULL;
```

## 15. Copertina per Link, Blog ed Eventi
```sql
ALTER TABLE links ADD COLUMN cover_path VARCHAR(255) DEFAULT NULL;
ALTER TABLE blog_posts ADD COLUMN cover_path VARCHAR(255) DEFAULT NULL;
ALTER TABLE events ADD COLUMN cover_path VARCHAR(255) DEFAULT NULL;
```

## 16. Integrazione YouTube
```sql
ALTER TABLE profiles
  ADD COLUMN youtube_channel_id VARCHAR(50) DEFAULT NULL,
  ADD COLUMN youtube_channel_name VARCHAR(200) DEFAULT NULL;

INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES ('youtube_api_key', '');
```

## 17. Recupero password
```sql
ALTER TABLE users
  ADD COLUMN reset_token VARCHAR(64) DEFAULT NULL,
  ADD COLUMN reset_token_expires DATETIME DEFAULT NULL;
```

## 18. Podcast collegato a Spotify
```sql
ALTER TABLE profiles
  ADD COLUMN spotify_show_id VARCHAR(50) DEFAULT NULL,
  ADD COLUMN spotify_show_name VARCHAR(200) DEFAULT NULL;
```
Nessuna nuova credenziale da configurare: riusa la stessa API Key Spotify già impostata in
Area Admin → Spotify.

## 19. Campi profilo mancanti in dashboard (genere, città, provincia, telefono)
Le colonne esistono già dalla migrazione 13 (import legacy) — questa sezione non richiede
nuovi comandi SQL, solo il nuovo codice che finalmente le espone in Dashboard → Profilo.

## 20. Tipo di account (Band/Fan/Etichetta) e lista "Band che amo" dei Fan
```sql
ALTER TABLE users
  ADD COLUMN account_type ENUM('band','fan','label') NOT NULL DEFAULT 'band',
  ADD COLUMN account_type_chosen TINYINT(1) NOT NULL DEFAULT 0;

-- IMPORTANTE: segna tutti gli account già esistenti come "tipo già scelto", altrimenti al
-- prossimo login vedrebbero comparire la schermata di scelta anche se sono account reali già
-- attivi da tempo (compresi i profili importati dal vecchio sistema)
UPDATE users SET account_type_chosen = 1 WHERE account_type_chosen = 0;

CREATE TABLE IF NOT EXISTS fan_favorite_bands (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    spotify_artist_id VARCHAR(50) NOT NULL,
    spotify_artist_name VARCHAR(200) NOT NULL,
    artist_image VARCHAR(500) DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_artist (user_id, spotify_artist_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
```

## 21. Segui tra account + Timeline aggregata (con compositore "Pubblica")
```sql
CREATE TABLE IF NOT EXISTS account_follows (
    id INT AUTO_INCREMENT PRIMARY KEY,
    follower_user_id INT NOT NULL,
    followed_user_id INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_follow (follower_user_id, followed_user_id),
    FOREIGN KEY (follower_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (followed_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS timeline_posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    testo TEXT DEFAULT NULL,
    image_path VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
```
Entrambe puramente additive, nessuna modifica a tabelle esistenti. Reversibili con
`DROP TABLE account_follows;` e `DROP TABLE timeline_posts;` se la funzionalità non convince.

## 22. Nuovo modulo Brani (ricerca Spotify al posto dell'upload mp3)
```sql
CREATE TABLE IF NOT EXISTS favorite_tracks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    spotify_track_id VARCHAR(50) NOT NULL,
    track_name VARCHAR(200) NOT NULL,
    artist_name VARCHAR(200) DEFAULT NULL,
    track_image VARCHAR(500) DEFAULT NULL,
    spotify_url VARCHAR(500) DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_track (user_id, spotify_track_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
```
Su richiesta esplicita, i vecchi brani caricati come mp3 vanno eliminati (non solo lasciati
inutilizzati):
```sql
DELETE FROM audio_tracks;
```
La tabella resta nello schema (per compatibilità), ma svuotata — nessun brano mp3 residuo.
Se vuoi anche liberare lo spazio disco dei file fisici già caricati:
```bash
rm -rf /data/compose/26/app/public/uploads/audio/*
```

## 23. Data di pubblicazione per gli eventi (per l'ordinamento corretto nella Timeline)
```sql
ALTER TABLE events ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;
```
Distingue quando un evento è stato **pubblicato** (usato per ordinare la Timeline) da quando
**si terrà** (`event_date`, resta invariato e continua a comparire nella pagina dedicata
all'evento). Gli eventi già esistenti riceveranno automaticamente la data odierna come
`created_at` (comportamento di default per le righe già presenti quando si aggiunge una colonna
con `DEFAULT CURRENT_TIMESTAMP`).

## 24. Modulo Menù (categorie e piatti con allergeni, disponibile per qualsiasi tipo di account)
```sql
CREATE TABLE IF NOT EXISTS menu_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(120) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS menu_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    category_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    description VARCHAR(300) DEFAULT NULL,
    price DECIMAL(6,2) DEFAULT NULL,
    allergens VARCHAR(60) DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES menu_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB;
```
`allergens` salva una stringa tipo `"1,4,7"` con i codici numerici dei 14 allergeni UE
(Regolamento 1169/2011), elencati in `functions.php::MENU_ALLERGENS`. Pagina dashboard
`dashboard_menu.php`, pagina pubblica `/slug/menu`. Il tab "Menù" compare sulla pagina pubblica
solo se esiste almeno un piatto attivo.

## 25. Menu di Navigazione (nascondi singoli tab standard dal menu pubblico)
```sql
CREATE TABLE IF NOT EXISTS profile_navigation_menu (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(50) NOT NULL,
    icon VARCHAR(50) NULL,
    url VARCHAR(255) NOT NULL,
    is_visible TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_name (user_id, name),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
```
Pagina dashboard `dashboard_nav_menu.php`: permette di nascondere i tab standard (Home,
Timeline, Blog, Brani, Menù, Eventi, Contatti) dal menu pubblico del proprio profilo. Le righe
per un profilo si creano automaticamente al primo accesso alla pagina (nessun bisogno di
popolarle a mano) — un profilo che non l'ha mai aperta non ha righe qui, e quindi nessun tab
nascosto, esattamente come prima di questa funzionalità. Le integrazioni (Spotify, Podcast,
Video, il pulsante "Segui") non sono coperte, restano sempre governate dalla loro logica
esistente.

## 26. Prenotazione tavoli per gli eventi
```sql
ALTER TABLE events ADD COLUMN accepts_reservations TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS table_reservations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    event_id INT NULL,
    guest_name VARCHAR(120) NOT NULL,
    guest_email VARCHAR(190) NOT NULL,
    guest_phone VARCHAR(30) DEFAULT NULL,
    party_size SMALLINT UNSIGNED NOT NULL,
    notes VARCHAR(300) DEFAULT NULL,
    status ENUM('pending','confirmed','declined','cancelled','no_show','completed') NOT NULL DEFAULT 'confirmed',
    marketing_opt_in TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    INDEX idx_owner_status (user_id, status),
    INDEX idx_guest_email (guest_email)
) ENGINE=InnoDB;
```
Il gestore attiva "Accetta prenotazioni tavolo" su un singolo evento da `dashboard_events.php`;
solo allora `evento.php` mostra il modulo pubblico di prenotazione (nome, email, telefono,
numero di persone, note, consenso facoltativo a ricevere aggiornamenti/offerte in futuro —
separato dalla prenotazione stessa per restare in regola: la prenotazione da sola autorizza a
ricontattare solo per quella prenotazione). Le prenotazioni si vedono/gestiscono da
`dashboard_reservations.php`, che offre anche una vista "clienti" raggruppata per email con lo
storico di chi ha già prenotato — pensata per superare il limite del sistema "Segui"
(`followers`), che è solo un elenco email da broadcast senza storico né dati di contatto
completi. `event_id` resta nullable apposta: una futura fase "prenotazioni libere" (non legate
a un evento) userà la stessa tabella senza nuove migrazioni.

## 27. Separatori e mappa (gratuita, OpenStreetMap) tra i Link in Bio
```sql
ALTER TABLE links
  ADD COLUMN link_type ENUM('link','divider','map') NOT NULL DEFAULT 'link',
  ADD COLUMN map_lat DECIMAL(10,7) DEFAULT NULL,
  ADD COLUMN map_lng DECIMAL(10,7) DEFAULT NULL;
```
Due nuovi tipi di voce nella stessa lista di `dashboard_links.php`, riordinabili con le stesse
frecce dei link normali: un **separatore** (solo un titolo di sezione, es. "Per prenotare", non
cliccabile) e una **mappa** (indirizzo cercato tramite Nominatim/OpenStreetMap — gratuito, nessuna
chiave API — e mostrato come mappa incorporata sulla pagina pubblica, sempre da OpenStreetMap,
anch'esso gratuito). Vedi `app/src/geocoding.php`. I link esistenti restano tutti `link_type='link'`
per via del `DEFAULT`, nessun impatto sui dati già presenti.

## 28. Link personalizzato per i post (redirect via JS sulla pagina, non nel feed)
```sql
ALTER TABLE profiles
  ADD COLUMN custom_feed_guid VARCHAR(500) DEFAULT NULL,
  ADD COLUMN custom_feed_guid_since DATETIME DEFAULT NULL;
```
Da Dashboard → Feed RSS, un utente può impostare un URL personalizzato che si applica ai post
pubblicati da quando il campo è stato impostato/modificato in poi (`custom_feed_guid_since`,
aggiornato automaticamente a ogni cambio di valore) — i post già esistenti al momento del
salvataggio non vengono toccati.

Prima versione (ora corretta): il link personalizzato sostituiva `<link>` nel feed RSS
(`feed.php`). Si è rivelato incompatibile con Metricool, che per l'immagine dei post automatici
non guarda `<enclosure>`/`<media:content>` ma legge sempre l'`og:image` della pagina puntata da
`<link>` — puntando al sito esterno, Metricool prendeva l'immagine sbagliata (o nessuna).

Comportamento attuale: `<link>` e `<guid>` nel feed RSS restano **sempre** il permalink interno
chifacosa.it (che ha gli og:image/og:title corretti). È invece la pagina di destinazione del
singolo contenuto (`timeline_post.php`, `blog_post.php`, `evento.php`) a reindirizzare i
visitatori umani all'URL esterno via JavaScript (`emitCustomFeedLinkRedirect()` in
`functions.php`, chiamata nell'`<head>`) non appena la pagina carica. I bot che leggono solo
l'HTML statico (Metricool, Facebook, ecc.) non eseguono JS e continuano a leggere gli og:image
corretti; solo chi clicca davvero dal social/feed finisce sul sito esterno.

Il valore resta invariato finché l'utente non lo modifica o lo svuota: se nel frattempo pubblica
più post, tutti reindirizzano allo stesso link finché il campo non viene aggiornato. (Le colonne
si chiamano ancora `custom_feed_guid*` per non richiedere una nuova migrazione dopo le due
correzioni successive al meccanismo — sono solo nomi interni, non visibili all'utente.)

## 29. Profili multipli per utente ("Crea nuovo profilo")
```sql
ALTER TABLE profile_admins ADD COLUMN role ENUM('coadmin','owner') NOT NULL DEFAULT 'coadmin';
```
Riusa la tabella `profile_admins` già esistente per "Team e co-admin", distinguendo due casi con
la nuova colonna `role`:

- **`coadmin`** (comportamento esistente, invariato): un altro utente promosso da Team e
  co-admin — accesso volutamente limitato a Timeline e Brani, come documentato in
  `dashboard_team.php`.
- **`owner`** (nuovo): un profilo che l'utente ha creato da sé da Dashboard → I tuoi profili
  (`dashboard_profiles.php`) — stesso account di login, nessuna registrazione separata, ma un
  secondo profilo pubblico (slug/pagina) del tutto proprio. A differenza di un co-admin, chi ha
  `role='owner'` ha accesso pieno a tutte le pagine di gestione contenuti (Link, Eventi, Blog,
  Menù, Profilo, Feed RSS, Tema, integrazioni, ecc.), non solo Timeline e Brani.

Il profilo creato è una riga `users`/`profiles` a tutti gli effetti (email sintetica univoca,
password casuale mai comunicata — non è pensato per un login diretto, solo per essere gestito
via switch dal profilo che l'ha creato), quindi nessuna nuova tabella: stessa struttura dati di
un account normale. Lo switch tra profili (menu in alto in dashboard) esisteva già da prima per
i co-admin; questa migrazione lo estende con lo stesso meccanismo (`getActingProfile()`) a tutte
le pagine di gestione contenuti, ma solo per chi ha `role='owner'` (o è il titolare) — i co-admin
restano limitati esattamente come prima, nessuna modifica al loro perimetro.

## 30. Privacy/Cookie e Tracking personalizzabili per profilo
```sql
ALTER TABLE profiles ADD COLUMN privacy_tracking_settings TEXT DEFAULT NULL;
```
Un unico campo JSON (invece di tante colonne separate, per non richiedere una nuova migrazione a
ogni parametro futuro) con le chiavi: `privacy_script`, `privacy_policy_url`,
`ga_measurement_id`, `gtm_head_script`, `gtm_body_script`, `fb_pixel_script`, `fb_pixel_id`,
`fb_capi_token`. Gestito da `dashboard_privacy_tracking.php` (Dashboard → menu hamburger →
Privacy e Tracking), decodificato da `getProfileTracking()` in `functions.php`.

Per impostazione predefinita ogni pagina pubblica usa le impostazioni generali del sito (pannello
ADMIN → Privacy/Cookie, Tracking). Se il profilo compila un campo qui, **solo quel campo**
prevale su quello generale del sito per le sue pagine pubbliche — gli altri campi lasciati vuoti
continuano a usare quelli del sito (fallback per-campo, non tutto-o-niente). Le funzioni
`embedPrivacyScript()`, `embedTrackingHead()`, `embedTrackingBodyStart()`,
`embedGoogleAnalytics()`, `renderSiteFooterBar()`, `sendMetaConversionEvent()` e
`embedClientSideConversionEvent()` accettano ora un parametro opzionale `$profile` proprio per
questo — omesso (pagine di sistema come login/registrazione, senza un profilo specifico) restano
col comportamento di sempre, solo le impostazioni dell'admin.

La Meta Conversions API del profilo (se `fb_pixel_id`+`fb_capi_token` sono compilati) invia
automaticamente un evento quando qualcuno segue quel profilo (`follow_account.php`,
`follow_confirm.php`), scrive dal form Contatti (`contatti.php`) o prenota un tavolo/posto a un
suo evento (`reserve_table.php`).

## 31. Assistente AI (Google Gemini) per generare i testi della Timeline

Nessuna migrazione SQL: riusa la tabella `site_settings` già esistente (chiave
`gemini_api_key`, impostata da `admin_gemini.php`, nessuna `ALTER TABLE` necessaria).

Aggiunge un pulsante "✨ Genera con AI" nel modulo Timeline (`dashboard_post.php`): l'utente
scrive qualche parola chiave, `dashboard_ai_caption.php` (endpoint AJAX, stesso profilo/CSRF di
sempre) chiama `geminiGenerateText()` in `app/src/gemini.php` — un client minimale per l'API REST
di Google Gemini (livello gratuito, chiave da aistudio.google.com/apikey, stesso pattern già
usato per Spotify/YouTube: solo `httpRequest()`, nessuna libreria esterna) — e restituisce un
testo pronto che l'utente può modificare liberamente prima di pubblicare. Chiave unica
condivisa da tutti i profili (come Spotify/YouTube), impostata in ADMIN → Assistente AI.

## 32. Miniatura leggera per le foto della Timeline (`timeline_posts.image_thumb_path`)

```sql
ALTER TABLE timeline_posts ADD COLUMN image_thumb_path VARCHAR(255) DEFAULT NULL;
```

Le foto caricate da telefono/fotocamera possono pesare diversi MB, ma nella lista/feed della
Timeline vengono mostrate solo come miniature 56-64px — scaricare il file intero solo per quello
è uno spreco di banda, specie scorrendo molti post. Ora, quando si allega una foto in
`dashboard_post.php`, il browser genera anche una miniatura leggera JPEG (max 320px, qualità
0.82, via `<canvas>` — stesso approccio già usato per il ritaglio avatar, nessun GD/Imagick
lato server) e la salva in `image_thumb_path`.

`getTimelineFeedForUsers()` espone `cover_thumb` (= `image_thumb_path` se presente, altrimenti
ricade su `image_path`) accanto al normale `cover`; `renderTimelineFeedItem()` e
`renderDashboardTimelineItem()` (le liste pubblica e privata) usano `cover_thumb` per la
miniatura. **La foto originale a piena qualità non viene mai toccata**: `timeline_post.php`
(la pagina del singolo post) continua a mostrare sempre `image_path`, il file esattamente come
caricato — nessuna riduzione di peso o qualità aprendo il link. I post pubblicati prima di
questa modifica (senza miniatura) continuano a funzionare col fallback su `image_path`.

---

## Come aggiungere una nuova voce

Quando una futura modifica tocca lo schema, aggiungi qui una nuova sezione numerata con il
comando SQL esatto, PRIMA di eseguirlo in produzione — così questo file resta sempre lo
specchio fedele di cosa contiene il database.
