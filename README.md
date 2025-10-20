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
- Natural language query support
- Interactive API documentation with Swagger UI

## Requirements

- PHP 7.4 or higher
- SQLite3 PHP extension
- PDO PHP extension
- Apache with mod_rewrite (or equivalent server with URL rewriting)

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

Start the PHP development server:
```bash
php -S localhost:8000
```

The API will be available at `http://localhost:8000`

## API Documentation

Interactive API documentation is available at:
```
http://localhost:8000/docs.html
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

#### 2. Get All Strings with Filtering
```http
GET /strings?is_palindrome=true&min_length=5&max_length=20&word_count=2&contains_character=a
```

#### 3. Get Specific String
```http
GET /strings/{string_value}
```

#### 4. Natural Language Filtering
```http
GET /strings/filter-by-natural-language?query=all single word palindromic strings
```

#### 5. Delete String
```http
DELETE /strings/{string_value}
```

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
curl "http://localhost:8000/strings/filter-by-natural-language?query=all single word palindromic strings"
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
- Natural language query parsing

## Error Handling

The API uses standard HTTP status codes:
- 200: Success
- 201: Created
- 204: No Content (successful deletion)
- 400: Bad Request
- 404: Not Found
- 409: Conflict (string already exists)
- 422: Unprocessable Entity
- 500: Internal Server Error

## License

This project is licensed under the MIT License - see the LICENSE file for details.
