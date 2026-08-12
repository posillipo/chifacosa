# Integrazione Spotify — come ottenere le credenziali e attivarla

## 1. Crea un'app su Spotify for Developers

1. Vai su https://developer.spotify.com/dashboard e accedi (con un account Spotify qualsiasi)
2. **Create app**
3. Compila:
   - **App name**: es. "CHI FA COSA"
   - **App description**: es. "Piattaforma Link in Bio"
   - **Redirect URI**: puoi mettere `https://www.tuodominio.it/` (non viene effettivamente usata da
     questa integrazione, che non richiede login utente, ma il campo è obbligatorio)
   - **Which API/SDKs are you planning to use?**: spunta **Web API**
4. Salva, poi apri **Settings** dell'app appena creata
5. Copia **Client ID** e (dopo aver cliccato "View client secret") **Client Secret**

## 2. Inserisci le credenziali in CHI FA COSA

1. **Area Admin → Spotify**
2. Incolla Client ID e Client Secret, **Salva credenziali**
3. Clicca **Testa connessione** — deve confermare che le credenziali funzionano

## 3. Come lo usa il band manager

1. **Dashboard → Spotify**
2. Cerca il proprio nome artista
3. Seleziona il profilo corretto tra i risultati (attenzione a eventuali omonimie)
4. La pagina pubblica `tuodominio.it/tuoslug/spotify` si popola automaticamente con album, singoli
   e brani più ascoltati, e il tab "Spotify" compare nel menu di navigazione pubblico

## Note tecniche

- Non richiede login Spotify da parte degli utenti: usa il **Client Credentials Flow**, pensato
  per accedere a dati pubblici del catalogo (ricerca, album, brani) autenticando solo l'app
- Non soggetto al limite di 25 utenti del "Development Mode" (quella restrizione si applica solo
  al login OAuth personale, non a questo flusso app-to-app)
- Il token di accesso viene messo in cache nel database e rinnovato automaticamente quando scade
  (circa ogni ora)
- Se le credenziali non sono configurate, il tab "Spotify" semplicemente non compare per nessun
  utente — nessun errore visibile

## Migrazione database necessaria

Vedi `MIGRAZIONI.md`, sezione 11.
