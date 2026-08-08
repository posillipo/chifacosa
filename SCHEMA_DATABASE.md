# Schema del database CHI FA COSA

Database: `chifacosa` (MySQL 8.0, charset `utf8mb4`)

## Diagramma delle relazioni

```
users (1) ──── (1) profiles
  │
  ├──< links            (1 utente → N link)
  ├──< audio_tracks      (1 utente → N brani)
  ├──< events            (1 utente → N concerti)
  ├──< blog_posts        (1 utente → N articoli)
  └──< contact_requests  (1 utente → N richieste ricevute)

site_settings   (tabella indipendente, chiave/valore globale)
```

---

## `users`
Account dei musicisti registrati (include anche l'account admin).

| Colonna | Tipo | Chiave / Vincoli | Descrizione |
|---|---|---|---|
| id | INT AUTO_INCREMENT | PK | Identificativo utente |
| slug | VARCHAR(60) | UNIQUE, NOT NULL | Nome pagina pubblica (`tuodominio.it/slug`) |
| email | VARCHAR(190) | UNIQUE, NOT NULL | Email di login |
| password_hash | VARCHAR(255) | NOT NULL | Password con hash bcrypt |
| is_active | TINYINT(1) | DEFAULT 1 | Account attivo/disattivato |
| is_admin | TINYINT(1) | DEFAULT 0 | Ruolo amministratore |
| created_at | DATETIME | DEFAULT NOW() | Data iscrizione |

## `profiles`
Dati del profilo pubblico, uno per utente.

| Colonna | Tipo | Chiave / Vincoli | Descrizione |
|---|---|---|---|
| user_id | INT | PK, FK → users.id (CASCADE) | Collegamento 1:1 con users |
| display_name | VARCHAR(120) | NOT NULL | Nome d'arte / band |
| bio | TEXT | — | Biografia |
| avatar_path | VARCHAR(255) | — | Percorso foto profilo |
| theme_color | VARCHAR(7) | DEFAULT `#6C5CE7` | Colore accento pagina pubblica |
| updated_at | DATETIME | AUTO UPDATE | Ultimo aggiornamento profilo |

## `links`
Link personalizzati mostrati nella pagina pubblica (Spotify, social, ecc.).

| Colonna | Tipo | Chiave / Vincoli | Descrizione |
|---|---|---|---|
| id | INT AUTO_INCREMENT | PK | — |
| user_id | INT | FK → users.id (CASCADE) | Proprietario del link |
| label | VARCHAR(120) | NOT NULL | Etichetta visualizzata |
| url | VARCHAR(500) | NOT NULL | URL di destinazione |
| icon | VARCHAR(40) | DEFAULT `link` | Icona (riservato, non ancora usato in UI) |
| sort_order | INT | DEFAULT 0 | Ordine di visualizzazione |
| click_count | INT | DEFAULT 0 | Contatore click (via `link.php`) |
| is_active | TINYINT(1) | DEFAULT 1 | Visibile/nascosto |

## `audio_tracks`
Brani audio caricati dal musicista.

| Colonna | Tipo | Chiave / Vincoli | Descrizione |
|---|---|---|---|
| id | INT AUTO_INCREMENT | PK | — |
| user_id | INT | FK → users.id (CASCADE) | Proprietario del brano |
| title | VARCHAR(150) | NOT NULL | Titolo brano |
| file_path | VARCHAR(255) | NOT NULL | Percorso file audio |
| sort_order | INT | DEFAULT 0 | Ordine di visualizzazione |
| created_at | DATETIME | DEFAULT NOW() | Data caricamento |

## `events`
Concerti/date in calendario.

| Colonna | Tipo | Chiave / Vincoli | Descrizione |
|---|---|---|---|
| id | INT AUTO_INCREMENT | PK | — |
| user_id | INT | FK → users.id (CASCADE) | Proprietario evento |
| title | VARCHAR(150) | NOT NULL | Nome evento |
| venue | VARCHAR(150) | — | Locale |
| city | VARCHAR(100) | — | Città |
| event_date | DATETIME | NOT NULL | Data e ora concerto |
| ticket_url | VARCHAR(500) | — | Link biglietti (opzionale) |

## `blog_posts`
Articoli del blog, con permalink SEO.

| Colonna | Tipo | Chiave / Vincoli | Descrizione |
|---|---|---|---|
| id | INT AUTO_INCREMENT | PK | — |
| user_id | INT | FK → users.id (CASCADE) | Autore |
| title | VARCHAR(200) | NOT NULL | Titolo articolo |
| slug | VARCHAR(180) | UNIQUE per (user_id, slug) | Slug per permalink `/blog/anno.mese.giorno.slug` |
| excerpt | VARCHAR(300) | — | Estratto per anteprime/social |
| content | TEXT | NOT NULL | Testo completo |
| published_at | DATETIME | DEFAULT NOW() | Data pubblicazione |

## `contact_requests`
Messaggi ricevuti dal form contatti/booking.

| Colonna | Tipo | Chiave / Vincoli | Descrizione |
|---|---|---|---|
| id | INT AUTO_INCREMENT | PK | — |
| user_id | INT | FK → users.id (CASCADE) | Destinatario |
| sender_name | VARCHAR(120) | NOT NULL | Nome mittente |
| sender_email | VARCHAR(190) | NOT NULL | Email mittente |
| message | TEXT | NOT NULL | Testo messaggio |
| is_read | TINYINT(1) | DEFAULT 0 | Letto/non letto |
| created_at | DATETIME | DEFAULT NOW() | Data ricezione |

## `site_settings`
Impostazioni globali chiave/valore (non legate a un utente specifico).

| Colonna | Tipo | Chiave / Vincoli | Descrizione |
|---|---|---|---|
| setting_key | VARCHAR(60) | PK | Nome impostazione (es. `privacy_script`) |
| setting_value | TEXT | — | Valore (es. script Iubenda) |

---

## Note

- Tutte le tabelle collegate a `users` hanno `ON DELETE CASCADE`: eliminando un utente vengono
  eliminati automaticamente profilo, link, brani, eventi, articoli e richieste di contatto
  collegate.
- `profiles.user_id` è sia chiave primaria che chiave esterna: garantisce la relazione 1:1 con
  `users` (un profilo per utente, non di più).
- `blog_posts` ha una chiave univoca composta su `(user_id, slug)`: due utenti diversi possono
  avere articoli con lo stesso slug, ma non lo stesso utente due volte.
