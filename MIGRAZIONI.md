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

---

## Come aggiungere una nuova voce

Quando una futura modifica tocca lo schema, aggiungi qui una nuova sezione numerata con il
comando SQL esatto, PRIMA di eseguirlo in produzione — così questo file resta sempre lo
specchio fedele di cosa contiene il database.
