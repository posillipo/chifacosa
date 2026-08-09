<?php
// Connessione PDO al database MySQL. Le credenziali arrivano da due posti possibili, in ordine
// di priorità:
//   1. Variabili d'ambiente del container (DB_HOST/DB_NAME/DB_USER/DB_PASS) — come sempre, per
//      non cambiare nulla nelle installazioni Docker già configurate così.
//   2. Un file scritto dal wizard di installazione (vedi install.php), su un volume Docker
//      dedicato fuori dalla webroot — per chi preferisce configurare tutto dal browser al primo
//      avvio, senza dover compilare variabili d'ambiente prima ancora di partire.
// Se non c'è né l'uno né l'altro, il sito non è "rotto": è semplicemente una nuova
// installazione che deve ancora passare dal wizard.

// File separato dalla classe (non extends Exception con nome descrittivo) apposta per non
// essere confuso con PDOException, che estende RuntimeException — un catch generico su
// RuntimeException prenderebbe anche gli errori di connessione veri, mescolando "non ancora
// configurato" con "configurato ma irraggiungibile".
class DbNotConfiguredException extends Exception {
}

function dbConfigPath(): string {
    return '/var/www/config/db.php';
}

function getDbCredentials(): ?array {
    $host = getenv('DB_HOST');
    if ($host !== false && $host !== '') {
        return [
            'host' => $host,
            'name' => getenv('DB_NAME') ?: 'chifacosa',
            'user' => getenv('DB_USER') ?: 'chifacosa_user',
            'pass' => getenv('DB_PASS') ?: '',
        ];
    }

    $path = dbConfigPath();
    if (is_file($path)) {
        $cfg = require $path;
        if (is_array($cfg) && !empty($cfg['host']) && !empty($cfg['name']) && !empty($cfg['user'])) {
            return $cfg;
        }
    }

    return null;
}

// Scrive le credenziali sul volume dedicato. Va chiamata solo dopo aver già verificato che la
// connessione funziona (vedi install.php) — questo file non convalida nulla da solo.
function saveDbCredentials(string $host, string $name, string $user, string $pass): bool {
    $path = dbConfigPath();
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0770, true)) {
        return false;
    }
    $php = "<?php\n// Generato da install.php — credenziali del database per questa installazione.\nreturn "
        . var_export(['host' => $host, 'name' => $name, 'user' => $user, 'pass' => $pass], true)
        . ";\n";
    return file_put_contents($path, $php) !== false;
}

// Crea il database se non esiste ancora, quando l'utente fornito ha i permessi per farlo — usata
// solo dal wizard di installazione (install.php), per non dover richiedere all'utente di creare
// a mano il database vuoto prima ancora di iniziare. Se le credenziali sono sbagliate o il server
// non è raggiungibile, l'eccezione risale invariata (non è questo il compito di questa funzione).
function ensureDatabaseExists(string $host, string $name, string $user, string $pass): void {
    try {
        new PDO("mysql:host={$host};dbname={$name};charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        return; // il database esiste già ed è raggiungibile
    } catch (PDOException $e) {
        $isUnknownDatabase = ($e->errorInfo[1] ?? null) === 1049
            || stripos($e->getMessage(), 'Unknown database') !== false;
        if (!$isUnknownDatabase) {
            throw $e; // credenziali sbagliate, host irraggiungibile, ecc. — non nascondere
        }
    }

    // Il database non esiste: prova a crearlo connettendosi al server senza specificarne uno.
    // Richiede che l'utente fornito abbia il privilegio CREATE a livello di server, non solo sul
    // singolo database — se non ce l'ha, l'eccezione qui sotto spiega il problema a install.php.
    $server = new PDO("mysql:host={$host};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $safeName = str_replace('`', '', $name);
    $server->exec("CREATE DATABASE IF NOT EXISTS `{$safeName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
}

// Scarica lo schema ufficiale dal repository e crea le tabelle mancanti, se il database è vuoto
// (nessuna tabella "users"). Usata solo dal wizard — se il database ha già le tabelle non tocca
// nulla. Restituisce false (senza sollevare eccezioni) se non riesce a scaricare lo schema, così
// chi chiama può ripiegare sull'importazione manuale di database/schema.sql, come sempre
// possibile.
function importSchemaIfEmpty(PDO $pdo): bool {
    try {
        $pdo->query('SELECT 1 FROM users LIMIT 1');
        return true; // le tabelle ci sono già, niente da fare
    } catch (PDOException $e) {
        // Tabella "users" non esiste: procede con l'importazione qui sotto.
    }

    $ctx = stream_context_create(['http' => ['timeout' => 10]]);
    $sql = @file_get_contents(
        'https://raw.githubusercontent.com/posillipo/chifacosa/main/database/schema.sql',
        false,
        $ctx
    );
    if ($sql === false || trim($sql) === '') {
        return false;
    }

    // Le prime righe (CREATE DATABASE / USE) non servono: siamo già connessi al database giusto,
    // che può avere un nome diverso da quello di default usato nel repository.
    $sql = preg_replace('/^CREATE DATABASE.*?;\s*USE\s+\S+;\s*/is', '', $sql, 1);

    // Rimuove le righe di commento (--) prima di dividere per ";": alcuni commenti nel file
    // contengono punti e virgola nel testo (es. "fase 1: sempre agganciate a un evento; ..."),
    // che altrimenti spezzerebbero a metà lo statement successivo.
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);

    foreach (explode(';', $sql) as $statement) {
        $statement = trim($statement);
        if ($statement === '') {
            continue;
        }
        $pdo->exec($statement);
    }
    return true;
}

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $cfg = getDbCredentials();
        if ($cfg === null) {
            throw new DbNotConfiguredException('Nessuna configurazione database trovata');
        }
        $dsn = "mysql:host={$cfg['host']};dbname={$cfg['name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
        ]);
    }
    return $pdo;
}
