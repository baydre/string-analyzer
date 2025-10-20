# String Analyzer API

A RESTful API service that analyzes strings and provides various properties such as length, palindrome status, unique characters count, and more. Built with PHP and SQLite.

## Features

- String analysis including:
  - Length calculation
  - Palindrome detection
  - Unique character counting
  - Word count
  - SHA-256 hash generation
  - Character frequency mapping
- CRUD operations for string management
- Advanced filtering capabilities
- Natural language query support (e.g., “single word palindromic strings”)
- Interactive API documentation with Swagger UI

## Requirements

- PHP 7.4 or higher
- SQLite3 PHP extension
- PDO PHP extension
- Apache with mod_rewrite (or use PHP’s built-in server with a router script)

## Installation

1. Clone the repository:
```bash
git clone https://github.com/baydre/string-analyzer.git
cd string-analyzer
```

2. Ensure required PHP extensions are installed:
```bash
sudo apt-get install php-sqlite3
```

3. Set proper permissions:
```bash
chmod 755 .
```

## Running Locally

Use PHP’s built-in server with the router script (so all routes go to index.php):
```bash
php -S localhost:8000 index.php
```

The API will be available at:
```
http://localhost:8000
```

Note: If you use Apache, the provided `.htaccess` routes requests to `index.php`.

## API Documentation

Interactive API documentation (Swagger UI):
```
http://localhost:8000/docs.html
```

OpenAPI spec:
```
http://localhost:8000/swagger.yaml
```

### API Endpoints

#### 1. Create/Analyze String
```http
POST /strings
Content-Type: application/json

{
  "value": "string to analyze"
}
```

Responses:
- `201` Created — returns analyzed string
- `409` Conflict — string already exists
- `400`/`422` — invalid body

#### 2. Get All Strings with Filtering
```http
GET /strings?is_palindrome=true&min_length=5&max_length=20&word_count=2&contains_character=a
```

Query parameters:
- is_palindrome: boolean
- min_length: integer
- max_length: integer
- word_count: integer
- contains_character: string (single character)

Response:
- `200` OK — `{ data, count, filters_applied }`

#### 3. Get Specific String
```http
GET /strings/{string_value}
```
Response:
- `200` OK — string details
- `404` Not Found

#### 4. Natural Language Filtering
```http
GET /strings/filter-by-natural-language?query=all%20single%20word%20palindromic%20strings
```

- Be sure to URL-encode the query value (spaces as `%20`).

Success response (200 OK):
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

Error responses:
- `400` — Missing query parameter
- `422` — Could not parse meaningful filters

Example queries supported:
- "all single word palindromic strings" → `word_count=1, is_palindrome=true`
- "strings longer than 10" → `min_length=11`
- "shorter than 8" → `max_length=7`
- "strings containing the letter z" → `contains_character=z`

#### 5. Delete String
```http
DELETE /strings/{string_value}
```
Responses:
- `204` No Content
- `404` Not Found

## Example Usage

1. Create a new string:
```bash
curl -X POST http://localhost:8000/strings \
  -H "Content-Type: application/json" \
  -d '{"value": "racecar"}'
```

2. Get all palindromes:
```bash
curl "http://localhost:8000/strings?is_palindrome=true"
```

3. Natural language query:
```bash
curl "http://localhost:8000/strings/filter-by-natural-language?query=all%20single%20word%20palindromic%20strings"
```

## Project Structure

```
string-analyzer/
├── index.php          # Main API implementation
├── swagger.yaml       # OpenAPI/Swagger specification
├── docs.html          # Swagger UI documentation
├── .htaccess          # Apache URL rewriting rules
├── strings.db         # SQLite database (auto-created)
└── README.md          # Project documentation
```

## Implementation Details

- SQLite database with JSON storage for string properties
- RESTful API design following HTTP standards
- CORS support for cross-origin requests
- Error handling with appropriate HTTP status codes
- URL-encoded string support
- Natural language query parsing with simple heuristics

## Error Handling

The API uses standard HTTP status codes:
- `200`: Success
- `201`: Created
- `204`: No Content (successful deletion)
- `400`: Bad Request
- `404`: Not Found
- `409`: Conflict (string already exists)
- `422`: Unprocessable Entity
- `500`: Internal Server Error

## License

This project is licensed under the MIT License - see the LICENSE file for details.
