<?php
// Date e fusi orari: formati italiani, conversioni, etichette.
// Funzioni spostate da functions.php senza modifiche (stesso contenuto, solo file diverso).


// Chiave del fuso orario scelto dal profilo, con fallback a "rome": quasi tutti i profili di
// questa piattaforma sono italiani, ed è il fuso più probabile a "correggere" subito l'orario per
// chi non ha ancora impostato nulla (valore di default della colonna, prima 'dark', non è una
// chiave valida di TIMEZONE_OPTIONS e ricade quindi qui).
function profileTimezoneKey(?array $profile): string {
    $key = is_array($profile) ? ($profile['dashboard_theme'] ?? '') : '';
    return isset(TIMEZONE_OPTIONS[$key]) ? $key : 'rome';
}


function profileTimezoneName(?array $profile): string {
    return TIMEZONE_OPTIONS[profileTimezoneKey($profile)]['tz'];
}


function formatItalianDate(?string $datetime, ?array $profile): string {
    if (!$datetime) {
        return '';
    }
    try {
        $dt = new DateTime($datetime, new DateTimeZone(date_default_timezone_get()));
        $dt->setTimezone(new DateTimeZone(profileTimezoneName($profile)));
        $giorni = ['domenica','lunedì','martedì','mercoledì','giovedì','venerdì','sabato'];
        $mesi = ['','gennaio','febbraio','marzo','aprile','maggio','giugno','luglio','agosto','settembre','ottobre','novembre','dicembre'];
        return $giorni[(int)$dt->format('w')] . ' ' . $dt->format('j') . ' ' . $mesi[(int)$dt->format('n')] . ' ' . $dt->format('Y');
    } catch (Throwable $e) {
        return date('d/m/Y', strtotime($datetime));
    }
}


function formatLocalDateTime(?string $datetime, ?array $profile, string $format = 'd/m/Y H:i'): string {
    if (!$datetime) {
        return '';
    }
    try {
        $dt = new DateTime($datetime, new DateTimeZone(date_default_timezone_get()));
        $dt->setTimezone(new DateTimeZone(profileTimezoneName($profile)));
        return $dt->format($format);
    } catch (Exception $e) {
        return date($format, strtotime($datetime));
    }
}


// Stessa conversione di formatLocalDateTime(), ma nel formato richiesto da un campo
// <input type="datetime-local"> — da usare SEMPRE per precompilare un simile campo con un
// publish_at/published_at già salvato (pannelli "✏️ Gestisci pubblicazione" di Timeline e di
// ogni modulo Che Amo, Album, Servizi...). Prima questi pannelli usavano un date(strtotime(...))
// diretto, che ristampa il valore salvato nel fuso orario del SERVER invece che in quello del
// profilo — corretto solo per pura coincidenza se i due fusi coincidono, sbagliato altrimenti (un
// post programmato per le 9:00 secondo il profilo poteva ricomparire in modifica come "7:00",
// pur essendo lo stesso identico istante — bug segnalato da un utente confrontando questa pagina
// con dashboard_schedule.php, che invece già usava la conversione corretta).
function localDateTimeInputValue(?string $datetime, ?array $profile): string {
    if (!$datetime) {
        return '';
    }
    try {
        $dt = new DateTime($datetime, new DateTimeZone(date_default_timezone_get()));
        $dt->setTimezone(new DateTimeZone(profileTimezoneName($profile)));
        return $dt->format('Y-m-d\TH:i');
    } catch (Exception $e) {
        return date('Y-m-d\TH:i', strtotime($datetime) ?: time());
    }
}


// Etichetta "Mese Anno" (es. "Settembre 2026") di un datetime nel fuso del profilo — usata per
// raggruppare contenuti per mese su una pagina pubblica (es. /slug/viaggi). Va di pari passo con
// monthYearGroupKey() qui sotto: stessa conversione di fuso orario, la chiave serve a raggruppare
// senza rifare il calcolo, l'etichetta è solo per la stampa.
function monthYearLabel(?string $datetime, ?array $profile): string {
    if (!$datetime) {
        return '';
    }
    try {
        $dt = new DateTime($datetime, new DateTimeZone(date_default_timezone_get()));
        $dt->setTimezone(new DateTimeZone(profileTimezoneName($profile)));
    } catch (Exception $e) {
        $dt = new DateTime(date('Y-m-d H:i:s', strtotime($datetime) ?: time()));
    }
    return ITALIAN_MONTHS[(int) $dt->format('n')] . ' ' . $dt->format('Y');
}


