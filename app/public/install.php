<?php
/**
 * CHIFACOSA Installation Wizard
 * Guida l'utente attraverso il primo setup del sito
 */

require_once __DIR__ . '/../src/db.php';

// Controlla se l'installazione è già stata completata
try {
    $pdo = getDB();
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
    // Database non inizializzato - mostra wizard
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
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
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
        
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .header h1 {
            font-size: 32px;
            color: #1a1a1a;
            margin-bottom: 10px;
        }
        
        .header p {
            color: #666;
            font-size: 14px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #333;
            font-size: 14px;
        }
        
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
        
        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }
        
        button:active {
            transform: translateY(0);
        }
        
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
    </style>
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