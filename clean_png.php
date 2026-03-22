<?php
require_once 'config.php';
require_once 'auth.php';
checkAuth();

// Verzeichnis mit den Favicons
$faviconsDir = __DIR__ . '/favicons/';

// Alle Dateien im Favicons-Ordner holen (alle Typen)
$files = file_exists($faviconsDir) ? glob($faviconsDir . '*') : [];
$orphanedFiles = [];

// Alle favicon_urls aus der Datenbank holen
$stmt = $pdo->query("SELECT DISTINCT favicon_url FROM favorites WHERE favicon_url IS NOT NULL AND favicon_url != ''");
$dbFavicons = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Normalisieren: führendes '/' entfernen → immer 'favicons/...'
$normalizedDb = array_flip(
    array_map(fn($f) => ltrim((string)$f, '/'), $dbFavicons)
);

// Prüfen, welche Dateien nicht in der DB referenziert sind
foreach ($files as $filePath) {
    $relativePath = 'favicons/' . basename($filePath);
    if (!isset($normalizedDb[$relativePath])) {
        $orphanedFiles[] = $filePath;
    }
}

// Löschen der Dateien, wenn der Button gedrückt wurde
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete'])) {
    foreach ($orphanedFiles as $file) {
        if (file_exists($file)) {
            unlink($file);
        }
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="author" content="yogi7777">
    <title>Non-referenced favicons</title>
    <link href="assets/src/bootstrap.min.css" rel="stylesheet">
    <link href="assets/style.css?v1.4" rel="stylesheet">
</head>
<body data-bs-theme="dark">
    <div class="container-fluid p-0 m-0">
        <div class="row mx-auto col-md-12"><br /></div>
        <div class="row mx-auto col-md-12">
            <h2>Non-referenced favicons</h2>
            
            <?php if (empty($orphanedFiles)): ?>
                <div class="alert alert-success" role="alert">
                    No unreferenced favicons found.
                </div>
            <?php else: ?>
                <p>The following favicons are referenced in the <code>favicons/</code> folder, but not in the database:</p>
                
                <div class="row">
                    <?php foreach ($orphanedFiles as $file): ?>
                        <div class="col-auto mb-3">
                            <img src="/<?php echo htmlspecialchars(str_replace($_SERVER['DOCUMENT_ROOT'], '', $file)); ?>" 
                                alt="Favicon" 
                                width="32" 
                                height="32" 
                                class="border">
                            <small class="d-block text-muted"><?php echo htmlspecialchars(basename($file)); ?></small>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <form method="post" onsubmit="return confirm('Do you want to delete all the favicons listed?');">
                    <button type="submit" name="delete" class="btn btn-danger">Delete All</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

        <?php include 'navigation.php'; ?>

    <script src="assets/src/bootstrap.bundle.min.js"></script>
    <script src="assets/script.js?v1.5"></script>
</body>
</html>