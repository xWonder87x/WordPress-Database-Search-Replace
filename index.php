<?php
/**
 * WordPress Database Domain Search & Replace - Web Interface
 *
 * Upload this folder to your WordPress site and access via browser.
 * SECURITY: Set SECRET_KEY in config.php and delete this folder after use!
 */

session_start();

require_once __DIR__ . '/config.php';

// Check if secret key is still default
if (SECRET_KEY === 'interface') {
    die('<h1>Setup Required</h1><p>Edit <strong>config.php</strong> and set a unique SECRET_KEY before using this tool.</p>');
}

// Authentication
$loggedIn = isset($_SESSION['auth']) && $_SESSION['auth'] === true;

if (isset($_GET['logout'])) {
    unset($_SESSION['auth']);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

if (!$loggedIn) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['key'])) {
        if ($_POST['key'] === SECRET_KEY) {
            $_SESSION['auth'] = true;
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
            exit;
        }
        $authError = 'Invalid key';
    }
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Domain Replace - Login</title>
        <style>
            * { box-sizing: border-box; }
            body { font-family: system-ui, sans-serif; max-width: 400px; margin: 80px auto; padding: 20px; }
            h1 { font-size: 1.25rem; margin-bottom: 1rem; }
            input[type="password"] { width: 100%; padding: 10px; margin: 8px 0; font-size: 1rem; }
            button { width: 100%; padding: 12px; background: #2271b1; color: white; border: none; cursor: pointer; font-size: 1rem; }
            button:hover { background: #135e96; }
            .error { color: #b32d2e; margin-bottom: 10px; }
        </style>
    </head>
    <body>
        <h1>Domain Search & Replace</h1>
        <?php if (isset($authError)): ?><p class="error"><?= htmlspecialchars($authError) ?></p><?php endif; ?>
        <form method="post">
            <label for="key">Enter secret key:</label>
            <input type="password" name="key" id="key" required autofocus>
            <button type="submit">Continue</button>
        </form>
    </body>
    </html>
    <?php
    exit;
}

// Process form submission
$output = '';
$ran = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['old_domain']) && isset($_POST['new_domain'])) {
    $ran = true;
    $oldDomain = trim($_POST['old_domain']);
    $newDomain = trim($_POST['new_domain']);
    $dryRun = isset($_POST['dry_run']);

    if (empty($oldDomain) || empty($newDomain)) {
        $output = '<p class="error">Both old and new domains are required.</p>';
    } else {
        $output = runReplace($oldDomain, $newDomain, $dryRun);
    }
}

function runReplace($oldDomain, $newDomain, $dryRun) {
    $wpPath = isset($_POST['wp_path']) ? rtrim($_POST['wp_path'], '/\\') : dirname(__DIR__);
    $wpConfigPath = $wpPath . DIRECTORY_SEPARATOR . 'wp-config.php';

    if (!file_exists($wpConfigPath)) {
        return '<p class="error">wp-config.php not found at: ' . htmlspecialchars($wpConfigPath) . '</p>';
    }

    $wpConfig = file_get_contents($wpConfigPath);
    foreach (['DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST'] as $constant) {
        if (!defined($constant) && preg_match("/define\s*\(\s*['\"]{$constant}['\"]\s*,\s*['\"]([^'\"]*)['\"]\s*\)/", $wpConfig, $m)) {
            define($constant, $m[1]);
        }
    }
    if (!defined('DB_HOST')) define('DB_HOST', 'localhost');

    if (!defined('DB_NAME') || !defined('DB_USER') || !defined('DB_PASSWORD')) {
        return '<p class="error">Could not parse database credentials from wp-config.php</p>';
    }

    $mysqli = @new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    if ($mysqli->connect_error) {
        return '<p class="error">Database connection failed: ' . htmlspecialchars($mysqli->connect_error) . '</p>';
    }
    $mysqli->set_charset('utf8mb4');

    $searchPatterns = ['http://' . $oldDomain, 'https://' . $oldDomain, $oldDomain];
    $replacements = ['http://' . $newDomain, 'https://' . $newDomain, $newDomain];

    $result = runReplacementLogic($mysqli, $oldDomain, $searchPatterns, $replacements, $dryRun);
    $mysqli->close();

    return $result;
}

