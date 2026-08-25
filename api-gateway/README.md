# API Gateway

The API Gateway is a centralized entry point that forwards client requests to microservices. It also acts as the authentication boundary, validating Bearer tokens and creating trusted internal headers for downstream services.

## Purpose

The API Gateway:
- Acts as the single entry point for all client requests
- Validates Bearer tokens with Auth Service
- Forwards requests to appropriate microservices
- Creates trusted internal headers (X-User-Id, X-User-Email)
- Strips client-provided identity headers to prevent spoofing
- Handles error scenarios gracefully (timeouts, connection failures)
- Maintains separation of concerns between services

## Authentication

### Public Routes (No Authentication Required)
```
POST   /api/v1/auth/register
POST   /api/v1/auth/login
GET    /api/health
```

### Protected Routes (Bearer Token Required)
```
GET    /api/v1/auth/me
POST   /api/v1/auth/logout
GET    /api/v1/products
POST   /api/v1/products
GET    /api/v1/products/{id}
PUT    /api/v1/products/{id}
DELETE /api/v1/products/{id}
GET    /api/v1/orders
POST   /api/v1/orders
GET    /api/v1/orders/{id}
```

### Authentication Flow

1. Client sends request with `Authorization: Bearer <token>` header
2. Gateway checks if route is protected
3. If protected:
   - Extract Bearer token from Authorization header
   - Validate token by calling Auth Service `/api/v1/auth/me`
   - If valid, extract user info (id, email, name)
   - Store authenticated user data in request
4. Gateway forwards request to downstream service with trusted headers:
   - `X-User-Id: <user_id>`
   - `X-User-Email: <user_email>`
5. Downstream service receives authenticated user context

### Security Features

- **Token Validation**: All tokens validated with Auth Service
- **Client Header Stripping**: Headers like `X-User-Id` from clients are removed before forwarding
- **Trusted Header Creation**: Only Gateway creates authenticated user headers after validation
- **Service Unavailability**: Returns 503 if Auth Service is unavailable
- **Token Extraction**: Supports only `Bearer <token>` format

## Architecture

```
Client
   |
   | Authorization: Bearer <token>
   v
API Gateway :8000
   |
   | Authenticate with Auth Service :8003
   |
   +---> Auth Service
   |     (validate token, get user)
   |
   | X-User-Id header added
   v
Downstream Service
├─ Auth Service :8003
├─ Product Service :8001
└─ Order Service :8002
```

## Middleware

**AuthenticateWithAuthService** - Validates Bearer tokens with Auth Service
- Location: `app/Http/Middleware/AuthenticateWithAuthService.php`
- Registered as: `auth.service`
- Applied to protected routes

## Configuration

**services.php**
```php
'auth_service_url' => env('AUTH_SERVICE_URL', 'http://127.0.0.1:8003'),
'product_service_url' => env('PRODUCT_SERVICE_URL', 'http://127.0.0.1:8001'),
'order_service_url' => env('ORDER_SERVICE_URL', 'http://127.0.0.1:8002'),
```

## Testing

Run tests:
```bash
php artisan test
```

Test coverage:
- Authentication tests
- User context forwarding
- Client header stripping
- Protected route access control
```

## Service URLs

Configure these in your `.env` file:

```
PRODUCT_SERVICE_URL=http://127.0.0.1:8001
ORDER_SERVICE_URL=http://127.0.0.1:8002
AUTH_SERVICE_URL=http://127.0.0.1:8003
```

## Running the Gateway

Start the API Gateway on port 8000:

```bash
php artisan serve --port=8000
```

## Available Gateway Endpoints

### Health Check

**GET** `/api/health`

Returns the health status of the gateway.

**Response:**
```json
{
  "service": "api-gateway",
  "status": "healthy"
}
```

### Product Service Forwarding

All requests to product endpoints are forwarded to the Product Service:

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/products` | Get all products |
| POST | `/api/v1/products` | Create a new product |
| GET | `/api/v1/products/{id}` | Get product by ID |
| PUT | `/api/v1/products/{id}` | Update product |
| DELETE | `/api/v1/products/{id}` | Delete product |

