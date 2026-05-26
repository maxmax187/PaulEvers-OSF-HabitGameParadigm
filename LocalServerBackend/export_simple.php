<?php
// ============================================================
// CONFIGURATION - change these before uploading
// ============================================================
require_once __DIR__ . '/env.php';
loadEnv();

// Remove the DB_HOST/USER/PASSWORD/NAME defines and use getenv() instead:
// DB_HOST -> getenv('DB_HOST')  etc. -- already done in index.php pattern
// ============================================================

session_start();

$exportPassword = getenv('EXPORT_PASSWORD') ?: 'NOT_CONFIGURED';
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

// Handle export request
if ($authed && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export'])) {
    $exports = isset($_POST['exports']) ? $_POST['exports'] : [];

    if (!empty($exports)) {
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

        $queries = [
            'rounds' => [
                'query' => 'SELECT * FROM rounds ORDER BY participantEmail, day, round',
                'filename' => 'rounds'
            ],
            'round_logs' => [
                'query' => '
                    SELECT r.participantEmail, r.day, r.round, r.phase, rl.t, rl.d
                    FROM roundLogs rl
                    JOIN rounds r ON r.id = rl.roundId
                    ORDER BY r.participantEmail, r.day, r.round, rl.t
                ',
                'filename' => 'round_logs'
            ],
            'habit_survey' => [
                'query' => '
                    SELECT participantEmail, day, srbai1, srbai2, srbai3, srbai4
                    FROM habitSurvey
                    ORDER BY participantEmail, day
                ',
                'filename' => 'habit_survey'
            ],
            'participants' => [
                'query' => 'SELECT * FROM participants ORDER BY email',
                'filename' => 'participants'
            ],
        ];

        // If only one export selected, stream it directly as a download
        if (count($exports) === 1) {
            $key = $exports[0];
            if (isset($queries[$key])) {
                $timestamp = date('Ymd_His');
                $filename  = $timestamp . '_' . $queries[$key]['filename'] . '.csv';
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="' . $filename . '"');

                $result = $db->query($queries[$key]['query']);
                $out = fopen('php://output', 'w');

                // Header row
                $fields = $result->fetch_fields();
                fputcsv($out, array_column($fields, 'name'));

                // Data rows
                while ($row = $result->fetch_assoc()) {
                    fputcsv($out, $row);
                }
                fclose($out);
                $db->close();
                exit;
            }
        }

        // Multiple exports — bundle into a zip
        $timestamp = date('Ymd_His');
        $zipName   = $timestamp . '_export.zip';
        $zipPath   = sys_get_temp_dir() . '/' . $zipName;

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
            die('Could not create zip file.');
        }

        foreach ($exports as $key) {
            if (!isset($queries[$key])) continue;

            $result = $db->query($queries[$key]['query']);
            $csvFilename = $timestamp . '_' . $queries[$key]['filename'] . '.csv';

            // Write CSV to a temp file
            $tmpFile = tempnam(sys_get_temp_dir(), 'csv_');
            $out = fopen($tmpFile, 'w');

            $fields = $result->fetch_fields();
            fputcsv($out, array_column($fields, 'name'));
            while ($row = $result->fetch_assoc()) {
                fputcsv($out, $row);
            }
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
            max-width: 420px;
        }

        h1 {
            font-size: 0.75rem;
            letter-spacing: 0.2em;
            text-transform: uppercase;
            color: #666;
            margin-bottom: 2rem;
        }

        h1 span {
            color: #00ff88;
        }

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

        input[type="password"]:focus {
            border-color: #00ff88;
        }

        .error {
            font-size: 0.75rem;
            color: #ff4444;
            margin-bottom: 1rem;
            letter-spacing: 0.05em;
        }

        .checkbox-group {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }

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

        .checkbox-item span {
            font-size: 0.85rem;
            color: #ccc;
        }

        .checkbox-item .desc {
            font-size: 0.7rem;
            color: #555;
            margin-left: auto;
        }

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

        button[type="submit"]:hover {
            background: #00cc6a;
        }

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

        .note {
            font-size: 0.68rem;
            color: #444;
            margin-top: 1rem;
            line-height: 1.5;
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
    <form method="POST">
        <input type="hidden" name="export" value="1">
        <label>Select datasets to export</label>
        <div class="checkbox-group">
            <label class="checkbox-item">
                <input type="checkbox" name="exports[]" value="participants" checked>
                <span>Participants</span>
                <span class="desc">participants</span>
            </label>
            <label class="checkbox-item">
                <input type="checkbox" name="exports[]" value="rounds" checked>
                <span>Rounds</span>
                <span class="desc">rounds</span>
            </label>
            <label class="checkbox-item">
                <input type="checkbox" name="exports[]" value="round_logs">
                <span>Round Logs</span>
                <span class="desc">roundLogs</span>
            </label>
            <label class="checkbox-item">
                <input type="checkbox" name="exports[]" value="habit_survey">
                <span>Habit Survey</span>
                <span class="desc">habitSurvey</span>
            </label>
        </div>
        <button type="submit">Download CSVs</button>
    </form>
    <p class="note">Single selection downloads a .csv directly. Multiple selections download a .zip containing all selected files. Filenames are prefixed with the current timestamp.</p>
    <a href="?logout" class="logout">Log out</a>

<?php endif; ?>

</div>
</body>
</html>
```