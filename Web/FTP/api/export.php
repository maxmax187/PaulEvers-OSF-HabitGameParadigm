<?php
// ============================================================
// CONFIGURATION - change these before uploading
// ============================================================
require_once __DIR__ . '/env.php';
loadEnv();

session_start();

$error = '';

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if ($_POST['password'] === getenv('EXPORT_PASSWORD')) {
        $_SESSION['auth'] = true;
    } else {
        $error = 'Incorrect password.';
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: export.php');
    exit;
}

$authed = isset($_SESSION['auth']) && $_SESSION['auth'] === true;

// ============================================================
// Helper: open a DB connection
// ============================================================
function getDb() {
    $db = new mysqli(
        getenv('DB_HOST') ?: 'localhost',
        getenv('DB_USER'),
        getenv('DB_PASSWORD'),
        getenv('DB_NAME'),
        getenv('DB_PORT') ?: 3306
    );
    if ($db->connect_error) {
        die('Database connection failed: ' . $db->connect_error);
    }
    return $db;
}

// ============================================================
// Helper: fetch all table names from the current DB
// ============================================================
function getTableNames($db) {
    $tables = [];
    $result = $db->query('SHOW TABLES');
    while ($row = $result->fetch_row()) {
        $tables[] = $row[0];
    }
    return $tables;
}

// ============================================================
// Helper: write a query result as CSV to a file handle
// ============================================================
function writeResultAsCsv($result, $out) {
    $fields = $result->fetch_fields();
    fputcsv($out, array_column($fields, 'name'));
    while ($row = $result->fetch_assoc()) {
        fputcsv($out, $row);
    }
}



// ============================================================
// Handle export request
// ============================================================
if ($authed && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export'])) {
    $selectedTables = isset($_POST['tables']) ? $_POST['tables'] : [];

    // Validate: only allow table names that actually exist (prevent injection)
    $db = getDb();
    $validTables = getTableNames($db);
    $selectedTables = array_filter($selectedTables, fn($t) => in_array($t, $validTables, true));

    if (!empty($selectedTables)) {
        $timestamp = date('Ymd_His');

        // -- Single table: stream CSV directly ------------------------------
        if (count($selectedTables) === 1) {
            $table    = $selectedTables[0];
            $filename = $timestamp . '_' . $table . '.csv';
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="' . $filename . '"');

            $result = $db->query('SELECT * FROM `' . $db->real_escape_string($table) . '`');
            $out    = fopen('php://output', 'w');
            writeResultAsCsv($result, $out);
            fclose($out);
            $db->close();
            exit;
        }

        // -- Multiple tables: separate CSVs bundled in ZIP -----------------
        $zipName = $timestamp . '_export.zip';
        $zipPath = sys_get_temp_dir() . '/' . $zipName;

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
            die('Could not create zip file.');
        }

        foreach ($selectedTables as $table) {
            $result      = $db->query('SELECT * FROM `' . $db->real_escape_string($table) . '`');
            $csvFilename = $timestamp . '_' . $table . '.csv';
            $tmpFile     = tempnam(sys_get_temp_dir(), 'csv_');
            $out         = fopen($tmpFile, 'w');
            writeResultAsCsv($result, $out);
            fclose($out);
            $zip->addFile($tmpFile, $csvFilename);
        }

        $zip->close();
        $db->close();

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zipName . '"');
        header('Content-Length: ' . filesize($zipPath));
        readfile($zipPath);
        unlink($zipPath);
        exit;
    }

    $db->close();
}

