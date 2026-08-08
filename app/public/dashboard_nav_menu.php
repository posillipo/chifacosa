<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
requireLogin();

$user = currentUser();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    
    $items = getAllProfileNavigationMenu($user['id']);
    foreach ($items as $item) {
        $isVisible = isset($_POST['visibility'][$item['id']]) ? 1 : 0;
        getDB()->prepare('UPDATE profile_navigation_menu SET is_visible = ? WHERE id = ? AND user_id = ?')
            ->execute([$isVisible, $item['id'], $user['id']]);
    }
    
    $message = 'Menu aggiornato!';
}

$items = getAllProfileNavigationMenu($user['id']);
$visibleItems = getVisibleProfileNavigation($user['id']);
$activeTab = 'nav_menu';
?><!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Menu di Navigazione — Dashboard</title>
<link rel="stylesheet" href="<?= assetUrl('/assets/css/style.css') ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
<style>
.nav-menu-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 15px;
    margin-top: 20px;
}

.nav-menu-card {
    background: white;
    border: 1px solid #eee;
    border-radius: 8px;
    padding: 15px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.nav-menu-card-content {
    display: flex;
    align-items: center;
    gap: 12px;
}

.nav-menu-icon {
    width: 40px;
    height: 40px;
    background: #f5f5f5;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    color: #6c5ce7;
}

.nav-menu-info h4 {
    margin: 0;
    font-size: 15px;
    color: #1a1a1a;
}

.nav-menu-info p {
    margin: 4px 0 0;
    font-size: 12px;
    color: #999;
}

.checkbox-toggle {
    position: relative;
    width: 50px;
    height: 26px;
    background: #ddd;
    border-radius: 13px;
    cursor: pointer;
    border: none;
    padding: 0;
    transition: background 0.3s;
}

.checkbox-toggle.active {
    background: #6c5ce7;
}

.checkbox-toggle::before {
    content: '';
    position: absolute;
    width: 22px;
    height: 22px;
    background: white;
    border-radius: 50%;
    top: 2px;
    left: 2px;
    transition: left 0.3s;
}

.checkbox-toggle.active::before {
    left: 26px;
}

.preview-menu {
    background: #f9f9f9;
    border: 1px solid #eee;
    border-radius: 8px;
    padding: 20px;
    margin-top: 30px;
}

.preview-menu h4 {
    margin: 0 0 15px;
    font-size: 15px;
    color: #333;
}

.preview-menu nav {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.preview-menu a {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    background: #6c5ce7;
    color: white;
    text-decoration: none;
    border-radius: 20px;
    font-weight: 500;
    font-size: 13px;
}

.preview-menu a:hover {
    background: #5a4fb8;
}

.preview-empty {
    color: #999;
    text-align: center;
    padding: 20px;
}
</style>
</head>
<body>
<?php require __DIR__ . '/_dash_header.php'; ?>

<div class="dashboard-content">
    <h2>Menu di Navigazione</h2>
    
    <?php if (!empty($message)): ?>
        <div class="card" style="background: #e8f5e9; border-left: 4px solid #4caf50; color: #2e7d32;">
            <?= e($message) ?>
        </div>
    <?php endif; ?>
    
    <p style="color: #666; margin-bottom: 20px;">Seleziona quali voci mostrare nel menu pubblico del tuo profilo.</p>
    
    <form method="POST">
        <?= csrfField() ?>
        
        <div class="nav-menu-grid">
            <?php foreach ($items as $item): ?>
                <div class="nav-menu-card">
                    <div class="nav-menu-card-content">
                        <div class="nav-menu-icon">
                            <i class="<?= e($item['icon']) ?>"></i>
                        </div>
                        <div class="nav-menu-info">
                            <h4><?= e($item['name']) ?></h4>
                            <p><?= e($item['url']) ?></p>
                        </div>
                    </div>
                    <label class="checkbox-toggle <?= $item['is_visible'] ? 'active' : '' ?>">
                        <input type="checkbox" name="visibility[<?= $item['id'] ?>]" value="1" 
                               <?= $item['is_visible'] ? 'checked' : '' ?> 
                               onchange="this.form.submit()" style="display: none;">
                    </label>
                </div>
            <?php endforeach; ?>
        </div>
    </form>
    
    <!-- Anteprima -->
    <div class="preview-menu">
        <h4>Anteprima Menu Pubblico</h4>
        <?php if (!empty($visibleItems)): ?>
            <nav>
                <?php foreach ($visibleItems as $item): ?>
                    <a href="<?= e($item['url']) ?>">
                        <i class="<?= e($item['icon']) ?>"></i>
                        <?= e($item['name']) ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        <?php else: ?>
            <div class="preview-empty">Nessuna voce di menu visibile</div>
        <?php endif; ?>
    </div>
</div>

<script>
document.querySelectorAll('.checkbox-toggle').forEach(btn => {
    const checkbox = btn.querySelector('input[type="checkbox"]');
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        checkbox.checked = !checkbox.checked;
        btn.classList.toggle('active');
        checkbox.form.submit();
    });
});
</script>
</body>
</html>
