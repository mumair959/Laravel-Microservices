# Auth Service

The Auth Service is a dedicated microservice for user authentication and authorization. It manages user registration, login, token-based authentication, and session management.

## Overview

The Auth Service provides API endpoints for:
- User registration
- User login with token generation
- Getting current authenticated user information
- User logout with token revocation
- Health check

## Architecture

```
Client → Auth Service (port 8003) → MySQL (auth_db)
```

## Configuration

### Environment Variables

Configure `.env` with MySQL credentials:

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=auth_db
DB_USERNAME=root
DB_PASSWORD=
```

### Database

The auth-service owns the `auth_db` database which contains:
- `users` table - User credentials and profile
- `personal_access_tokens` table - API authentication tokens

### Migrations

Run migrations to create tables:

```bash
php artisan migrate
```

## API Endpoints

### Health Check

**Endpoint:**
```
GET /api/health
```

**Response:**
```json
{
    "service": "auth-service",
    "status": "healthy"
}
```

### Register

**Endpoint:**
```
POST /api/v1/auth/register
```

**Request:**
```json
{
    "name": "John Doe",
    "email": "john@example.com",
    "password": "password123",
    "password_confirmation": "password123"
}
```

**Response (201):**
```json
{
    "success": true,
    "data": {
        "user": {
            "id": 1,
            "name": "John Doe",
            "email": "john@example.com"
        }
    }
}
```

**Validation Errors (422):**
```json
{
    "message": "The given data was invalid.",
    "errors": {
        "email": ["Email already exists"],
        "password": ["Password must be at least 8 characters"]
    }
}
```

### Login

**Endpoint:**
```
POST /api/v1/auth/login
```

**Request:**
```json
{
    "email": "john@example.com",
    "password": "password123"
}
```

**Response (200):**
```json
{
    "success": true,
    "data": {
        "user": {
            "id": 1,
            "name": "John Doe",
            "email": "john@example.com"
        },
        "token": "abcdef1234567890..."
    }
}
```

**Invalid Credentials (401):**
```json
{
    "success": false,
    "message": "Invalid credentials"
}
```

### Current User

**Endpoint:**
```
GET /api/v1/auth/me
```

**Headers:**
```
Authorization: Bearer <token>
```

**Response (200):**
```json
{
    "success": true,
    "data": {
        "user": {
            "id": 1,
            "name": "John Doe",
            "email": "john@example.com"
        }
    }
}
```

**Unauthenticated (401):**
```json
{
    "success": false,
    "message": "Unauthenticated"
}
```

### Logout

**Endpoint:**
```
POST /api/v1/auth/logout
```

**Headers:**
```
Authorization: Bearer <token>
```

**Response (200):**
```json
{
    "success": true,
    "message": "Logged out successfully"
}
```

**Unauthenticated (401):**
```json
{
    "success": false,
    "message": "Unauthenticated"
}
```

## Authentication

### Bearer Token

The Auth Service uses bearer token authentication. After login, the client receives a token that must be included in subsequent requests:

```
Authorization: Bearer <token>
```

### Token Storage

Tokens are stored in the `personal_access_tokens` table with:
- Hashed token value (stored securely)
- Associated user ID
- Creation timestamp
- Last used timestamp
- Expiration timestamp (optional)

### Token Generation

When a user logs in:
1. A new token is generated using cryptographically secure random bytes
2. The token is hashed using SHA-256
3. Only the hash is stored in the database
4. The plain text token is returned to the client once
5. The client must store and use the plain text token in future requests

### Token Validation

Protected endpoints validate tokens by:
1. Reading the Authorization header
2. Extracting the bearer token
3. Hashing the token
4. Looking up the hash in the database
5. Resolving the associated user
6. Rejecting invalid or missing tokens

### Logout

When a user logs out:
- All tokens for that user are deleted from the database
- The user must log in again to get a new token
- This invalidates all active sessions for that user

## Running the Service

### Start the Server

```bash
php artisan serve --port=8003
```

### Seed Test Data

```bash
php artisan migrate:fresh --seed
```

This creates test users:
- Email: john@example.com, Password: password123
- Email: jane@example.com, Password: password456
- Email: test@example.com, Password: testpassword
- Plus 5 random users

### View Routes

```bash
php artisan route:list
```

## Security Considerations

### Password Hashing

- All passwords are hashed using bcrypt (Laravel's default)
- Plain text passwords are never stored or returned
- Password hashes are never exposed in API responses

### Token Security

- Tokens are hashed before storage
- Plain text tokens are only shown once during login
- Tokens are validated on every protected request
- Expired tokens should be invalidated by the application

### Validation

- Email uniqueness is enforced
- Password confirmation required on registration
- Minimum password length of 8 characters
- Generic error messages for failed login (no email existence leaks)

### HTTPS

In production, always use HTTPS to prevent token interception:
- Never send tokens over HTTP
- Use secure cookies when applicable
- Implement CORS policies appropriately

## Integration with API Gateway

The Auth Service runs independently. Integration with the API Gateway (Sprint 5) will:
- Forward authentication requests to this service
- Validate tokens using this service's endpoints
- Protect downstream services with authentication middleware

## Database Schema

### users Table

```sql
- id (bigint, primary key)
- name (string)
- email (string, unique)
- email_verified_at (timestamp, nullable)
- password (string, hashed)
- remember_token (string, nullable)
- created_at (timestamp)
- updated_at (timestamp)
```

### personal_access_tokens Table

```sql
- id (bigint, primary key)
- tokenable_type (string, for polymorphic relation)
- tokenable_id (bigint, for polymorphic relation)
- name (string)
- token (string, unique, hashed)
- abilities (json)
- last_used_at (timestamp, nullable)
- expires_at (timestamp, nullable)
- created_at (timestamp)
- updated_at (timestamp)
```

## Error Handling

All endpoints return JSON responses with consistent format:

**Success:**
```json
{
    "success": true,
    "data": { ... }
}
```

or

```json
{
    "success": true,
    "message": "..."
}
```

**Error:**
```json
{
    "success": false,
    "message": "..."
}
```

**HTTP Status Codes:**
- 200 OK - Successful request
- 201 Created - Resource created
- 401 Unauthorized - Authentication failed or missing
- 422 Unprocessable Entity - Validation error
- 500 Internal Server Error - Server error

## Example Workflow

### 1. Register a User

```bash
curl -X POST http://127.0.0.1:8003/api/v1/auth/register \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Alice",
    "email": "alice@example.com",
    "password": "securepass123",
    "password_confirmation": "securepass123"
  }'
```

### 2. Login

```bash
curl -X POST http://127.0.0.1:8003/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "alice@example.com",
    "password": "securepass123"
  }'
```

Response includes token: `"token": "abc123def456..."`

### 3. Access Protected Endpoint

```bash
curl -X GET http://127.0.0.1:8003/api/v1/auth/me \
  -H "Authorization: Bearer abc123def456..."
```

### 4. Logout

```bash
curl -X POST http://127.0.0.1:8003/api/v1/auth/logout \
  -H "Authorization: Bearer abc123def456..."
```

## Development Notes

- No authentication logic in routes; all validation handled by Form Requests
- No complex design patterns; straightforward Laravel conventions
- Minimal dependencies; uses only Laravel built-in functionality
- One database per service; auth-service owns auth_db exclusively
- Tokens are independent of HTTP sessions
- Stateless API design; no session storage required

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
