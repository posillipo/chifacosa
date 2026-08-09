<?php
/**
 * CHIFACOSA Installation Wizard
 * Guida l'utente attraverso il primo setup del sito
 */

require_once __DIR__ . '/../src/db.php';

$installerCss = '
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: 8px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            width: 100%;
            max-width: 400px;
            padding: 40px;
        }
        .header { text-align: center; margin-bottom: 30px; }
        .header h1 { font-size: 32px; color: #1a1a1a; margin-bottom: 10px; }
        .header p { color: #666; font-size: 14px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: 500; color: #333; font-size: 14px; }
        input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            transition: border-color 0.2s;
        }
        input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        .error {
            background: #fee;
            color: #c33;
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 20px;
            font-size: 14px;
            border-left: 4px solid #c33;
        }
        button {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 4px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        button:hover { transform: translateY(-2px); box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3); }
        button:active { transform: translateY(0); }
        .info {
            background: #f0f4ff;
            border-left: 4px solid #667eea;
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 20px;
            font-size: 13px;
            color: #555;
            line-height: 1.6;
        }
';

// ============================================================
// Passo 0 — solo se manca del tutto una configurazione database (né variabili d'ambiente del
// container né un file scritto da un'installazione precedente): permette di inserire le
// credenziali dal browser invece di doverle compilare per forza come variabili d'ambiente
// prima ancora di avviare il container. Chi invece ha già DB_HOST/DB_NAME/DB_USER/DB_PASS
// impostati (installazioni Docker esistenti) non vede mai questo passaggio: getDbCredentials()
// trova subito le variabili d'ambiente e si passa oltre.
// ============================================================
if (getDbCredentials() === null) {
    $dbSetupError = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'db_setup') {
        $dbHost = trim($_POST['db_host'] ?? '');
        $dbName = trim($_POST['db_name'] ?? '');
        $dbUser = trim($_POST['db_user'] ?? '');
        $dbPass = $_POST['db_pass'] ?? '';

        if ($dbHost === '' || $dbName === '' || $dbUser === '') {
            $dbSetupError = 'Host, nome database e utente sono obbligatori.';
        } else {
            try {
                // Crea il database se non esiste già (richiede che l'utente fornito abbia il
                // privilegio CREATE lato server — se non ce l'ha, l'eccezione qui sotto spiega
                // il motivo, e resta comunque possibile creare il database a mano prima di
                // riprovare, come in precedenza).
                ensureDatabaseExists($dbHost, $dbName, $dbUser, $dbPass);

                $testPdo = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);
                $schemaImported = importSchemaIfEmpty($testPdo);

                if (!saveDbCredentials($dbHost, $dbName, $dbUser, $dbPass)) {
                    $dbSetupError = 'Connessione riuscita, ma non sono riuscito a salvare la configurazione su disco (controlla i permessi del volume di configurazione).';
                } elseif (!$schemaImported) {
                    header('Location: install.php?schema_import_failed=1');
                    exit;
                } else {
                    header('Location: install.php');
                    exit;
                }
            } catch (Exception $e) {
                $dbSetupError = 'Connessione al database fallita: ' . $e->getMessage();
            }
        }
    }
    ?>
    <!DOCTYPE html>
    <html lang="it">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>CHI FA COSA - Configurazione Database</title>
        <style><?= $installerCss ?></style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>CHI FA COSA</h1>
                <p>Configurazione Database</p>
            </div>

            <?php if ($dbSetupError): ?>
                <div class="error"><?= htmlspecialchars($dbSetupError) ?></div>
            <?php endif; ?>

            <div class="info">
                Prima di tutto, dove si trova il database? Se non sai cosa inserire, chiedi a chi
                ha preparato il server. Se il database indicato non esiste ancora, provo a crearlo
                da solo (serve un utente con i permessi adatti) — così come le tabelle al suo
                interno: non serve prepararle a mano in anticipo.
            </div>

            <form method="POST">
                <input type="hidden" name="step" value="db_setup">
                <div class="form-group">
                    <label for="db_host">Host del database</label>
                    <input type="text" id="db_host" name="db_host" placeholder="es. myband_db o localhost" value="<?= htmlspecialchars($_POST['db_host'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label for="db_name">Nome database</label>
                    <input type="text" id="db_name" name="db_name" placeholder="es. chifacosa" value="<?= htmlspecialchars($_POST['db_name'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label for="db_user">Utente database</label>
                    <input type="text" id="db_user" name="db_user" value="<?= htmlspecialchars($_POST['db_user'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label for="db_pass">Password database</label>
                    <input type="password" id="db_pass" name="db_pass">
                </div>
                <button type="submit">Continua →</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ============================================================
// Da qui in poi una configurazione database esiste già (variabili d'ambiente o file scritto al
// passo precedente) — logica invariata rispetto a prima.
// ============================================================

