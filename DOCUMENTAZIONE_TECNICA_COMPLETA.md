# CHI FA COSA — Documentazione tecnica completa del progetto

Piattaforma multi-utente in stile "Link in Bio", nata per band e musicisti ma pensata per
qualsiasi tipo di attività o profilo (locali, ristoranti, professionisti, fan), con area pubblica
per ogni profilo, dashboard di gestione contenuti, area di amministrazione, e integrazioni con
servizi esterni (email transazionali, Spotify, tracking).

---

## 1. Architettura e stack tecnologico

| Componente | Tecnologia |
|---|---|
| Linguaggio backend | PHP 8.2 (procedurale, nessun framework — scelta deliberata per semplicità e trasparenza) |
| Web server | Apache 2 (immagine ufficiale `php:8.2-apache`) |
| Database | MySQL 8.0 |
| Frontend | HTML + CSS custom (nessun framework CSS nelle pagine pubbliche/dashboard) + Bootstrap 4/AdminLTE 3 solo nell'Area Admin |
| Icone | Font Awesome 6 (CDN) |
| Containerizzazione | Docker + Docker Compose (2 servizi: `app` e `db`) |
| Orchestrazione in produzione | Portainer (deploy diretto da repository Git) |
| Reverse proxy / SSL | Nginx Proxy Manager (Let's Encrypt automatico) |
| Versionamento | Git, repository GitHub `posillipo/chifacosa` |
| Server di produzione | Hetzner Cloud (Ubuntu), condiviso con altri progetti dell'utente (WordPress, ecc.) |

Nessuna dipendenza esterna via Composer/npm: sia il client SMTP (`app/src/mailer.php`) sia il
client Spotify (`app/src/spotify.php`) sono scritti da zero usando solo le funzioni native di
PHP (`stream_socket_client`, `file_get_contents` con stream context), per evitare di dover
gestire un processo di build nel Dockerfile.

---

## 2. Infrastruttura Docker

**`docker-compose.yml`** definisce due servizi su una rete Docker dedicata:

- **`app`** (container `chifacosa_app`): build da `app/Dockerfile`, espone la porta 8085 sull'host,
  monta come volume la cartella `uploads/` (foto profilo e brani audio) per la persistenza dei
  file caricati dagli utenti
- **`db`** (container `myband_db`): immagine ufficiale `mysql:8.0`, volume persistente per i
  dati, inizializza lo schema da `database/schema.sql` al primo avvio (se il volume è vuoto)

**`app/Dockerfile`** (basato su `php:8.2-apache`):
- Estensioni PHP installate: `pdo`, `pdo_mysql`, `mysqli`
- `mod_rewrite` e `mod_headers` abilitati
- `AllowOverride All` per il supporto a `.htaccess`
- Limiti di upload configurati (`upload_max_filesize=20M`, `post_max_size=25M`) per i brani audio
- **Configurazione errori di produzione**: `display_errors=Off`, `log_errors=On`,
  `error_log=/dev/stderr` — nessun warning/errore PHP viene mai mostrato ai visitatori, tutto
  finisce nei log del container (visibili da Portainer)
- **Entrypoint personalizzato** (`docker-entrypoint-wrapper.sh`): ad ogni avvio del container,
  ricrea le cartelle `uploads/images` e `uploads/audio` e ne corregge i permessi per `www-data`,
  risolvendo alla radice un problema riscontrato in produzione dove il bind mount Docker non
  garantiva permessi coerenti

Variabili d'ambiente iniettate da Portainer (Stack "chifacosa"): `DB_PASSWORD`, `DB_ROOT_PASSWORD`,
`SITE_URL`. Le credenziali di servizi esterni (SMTP, Spotify) **non** passano più da variabili
d'ambiente: sono gestite interamente dall'interfaccia di amministrazione e salvate cifrate nel
database (vedi sezione Area Admin).

---

## 3. Schema del database (10 tabelle)

| Tabella | Scopo | Colonne chiave |
|---|---|---|
| `users` | Account (profili + admin) | `slug` (univoco, pagina pubblica), `email`, `password_hash` (bcrypt), `is_active`, `is_admin`, `email_verified`, `verification_token`/`verification_expires` |
| `profiles` | Dati profilo pubblico, 1:1 con `users` | `display_name`, `bio`, `avatar_path`, `theme_color`, `dashboard_theme` (non più usato in UI), `spotify_artist_id`/`spotify_artist_name` |
| `links` | Link della pagina Link in Bio | `label`, `url`, `sort_order` (riordinabile), `click_count`, `is_active`, `is_website_icon` (flag manuale per l'icona "sito web personale") |
| `audio_tracks` | Brani audio caricati | `title`, `file_path`, `sort_order` |
| `events` | Concerti/date in calendario | `title`, `venue`, `city`, `event_date`, `ticket_url` |
| `blog_posts` | Articoli del blog | `title`, `slug` (univoco per utente, permalink SEO), `excerpt`, `content`, `published_at` |
| `contact_requests` | Messaggi dal form contatti/booking | `sender_name`, `sender_email`, `message`, `is_read` |
| `site_settings` | Impostazioni globali chiave/valore | script privacy, GTM/Pixel, credenziali SMTP, credenziali Spotify, URL privacy policy |
| `remember_tokens` | Login persistente "ricordami" | `selector` (in chiaro), `validator_hash` (mai in chiaro), `expires_at` — pattern standard selector/validator |
| `followers` | Sistema "Segui via email" | `email`, `verified`, `token` (doppio uso: conferma iscrizione + disiscrizione) |

Tutte le tabelle collegate a `users` hanno `ON DELETE CASCADE`: eliminare un account elimina
automaticamente profilo, link, brani, eventi, articoli, contatti e follower collegati.

---

## 4. Sito pubblico di ogni profilo

Ogni utente ha una pagina pubblica raggiungibile su `tuodominio.it/nomeslug`, con routing gestito
via `.htaccess` (mod_rewrite) verso i seguenti script:

| URL | Script | Contenuto |
|---|---|---|
| `/slug` | `u.php` | Home artista: avatar, bio (a comparsa al passaggio del mouse), icone social ufficiali, pulsanti link colorati, form "Segui via email" |
| `/slug/blog` | `blog_index.php` | Elenco articoli |
| `/slug/blog/anno.mese.giorno.slug-articolo` | `blog_post.php` | Singolo articolo, permalink SEO-friendly |
| `/slug/brani` | `brani.php` | Brani audio caricati (player HTML5) |
| `/slug/eventi` | `eventi.php` | Prossimi concerti |
| `/slug/contatti` | `contatti.php` | Form di contatto/booking |
| `/slug/link` | `u.php?view=link` | Link in Bio con il tema Colorful di base, qualunque sia il tema della Home (AdminLTE, PA Italia, ...); voce "Link" nel menu AdminLTE se il profilo ha link attivi |
| `/slug/spotify` | `artist_spotify.php` | Discografia Spotify (solo se collegata) |

**Header condiviso** (`publicProfileHeader()` in `functions.php`): avatar, nome, menu di
navigazione identico su tutte le pagine, con la voce attiva evidenziata in bianco. Il tab
"Spotify" compare solo se l'artista ha collegato un profilo.

**Icone social**: rilevamento automatico della piattaforma da dominio URL (Spotify, Apple Music,
Instagram, Facebook, TikTok, YouTube, LinkedIn, SoundCloud, WhatsApp) tramite
`detectPlatform()`/`splitSocialAndActionLinks()`. Solo la prima occorrenza di ciascuna
piattaforma diventa icona (nell'ordine in cui il band manager dispone i link); i duplicati
restano pulsanti normali. Un flag manuale (`is_website_icon`) permette di forzare un link
qualsiasi come icona "sito web personale" (dominio non riconoscibile automaticamente).

**Tema visivo "colorful"**: sfondo sfumato pastello, pulsanti arrotondati con palette di 8 colori
a rotazione, ispirato al layout del tema WordPress "Meeek".

**Bio come vignetta**: passando il mouse sull'avatar compare un fumetto con la biografia (CSS
puro, nessun JavaScript).

**Barra fissa "Unisciti a CHI FA COSA"**: sempre visibile in fondo a ogni pagina pubblica, con
`position: fixed`, invita alla registrazione di nuovi band manager.

**Footer**: link orizzontali "Preferenze Cookie" (riapre il pannello CookieYes tramite la classe
`cky-banner-element`) · "Privacy" (URL configurabile da admin) · "CHI FA COSA" (torna alla home).

**Open Graph / Twitter Card** su tutte le pagine per una condivisione social corretta.

**Cache-busting automatico**: il CSS viene caricato con un parametro di versione basato sulla
data di modifica del file (`assetUrl()`), così un aggiornamento del CSS si riflette subito senza
richiedere lo svuotamento manuale della cache del browser.

---

## 5. Dashboard band manager (utente registrato)

Layout: navbar + tab orizzontali (stile originale, non AdminLTE — l'unificazione grafica con
l'Area Admin era stata valutata e poi annullata su richiesta esplicita). Tema forzato "chiaro"
(la scelta scuro/chiaro è stata rimossa dall'interfaccia).

| Pagina | Funzione |
|---|---|
| `dashboard_profile.php` | Nome, bio, foto profilo, colore tema pagina pubblica |
| `dashboard_links.php` | Gestione link: aggiungi, **modifica** (nuovo), riordina (frecce ▲▼ con pulsanti a sola icona), mostra/nascondi, elimina, flag "sito web personale" |
| `dashboard_audio.php` | Upload/gestione brani audio |
| `dashboard_events.php` | Gestione concerti |
| `dashboard_blog.php` | Editor articoli (genera slug SEO automaticamente, notifica i follower alla pubblicazione) |
| `dashboard_contacts.php` | Richieste di contatto ricevute |
| `dashboard_spotify.php` | Ricerca e collegamento manuale del proprio profilo Spotify |
| `dashboard_followers.php` | Conteggio e elenco follower, crescita 7/30 giorni |
| `dashboard_timeline.php` (Feed) | Elenco dei contenuti dei profili seguiti e propri. I propri si aprono nella pagina di modifica; quelli degli **altri profili** aprono un **popup di anteprima** (titolo, copertina, autore, data, testo breve per articoli e aggiornamenti via `dashboard_feed_preview.php`) con il pulsante «Vai alla pagina pubblica» (`dashboardFeedPreviewAttr()` in `fn_feed.php`) |
| `dashboard_contests.php` | Contest per **tutti** gli utenti, in sola lettura: elenco dei contest non in bozza di profili con il modulo abilitato dall'admin (`contestListAllPublic()`), dettaglio con «Come si partecipa» (date, regole, regolamento) e **propria partecipazione**: candidatura con ricerca Spotify (`contestAddEntry()`), modifica di nome ed email, ritiro (`contestWithdrawEntry()`, non possibile a votazioni in corso per i brani approvati o a contest concluso). Voce di menu «Contest» (gruppo Eventi e prenotazioni), visibile se esiste almeno un contest; la voce di gestione per i profili abilitati si chiama «Gestisci Contest» (`dashboard_contest.php`) |
| `dashboard_videos.php` | Caricamento video (MP4/MOV/WebM, uno alla volta con avanzamento). Il modulo mostra sempre: titolo, descrizione con «Genera con AI», file, privacy (Pubblico / Solo io), «Includi nel Feed», «Pubblica sui social» (data programmata). Con più file titolo e descrizione si scrivono per ciascuno. Salvataggio lato server invariato (`action=upload`): `title`, `description`, `visibility`, `in_feed`, `publish_at`; stessi campi di API/MCP (`add_video_from_url`, `update_video`) |
| `dashboard_profile_view.php` | Scheda di un profilo (dalla ricerca profili nella barra in alto): info essenziali, Segui / Non seguire più, «Scrivi un messaggio» se reciproci, link alla pagina pubblica. La ricerca usa `api_profile_search.php` |

Menu laterale (`_dash_header.php`), gruppi nell'ordine: **Pubblica Contenuti** (con l'ingranaggio che porta a Menu di Navigazione, `dashboard_nav_menu.php`), Eventi e prenotazioni, **Integrazioni** (Spotify, Podcast e YouTube per band/etichette; Telegram, Social Automatico e Marketing solo admin; pallino verde = collegata), **Business** (prima «Business e vendite»; «Contatti» ora «Contatti Web»); Community non è più nel menu di sinistra. Sotto Business: **Prossimi eventi** (`renderDashSideEvents()`, solo profili con la sezione Eventi). Contatori rossi accanto a «Richieste» e «Prenotazioni». **Colonna destra** (`renderDashRight()`): per tutti il blocco **Community** (`renderDashCommunity()`: tessere Follower / Seguiti / Invita con i conteggi, schede «Ultimi follower» e «Ultimi seguiti» con gli ultimi 10, pulsante «Segui anche tu» sui follower non ricambiati; la scheda scelta e l'altezza dell'elenco (maniglia trascinabile in basso, frecce da tastiera, doppio clic = altezza iniziale) si ricordano nel browser (`localStorage`: `dashCommTab`, `dashCommH`), link alla scheda `dashboard_profile_view.php`; i follower sono profili + iscritti via email); terza scheda **Scopri** (`dashNewProfiles()`: gli ultimi 10 profili iscritti e attivi, in ordine di arrivo, con «Segui anche tu»); solo per gli amministratori anche Da gestire, Coda Social e Automazioni e MCP. Strumenti disattivabili per tutto il sito da Admin → Funzioni del sito → «Strumenti della dashboard» (chiavi in `disabled_nav_keys`, helper `siteToolEnabled()`): `bacheca`, `cinema`, `prodotti`, `programmati` (Programmati + Calendario).

I pulsanti azione (sposta su/giù, modifica, mostra/nascondi, elimina) usano icone Font Awesome
(`.icon-btn`), non testo — corretto un bug per cui il tab attivo nel menu diventava illeggibile
(bianco su bianco) nel tema chiaro.

---

### Guide delle pagine (popup "?")

Ogni pagina della dashboard che ha una guida mostra, accanto al titolo, un **?** che apre un popup con
un testo breve e, se c'è un approfondimento (o un link esterno), il collegamento "Approfondisci".

- **Testi nel database**, tabella `page_help` (`page_key` = nome del file senza `.php`, `title`, `body`, `more`,
  `doc_url`): non nel codice delle pagine. Migrazione `2026_11_29_page_help.sql` (crea la tabella e inserisce i testi
  iniziali senza sovrascrivere quelli modificati).
### Profilo privato
- **Impostazione**: Dashboard → Profilo → «Profilo privato» (`profiles.is_private`). Codice in `app/src/fn_privacy.php`.
- **Pagina pubblica chiusa**: `enforcePrivateProfileGate()` (chiamata alla fine di `functions.php`) controlla `?slug=` su tutte le pagine pubbliche del profilo (Home, Timeline, Blog, Eventi, Contatti, feed RSS, ecc.). Se il profilo è privato e chi guarda non è autorizzato risponde 404 con `X-Robots-Tag: noindex`. Autorizzati: il proprietario, chi lo gestisce (`canManageProfile`), gli admin del sito e gli account che lo seguono (`account_follows`). Chi segue solo via email non è identificabile e non entra.
- **Nascosto da**: ricerca della dashboard (`api_profile_search.php`), scheda «Scopri» (`dashNewProfiles()`), sitemap, scheda profilo in dashboard (`dashboard_profile_view.php`).
- **Non toccati**: API e server MCP (passano dal token del profilo; le pagine `api_*`, dashboard, admin, accesso e «Segui» sono escluse dal blocco). Se la colonna non esiste ancora (migrazione non eseguita) nessuna lista cambia e nessuna pagina si chiude.

- **Codice**: `app/src/fn_help.php` (`renderPageHelpHead()`, chiamata da `_dash_header.php`; segnaposto `{site_name}`,
  `{slug}`, `{album_max_photos}`, `{service_max_photos}`, `{max_video_mb}`; formato: riga vuota = paragrafo,
  `**grassetto**`, `` `codice` ``).
- **Il "?" compare sempre** su ogni pagina della dashboard: se manca il testo (o la tabella) il popup mostra un messaggio generico. Le pagine con un titolo proprio (Feed, Coda Social, Calendario Programmazioni, Marketing) dichiarano `$pageHelpInline = true` e usano `renderPageHelpButton()` accanto al loro titolo. Migrazioni: `2026_11_29_page_help.sql` e `2026_11_30_page_help_all_pages.sql` (testi delle restanti pagine).
- **Approfondisci** apre `guida.php?p=<chiave>` (elenco di tutte le guide in `guida.php`) oppure il link esterno `doc_url`.
- **Modifica testi**: Admin → "Guide delle pagine" (`admin_page_help.php`).
- **Regola**: quando cambia il comportamento di una pagina si aggiorna anche la sua guida (e questa documentazione).
  Le pagine non usano più riquadri "Come funziona" in cima.

---

### Studio — copia della dashboard per la nuova grafica (in prova)

Copia **funzionante** della dashboard in `app/public/studio/` (indirizzo `/studio/`): stesse funzioni, stesso login e stessi dati dell'originale, che resta intatta. Non è collegata dal sito, è `noindex`, il nome è riservato come slug; serve il login ma non è limitata agli admin.

- **Pagine generate:** `scripts/make-studio.py` copia ogni `app/public/dashboard*.php` in `studio/` correggendo solo i percorsi (`../../src`, testata e piè originali, `../uploads`). **Non modificare a mano** le pagine generate: dopo ogni modifica alla dashboard originale si rilancia `python3 scripts/make-studio.py` (le modifiche specifiche di Studio vanno in `studio/_overrides/NOME.php`, che lo script copia al posto della pagina generata).
- **Strato Studio:** `studio/_studio_boot.php`, incluso all'inizio di ogni pagina copiata, tiene la navigazione dentro `/studio/` (riscrive i collegamenti `/dashboard*.php` nell'HTML, nel JSON e nei reindirizzamenti), aggiunge `assets/studio.css` e la classe `studio-mode` al `body`, ed esclude dai motori di ricerca.
- **Grafica:** si costruisce in `studio/assets/studio.css` (token di colore, schede, pulsanti, menu…). A regime la copia sostituirà l'originale.


---

## 6. Area di amministrazione (`is_admin = 1`)

Layout **AdminLTE 3** (Bootstrap 4) via CDN, sidebar laterale con 7 sezioni:

| Pagina | Funzione |
|---|---|
| `admin_dashboard.php` | Statistiche generali: utenti totali/attivi/disattivati/da verificare, nuove iscrizioni 7/30gg, contenuti totali, contatti ricevuti |
| `admin_users.php` | Elenco utenti con filtri (nome/email/slug, stato), attiva/disattiva, **rendi/rimuovi admin**, verifica email manuale, elimina account |
| `admin_user_edit.php` | Modifica dati utente (nome, email, slug) |
| `admin_user_detail.php` | Dettaglio utente + moderazione contenuti (elimina singolarmente link/brani/eventi/articoli senza disattivare l'account) |
| `admin_contacts.php` | Casella globale di tutte le richieste di contatto di tutti gli utenti |
| `admin_privacy.php` | Script privacy/cookie (iniettato in automatico su tutte le pagine pubbliche) + URL Privacy Policy + **Google Analytics** (solo Measurement ID, snippet generato automaticamente) |
| `admin_tracking.php` | Script Google Tag Manager (head + noscript body) e Facebook Pixel |
| `admin_smtp.php` | Configurazione SMTP completa (host/porta/tipo cifratura/credenziali/mittente) con pulsante "Invia prova"; opzione per disattivare la verifica del certificato SSL (utile con hosting che usano hostname personalizzati) |
| `admin_spotify.php` | Credenziali API Spotify (Client ID/Secret) con test di connessione |

Il primo account admin va promosso via comando SQL diretto (`UPDATE users SET is_admin=1 WHERE
email=...`); da lì in poi si possono promuovere altri utenti dall'interfaccia.

---

## 7. Sistema di autenticazione

- **Registrazione** (`register.php`): crea l'account con `email_verified=0`, genera un token di
  verifica (32 byte casuali, validità 24 ore), invia un'email con link di conferma
- **Verifica** (`verify.php`): valida il token, attiva l'account
- **Reinvio conferma** (`resend_verification.php`): rigenera il token se necessario (risposta
  identica indipendentemente dall'esito, per non rivelare quali email sono registrate)
- **Login** (`login.php`): bloccato per account non verificati o disattivati; checkbox
  "Ricordami" opzionale
- **Login persistente "ricordami"**: cookie con pattern selector/validator (32 byte casuali per
  il validator, solo l'hash SHA-256 salvato nel database); validità 30 giorni; il token viene
  **ruotato** (invalidato e riemesso) ad ogni utilizzo automatico, per limitare i danni in caso
  di furto del cookie
- **Logout**: invalida sia la sessione sia l'eventuale token "ricordami"

---

### Registrazione con Google e benvenuto

- **Registrazione solo con Google**: `register.php` rimanda a `auth_google_start.php` (mantiene `?ref=`). Il modulo con email e password resta
  solo come ripiego dove l'accesso con Google non è configurato. L'email deve essere verificata da Google.
- **Profilo incompleto = niente dashboard**: `requireLogin()` chiama `onboardingGate()` (`fn_auth.php`): finché `users.account_type_chosen = 0` ogni pagina che richiede il login rimanda a `onboarding_setup.php` (le API rispondono 403). Pagine escluse: il form stesso, logout, condizioni d'uso, eliminazione account.
- **Benvenuto** (`onboarding_setup.php`): form precompilato con i dati dell'account Google (email non modificabile, nome, foto — salvati in
  `$_SESSION['google_profile']` dal callback). Campi: tipo di profilo, nome del profilo, **Username**, bio, foto, moduli.
- **Tipo di profilo** in forma generica: «Profilo artista» (`band`), «Profilo aziendale» (`label`), «Profilo personale» (`fan`). I valori nel database
  restano gli stessi e servono solo come dato statistico e per attivare i moduli giusti.
- **Username** = `users.slug`, univoco in tutto il sito (`usernameNormalize()` / `usernameValidationError()` in `fn_auth.php`, esclusi gli slug riservati).
  Il controllo in tempo reale usa `api_username_check.php`; l'indirizzo tra parentesi (`dominio/username`) si aggiorna mentre si scrive.

---

## 8. Sistema "Segui via email"

Meccanismo di following leggero (nessun account fan, solo email) per dare ai visitatori un modo
di restare aggiornati sulle novità di un artista senza registrarsi:

1. Form email sulla pagina pubblica artista (`follow.php`)
2. **Doppio opt-in obbligatorio**: email di conferma con token prima che l'iscrizione sia attiva
   (`follow_confirm.php`) — protezione anti-spam essenziale, non opzionale
3. **Notifica automatica** ai follower verificati quando l'artista pubblica un nuovo articolo
   blog o un nuovo evento (`notifyFollowersNewContent()` in `functions.php`), con link di
   disiscrizione obbligatorio in ogni email (`follow_unsubscribe.php`)
4. Contatore follower pubblico sulla pagina artista
5. Sezione dedicata nella dashboard del band manager con elenco ed evoluzione nel tempo

Non ancora implementata (valutata e rimandata): una directory pubblica per scoprire altri
artisti sulla piattaforma — rafforzerebbe l'effetto rete del sistema di following.

---

## 9. Integrazione email (SMTP)

Client SMTP scritto da zero (`app/src/mailer.php`, classe `SimpleSmtpMailer`), senza dipendenze
esterne: supporta STARTTLS (porta 587), SSL implicito (porta 465), o nessuna cifratura,
autenticazione AUTH LOGIN, e un'opzione per disattivare la verifica del certificato SSL (utile
con hosting che usano un hostname personalizzato diverso dal nome nel certificato).

**Provider attivo in produzione: SendPulse** (email transazionali approvate), configurato
interamente da `admin_smtp.php` — nessuna variabile d'ambiente da gestire.

Usato per: email di verifica registrazione, notifiche messaggi di contatto, email di conferma
"Segui", notifiche nuovi contenuti ai follower. In tutti i casi l'invio è "best effort": un
fallimento non blocca mai l'azione principale dell'utente (registrazione, pubblicazione post,
ecc.), e viene solo registrato nei log per la diagnosi (`error_log`, prefisso
`[SimpleSmtpMailer]`).

---

## 10. Integrazione Spotify

Client scritto da zero (`app/src/spotify.php`), **Client Credentials Flow** (autenticazione
app-to-app, nessun login utente Spotify richiesto):

- `getSpotifyAppToken()`: ottiene e mette in cache nel database il token di accesso (validità
  ~1 ora, rinnovo automatico)
- `spotifySearchArtist()`: ricerca artisti per nome (usata nella dashboard per il collegamento
  manuale del profilo)
- `spotifyGetArtistAlbums()` / `spotifyGetArtistTopTracks()`: dati pubblici del catalogo per la
  pagina discografia pubblica

Scelta progettuale importante: **non è stato implementato il login OAuth personale con
Spotify**, dopo un'analisi che ha evidenziato due criticità — (1) non esiste alcuna API pubblica
che colleghi un account Spotify personale al relativo profilo Artista, quindi l'automatismo
richiesto in origine non è tecnicamente realizzabile; (2) le nuove app Spotify sono limitate a
25 utenti finché non approvata la "Extended Quota Mode". Il Client Credentials Flow non è
soggetto a questa restrizione, quindi la funzionalità realizzata è pienamente utilizzabile da
subito da tutti gli utenti della piattaforma.

---

## 11. Sicurezza

- Password hashate con `password_hash` (bcrypt)
- Query parametrizzate ovunque (PDO prepared statements) contro SQL injection
- Token CSRF su tutti i form POST (`csrfField()`/`checkCsrf()`)
- Validazione estensioni sui file caricati (immagini e audio)
- Slug riservati (whitelist `RESERVED_SLUGS`) per evitare collisioni tra le pagine di sistema e
  gli slug scelti dagli utenti
- Cookie di sessione e "ricordami" con `httponly`, `samesite=Lax`, `secure` quando su HTTPS
- Password/secret (SMTP, Spotify) mai esposte in chiaro nei form (campo vuoto = "non modificare
  il valore esistente")
- `display_errors` disattivato in produzione (nessuna informazione tecnica esposta a visitatori)

---

## 12. Workflow di sviluppo e deploy

- Repository GitHub pubblico `posillipo/chifacosa`
- Deploy su Portainer collegato direttamente al repository (build method "Repository"),
  aggiornamento con un clic ("Pull and redeploy")
- Regola operativa stabilita: **ogni modifica al codice passa esclusivamente da GitHub +
  redeploy**, mai interventi manuali dentro i container in produzione — l'unica eccezione sono i
  comandi SQL sui *dati* (migrazioni), mai sul codice
- Ogni modifica allo schema del database è documentata in `MIGRAZIONI.md` (12 migrazioni
  applicate finora, in ordine cronologico) prima ancora di essere eseguita in produzione
- Reverse proxy Nginx Proxy Manager con certificato SSL Let's Encrypt automatico

## 13. Documentazione del progetto

10 file `.md` nella root del repository (ridotti da un totale di 33 durante una pulizia di
consolidamento, poi arricchiti con due nuove guide specifiche): `README.md` (indice +
panoramica), `LOCAL_TESTING.md`, `GITHUB.md`, `DEPLOY.md`, `ADMIN_SETUP.md`, `SMTP.md`,
`SPOTIFY_SETUP.md`, `COOKIE_PREFERENZE_SETUP.md`, `MIGRAZIONI.md`, `SCHEMA_DATABASE.md`.

## Dashboard classica riservata al proprietario

- Le pagine `/dashboard*.php` rimandano per tutti alla versione Studio (`/studio/dashboard*.php`, 302 in GET, 307 negli altri metodi, query string conservata).
- La dashboard classica resta attiva solo per il proprietario: va aperto una volta `/classic-<TOKEN>` da loggato con il suo account; viene impostato il cookie `cdash` (HMAC, 30 giorni, httponly) e da quel momento `/dashboard*.php` funziona normalmente su quel browser.
- Variabili d'ambiente (mai nel codice): `CLASSIC_DASH_TOKEN` (almeno 16 caratteri alfanumerici) e `CLASSIC_DASH_OWNER_EMAIL`. Opzionali: se mancano, la classica è aperta solo agli amministratori del sito (`isSiteAdmin`), senza percorso segreto. Token sbagliato → 404.
- Codice: `app/src/fn_classic_gate.php` (richiamato a fine `functions.php`), `app/public/classic_entry.php`, regola in `.htaccess`.

- Senza variabili d'ambiente: gli amministratori vedono la voce di menu "Dashboard classica" (`/classic_entry.php`, 404 per i non admin) che imposta il flag di sessione `classic_ok`; al nuovo login il flag si azzera e si riparte da Studio.

## Studio: pagine riscritte (gruppo 4)

Profilo, Spotify, YouTube, Telegram, Metricool, Log, API e Coda Social hanno una vista Studio dedicata in `app/public/studio/_overrides/` (logica PHP identica all'originale; solo la parte visiva cambia). La Coda Social usa `studio/assets/studio-queue.js` (anteprima, sospendi/riattiva, rimuovi, riprogramma, stessa API `api_social_queue_action.php`). Il ritaglio dell'avatar nel profilo usa gli stessi id e lo stesso `assets/js/avatar-crop.js`.

Nelle pagine Studio Spotify, Podcast e YouTube sotto il collegamento si vedono i contenuti del profilo agganciato (dischi e brani più ascoltati; ultimi 10 episodi; ultimi 12 video), letti con le stesse funzioni e la stessa cache delle pagine pubbliche (`spotifyGetArtistAlbums`, `spotifyGetArtistTopTracks`, `spotifyGetShowEpisodes`, `youtubeGetChannelVideos`).

## Coda social: fino a 10 immagini per uscita

- `socialQueueImageUrls($riga)` (in `app/src/metricool_queue.php`) restituisce sempre una lista (max `SOCIAL_QUEUE_MAX_IMAGES` = 10): l'elenco salvato in `social_queue.image_urls` se c'è; per i post Timeline le foto lette dal post (principale + `timeline_post_photos`, quindi aggiornate se le foto cambiano dopo l'accodamento); altrimenti `image_url`.
- Le API `social-queue/pending`, `/list` e `/enqueue` espongono `image_urls`; `enqueue` accetta `image_urls` (max 10, altrimenti 422). La prima immagine resta anche in `image_url`.
- MCP: `get_social_queue` restituisce `image_urls`; `enqueue_social_queue` ha il parametro `image_urls` (modificato in entrambi i repo: `mcp-server/server.js` di chifacosa e di myband).
- Il taglio per social (X e Bluesky 4, Pinterest 1, ecc.) NON è nel codice: è una regola della routine che passa i dati a Metricool.
- Studio, Coda Social: etichetta «N foto» e anteprima con tutte le immagini. La coda della dashboard classica mostra ancora solo la prima.
- Migrazione `2026_11_35_social_queue_image_urls.sql` (opzionale: serve solo per gli elenchi passati a mano).

## Messaggi: eccezione per gli amministratori

In `dashboard_chat.php` si può scrivere solo se i due profili si seguono a vicenda (`areMutualFollowers`); gli amministratori del sito (`users.is_admin` dell'utente loggato) possono scrivere a chiunque, anche più messaggi di seguito. Il controllo è lato server (`$canWrite`). Nella scheda `dashboard_profile_view.php` il pulsante «Scrivi un messaggio» compare ai follower reciproci e sempre agli admin. Il destinatario vede la conversazione e i messaggi ma può rispondere solo se vi seguite a vicenda (regola invariata).
