<?php
/**
 * WordPress Database Domain Search & Replace Tool
 *
 * Replaces old domain with new domain across the entire WordPress database.
 * Properly handles PHP serialized data (options, post meta, etc.).
 *
 * Usage:
 *   php wp-domain-replace.php
 *   php wp-domain-replace.php --old=olddomain.com --new=newdomain.com
 *   php wp-domain-replace.php --dry-run
 *
 * Place this file in your WordPress root directory or set WP_PATH.
 */

$options = getopt('', ['old:', 'new:', 'dry-run', 'wp-path:', 'help', 'skip-tables:']);

if (isset($options['help'])) {
    echo <<<HELP
WordPress Database Domain Search & Replace

Usage:
  php wp-domain-replace.php [options]

Options:
  --old=DOMAIN       Old domain to search for (e.g., oldsite.com)
  --new=DOMAIN       New domain to replace with (e.g., newsite.com)
  --dry-run          Preview changes without modifying the database
  --wp-path=PATH     Path to WordPress root (default: current directory)
  --skip-tables=     Comma-separated list of tables to skip (optional)
  --help             Show this help

Examples:
  php wp-domain-replace.php --old=staging.example.com --new=example.com
  php wp-domain-replace.php --old=localhost/wp --new=example.com --dry-run

IMPORTANT: Always backup your database before running!
HELP;
    exit(0);
}

// Configuration
$wpPath = isset($options['wp-path']) ? rtrim($options['wp-path'], '/\\') : __DIR__;
$dryRun = isset($options['dry-run']);
$oldDomain = $options['old'] ?? null;
$newDomain = $options['new'] ?? null;
$skipTables = isset($options['skip-tables']) ? array_map('trim', explode(',', $options['skip-tables'])) : [];

// Load database credentials from wp-config.php (without loading full WordPress)
$wpConfigPath = $wpPath . DIRECTORY_SEPARATOR . 'wp-config.php';
if (!file_exists($wpConfigPath)) {
    echo "Error: wp-config.php not found at: {$wpConfigPath}\n";
    echo "Use --wp-path=PATH to specify your WordPress directory.\n";
    exit(1);
}

$wpConfig = file_get_contents($wpConfigPath);
foreach (['DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST'] as $constant) {
    if (!defined($constant) && preg_match("/define\s*\(\s*['\"]{$constant}['\"]\s*,\s*['\"]([^'\"]*)['\"]\s*\)/", $wpConfig, $m)) {
        define($constant, $m[1]);
    }
}
if (!defined('DB_HOST')) {
    define('DB_HOST', 'localhost');
}

if (!defined('DB_NAME') || !defined('DB_USER') || !defined('DB_PASSWORD')) {
    echo "Error: Could not parse DB_NAME, DB_USER, DB_PASSWORD from wp-config.php\n";
    exit(1);
}

// Interactive mode if domains not provided
if ($oldDomain === null || $newDomain === null) {
    echo "\n=== WordPress Domain Search & Replace ===\n\n";
    echo "Enter OLD domain (to find): ";
    $oldDomain = trim(fgets(STDIN));
    echo "Enter NEW domain (to replace with): ";
    $newDomain = trim(fgets(STDIN));
    echo "\nDry run? (y/n) [n]: ";
    $dryInput = trim(fgets(STDIN));
    $dryRun = strtolower($dryInput) === 'y' || strtolower($dryInput) === 'yes';
}

if (empty($oldDomain) || empty($newDomain)) {
    echo "Error: Both old and new domains are required.\n";
    exit(1);
}

// Normalize domains for consistency (with/without protocol)
$searchPatterns = [
    'http://' . $oldDomain,
    'https://' . $oldDomain,
    $oldDomain,
];

$replacements = [
    'http://' . $newDomain,
    'https://' . $newDomain,
    $newDomain,
];

echo "\n" . str_repeat('=', 60) . "\n";
echo "Domain Replacement: {$oldDomain} -> {$newDomain}\n";
echo $dryRun ? "*** DRY RUN - No changes will be made ***\n" : "*** LIVE MODE - Database will be modified ***\n";
echo str_repeat('=', 60) . "\n\n";

// Connect to database
$mysqli = @new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

if ($mysqli->connect_error) {
    echo "Database connection failed: " . $mysqli->connect_error . "\n";
    exit(1);
}

$mysqli->set_charset('utf8mb4');

