# Import dati legacy dal vecchio myband.it

## Cosa contiene questo import

- **1.835 profili band** pronti da importare (`app/import/legacy_import_ready.csv`), uno per
  ogni gestore con email valida e almeno una band collegata
- Per i gestori con più band, è stata tenuta la **prima in ordine di ID** (`elenco_locali.id`
  più basso) — le altre 67 band di quei 53 gestori sono salvate a parte in
  `app/import/legacy_extra_bands_non_importate.csv`, non perse, solo rimandate

## Filtri applicati durante la pulizia dei dati

Partendo da 2.419 gestori totali:
- 18 scartati per email non valida (formato errato)
- 566 scartati perché senza nessuna band collegata
- Nessun duplicato di email trovato all'interno del vecchio database
- **1.835 pronti per l'import**

## Mapping applicato

| Vecchio campo | Nuova colonna |
|---|---|
| `elenco_gestori.email` | `users.email` |
| `elenco_locali.nomeband` (slugificato) | `users.slug` |
| `elenco_gestori.abilitato` | `users.legacy_stato` (conservato per riferimento, non usato per attivare l'account) |
| `elenco_gestori.data_attivazione_servizio` | `users.created_at` |
| `elenco_locali.nomeband` | `profiles.display_name` |
| `elenco_locali.descrizione` + referente | `profiles.bio` |
| `elenco_locali.genere` | `profiles.genere` (nuovo campo) |
| `elenco_locali.citta`/`provincia` | `profiles.citta`/`profiles.provincia` (nuovi campi) |
| `elenco_gestori.telefono` | `profiles.telefono` (nuovo campo) |
| `elenco_locali.sito_ufficiale` | un link "Sito ufficiale" nella tabella `links` |
| `elenco_gestori.ID` / `elenco_locali.id` | `users.legacy_gestore_id` / `users.legacy_band_id` (per tracciabilità e per evitare doppie importazioni) |

**Non importati in questo giro**: foto profilo (andrebbero scaricate dal vecchio sito e
ricaricate, operazione separata se interessa), il repertorio brani (`brano1`...`brano20`,
erano probabilmente solo titoli testuali, non file audio).

## Sicurezza dell'importazione

- Ogni account creato ha **`is_active = 0`**: non compare sulla pagina pubblica, non può fare
  login. Restano "dormienti" finché non deciderai tu come e quando attivarli (es. con la
  campagna di invito di cui parlavamo)
- Password impostata a un valore casuale e inutilizzabile (nessuno può accedere con quella,
  nemmeno per errore)
- **Operazione idempotente**: lo script di import (`admin_import_legacy.php`) riconosce i
  gestori già importati (tramite `legacy_gestore_id`) e li salta — puoi rilanciarlo più volte
  senza creare doppioni
- Se un'email risultasse già usata da un account reale già registrato sul nuovo sistema, quel
  record legacy viene saltato (non sovrascrive mai un account vero)

## Come eseguire l'import

1. Push su GitHub (il CSV è incluso nel repository, dentro `app/import/`)
2. Portainer → Pull and redeploy (il Dockerfile è stato aggiornato per includere la cartella
   `import/` nell'immagine — **fuori dalla cartella pubblica del sito**, non accessibile dal web)
3. Comando SQL di migrazione (sezione 13 di `MIGRAZIONI.md`) — aggiunge le colonne necessarie
4. **Area Admin → Import legacy** → pulsante "Esegui importazione"
5. La pagina mostra un riepilogo: quanti account creati, quanti saltati e perché

## Dopo l'import

Gli account importati sono visibili in **Area Admin → Utenti iscritti**, ma risultano
disattivati (non possono fare login, non hanno pagina pubblica visibile) finché non deciderai il
prossimo passo — es. una campagna di invito via email per far loro reimpostare una password e
attivare l'account, filtrando per `legacy_stato = 'OK'` per dare priorità a chi risultava attivo
nel vecchio sistema.