// Il DB irraggiungibile (credenziali sbagliate, host non risolvibile) è un problema diverso da
// "schema vuoto, prima installazione": nel primo caso mostrare comunque il wizard rischia di far
// credere che non ci sia nessun dato, e un eventuale nuovo invio del form andrebbe comunque in
// errore (o peggio, creerebbe un secondo admin) una volta che la connessione torna disponibile.
try {
    $pdo = getDB();
} catch (Exception $e) {
    die('
        <html>
        <head><title>Database non raggiungibile</title></head>
        <body style="font-family: sans-serif; text-align: center; padding: 50px; background: #f5f5f5;">
            <h1>⚠️ Database non raggiungibile</h1>
            <p style="font-size: 16px; color: #666; max-width: 480px; margin: 0 auto;">
                Controlla le variabili d\'ambiente del container (DB_HOST, DB_NAME, DB_USER, DB_PASS).
                Se hai appena ricreato lo stack, assicurati che corrispondano esattamente a quelle usate
                in precedenza — i dati non vengono toccati da questo errore, sono solo irraggiungibili.
            </p>
        </body>
        </html>
    ');
}

// Controlla se l'installazione è già stata completata
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM users");
    $user_count = $stmt->fetchColumn();

    if ($user_count > 0) {
        // Installazione già completata
        die('
            <html>
            <head><title>CHI FA COSA - Già Configurato</title></head>
            <body style="font-family: sans-serif; text-align: center; padding: 50px; background: #f5f5f5;">
                <h1>✓ CHI FA COSA</h1>
                <p style="font-size: 18px; color: #666;">La configurazione è già stata completata.</p>
                <p><a href="login.php" style="color: #0066cc; text-decoration: none;">Vai al Login →</a></p>
            </body>
            </html>
        ');
    }
} catch (Exception $e) {
    // Tabella users non ancora creata (schema non importato) - mostra wizard
}

// Processo il form di setup
$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $password_confirm = trim($_POST['password_confirm'] ?? '');
    $site_name = trim($_POST['site_name'] ?? 'CHI FA COSA');

    // Validazione
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Email non valida';
    } elseif (strlen($password) < 8) {
        $error = 'La password deve essere almeno 8 caratteri';
    } elseif ($password !== $password_confirm) {
        $error = 'Le password non corrispondono';
    } else {
        try {
            $pdo = getDB();

            // Crea il primo utente admin
            $password_hash = password_hash($password, PASSWORD_BCRYPT);
            $slug = strtolower(preg_replace('/[^a-z0-9-]/i', '-', $email));
            $slug = preg_replace('/-+/', '-', $slug);
            $slug = trim($slug, '-');

            // Se non sono stati creati utenti, il primo è admin
            $stmt = $pdo->prepare("
                INSERT INTO users (slug, email, password_hash, is_admin, is_active)
                VALUES (?, ?, ?, 1, 1)
            ");
            $stmt->execute([$slug, $email, $password_hash]);

            $user_id = $pdo->lastInsertId();

            // Crea il profilo dell'admin
            $stmt = $pdo->prepare("
                INSERT INTO profiles (user_id, display_name)
                VALUES (?, ?)
            ");
            $stmt->execute([$user_id, $site_name]);

            // Aggiorna il nome del sito nelle impostazioni
            $stmt = $pdo->prepare("
                INSERT INTO site_settings (setting_key, setting_value)
                VALUES ('site_name', ?)
                ON DUPLICATE KEY UPDATE setting_value = ?
            ");
            $stmt->execute([$site_name, $site_name]);

            $success = true;
        } catch (Exception $e) {
            $error = 'Errore durante la creazione dell\'admin: ' . $e->getMessage();
        }
    }
}

// Se l'installazione è andata a buon fine, reindirizza
if ($success) {
    header('Location: login.php?installed=1');
    exit;
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CHI FA COSA - Configurazione Iniziale</title>
    <style><?= $installerCss ?></style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>CHI FA COSA</h1>
            <p>Configurazione Iniziale</p>
        </div>

        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if (($_GET['schema_import_failed'] ?? '') === '1'): ?>
            <div class="error">
                Il database è raggiungibile, ma non sono riuscito a scaricare e creare
                automaticamente le tabelle (serve una connessione internet dal server verso
                GitHub). Importa manualmente <code>database/schema.sql</code> nel database che hai
                appena indicato, poi ricarica questa pagina.
            </div>
        <?php endif; ?>

        <div class="info">
            Questa è la prima configurazione del sito. Crea un account amministratore per iniziare.
        </div>

        <form method="POST">
            <div class="form-group">
                <label for="site_name">Nome del Sito</label>
                <input type="text" id="site_name" name="site_name" placeholder="es: Pizzeria La Caraffa" required>
            </div>

            <div class="form-group">
                <label for="email">Email Admin</label>
                <input type="email" id="email" name="email" placeholder="admin@example.com" required>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" placeholder="Minimo 8 caratteri" required>
            </div>

            <div class="form-group">
                <label for="password_confirm">Conferma Password</label>
                <input type="password" id="password_confirm" name="password_confirm" placeholder="Ripeti la password" required>
            </div>

            <button type="submit">Configura CHI FA COSA</button>
        </form>
    </div>
</body>
</html>
