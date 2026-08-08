# Come far funzionare il pulsante "Preferenze Cookie" (CookieYes)

Il pulsante nel footer del sito ha già la classe `cky-banner-element`, che è il meccanismo
standard con cui CookieYes intercetta automaticamente qualsiasi link o pulsante per riaprire
il pannello delle preferenze — non serve nessuna chiamata JavaScript personalizzata.

## 1. Incolla lo script di installazione CookieYes

1. Accedi a https://app.cookieyes.com
2. Dashboard → **Advanced Settings** → **Get Installation Code**
3. Copia lo script mostrato (quello simile a):
   ```html
   <script id="cky-cookie-policy" type="text/javascript"
           src="https://cdn-cookieyes.com/client_data/TUO-ID/cookie-policy/script.js"></script>
   ```
4. Incollalo nel campo **"Script privacy / cookie"** in **Area Admin → Privacy / Cookie** del
   tuo pannello CHI FA COSA, e salva

Da questo momento lo script CookieYes viene caricato automaticamente su tutte le pagine
pubbliche del sito (homepage, pagine artista, blog, brani, eventi, contatti).

## 2. Verifica che il banner compaia

1. Apri una pagina pubblica in incognito (es. `https://www.tuodominio.it/tuoslug`)
2. Dovresti vedere comparire il banner cookie di CookieYes
3. Su CookieYes Dashboard, dovresti vedere lo stato passare a "Banner active" (premi il
   pulsante "Verify" se necessario)

## 3. Verifica il pulsante "Preferenze Cookie"

1. Accetta/chiudi il banner
2. Scorri in fondo alla pagina pubblica
3. Clicca **"Preferenze Cookie"**
4. Deve riaprirsi il pannello delle preferenze — funziona già automaticamente grazie alla
   classe `cky-banner-element` già presente nel codice

## Nota sul Revisit Consent Button di CookieYes

CookieYes offre anche un proprio "floating button" fluttuante integrato (l'iconcina cookie in
basso a sinistra che a volte compare di default). Puoi disattivarlo dal pannello CookieYes
(Cookie Banner → Content & Colors → Revisit Consent Button → disattiva il floating button) se
preferisci usare solo il link "Preferenze Cookie" nel footer del sito, per evitare doppioni.

## Se le personalizzazioni non si vedono

Le modifiche fatte sul pannello CookieYes potrebbero non comparire subito per via della cache
del browser — prova un refresh forzato (Ctrl+Shift+R su Windows).
