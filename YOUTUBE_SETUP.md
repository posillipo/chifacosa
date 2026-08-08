# Integrazione YouTube — come ottenere la API Key e attivarla

## 1. Crea una API Key su Google Cloud

1. Vai su https://console.cloud.google.com/ e accedi (con un account Google qualsiasi)
2. Crea un nuovo progetto (o usane uno esistente) — gratuito
3. Nel menu, vai su **API e servizi → Libreria**
4. Cerca **YouTube Data API v3** e clicca **Abilita**
5. Vai su **API e servizi → Credenziali** → **Crea credenziali** → **Chiave API**
6. Copia la chiave generata (una stringa tipo `AIzaSy...`)

Consiglio: dalla stessa pagina puoi restringere la chiave solo alla "YouTube Data API v3" per
sicurezza, ma non è obbligatorio per il funzionamento.

## 2. Inserisci la chiave in CHI FA COSA

1. **Area Admin → YouTube**
2. Incolla la API Key, **Salva chiave**
3. Clicca **Testa connessione** — deve confermare che funziona

## 3. Come lo usa il band manager

1. **Dashboard → YouTube**
2. Incolla il link del proprio canale (es. `https://www.youtube.com/@nomeband`, oppure il
   classico `https://www.youtube.com/channel/UC...`)
3. **Collega canale**
4. La pagina pubblica `tuodominio.it/tuoslug/video` si popola automaticamente con gli ultimi video
   caricati, incorporati con il player ufficiale YouTube — il tab "Video" compare nel menu
   pubblico subito dopo "Spotify"

## Note tecniche

- Non richiede login YouTube/Google da parte degli utenti: solo una API Key per l'accesso ai
  dati pubblici del canale
- **Limite gratuito**: 10.000 "unità" al giorno di default. Le operazioni usate qui (leggere il
  canale e l'elenco video) sono economiche; l'unica accortezza è che il band manager incolli il
  link diretto del canale invece di doverlo cercare per nome (la ricerca costa molto di più in
  quota e non l'abbiamo implementata, proprio per questo motivo)
- I video vengono incorporati con il player ufficiale di YouTube (iframe), quindi la
  riproduzione non consuma quota API — solo la lista iniziale dei video la consuma

## Migrazione database necessaria

Vedi `MIGRAZIONI.md`, sezione 16.
