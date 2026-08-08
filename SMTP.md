# Email / SMTP — configurazione e diagnosi

## Configurazione (dall'interfaccia, nessuna variabile d'ambiente da gestire)

**Area Admin → Email / SMTP**: host, porta, tipo di connessione (TLS/SSL/nessuna), username,
password, mittente. Include un pulsante **"Invia prova"** per testare subito senza registrare
account fittizi. Se lasci il campo password vuoto quando salvi, resta quella già impostata.

Le vecchie variabili d'ambiente (`SMTP_HOST` ecc., se già configurate su Portainer) restano
valide come ripiego, ma la pagina admin ha sempre la priorità se compilata.

Se l'SMTP non è configurato, non si rompe nulla: i messaggi di contatto restano salvati in
dashboard, e per gli account nuovi serve la verifica manuale da Area Admin finché non attivi
l'invio automatico.

## Provider attivo: SendPulse ✅

Configurato e funzionante in produzione (email transazionali approvate da SendPulse). Valori
usati:
```
Host:     smtp-pulse.com
Porta:    587 (o 2525, entrambe supportate)
Tipo:     TLS
Username: login SMTP dedicato generato da SendPulse (diverso dall'email dell'account)
Password: generata dal pannello SendPulse
```

Se in futuro si cambia provider (Brevo, Mailgun, o altro), lo stesso pattern si applica: basta
sostituire i 4 valori in Area Admin → Email/SMTP, nessuna modifica al codice necessaria.

## Opzione "Verifica il certificato SSL del server"

Disattivala **solo** se il tuo provider usa un hostname personalizzato che non corrisponde al
nome nel certificato (capita con alcuni hosting condivisi, es. Aruba: hostname tipo
`smtp.tuodominio.it` ma certificato intestato al nome reale del provider, es.
`smtps.aruba.it`). In quel caso, prima di disattivare la verifica, prova semplicemente a usare
come Host il nome esatto del certificato — se funziona così, è meglio (nessun compromesso sulla
sicurezza TLS).

## Interpretare gli errori nei log (Portainer → Containers → chifacosa_app → Logs, cerca `[SimpleSmtpMailer]`)

| Errore | Significato |
|---|---|
| `Connessione SMTP fallita: Connection timed out` | La porta è bloccata dal provider (es. Hetzner blocca di default la porta 25 in uscita per prevenire spam — non risolvibile lato codice, serve un'altra porta o servizio) |
| `Connessione SMTP fallita: Connection refused` | Host o porta sbagliati |
| `Risposta SMTP inattesa: atteso 220, ricevuto ''` | Tipo di cifratura sbagliato per quella porta (es. SSL implicito su una porta che si aspetta connessione in chiaro) |
| `Attivazione TLS fallita` | Problema nella fase di cifratura: certificato non corrispondente al nome host (vedi sopra), o il server non supporta STARTTLS su quella porta |
| `Risposta SMTP inattesa: atteso 334/235, ricevuto '530/535 ...'` | Il server rifiuta l'autenticazione (credenziali sbagliate, o richiede prima STARTTLS) |

## Note tecniche

L'invio usa un client SMTP scritto internamente (`app/src/mailer.php`), senza dipendenze esterne
(niente Composer/PHPMailer da gestire nel build Docker). Supporta STARTTLS (porta 587, la più
comune) e SSL implicito (porta 465). L'invio è sempre "best effort": un fallimento non blocca
mai l'esperienza dell'utente (il messaggio di contatto resta comunque salvato, la registrazione
comunque completata).
