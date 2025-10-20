# String Analyzer Service

A RESTful API service built with PHP and SQLite, deployable to PXXL/Infinityfree hosting platforms.

## Features

For each analyzed string, the service computes and stores the following properties:

- **length**: Number of characters in the string
- **is_palindrome**: Boolean indicating if the string reads the same forwards and backwards (case-insensitive)
- **unique_characters**: Count of distinct characters in the string
- **word_count**: Number of words separated by whitespace
- **sha256_hash**: SHA-256 hash of the string for unique identification
- **character_frequency_map**: Object/dictionary mapping each character to its occurrence count

## API Endpoints

### 1. Create/Analyze String

```http
POST /strings
Content-Type: application/json
```

**Request Body:**
```json
{
  "value": "string to analyze"
}
```

**Success Response** (201 Created):
```json
{
  "id": "sha256_hash_value",
  "value": "string to analyze",
  "properties": {
    "length": 16,
    "is_palindrome": false,
    "unique_characters": 12,
    "word_count": 3,
    "sha256_hash": "abc123...",
    "character_frequency_map": {
      "s": 2,
      "t": 3,
      "r": 2
    }
  },
  "created_at": "2025-08-27T10:00:00Z"
}
```

**Error Responses:**
- `409` Conflict: String already exists in the system
- `400` Bad Request: Invalid request body or missing "value" field
- `422` Unprocessable Entity: Invalid data type for "value" (must be string)
### 2. Get Specific String

```http
GET /strings/{string_value}
```

**Success Response** (200 OK):
```json
{
  "id": "sha256_hash_value",
  "value": "requested string",
  "properties": { /* same as above */ },
  "created_at": "2025-08-27T10:00:00Z"
}
```

**Error Response:**
- `404` Not Found: String does not exist in the system
### 3. Get All Strings with Filtering

```http
GET /strings?is_palindrome=true&min_length=5&max_length=20&word_count=2&contains_character=a
```

**Success Response** (200 OK):
```json
{
  "data": [
    {
      "id": "hash1",
      "value": "string1",
      "properties": { /* ... */ },
      "created_at": "2025-08-27T10:00:00Z"
    }
  ],
  "count": 15,
  "filters_applied": {
    "is_palindrome": true,
    "min_length": 5,
    "max_length": 20,
    "word_count": 2,
    "contains_character": "a"
  }
}
```

**Query Parameters:**
- `is_palindrome`: boolean (true/false)
- `min_length`: integer (minimum string length)
- `max_length`: integer (maximum string length)
- `word_count`: integer (exact word count)
- `contains_character`: string (single character to search for)

**Error Response:**
- `400` Bad Request: Invalid query parameter values or types
### 4. Natural Language Filtering

```http
GET /strings/filter-by-natural-language?query=all%20single%20word%20palindromic%20strings
```

**Success Response** (200 OK):
```json
{
  "data": [ /* array of matching strings */ ],
  "count": 3,
  "interpreted_query": {
    "original": "all single word palindromic strings",
    "parsed_filters": {
      "word_count": 1,
      "is_palindrome": true
    }
  }
}
```

**Example Queries Supported:**
- "all single word palindromic strings" → `word_count=1, is_palindrome=true`
- "strings longer than 10 characters" → `min_length=11`
- "palindromic strings that contain the first vowel" → `is_palindrome=true, contains_character=a`
- "strings containing the letter z" → `contains_character=z`

**Error Responses:**
- `400` Bad Request: Unable to parse natural language query
- `422` Unprocessable Entity: Query parsed but resulted in conflicting filters
### 5. Delete String

```http
DELETE /strings/{string_value}
```

**Success Response:**
- `204` No Content (Empty response body)

**Error Response:**
- `404` Not Found: String does not exist in the system

## Submission Requirements

1. Implementation can be in any language of choice (e.g., Fortran, C, Assembly, etc.)
2. Host the API on approved platforms:
   - Railway
   - Heroku
   - AWS
   - PXXL App
   - Note: Vercel and Render are not accepted

3. GitHub Repository must include:
   - Clear README with setup instructions
   - Instructions to run locally
   - List of dependencies and installation steps
   - Environment variables documentation (if any)
   - Test endpoints documentation
   - API documentation
   - Relevant tests and notes

Nice — below is a complete, runnable plan you can follow in VS Code. I’ll give you:

## Project layout

A ready-to-paste single-file PHP implementation (using SQLite) that implements all endpoints and required behaviours

Database migration / schema

Exact endpoint examples and curl tests

Natural-language filter heuristics

Error handling notes, deployment guidance and README content you can copy to your repo

This is intentionally done with vanilla PHP + PDO + SQLite so it’s lightweight, portable, and easy to deploy to hosts that accept PHP apps (Railway, Heroku, PXXL). SQLite keeps state without a separate DB server.

### 1) Project structure
```
string-analyzer/
│
├── index.php             # main API
├── strings.db            # SQLite DB (created automatically on first run)
├── README.md
└── .htaccess             # optional for Apache to route clean endpoints
```
### 2) Quick design notes (requirements → implementation choices)

- Storage: SQLite table strings with id (sha256), value, properties fields and created_at.

- Unique id: sha256_hash (hash('sha256', $value)) used as id in responses.

- character_frequency_map stored as JSON string in DB, returned as object in responses.

- timestamp uses UTC ISO 8601: gmdate("Y-m-d\TH:i:s\Z")

- No caching: each POST computes properties and stores if not exists.

- Natural language filtering: simple heuristic parser using regex & keywords; supports examples in the spec (single-word palindromes, min length, contains character, strings containing 'z', etc.). If parsing fails, returns 400.