/**
 * Recursively replace in value - handles serialized data
 */
function replace_in_value($value, $old, $new) {
    if (is_string($value)) {
        return str_replace($old, $new, $value);
    }
    if (is_array($value)) {
        return array_map(function ($item) use ($old, $new) {
            return replace_in_value($item, $old, $new);
        }, $value);
    }
    if (is_object($value)) {
        foreach ($value as $key => $val) {
            $value->$key = replace_in_value($val, $old, $new);
        }
        return $value;
    }
    return $value;
}

/**
 * Check if string is serialized PHP data
 */
function is_serialized($value) {
    if (!is_string($value) || trim($value) === '') {
        return false;
    }
    return ($value[0] === 'a' || $value[0] === 'O' || $value[0] === 's') && preg_match('/^[aOs]:\d+:/', $value);
}

/**
 * Replace in value with serialization handling
 */
function replace_value($value, $old, $new) {
    if (strpos($value, $old) === false) {
        return $value;
    }

    if (is_serialized($value)) {
        $unserialized = @unserialize($value);
        if ($unserialized !== false || $value === 'b:0;') {
            $replaced = replace_in_value($unserialized, $old, $new);
            return serialize($replaced);
        }
    }

    return str_replace($old, $new, $value);
}

/**
 * Get primary key columns for a table
 */
function get_primary_key($mysqli, $table) {
    $result = $mysqli->query("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'");
    $keys = [];
    while ($row = $result->fetch_assoc()) {
        $keys[] = $row['Column_name'];
    }
    return $keys;
}

// Get all tables
$tables = [];
$result = $mysqli->query("SHOW TABLES");
while ($row = $result->fetch_array()) {
    $tableName = $row[0];
    if (!in_array($tableName, $skipTables)) {
        $tables[] = $tableName;
    }
}

$totalReplacements = 0;
$tablesModified = 0;

foreach ($tables as $table) {
    $result = $mysqli->query("DESCRIBE `{$table}`");
    if (!$result) continue;

    $textColumns = [];
    $allColumns = [];
    while ($row = $result->fetch_assoc()) {
        $allColumns[] = $row['Field'];
        if (preg_match('/^(blob|text|longtext|mediumtext|tinytext|varchar|char)/i', $row['Type'])) {
            $textColumns[] = $row['Field'];
        }
    }

    if (empty($textColumns)) continue;

    $primaryKey = get_primary_key($mysqli, $table);
    $hasPrimaryKey = !empty($primaryKey);

    $tableReplacements = 0;
    $selectCols = array_merge($primaryKey, $textColumns);
    $selectCols = array_unique($selectCols);

    // Build WHERE clause for any text column containing old domain
    $likeConditions = [];
    foreach ($textColumns as $col) {
        $likeConditions[] = "`{$col}` LIKE '%" . $mysqli->real_escape_string($oldDomain) . "%'";
    }
    $whereClause = implode(' OR ', $likeConditions);
    $selectColsList = implode('`, `', $selectCols);

    $sql = "SELECT `{$selectColsList}` FROM `{$table}` WHERE {$whereClause}";
    $rows = $mysqli->query($sql);

    if (!$rows) continue;

    while ($row = $rows->fetch_assoc()) {
        $updates = [];
        $rowModified = false;

        foreach ($textColumns as $column) {
            $oldVal = $row[$column];
            if ($oldVal === null) continue;

            $newVal = $oldVal;
            foreach ($searchPatterns as $i => $search) {
                if (strpos($oldVal, $search) !== false) {
                    $newVal = replace_value($newVal, $search, $replacements[$i]);
                }
            }

            if ($newVal !== $oldVal) {
                $rowModified = true;
                $updates[$column] = $newVal;
            }
        }

        if ($rowModified && !empty($updates)) {
            $tableReplacements += count($updates);
            $totalReplacements += count($updates);

            if (!$dryRun) {
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
                    // Fallback: use all column values (less reliable for duplicates)
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
        }
    }

    if ($tableReplacements > 0) {
        $tablesModified++;
        echo "  [{$table}] {$tableReplacements} replacement(s)\n";
    }
}

echo "\n" . str_repeat('-', 60) . "\n";
echo "Complete! {$totalReplacements} total replacement(s) in {$tablesModified} table(s).\n";
if ($dryRun) {
    echo "\nThis was a dry run. Run without --dry-run to apply changes.\n";
}

$mysqli->close();