// ============================================================
// Fetch table list for the UI (only when authenticated)
// ============================================================
$availableTables = [];
if ($authed) {
    $db = getDb();
    $availableTables = getTableNames($db);
    $db->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Export</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Courier New', monospace;
            background: #0f0f0f;
            color: #e0e0e0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .card {
            background: #1a1a1a;
            border: 1px solid #2a2a2a;
            padding: 2.5rem;
            width: 100%;
            max-width: 480px;
        }

        h1 {
            font-size: 0.75rem;
            letter-spacing: 0.2em;
            text-transform: uppercase;
            color: #666;
            margin-bottom: 2rem;
        }

        h1 span { color: #00ff88; }

        label {
            display: block;
            font-size: 0.7rem;
            letter-spacing: 0.15em;
            text-transform: uppercase;
            color: #666;
            margin-bottom: 0.5rem;
        }

        input[type="password"] {
            width: 100%;
            background: #0f0f0f;
            border: 1px solid #333;
            color: #e0e0e0;
            padding: 0.75rem 1rem;
            font-family: inherit;
            font-size: 0.9rem;
            outline: none;
            margin-bottom: 1rem;
        }

        input[type="password"]:focus { border-color: #00ff88; }

        .error {
            font-size: 0.75rem;
            color: #ff4444;
            margin-bottom: 1rem;
            letter-spacing: 0.05em;
        }

        /* -- Table selection grid -- */
        .section-label {
            font-size: 0.7rem;
            letter-spacing: 0.15em;
            text-transform: uppercase;
            color: #666;
            margin-bottom: 0.75rem;
        }

        .checkbox-group {
            display: flex;
            flex-direction: column;
            gap: 0.6rem;
            margin-bottom: 1.5rem;
            max-height: 260px;
            overflow-y: auto;
            padding-right: 0.25rem;
        }

        .checkbox-group::-webkit-scrollbar { width: 4px; }
        .checkbox-group::-webkit-scrollbar-track { background: #0f0f0f; }
        .checkbox-group::-webkit-scrollbar-thumb { background: #333; }

        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            cursor: pointer;
        }

        .checkbox-item input[type="checkbox"] {
            appearance: none;
            width: 16px;
            height: 16px;
            border: 1px solid #444;
            background: #0f0f0f;
            cursor: pointer;
            flex-shrink: 0;
            position: relative;
        }

        .checkbox-item input[type="checkbox"]:checked {
            background: #00ff88;
            border-color: #00ff88;
        }

        .checkbox-item input[type="checkbox"]:checked::after {
            content: '';
            position: absolute;
            left: 4px;
            top: 1px;
            width: 5px;
            height: 9px;
            border: 2px solid #0f0f0f;
            border-top: none;
            border-left: none;
            transform: rotate(45deg);
        }

        .checkbox-item span { font-size: 0.85rem; color: #ccc; }

        /* -- Select all / none -- */
        .selection-controls {
            display: flex;
            gap: 1rem;
            margin-bottom: 0.75rem;
        }

        .selection-controls a {
            font-size: 0.65rem;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: #555;
            text-decoration: none;
            cursor: pointer;
        }

        .selection-controls a:hover { color: #00ff88; }

        button[type="submit"] {
            width: 100%;
            background: #00ff88;
            color: #0f0f0f;
            border: none;
            padding: 0.85rem;
            font-family: inherit;
            font-size: 0.75rem;
            letter-spacing: 0.2em;
            text-transform: uppercase;
            cursor: pointer;
            font-weight: bold;
        }

        button[type="submit"]:hover { background: #00cc6a; }

        .logout {
            font-size: 0.65rem;
            color: #444;
            text-decoration: none;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            display: block;
            text-align: right;
            margin-top: 1.5rem;
        }

        .logout:hover { color: #888; }

        .empty-note {
            font-size: 0.75rem;
            color: #555;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
<div class="card">

<?php if (!$authed): ?>

    <h1>Maze Study — <span>Export</span></h1>
    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="POST">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" autofocus>
        <button type="submit">Authenticate</button>
    </form>

<?php else: ?>

    <h1>Maze Study — <span>Export</span></h1>

    <form method="POST" id="exportForm">
        <input type="hidden" name="export" value="1">

        <div class="section-label">Tables</div>

        <?php if (empty($availableTables)): ?>
            <p class="empty-note">No tables found in the database.</p>
        <?php else: ?>
            <div class="selection-controls">
                <a onclick="setAll(true)">Select all</a>
                <a onclick="setAll(false)">Select none</a>
            </div>
            <div class="checkbox-group" id="tableList">
                <?php foreach ($availableTables as $table): ?>
                <label class="checkbox-item">
                    <input type="checkbox" name="tables[]"
                           value="<?= htmlspecialchars($table) ?>">
                    <span><?= htmlspecialchars($table) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <button type="submit">Download</button>
    </form>

    <a href="?logout" class="logout">Log out</a>

    <script>
        function setAll(checked) {
            document.querySelectorAll('#tableList input[type="checkbox"]')
                .forEach(cb => cb.checked = checked);
        }
    </script>

<?php endif; ?>

</div>
</body>
</html>
```