### Order Service Forwarding

All requests to order endpoints are forwarded to the Order Service:

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/orders` | Get all orders |
| POST | `/api/v1/orders` | Create a new order |
| GET | `/api/v1/orders/{id}` | Get order by ID |

### Auth Service Forwarding

All requests to auth endpoints are forwarded to the Auth Service:

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/v1/auth/register` | Register a new user |
| POST | `/api/v1/auth/login` | Login and get authentication token |
| GET | `/api/v1/auth/me` | Get current authenticated user (requires token) |
| POST | `/api/v1/auth/logout` | Logout and revoke token (requires token) |

## Example Requests

### Get All Products

```bash
curl http://127.0.0.1:8000/api/v1/products
```

### Create a Product

```bash
curl -X POST http://127.0.0.1:8000/api/v1/products \
  -H "Content-Type: application/json" \
  -d '{"name": "Product Name", "price": 100}'
```

### Get Product by ID

```bash
curl http://127.0.0.1:8000/api/v1/products/1
```

### Update a Product

```bash
curl -X PUT http://127.0.0.1:8000/api/v1/products/1 \
  -H "Content-Type: application/json" \
  -d '{"name": "Updated Name", "price": 150}'
```

### Delete a Product

```bash
curl -X DELETE http://127.0.0.1:8000/api/v1/products/1
```

### Get All Orders

```bash
curl http://127.0.0.1:8000/api/v1/orders
```

### Create an Order

```bash
curl -X POST http://127.0.0.1:8000/api/v1/orders \
  -H "Content-Type: application/json" \
  -d '{"customer": "John Doe", "total": 500}'
```

### Get Order by ID

```bash
curl http://127.0.0.1:8000/api/v1/orders/1
```

### Register a New User

```bash
curl -X POST http://127.0.0.1:8000/api/v1/auth/register \
  -H "Content-Type: application/json" \
  -d '{
    "name": "John Doe",
    "email": "john@example.com",
    "password": "password123",
    "password_confirmation": "password123"
  }'
```

### Login

```bash
curl -X POST http://127.0.0.1:8000/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "john@example.com",
    "password": "password123"
  }'
```

Response includes a `token` field:
```json
{
  "success": true,
  "data": {
    "user": {
      "id": 1,
      "name": "John Doe",
      "email": "john@example.com"
    },
    "token": "abc123def456..."
  }
}
```

### Get Current User (Protected)

```bash
curl http://127.0.0.1:8000/api/v1/auth/me \
  -H "Authorization: Bearer abc123def456..."
```

### Logout (Protected)

```bash
curl -X POST http://127.0.0.1:8000/api/v1/auth/logout \
  -H "Authorization: Bearer abc123def456..."
```

## Error Handling

The gateway handles various error scenarios:

- **503 Service Unavailable**: Returned when a downstream service is unreachable or connection fails
- **504 Gateway Timeout**: Returned when a downstream service request times out (30 seconds)
- **4xx/5xx**: Downstream service errors are forwarded as-is

Example error response:

```json
{
  "message": "Service unavailable"
}
```

## Testing

Run the feature tests to verify the gateway is working correctly:

```bash
php artisan test
```

Tests use Laravel HTTP fakes and do not require the downstream services to be running.

### Running Specific Tests

```bash
php artisan test tests/Feature/GatewayTest.php
```

## Verification

Verify the setup with:

```bash
# List all routes
php artisan route:list

# Run tests
php artisan test

# Start the server
php artisan serve --port=8000
```

Then verify the health endpoint:

```bash
curl http://127.0.0.1:8000/api/health
```

## Request Forwarding

The gateway forwards:
- HTTP method (GET, POST, PUT, DELETE, etc.)
- Request body (JSON, form data)
- Query parameters
- Relevant headers (content-type, accept, authorization)

## Implementation Details

- Built with Laravel 13
- Uses Laravel's built-in HTTP Client for forwarding requests
- No database required for the gateway itself
- Simple, convention-based routing
- No external dependencies beyond Laravel

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
