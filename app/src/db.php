<?php
// Connessione PDO al database MySQL (parametri letti dalle variabili d'ambiente del container)
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $host = getenv('DB_HOST') ?: 'chifacosa_db';
        $name = getenv('DB_NAME') ?: 'chifacosa';
        $user = getenv('DB_USER') ?: 'chifacosa_user';
        $pass = getenv('DB_PASS') ?: '';
        $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
        ]);
    }
    return $pdo;
}
