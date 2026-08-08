# GitHub — push da Windows

Repository: `https://github.com/posillipo/chifacosa.git`

## Comandi standard (repo già collegato)

Nella cartella del progetto, PowerShell:
```powershell
git add -A
git commit -m "Descrizione della modifica"
git push
```

## Se ricevi "fatal: No configured push destination"

Capita quando estrai un nuovo zip sopra la cartella esistente (la cartella `.git` che ricevi
non ha il tuo remote configurato). Ricollega e forza il push (sicuro, è un progetto solo tuo):
```powershell
git remote add origin https://github.com/posillipo/chifacosa.git
git push -u origin main --force
```

## Se ricevi "git non riconosciuto"

Git non è installato su questo PC/profilo Windows. Installa con winget:
```powershell
winget install --id Git.Git -e --source winget
```
Poi **chiudi e riapri PowerShell** (serve per aggiornare il PATH) e verifica:
```powershell
git --version
```
Se winget non è disponibile, scarica l'installer da https://git-scm.com/download/win
(opzioni di default vanno bene).

## Autenticazione

Al primo push, Windows apre il browser per il login GitHub (Git Credential Manager, incluso in
Git per Windows). Le volte successive non richiede più credenziali.

Se preferisci un Personal Access Token invece del login via browser:
1. https://github.com/settings/tokens → "Generate new token (classic)"
2. Scope: `repo`
3. Incollalo come password quando richiesto

## Alternativa senza installare Git: modificare da browser

**Un file singolo**: vai su github.com/posillipo/chifacosa → apri il file → matita ✏️ "Edit this
file" → modifichi → "Commit changes" in fondo.

**Più file insieme**: estrai lo zip (solo estrazione, non serve Git) → su GitHub, nella cartella
giusta, "Add file" → "Upload files" → trascina i file estratti → conferma sovrascrittura →
"Commit changes". Scomodo per aggiornamenti che toccano molte cartelle contemporaneamente.

## Rendere il repository pubblico/privato

Settings del repo (non le impostazioni account) → in fondo, **Danger Zone** → **Change
repository visibility**. Va bene tenerlo pubblico: `.env` è escluso da Git (`.gitignore`), i
dati utenti restano solo nel database del server.

## Dopo ogni push

Portainer → Stacks → chifacosa → **Pull and redeploy**.