// Chiave ordinabile "AAAA-MM" per raggruppare per mese (vedi monthYearLabel()) — stesso fuso
// orario del profilo, così un contenuto salvato a cavallo di mezzanotte finisce nel mese giusto
// dal punto di vista di chi lo guarda, non del server.
function monthYearGroupKey(?string $datetime, ?array $profile): string {
    if (!$datetime) {
        return '';
    }
    try {
        $dt = new DateTime($datetime, new DateTimeZone(date_default_timezone_get()));
        $dt->setTimezone(new DateTimeZone(profileTimezoneName($profile)));
    } catch (Exception $e) {
        $dt = new DateTime(date('Y-m-d H:i:s', strtotime($datetime) ?: time()));
    }
    return $dt->format('Y-m');
}


// Inversa di formatLocalDateTime(): un valore digitato in un campo datetime-local del browser
// (es. "programma la pubblicazione", validità di un'offerta, data di un evento) è l'orario "a
// muro" di CHI lo sta scrivendo in quel momento — MAI il fuso del server. Va quindi convertito
// nella direzione opposta prima di salvarlo, altrimenti un confronto con NOW() (scritto dal
// server nel SUO fuso) è sfalsato dello scarto tra i due fusi.
//
// $browserOffsetMinutes, quando disponibile, ha SEMPRE la precedenza sul fuso del profilo:
// riflette l'orologio reale del dispositivo di chi sta scrivendo in questo istante (da
// JavaScript, new Date().getTimezoneOffset()) — il fuso salvato sul profilo descrive invece come
// va MOSTRATO il contenuto pubblicato, che non è necessariamente dove si trova chi lo gestisce in
// un momento specifico (es. in viaggio all'estero: il profilo di un'attività italiana resta in
// orario italiano per il pubblico, ma chi digita una data in quel momento pensa al proprio
// orologio locale, non a quello del profilo). Il fuso del profilo resta solo come fallback per
// gli inserimenti senza JavaScript.
//
// Ritorna null se l'input è vuoto o non interpretabile.
function parseLocalDateTime(?string $input, ?array $profile, ?int $browserOffsetMinutes = null): ?string {
    $input = trim((string) $input);
    if ($input === '') {
        return null;
    }
    try {
        if ($browserOffsetMinutes !== null) {
            // Convenzione JavaScript: getTimezoneOffset() = minuti (UTC - locale), positivo per i
            // fusi indietro rispetto a UTC — quindi UTC = digitato + offset.
            $dt = new DateTime($input, new DateTimeZone('UTC'));
            $dt->modify(sprintf('%+d minutes', $browserOffsetMinutes));
        } else {
            $dt = new DateTime($input, new DateTimeZone(profileTimezoneName($profile)));
        }
        $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
        return $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        $ts = strtotime($input);
        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }
}


// Legge l'offset di fuso orario inviato da JavaScript (vedi commento sopra) da $_POST, se
// presente e numerico — helper condiviso per non ripetere il parsing in ogni pagina che gestisce
// un campo datetime-local.
function browserTzOffsetFromRequest(): ?int {
    $raw = $_POST['tz_offset_minutes'] ?? null;
    return ($raw !== null && $raw !== '' && is_numeric($raw)) ? (int) $raw : null;
}


// Data/ora di pubblicazione mostrata pubblicamente per qualunque tipo di post/elemento
// (Timeline, Che Amo, Viaggi, Brani...): publish_at se impostato, altrimenti created_at — stesso
// criterio già usato per raggruppare "la stessa giornata" (getSameDayFavorites/
// getSameDayTimelinePosts), qui invece per mostrare la data e l'ora per intero, nel fuso orario
// del profilo (vedi formatLocalDateTime()).
function publishedAtLabel(?string $publishAt, string $createdAt, ?array $profile = null): string {
    return formatLocalDateTime($publishAt ?: $createdAt, $profile);
}


function eventCalendarUtcTimestamp(string $datetime, ?array $profile, int $addHours = 0): string {
    $dt = new DateTime($datetime, new DateTimeZone(date_default_timezone_get()));
    $dt->setTimezone(new DateTimeZone(profileTimezoneName($profile)));
    if ($addHours !== 0) {
        $dt->modify($addHours . ' hours');
    }
    $dt->setTimezone(new DateTimeZone('UTC'));
    return $dt->format('Ymd\THis\Z');
}


// Data lunga in italiano, es. "mercoledì 18 novembre 2026 21.00" (fuso del profilo).
function italianLongDateTime(?string $datetime, ?array $profile): string {
    if (!$datetime) {
        return '';
    }
    [$w, $j, $n, $y, $hi] = explode('|', formatLocalDateTime($datetime, $profile, 'w|j|n|Y|H.i'));
    $days = ['domenica', 'lunedì', 'martedì', 'mercoledì', 'giovedì', 'venerdì', 'sabato'];
    $months = ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];
    return $days[(int) $w] . ' ' . (int) $j . ' ' . $months[(int) $n - 1] . ' ' . $y . ' ' . $hi;
}