function runReplacementLogic($mysqli, $oldDomain, $searchPatterns, $replacements, $dryRun) {
    $lines = [];
    $lines[] = '<p><strong>' . ($dryRun ? 'Dry run (preview)' : 'Live run') . '</strong>: ' . htmlspecialchars($searchPatterns[0]) . ' → ' . htmlspecialchars($replacements[0]) . '</p>';

    $result = $mysqli->query("SHOW TABLES");
    $tables = [];
    while ($row = $result->fetch_array()) $tables[] = $row[0];

    $totalReplacements = 0;
    $tablesModified = 0;

    foreach ($tables as $table) {
        $desc = $mysqli->query("DESCRIBE `{$table}`");
        if (!$desc) continue;

        $textColumns = [];
        while ($row = $desc->fetch_assoc()) {
            if (preg_match('/^(blob|text|longtext|mediumtext|tinytext|varchar|char)/i', $row['Type'])) {
                $textColumns[] = $row['Field'];
            }
        }
        if (empty($textColumns)) continue;

        $pkResult = $mysqli->query("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'");
        $primaryKey = [];
        while ($row = $pkResult->fetch_assoc()) $primaryKey[] = $row['Column_name'];
        $hasPrimaryKey = !empty($primaryKey);

        $selectCols = array_unique(array_merge($primaryKey, $textColumns));
        $likeConditions = [];
        foreach ($textColumns as $col) {
            $likeConditions[] = "`{$col}` LIKE '%" . $mysqli->real_escape_string($oldDomain) . "%'";
        }
        $sql = "SELECT `" . implode('`, `', $selectCols) . "` FROM `{$table}` WHERE " . implode(' OR ', $likeConditions);
        $rows = $mysqli->query($sql);
        if (!$rows) continue;

        $tableReplacements = 0;

        while ($row = $rows->fetch_assoc()) {
            $updates = [];
            foreach ($textColumns as $column) {
                $oldVal = $row[$column];
                if ($oldVal === null) continue;

                $newVal = $oldVal;
                foreach ($searchPatterns as $i => $search) {
                    if (strpos($oldVal, $search) !== false) {
                        $newVal = replaceValue($newVal, $search, $replacements[$i]);
                    }
                }

                if ($newVal !== $oldVal) {
                    $updates[$column] = $newVal;
                }
            }

            if (!empty($updates) && !$dryRun) {
                $setParts = [];
                foreach ($updates as $col => $val) {
                    $setParts[] = "`{$col}` = '" . $mysqli->real_escape_string($val) . "'";
                }
                $whereParts = [];
                foreach ($primaryKey as $pk) {
                    $whereParts[] = "`{$pk}` = '" . $mysqli->real_escape_string($row[$pk]) . "'";
                }
                $updateSql = "UPDATE `{$table}` SET " . implode(', ', $setParts);
                if ($hasPrimaryKey) {
                    $updateSql .= " WHERE " . implode(' AND ', $whereParts);
                } else {
                    $whereParts = [];
                    foreach ($row as $k => $v) {
                        if (!isset($updates[$k]) && $v !== null) {
                            $whereParts[] = "`{$k}` = '" . $mysqli->real_escape_string($v) . "'";
                        }
                    }
                    $updateSql .= " WHERE " . implode(' AND ', $whereParts) . " LIMIT 1";
                }
                $mysqli->query($updateSql);
            }

            $tableReplacements += count($updates);
        }

        if ($tableReplacements > 0) {
            $tablesModified++;
            $totalReplacements += $tableReplacements;
            $lines[] = '<div class="table-row">' . htmlspecialchars($table) . ': ' . $tableReplacements . ' replacement(s)</div>';
        }
    }

    $lines[] = '<p class="summary"><strong>Complete!</strong> ' . $totalReplacements . ' replacement(s) in ' . $tablesModified . ' table(s).</p>';
    if ($dryRun) {
        $lines[] = '<p class="dry-run-note">This was a dry run. Uncheck "Dry run" and submit again to apply changes.</p>';
    }

    return implode("\n", $lines);
}

