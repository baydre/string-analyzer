<?php
// Start output buffering to prevent premature connection closure
ob_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle OPTIONS request for CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    ob_end_flush();
    exit();
}

// Enable error logging (production-safe)
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');
ini_set('display_errors', 0); // Off for production
error_reporting(E_ALL);

// Storage backend selection and initialization
// Supports: sqlite (default) or json fallback when PDO SQLite isn't available or STORAGE_BACKEND=json
$storageBackend = getenv('STORAGE_BACKEND') ?: 'auto';
$useJson = false;

if ($storageBackend === 'json') {
    $useJson = true;
} elseif ($storageBackend === 'auto') {
    $useJson = !extension_loaded('pdo_sqlite');
}

// Common helper: safe directory prep and fallback to /tmp if needed
function ensureWritablePath($targetPath, $fallbackFileName) {
    $dir = dirname($targetPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    if (!is_writable($dir)) {
        $tmp = '/tmp/' . ltrim($fallbackFileName, '/');
        if (is_dir('/tmp') && is_writable('/tmp')) {
            error_log("Path not writable: $dir. Falling back to $tmp");
            return $tmp;
        }
    }
    return $targetPath;
}

// JSON storage helpers
function json_store_path() {
    $path = getenv('DB_PATH') ?: (__DIR__ . '/strings.json');
    if (substr($path, -5) !== '.json') {
        // If DB_PATH provided but not a .json, use same dir with strings.json
        $path = dirname($path) . '/strings.json';
    }
    return ensureWritablePath($path, 'strings.json');
}

function json_store_init($path) {
    if (!file_exists($path)) {
        $init = json_encode([], JSON_PRETTY_PRINT);
        @file_put_contents($path, $init, LOCK_EX);
        @chmod($path, 0666);
    }
}

function json_store_load($path) {
    $fp = @fopen($path, 'c+');
    if (!$fp) {
        throw new Exception('Unable to open JSON store at ' . $path);
    }
    try {
        // Shared lock for reading
        flock($fp, LOCK_SH);
        $size = filesize($path);
        $content = $size > 0 ? fread($fp, $size) : '';
        flock($fp, LOCK_UN);
    } finally {
        fclose($fp);
    }
    if ($content === '' || $content === false) {
        return [];
    }
    $data = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        // Corruption fallback
        error_log('JSON store corrupted; resetting to empty array');
        return [];
    }
    return $data;
}

function json_store_save($path, $data) {
    $tmp = $path . '.tmp';
    $fp = @fopen($tmp, 'w');
    if (!$fp) {
        throw new Exception('Unable to write JSON store at ' . $tmp);
    }
    try {
        flock($fp, LOCK_EX);
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
        fflush($fp);
        flock($fp, LOCK_UN);
    } finally {
        fclose($fp);
    }
    // Atomic replace
    @rename($tmp, $path);
    @chmod($path, 0666);
}

