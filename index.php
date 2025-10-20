<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle OPTIONS request for CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Database connection and initialization
try {
    $dbPath = __DIR__ . '/strings.db';
    $dbExists = file_exists($dbPath);
    
    // Ensure directory is writable
    if (!is_writable(__DIR__)) {
        throw new Exception('Directory is not writable. Please check permissions.');
    }
    
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
        chmod($dbPath, 0666);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Database initialization failed',
        'details' => $e->getMessage(),
        'path' => $dbPath ?? __DIR__ . '/strings.db'
    ]);
    exit();
}

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
                $data = json_decode(file_get_contents('php://input'), true);
                $value = validateString($data['value'] ?? null);
                
                // Check if string already exists
                $stmt = $db->prepare('SELECT id FROM strings WHERE value = ?');
                $stmt->execute([$value]);
                if ($stmt->fetch()) {
                    http_response_code(409);
                    echo json_encode(['error' => 'String already exists']);
                    exit();
                }
                
                // Analyze and store string
                $properties = analyzeString($value);
                $stmt = $db->prepare('INSERT INTO strings (id, value, properties, created_at) VALUES (?, ?, ?, ?)');
                $stmt->execute([
                    $properties['sha256_hash'],
                    $value,
                    json_encode($properties),
                    gmdate('Y-m-d\TH:i:s\Z')
                ]);
                
                // Return response
                http_response_code(201);
                echo json_encode([
                    'id' => $properties['sha256_hash'],
                    'value' => $value,
                    'properties' => $properties,
                    'created_at' => gmdate('Y-m-d\TH:i:s\Z')
                ]);
            }
            break;

        case 'GET':
            if ($parts[0] === 'strings') {
                if (count($parts) === 1) {
                    // Get All Strings with Filtering endpoint
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
            break;

        case 'DELETE':
            if ($parts[0] === 'strings' && count($parts) === 2) {
                // Delete String endpoint
                $value = urldecode($parts[1]);
                $stmt = $db->prepare('DELETE FROM strings WHERE value = ?');
                $result = $stmt->execute([$value]);
                
                if ($stmt->rowCount() === 0) {
                    http_response_code(404);
                    echo json_encode(['error' => 'String not found']);
                    exit();
                }
                
                http_response_code(204);
            }
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>