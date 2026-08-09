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