function filter_items_php($items, $query) {
    return array_values(array_filter($items, function ($item) use ($query) {
        $props = $item['properties'] ?? [];
        // is_palindrome
        if (isset($query['is_palindrome'])) {
            $bool = filter_var($query['is_palindrome'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($bool !== null && (bool)($props['is_palindrome'] ?? null) !== $bool) return false;
        }
        // length
        if (isset($query['min_length']) && is_numeric($query['min_length'])) {
            if (($props['length'] ?? PHP_INT_MIN) < (int)$query['min_length']) return false;
        }
        if (isset($query['max_length']) && is_numeric($query['max_length'])) {
            if (($props['length'] ?? PHP_INT_MAX) > (int)$query['max_length']) return false;
        }
        // word_count
        if (isset($query['word_count']) && is_numeric($query['word_count'])) {
            if ((int)($props['word_count'] ?? -1) !== (int)$query['word_count']) return false;
        }
        // contains_character
        if (isset($query['contains_character']) && $query['contains_character'] !== '') {
            if (strpos($item['value'], $query['contains_character']) === false) return false;
        }
        return true;
    }));
}

// Backend init
if ($useJson) {
    // JSON backend
    try {
        $jsonPath = json_store_path();
        json_store_init($jsonPath);
        // expose as $store for route handlers
        $store = [
            'type' => 'json',
            'path' => $jsonPath
        ];
    } catch (Exception $e) {
        error_log('JSON store init error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'error' => 'Database initialization failed',
            'details' => $e->getMessage(),
            'path' => $jsonPath ?? (getenv('DB_PATH') ?: (__DIR__ . '/strings.json'))
        ]);
        ob_end_flush();
        exit();
    }
} else {
    // SQLite backend
    try {
        if (!extension_loaded('pdo_sqlite')) {
            throw new Exception('could not find driver');
        }
        // Allow overriding DB location via environment variable, e.g., DB_PATH=/tmp/strings.db
        $dbPath = getenv('DB_PATH') ?: (__DIR__ . '/strings.db');
        $dbPath = ensureWritablePath($dbPath, 'strings.db');
        $dbExists = file_exists($dbPath);
        
        // Create PDO connection
        $db = new PDO('sqlite:' . $dbPath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Create table if not exists
        $db->exec('CREATE TABLE IF NOT EXISTS strings (
            id TEXT PRIMARY KEY,
            value TEXT UNIQUE,
            properties TEXT,
            created_at TEXT
        )');
        
        // Set proper permissions for new database file
        if (!$dbExists && file_exists($dbPath)) {
            @chmod($dbPath, 0666);
        }
    } catch (Exception $e) {
        error_log('Database init error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'error' => 'Database initialization failed',
            'details' => $e->getMessage(),
            'path' => $dbPath ?? (__DIR__ . '/strings.db')
        ]);
        ob_end_flush();
        exit();
    }
}

// Determine active backend/path for diagnostics and logging
$activeBackend = $useJson ? 'json' : 'sqlite';
$activePath = $useJson ? ($store['path'] ?? null) : ($dbPath ?? null);
error_log("String Analyzer: backend=$activeBackend path=" . ($activePath ?: 'n/a'));

// Helper functions
function analyzeString($string) {
    return [
        'length' => strlen($string),
        'is_palindrome' => strcasecmp($string, strrev($string)) === 0,
        'unique_characters' => count(array_unique(str_split($string))),
        'word_count' => str_word_count($string),
        'sha256_hash' => hash('sha256', $string),
        'character_frequency_map' => array_count_values(str_split($string))
    ];
}

function validateString($value) {
    if (!isset($value)) {
        throw new Exception('Missing "value" field', 400);
    }
    if (!is_string($value)) {
        throw new Exception('Value must be a string', 422);
    }
    if (empty(trim($value))) {
        throw new Exception('String cannot be empty', 400);
    }
    return trim($value);
}

function parseNaturalLanguageQuery($query) {
    $filters = [];
    
    // Convert query to lowercase for case-insensitive matching
    $query = strtolower($query);
    
    // Check for palindrome requirement
    if (strpos($query, 'palindrom') !== false) {
        $filters['is_palindrome'] = true;
    }
    
    // Check for word count
    if (preg_match('/single word|one word/', $query)) {
        $filters['word_count'] = 1;
    } elseif (preg_match('/(\d+) words?/', $query, $matches)) {
        $filters['word_count'] = (int)$matches[1];
    }
    
    // Check for length constraints
    if (preg_match('/longer than (\d+)/', $query, $matches)) {
        $filters['min_length'] = (int)$matches[1] + 1;
    }
    if (preg_match('/shorter than (\d+)/', $query, $matches)) {
        $filters['max_length'] = (int)$matches[1] - 1;
    }
    
    // Check for specific character containment
    if (preg_match('/containing? (?:the )?(?:letter )?([a-z])/', $query, $matches)) {
        $filters['contains_character'] = $matches[1];
    }
    
    return $filters;
}

function applyFilters($query, $db) {
    $conditions = [];
    $params = [];
    
    if (isset($query['is_palindrome'])) {
        $value = filter_var($query['is_palindrome'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($value !== null) {
            $conditions[] = "json_extract(properties, '$.is_palindrome') = " . ($value ? 'true' : 'false');
        }
    }
    
    if (isset($query['min_length']) && is_numeric($query['min_length'])) {
        $conditions[] = "json_extract(properties, '$.length') >= ?";
        $params[] = (int)$query['min_length'];
    }
    
    if (isset($query['max_length']) && is_numeric($query['max_length'])) {
        $conditions[] = "json_extract(properties, '$.length') <= ?";
        $params[] = (int)$query['max_length'];
    }
    
    if (isset($query['word_count']) && is_numeric($query['word_count'])) {
        $conditions[] = "CAST(json_extract(properties, '$.word_count') AS INTEGER) = " . (int)$query['word_count'];
    }
    
    if (isset($query['contains_character'])) {
        $conditions[] = "value LIKE ?";
        $params[] = '%' . $query['contains_character'] . '%';
    }
    
    return [
        'where' => $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '',
        'params' => $params
    ];
}

// Route handler
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$parts = explode('/', trim($path, '/'));

try {
    switch ($method) {
        case 'POST':
            if ($parts[0] === 'strings' && count($parts) === 1) {
                // Create/Analyze String endpoint
                $rawInput = file_get_contents('php://input');
                $data = json_decode($rawInput, true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid JSON']);
                    break;
                }
                
                $value = validateString($data['value'] ?? null);
                
                // Analyze
                $properties = analyzeString($value);

                if (isset($store) && $store['type'] === 'json') {
                    $json = json_store_load($store['path']);
                    foreach ($json as $row) {
                        if ($row['value'] === $value) {
                            http_response_code(409);
                            echo json_encode(['error' => 'String already exists']);
                            exit();
                        }
                    }
                    $record = [
                        'id' => $properties['sha256_hash'],
                        'value' => $value,
                        'properties' => $properties,
                        'created_at' => gmdate('Y-m-d\TH:i:s\Z')
                    ];
                    $json[] = $record;
                    json_store_save($store['path'], $json);
                    http_response_code(201);
                    echo json_encode($record);
                } else {
                    // SQLite path
                    // Check if string already exists
                    $stmt = $db->prepare('SELECT id FROM strings WHERE value = ?');
                    $stmt->execute([$value]);
                    if ($stmt->fetch()) {
                        http_response_code(409);
                        echo json_encode(['error' => 'String already exists']);
                        exit();
                    }
                    // Store
                    $stmt = $db->prepare('INSERT INTO strings (id, value, properties, created_at) VALUES (?, ?, ?, ?)');
                    $stmt->execute([
                        $properties['sha256_hash'],
                        $value,
                        json_encode($properties),
                        gmdate('Y-m-d\TH:i:s\Z')
                    ]);
                    http_response_code(201);
                    echo json_encode([
                        'id' => $properties['sha256_hash'],
                        'value' => $value,
                        'properties' => $properties,
                        'created_at' => gmdate('Y-m-d\TH:i:s\Z')
                    ]);
                }
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Endpoint not found']);
            }
            break;

        case 'GET':
            // Health endpoint
            if ($parts[0] === 'health' && count($parts) === 1) {
                echo json_encode([
                    'status' => 'ok',
                    'backend' => $activeBackend,
                    'storage_path' => $activePath,
                    'pdo_sqlite_loaded' => extension_loaded('pdo_sqlite'),
                    'storage_backend_env' => getenv('STORAGE_BACKEND') ?: null,
                    'time' => gmdate('Y-m-d\TH:i:s\Z')
                ]);
                break;
            }
            // Base URL welcome/intro
            if (empty($parts[0])) {
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
                $base = $scheme . '://' . $host;

                echo json_encode([
                    'name' => 'String Analyzer API',
                    'version' => '1.0.0',
                    'status' => 'active',
                    'endpoints' => [
                        'create' => $base . '/strings',
                        'list' => $base . '/strings',
                        'get_specific' => $base . '/strings/{string_value}',
                        'natural_language_filter' => $base . '/strings/filter-by-natural-language?query=...',
                        'delete' => $base . '/strings/{string_value}'
                    ],
                    'docs' => $base . '/docs.html'
                ]);
                break;
            }

            if ($parts[0] === 'strings') {
                if (count($parts) === 1) {
                    // Get All Strings with Filtering endpoint
                    if (isset($store) && $store['type'] === 'json') {
                        $all = json_store_load($store['path']);
                        $strings = filter_items_php($all, $_GET);
                    } else {
                        $filters = applyFilters($_GET, $db);
                        $sql = 'SELECT * FROM strings ' . $filters['where'];
                        $stmt = $db->prepare($sql);
                        $stmt->execute($filters['params']);
                        $strings = [];
                        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                            $strings[] = [
                                'id' => $row['id'],
                                'value' => $row['value'],
                                'properties' => json_decode($row['properties'], true),
                                'created_at' => $row['created_at']
                            ];
                        }
                    }
                    echo json_encode([
                        'data' => $strings,
                        'count' => count($strings),
                        'filters_applied' => $_GET
                    ]);

                } elseif (count($parts) === 2 && $parts[1] === 'filter-by-natural-language') {
                    // Natural Language Filtering endpoint
                    if (!isset($_GET['query'])) {
                        http_response_code(400);
                        echo json_encode(['error' => 'Missing query parameter']);
                        exit();
                    }
                    $parsedFilters = parseNaturalLanguageQuery($_GET['query']);
                    if (empty($parsedFilters)) {
                        http_response_code(422);
                        echo json_encode(['error' => 'Could not parse meaningful filters from query']);
                        exit();
                    }
                    if (isset($store) && $store['type'] === 'json') {
                        $all = json_store_load($store['path']);
                        $strings = filter_items_php($all, $parsedFilters);
                    } else {
                        $filters = applyFilters($parsedFilters, $db);
                        $sql = 'SELECT * FROM strings ' . $filters['where'];
                        $stmt = $db->prepare($sql);
                        $stmt->execute($filters['params']);
                        $strings = [];
                        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                            $strings[] = [
                                'id' => $row['id'],
                                'value' => $row['value'],
                                'properties' => json_decode($row['properties'], true),
                                'created_at' => $row['created_at']
                            ];
                        }
                    }
                    echo json_encode([
                        'data' => $strings,
                        'count' => count($strings),
                        'interpreted_query' => [
                            'original' => $_GET['query'],
                            'parsed_filters' => $parsedFilters
                        ]
                    ]);

                } elseif (count($parts) === 2) {
                    // Get Specific String endpoint
                    $value = urldecode($parts[1]);
                    if (isset($store) && $store['type'] === 'json') {
                        $all = json_store_load($store['path']);
                        foreach ($all as $row) {
                            if ($row['value'] === $value) {
                                echo json_encode($row);
                                exit();
                            }
                        }
                        http_response_code(404);
                        echo json_encode(['error' => 'String not found']);
                        exit();
                    } else {
                        $stmt = $db->prepare('SELECT * FROM strings WHERE value = ?');
                        $stmt->execute([$value]);
                        $string = $stmt->fetch(PDO::FETCH_ASSOC);
                        if (!$string) {
                            http_response_code(404);
                            echo json_encode(['error' => 'String not found']);
                            exit();
                        }
                        echo json_encode([
                            'id' => $string['id'],
                            'value' => $string['value'],
                            'properties' => json_decode($string['properties'], true),
                            'created_at' => $string['created_at']
                        ]);
                    }
                }
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Endpoint not found']);
            }
            break;

        case 'DELETE':
            if ($parts[0] === 'strings' && count($parts) === 2) {
                // Delete String endpoint
                $value = urldecode($parts[1]);
                if (isset($store) && $store['type'] === 'json') {
                    $all = json_store_load($store['path']);
                    $originalCount = count($all);
                    $all = array_values(array_filter($all, function ($row) use ($value) { return $row['value'] !== $value; }));
                    if (count($all) === $originalCount) {
                        http_response_code(404);
                        echo json_encode(['error' => 'String not found']);
                        exit();
                    }
                    json_store_save($store['path'], $all);
                    http_response_code(204);
                } else {
                    $stmt = $db->prepare('DELETE FROM strings WHERE value = ?');
                    $stmt->execute([$value]);
                    if ($stmt->rowCount() === 0) {
                        http_response_code(404);
                        echo json_encode(['error' => 'String not found']);
                        exit();
                    }
                    http_response_code(204);
                }
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Endpoint not found']);
            }
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
    
    // Flush output buffer
    if (ob_get_level() > 0) {
        ob_end_flush();
    }
    
} catch (Exception $e) {
    error_log('Request error: ' . $e->getMessage() . ' | Code: ' . $e->getCode());
    $statusCode = $e->getCode();
    if ($statusCode < 400 || $statusCode >= 600) {
        $statusCode = 500;
    }
    http_response_code($statusCode);
    echo json_encode(['error' => $e->getMessage()]);
    
    if (ob_get_level() > 0) {
        ob_end_flush();
    }
}
?>