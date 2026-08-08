# Gestione admin — bootstrap e comandi utili

## Recuperare la password del database

Portainer → Stacks → chifacosa → sezione **Environment variables** → cerca `DB_PASSWORD` (a volte
mascherata da un'icona occhio 👁 da cliccare). Se non l'hai mai impostata, nel
`docker-compose.yml` c'è un default:
```
DB_PASS=${DB_PASSWORD:-cambiami_123}
```
Se non hai mai aggiunto `DB_PASSWORD` tra le variabili dello stack, la password in uso è
`cambiami_123`.

## Come accedere alla console MySQL

Portainer → Containers → `myband_db` → Console (">_") → Command `/bin/sh` → Connect, poi:
```bash
mysql -u chifacosa_user -p chifacosa
```
Inserisci la password recuperata sopra.

## Diventare admin (bootstrap, solo la prima volta)

Nella console MySQL, sostituendo con la tua email di registrazione:
```sql
UPDATE users SET is_admin = 1 WHERE email = 'gianlucadipietro@gmail.com';
```
Verifica:
```sql
SELECT email, is_admin FROM users WHERE email = 'gianlucadipietro@gmail.com';
```
Deve mostrare `is_admin = 1`. Poi logout/login sul sito: comparirà "Area Admin" nel menu.

## Verificare manualmente un account (bypassa la conferma email)

Via SQL (se serve farlo prima ancora che l'SMTP sia configurato):
```sql
UPDATE users SET email_verified = 1, verification_token = NULL, verification_expires = NULL
WHERE email = 'email-dell-utente@esempio.it';
```

Oppure, più comodo, dall'interfaccia: **Area Admin → Utenti iscritti** → pulsante "Verifica
email" accanto all'utente non verificato (nessun SQL necessario, disponibile dalla dashboard
web dal momento in cui questa funzione è stata aggiunta).

## Elevare altri utenti ad admin

Dall'interfaccia, non serve più SQL: **Area Admin → Utenti iscritti** (o il dettaglio del
singolo utente) → pulsante **"Rendi admin"**. Protetto contro l'auto-rimozione: non puoi
togliere i permessi admin a te stesso, per non restare bloccato fuori.
