<?php
// Never let PHP print raw errors — they corrupt the page output
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php-errors.log');
error_reporting(E_ALL);

// ============================================================
// CONFIGURATION
// ============================================================
require_once __DIR__ . '/env.php';
loadEnv();
// ============================================================

session_start();

$error   = '';
$message = '';
$results = null;
$columns = [];
$sqlFileContent = null; // holds file contents when "View" is clicked

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if ($_POST['password'] === getenv('ADMIN_PASSWORD')) {
        $_SESSION['admin_auth'] = true;
    } else {
        $error = 'Incorrect password.';
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

$authed = isset($_SESSION['admin_auth']) && $_SESSION['admin_auth'] === true;

// Preset queries
$presets = [
    'show_tables'        => 'SHOW TABLES',
    'all_participants'   => 'SELECT * FROM participants ORDER BY email',
    'all_rounds'         => 'SELECT * FROM rounds ORDER BY participantEmail, day, round',
    'all_logs'           => 'SELECT r.participantEmail, r.day, r.round, rl.t, rl.d FROM roundLogs rl JOIN rounds r ON r.id = rl.roundId ORDER BY r.participantEmail, r.day, r.round, rl.t LIMIT 500',
    'all_surveys'        => 'SELECT * FROM habitSurvey ORDER BY participantEmail, day',
    'clear_participants' => 'TRUNCATE TABLE participants',
    'clear_rounds'       => 'SET FOREIGN_KEY_CHECKS=0; TRUNCATE TABLE rounds; SET FOREIGN_KEY_CHECKS=1',
    'clear_surveys'      => 'TRUNCATE TABLE habitSurvey',
    'clear_logs'         => 'TRUNCATE TABLE roundLogs',
    'clear_all'          => 'SET FOREIGN_KEY_CHECKS=0; TRUNCATE TABLE roundLogs; TRUNCATE TABLE habitSurvey; TRUNCATE TABLE rounds; TRUNCATE TABLE participants; SET FOREIGN_KEY_CHECKS=1',
];

// Find all .sql files in the sql subdirectory
function getSqlFiles() {
    $files = glob(__DIR__ . '/sql/*.sql');
    if (!$files) return [];
    return array_map('basename', $files);
}

// Execute a SQL string that may contain multiple statements
function runSql($db, $sql) {
    try {
        $statements = array_filter(array_map('trim', explode(';', $sql)));

        if (empty($statements)) {
            return [false, '', 'No valid SQL statements found.', null, []];
        }

        $lastRows    = null;
        $lastColumns = [];

        foreach ($statements as $stmt) {
            if (empty($stmt)) continue;

            $result = @$db->query($stmt);

            if ($result === false) {
                return [false, '', 'MySQL error: ' . $db->error, null, []];
            }

            if ($result !== true) {
                $fields      = $result->fetch_fields();
                $lastColumns = $fields ? array_column($fields, 'name') : [];
                $lastRows    = $result->fetch_all(MYSQLI_ASSOC);
                $result->free();
            }
        }

        if ($lastRows !== null) {
            return [true, count($lastRows) . ' row(s) returned.', '', $lastRows, $lastColumns];
        }
        return [true, 'Executed successfully. Rows affected: ' . $db->affected_rows, '', null, []];

    } catch (Throwable $e) {
        return [false, '', 'Unexpected error: ' . $e->getMessage(), null, []];
    }
}

// Handle all POST actions
if ($authed && $_SERVER['REQUEST_METHOD'] === 'POST') {

    // -- View SQL file (no DB needed) ------------------------------------
    if (isset($_POST['view_sql_file']) && isset($_POST['sql_file'])) {
        $requestedFile = basename($_POST['sql_file']);
        $filePath      = __DIR__ . '/sql/' . $requestedFile;

        if (!preg_match('/\.sql$/', $requestedFile) || !file_exists($filePath)) {
            $error = 'Invalid or missing SQL file: ' . htmlspecialchars($requestedFile);
        } else {
            $sqlFileContent = file_get_contents($filePath);
            $message        = 'Viewing: ' . htmlspecialchars($requestedFile);
        }

    } elseif (isset($_POST['run_query']) || isset($_POST['preset']) || isset($_POST['run_sql_file'])) {

        $db = new mysqli(
            getenv('DB_HOST') ?: 'localhost',
            getenv('DB_USER'),
            getenv('DB_PASSWORD'),
            getenv('DB_NAME'),
            getenv('DB_PORT') ?: 3306
        );

        if ($db->connect_error) {
            $error = 'DB connection failed: ' . $db->connect_error;
        } else {
            $sql            = '';
            $message_prefix = '';

            if (isset($_POST['preset']) && isset($presets[$_POST['preset']])) {
                $sql = $presets[$_POST['preset']];
            }

            if (isset($_POST['run_query']) && isset($_POST['query'])) {
                $sql = trim($_POST['query']);
            }

            if (isset($_POST['run_sql_file']) && isset($_POST['sql_file'])) {
                $requestedFile = basename($_POST['sql_file']);
                $filePath      = __DIR__ . '/sql/' . $requestedFile;

                if (!preg_match('/\.sql$/', $requestedFile) || !file_exists($filePath)) {
                    $error = 'Invalid or missing SQL file: ' . htmlspecialchars($requestedFile);
                } else {
                    $sql            = file_get_contents($filePath);
                    $message_prefix = 'File: ' . htmlspecialchars($requestedFile) . ' — ';
                }
            }

            if ($sql && !$error) {
                [$ok, $msg, $err, $rows, $cols] = runSql($db, $sql);
                if ($ok) {
                    $message = $message_prefix . $msg;
                    $results = $rows;
                    $columns = $cols;
                } else {
                    $error = $err;
                }
            }

            $db->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DB Admin</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Courier New', monospace;
            background: #0f0f0f;
            color: #e0e0e0;
            min-height: 100vh;
            padding: 2rem;
        }

        .login-wrap {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 2rem;
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

        h1 span { color: #00ff88; }

        h2 {
            font-size: 0.7rem;
            letter-spacing: 0.15em;
            text-transform: uppercase;
            color: #555;
            margin-bottom: 0.75rem;
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

        input[type="password"]:focus { border-color: #00ff88; }

        textarea {
            width: 100%;
            background: #0f0f0f;
            border: 1px solid #333;
            color: #e0e0e0;
            padding: 0.75rem 1rem;
            font-family: inherit;
            font-size: 0.85rem;
            outline: none;
            resize: vertical;
            min-height: 100px;
            margin-bottom: 0.75rem;
        }

        textarea:focus { border-color: #00ff88; }

        .btn {
            background: #00ff88;
            color: #0f0f0f;
            border: none;
            padding: 0.65rem 1.25rem;
            font-family: inherit;
            font-size: 0.7rem;
            letter-spacing: 0.15em;
            text-transform: uppercase;
            cursor: pointer;
            font-weight: bold;
        }

        .btn:hover { background: #00cc6a; }

        .error   { font-size: 0.8rem; color: #ff4444; padding: 0.75rem; background: #1a0000; border: 1px solid #440000; margin-bottom: 1rem; }
        .success { font-size: 0.8rem; color: #00ff88; padding: 0.75rem; background: #001a0d; border: 1px solid #004422; margin-bottom: 1rem; }

        .layout {
            display: grid;
            grid-template-columns: 260px 1fr;
            gap: 1.5rem;
            max-width: 1400px;
        }

        .sidebar { display: flex; flex-direction: column; gap: 1.5rem; }

        .panel {
            background: #1a1a1a;
            border: 1px solid #2a2a2a;
            padding: 1.25rem;
        }

        .preset-group { margin-bottom: 1rem; }
        .preset-group:last-child { margin-bottom: 0; }

        .preset-group-label {
            font-size: 0.6rem;
            letter-spacing: 0.2em;
            text-transform: uppercase;
            color: #444;
            margin-bottom: 0.5rem;
        }

        .preset-btn {
            display: block;
            width: 100%;
            text-align: left;
            background: none;
            border: none;
            color: #888;
            font-family: inherit;
            font-size: 0.78rem;
            padding: 0.35rem 0.5rem;
            cursor: pointer;
            letter-spacing: 0.02em;
        }

        .preset-btn:hover { color: #00ff88; background: #111; }
        .preset-btn.danger { color: #664444; }
        .preset-btn.danger:hover { color: #ff4444; }

        /* SQL file list */
        .sql-file-list { display: flex; flex-direction: column; gap: 0.4rem; }

        .sql-file-row {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.4rem 0.5rem;
            background: #111;
        }

        .sql-file-row span {
            font-size: 0.75rem;
            color: #888;
            flex: 1;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .sql-file-row .file-actions {
            display: flex;
            gap: 0.3rem;
            flex-shrink: 0;
        }

        .sql-file-row button {
            background: none;
            border: 1px solid #333;
            color: #00ff88;
            font-family: inherit;
            font-size: 0.65rem;
            padding: 0.2rem 0.6rem;
            cursor: pointer;
            letter-spacing: 0.1em;
            text-transform: uppercase;
        }

        .sql-file-row button:hover { background: #00ff88; color: #0f0f0f; border-color: #00ff88; }

        .sql-file-row button.btn-view {
            color: #aaa;
            border-color: #333;
        }

        .sql-file-row button.btn-view:hover { background: #333; color: #fff; border-color: #555; }

        .no-files { font-size: 0.75rem; color: #444; }

        .results-panel { overflow-x: auto; }

        /* SQL source viewer */
        .sql-source {
            background: #0f0f0f;
            border: 1px solid #2a2a2a;
            padding: 1rem;
            font-size: 0.8rem;
            line-height: 1.7;
            color: #ccc;
            white-space: pre-wrap;
            word-break: break-word;
            overflow-x: auto;
        }

        table { width: 100%; border-collapse: collapse; font-size: 0.78rem; }

        th {
            background: #111;
            color: #00ff88;
            text-align: left;
            padding: 0.5rem 0.75rem;
            font-size: 0.65rem;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            white-space: nowrap;
            border-bottom: 1px solid #2a2a2a;
        }

        td {
            padding: 0.45rem 0.75rem;
            border-bottom: 1px solid #1f1f1f;
            color: #ccc;
            white-space: nowrap;
        }

        tr:hover td { background: #1f1f1f; }

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
        }

        a.logout {
            font-size: 0.65rem;
            color: #444;
            text-decoration: none;
            letter-spacing: 0.1em;
            text-transform: uppercase;
        }

        a.logout:hover { color: #888; }

        .empty { color: #444; font-size: 0.8rem; padding: 1rem 0; }
    </style>
</head>
<body>

<?php if (!$authed): ?>
<div class="login-wrap">
    <div class="card">
        <h1>DB <span>Admin</span></h1>
        <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <form method="POST">
            <label for="pw">Password</label>
            <input type="password" id="pw" name="password" autofocus>
            <button type="submit" class="btn">Authenticate</button>
        </form>
    </div>
</div>

<?php else: ?>

<div class="topbar">
    <h1 style="margin:0">DB <span style="color:#00ff88">Admin</span></h1>
    <a href="?logout" class="logout">Log out</a>
</div>

<div class="layout">
    <!-- Sidebar -->
    <div class="sidebar">

        <!-- Preset queries -->
        <div class="panel">
            <h2>Presets</h2>
            <div class="preset-group">
                <div class="preset-group-label">Inspect</div>
                <form method="POST">
                    <input type="hidden" name="preset" value="show_tables">
                    <button class="preset-btn" type="submit">Show tables</button>
                </form>
                <form method="POST">
                    <input type="hidden" name="preset" value="all_participants">
                    <button class="preset-btn" type="submit">All participants</button>
                </form>
                <form method="POST">
                    <input type="hidden" name="preset" value="all_rounds">
                    <button class="preset-btn" type="submit">All rounds</button>
                </form>
                <form method="POST">
                    <input type="hidden" name="preset" value="all_logs">
                    <button class="preset-btn" type="submit">All round logs (500)</button>
                </form>
                <form method="POST">
                    <input type="hidden" name="preset" value="all_surveys">
                    <button class="preset-btn" type="submit">All habit surveys</button>
                </form>
            </div>
            <div class="preset-group">
                <div class="preset-group-label">Danger zone</div>
                <form method="POST" onsubmit="return confirm('Clear roundLogs table?')">
                    <input type="hidden" name="preset" value="clear_logs">
                    <button class="preset-btn danger" type="submit">? Clear round logs</button>
                </form>
                <form method="POST" onsubmit="return confirm('Clear rounds table?')">
                    <input type="hidden" name="preset" value="clear_rounds">
                    <button class="preset-btn danger" type="submit">? Clear rounds</button>
                </form>
                <form method="POST" onsubmit="return confirm('Clear participants table?')">
                    <input type="hidden" name="preset" value="clear_participants">
                    <button class="preset-btn danger" type="submit">? Clear participants</button>
                </form>
                <form method="POST" onsubmit="return confirm('Clear habitSurvey table?')">
                    <input type="hidden" name="preset" value="clear_surveys">
                    <button class="preset-btn danger" type="submit">? Clear surveys</button>
                </form>
                <form method="POST" onsubmit="return confirm('CLEAR ALL TABLES? This cannot be undone.')">
                    <input type="hidden" name="preset" value="clear_all">
                    <button class="preset-btn danger" type="submit">?? Clear ALL tables</button>
                </form>
            </div>
        </div>

        <!-- SQL file loader -->
        <div class="panel">
            <h2>SQL Files</h2>
            <?php $sqlFiles = getSqlFiles(); ?>
            <?php if (empty($sqlFiles)): ?>
                <div class="no-files">No .sql files found in the sql directory.</div>
            <?php else: ?>
                <div class="sql-file-list">
                    <?php foreach ($sqlFiles as $file): ?>
                        <div class="sql-file-row">
                            <span title="<?= htmlspecialchars($file) ?>"><?= htmlspecialchars($file) ?></span>
                            <div class="file-actions">
                                <!-- View button — no confirm needed, read-only -->
                                <form method="POST">
                                    <input type="hidden" name="sql_file" value="<?= htmlspecialchars($file) ?>">
                                    <button type="submit" name="view_sql_file" value="1" class="btn-view">View</button>
                                </form>
                                <!-- Run button -->
                                <form method="POST"
                                      onsubmit="return confirm('Run <?= htmlspecialchars($file, ENT_QUOTES) ?>?\nThis may modify the database.')">
                                    <input type="hidden" name="sql_file" value="<?= htmlspecialchars($file) ?>">
                                    <button type="submit" name="run_sql_file" value="1">Run</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Custom query -->
        <div class="panel">
            <h2>Custom Query</h2>
            <form method="POST">
                <textarea name="query" placeholder="SELECT * FROM rounds LIMIT 10"><?= isset($_POST['query']) ? htmlspecialchars($_POST['query']) : '' ?></textarea>
                <button type="submit" name="run_query" value="1" class="btn">Run</button>
            </form>
        </div>

    </div>

    <!-- Results -->
    <div class="panel results-panel">
        <h2>Results</h2>
        <br>
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php elseif ($message): ?>
            <div class="success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($sqlFileContent !== null): ?>
            <pre class="sql-source"><?= htmlspecialchars($sqlFileContent) ?></pre>

        <?php elseif ($results !== null && count($results) > 0): ?>
            <table>
                <thead>
                    <tr><?php foreach ($columns as $col): ?><th><?= htmlspecialchars($col) ?></th><?php endforeach; ?></tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $row): ?>
                        <tr><?php foreach ($row as $val): ?><td><?= htmlspecialchars($val ?? 'NULL') ?></td><?php endforeach; ?></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php elseif ($results !== null): ?>
            <div class="empty">No rows returned.</div>
        <?php else: ?>
            <div class="empty">Run a query or select a preset to see results here.</div>
        <?php endif; ?>
    </div>
</div>

<?php endif; ?>
</body>
</html>