function replaceValue($value, $old, $new) {
    if (strpos($value, $old) === false) return $value;
    if (isSerialized($value)) {
        $unserialized = @unserialize($value);
        if ($unserialized !== false || $value === 'b:0;') {
            return serialize(replaceInValue($unserialized, $old, $new));
        }
    }
    return str_replace($old, $new, $value);
}

function replaceInValue($value, $old, $new) {
    if (is_string($value)) return str_replace($old, $new, $value);
    if (is_array($value)) return array_map(fn($i) => replaceInValue($i, $old, $new), $value);
    if (is_object($value)) {
        foreach ($value as $k => $v) $value->$k = replaceInValue($v, $old, $new);
        return $value;
    }
    return $value;
}

function isSerialized($value) {
    if (!is_string($value) || trim($value) === '') return false;
    return in_array($value[0], ['a', 'O', 's']) && preg_match('/^[aOs]:\d+:/', $value);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>WordPress Domain Search & Replace</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, sans-serif; max-width: 600px; margin: 40px auto; padding: 20px; color: #1e1e1e; }
        h1 { font-size: 1.5rem; margin-bottom: 0.5rem; }
        .subtitle { color: #666; margin-bottom: 1.5rem; font-size: 0.9rem; }
        label { display: block; margin-top: 12px; font-weight: 500; }
        input[type="text"] { width: 100%; padding: 10px; margin-top: 4px; font-size: 1rem; }
        .checkbox { display: flex; align-items: center; gap: 8px; margin-top: 12px; }
        .checkbox input { width: auto; }
        button { margin-top: 20px; padding: 12px 24px; background: #2271b1; color: white; border: none; cursor: pointer; font-size: 1rem; }
        button:hover { background: #135e96; }
        .logout { float: right; font-size: 0.85rem; color: #666; }
        .output { margin-top: 24px; padding: 16px; background: #f6f7f7; border-radius: 4px; font-family: monospace; font-size: 0.9rem; }
        .output .error { color: #b32d2e; }
        .output .summary { margin-top: 12px; font-weight: 500; }
        .output .dry-run-note { color: #d63638; margin-top: 8px; }
        .output .table-row { padding: 2px 0; }
        .warning { background: #fcf0f1; border-left: 4px solid #d63638; padding: 12px; margin-bottom: 20px; font-size: 0.9rem; }
    </style>
</head>
<body>
    <a href="?logout=1" class="logout">Logout</a>
    <h1>WordPress Domain Search & Replace</h1>
    <p class="subtitle">Update domain throughout your database. Backup first!</p>

    <div class="warning">
        <strong>Important:</strong> Always backup your database before running. Use "Dry run" first to preview changes. Delete this folder after use.
    </div>

    <form method="post">
        <label for="old_domain">Old domain (to find)</label>
        <input type="text" name="old_domain" id="old_domain" placeholder="e.g. staging.example.com or localhost/wp" value="<?= htmlspecialchars($_POST['old_domain'] ?? '') ?>" required>

        <label for="new_domain">New domain (to replace with)</label>
        <input type="text" name="new_domain" id="new_domain" placeholder="e.g. example.com" value="<?= htmlspecialchars($_POST['new_domain'] ?? '') ?>" required>

        <label for="wp_path">WordPress path (optional)</label>
        <input type="text" name="wp_path" id="wp_path" placeholder="Auto-detected: parent of this folder" value="<?= htmlspecialchars($_POST['wp_path'] ?? dirname(__DIR__)) ?>">

        <div class="checkbox">
            <input type="checkbox" name="dry_run" id="dry_run" value="1" <?= ($_POST['dry_run'] ?? true) ? 'checked' : '' ?>>
            <label for="dry_run" style="margin-top:0">Dry run (preview only, no changes)</label>
        </div>

        <button type="submit">Run</button>
    </form>

    <?php if ($ran && $output): ?>
    <div class="output"><?= $output ?></div>
    <?php endif; ?>
</body>
</html>
