# Laravel Microservices

A microservices-based e-commerce order management system built with Laravel.

## Services Overview

### Implemented Services

- **API Gateway** (:8000) - Entry point, authentication boundary, request forwarding
- **Auth Service** (:8003) - User authentication, token management
- **Product Service** (:8001) - Product catalog management
- **Order Service** (:8002) - Order management with user ownership

### Planned Services

- Payment Service
- Notification Service

## Tech Stack

- Laravel 13
- MySQL (per-service databases)
- Laravel API authentication with Bearer tokens

## Architecture Principle

Each service is independently deployable and owns its own database.
Services communicate through HTTP APIs with trusted headers.

## Authentication & Security Architecture

### Core Principle

**Auth Service is the ONLY service that owns users and authentication.**

Product and Order services do NOT have users tables.

### Authentication Flow

```
Client
   |
   | Authorization: Bearer <token>
   v
API Gateway :8000
   |
   | Validate token with Auth Service
   v
Auth Service :8003
   |
   | Return authenticated user info
   | (user_id, email, name)
   v
API Gateway
   |
   | Creates trusted internal headers:
   | X-User-Id: <authenticated_user_id>
   | X-User-Email: <authenticated_user_email>
   |
   +----------> Product Service :8001
   |
   +----------> Order Service :8002
```

### Key Security Rules

1. **Authentication Boundary**: Only the API Gateway validates Bearer tokens with Auth Service
2. **Trusted Headers**: Gateway creates `X-User-Id` and `X-User-Email` only after successful authentication
3. **Client Header Stripping**: Any client-provided identity headers are removed before forwarding
4. **User Ownership**: Order Service filters operations by authenticated user ID
5. **No Cross-Database Foreign Keys**: Services are isolated; relationships are logical only

### Protected Routes

#### Auth Service
```
POST   /api/v1/auth/register          (public)
POST   /api/v1/auth/login             (public)
GET    /api/v1/auth/me                (protected)
POST   /api/v1/auth/logout            (protected)
```

#### Product Service
```
GET    /api/v1/products               (protected)
POST   /api/v1/products               (protected)
GET    /api/v1/products/{id}          (protected)
PUT    /api/v1/products/{id}          (protected)
DELETE /api/v1/products/{id}          (protected)
```

#### Order Service
```
GET    /api/v1/orders                 (protected, user's own orders only)
POST   /api/v1/orders                 (protected, creates with authenticated user)
GET    /api/v1/orders/{id}            (protected, user's own order only)
```

#### Public Health
```
GET    /api/health                    (public - all services)
```

### Order Ownership Model

Orders table stores `user_id` but does NOT have a foreign key to `auth_db.users`.

```
auth_db.users
   |
   | user_id (logical relationship, no FK)
   v
order_db.orders.user_id
```

When creating an order:
- Client submits: `{ "items": [{"product_id": 1, "quantity": 2}] }`
- Gateway validates token, extracts `user_id` from Auth Service
- Gateway forwards request with header: `X-User-Id: 15`
- Order Service uses `X-User-Id` header value, NOT request body

When listing orders:
- Order Service filters: `WHERE user_id = X-User-Id header value`
- User sees only their own orders

When retrieving a specific order:
- Order Service checks: `WHERE id = requested_id AND user_id = X-User-Id header value`
- Returns 404 if order belongs to another user (no information leakage)

### API Response Format

All endpoints use consistent JSON responses:

#### Success
```json
{
    "success": true,
    "data": { ... }
}
```

#### Error
```json
{
    "success": false,
    "message": "Error description"
}
```

### HTTP Status Codes
- `200` - OK
- `201` - Created
- `400` - Bad Request / Invalid input
- `401` - Unauthorized / Invalid token
- `404` - Resource not found
- `422` - Unprocessable Entity / Validation error
- `503` - Service Unavailable

### Database Schema

Each service has its own database:

**auth_db**
- users (id, name, email, password, timestamps)
- personal_access_tokens (id, tokenable_id, tokenable_type, name, token, abilities, last_used_at, created_at)

**product_db**
- products (id, name, sku, price, stock, status, timestamps)

**order_db**
- orders (id, user_id, status, total_amount, timestamps)
- order_items (id, order_id, product_id, product_name, quantity, unit_price, total_price)

Note: product_db and order_db have NO users table.

## Sprint 7 — Resilience & Reliability

Sprint 7 introduces mechanisms to make the microservices more reliable when services fail, requests are duplicated, or network communication is interrupted.

### HTTP Timeouts

All service-to-service HTTP communication has explicit timeouts configured via environment variables:

```env
HTTP_TIMEOUT=10
HTTP_CONNECT_TIMEOUT=5
```

When a timeout occurs:
- The request is abandoned
- An exception is thrown
- The queue job can retry the operation
- The API client receives a 503 Service Unavailable response

### Correlation IDs

Every request is tracked with a `X-Correlation-ID` header that propagates through the entire system:

```
Client Request
  ↓
  X-Correlation-ID: 550e8400-e29b-41d4-a716-446655440000
  ↓
API Gateway (validates or generates)
  ↓
  X-Correlation-ID: 550e8400-e29b-41d4-a716-446655440000
  ↓
Order Service → Product Service (forward in request)
  ↓
Queue Jobs (preserve in payload)
  ↓
Product Service Worker (use in logging)
```

The correlation ID allows tracing a single user request across all services and workers.

**Providing a custom Correlation ID:**
```bash
curl -X POST http://127.0.0.1:8000/api/v1/orders \
  -H "Authorization: Bearer <token>" \
  -H "X-Correlation-ID: my-custom-id-123" \
  -H "Content-Type: application/json" \
  -d '{"items": [{"product_id": 1, "quantity": 2}]}'
```

**Response includes the Correlation ID:**
```json
{
  "success": true,
  "data": { ... }
}
```

Response headers include:
```
X-Correlation-ID: my-custom-id-123
```

### Idempotency for Order Creation

Order creation supports the `Idempotency-Key` header to prevent accidental duplicate orders:

```bash
curl -X POST http://127.0.0.1:8000/api/v1/orders \
  -H "Authorization: Bearer <token>" \
  -H "Idempotency-Key: 550e8400-e29b-41d4-a716-446655440000" \
  -H "Content-Type: application/json" \
  -d '{"items": [{"product_id": 1, "quantity": 2}]}'
```

**Behavior:**

1. **First request with key ABC:**
   - Order is created
   - Response is cached
   - Status: 201 Created

2. **Identical second request with key ABC:**
   - Order is NOT created again
   - Cached response is returned
   - Response header: `Idempotency-Replayed: true`
   - Status: 201 Created (same as first)

3. **Different request with same key ABC:**
   - Conflict detected
   - Status: 409 Conflict
   - Response: `"message": "Idempotency key has already been used with a different request."`

**How it works:**
- The idempotency key and request hash are stored in `order_db.idempotency_keys` table
- Unique constraint: `(user_id, key)` - each user can have one key
- On retry, the request hash is compared to detect conflicts
- Guaranteed to work correctly even with concurrent requests

### Health Endpoints

Every service exposes a health check endpoint:

```bash
GET /api/health
```

**Response (healthy):**
```json
{
  "success": true,
  "service": "order-service",
  "status": "healthy"
}
```
Status: `200 OK`

**Response (unhealthy):**
```json
{
  "success": false,
  "service": "order-service",
  "status": "unhealthy"
}
```
Status: `503 Service Unavailable`

Health checks verify:
- Application is running
- Database connection is available

### Queue Reliability

#### Database Queue

Queues use Laravel's database driver with the following configuration:

```env
QUEUE_CONNECTION=database
ORDER_QUEUE=order-processing
PRODUCT_QUEUE=product-processing
```

#### Job Configuration

Each queued job has:
- **tries**: Maximum number of attempts (default: 3)
- **backoff**: Seconds to wait before retrying (default: 60)
- **timeout**: Maximum execution time in seconds (default: 30)

#### Failed Jobs

If a job exhausts all retries:
1. It's moved to `failed_jobs` table
2. A critical log message is recorded
3. Manual intervention is required

**View failed jobs:**
```bash
php artisan queue:failed
```

**Retry a failed job:**
```bash
php artisan queue:retry <id>
```

**Retry all failed jobs:**
```bash
php artisan queue:retry all
```

#### Event Processing

When an order is created:
1. Order and items are created in a database transaction
2. OrderCreated event is dispatched
3. PublishOrderCreated job is queued
4. Worker sends event to Product Service
5. Product Service queues ProcessOrderCreated job
6. ProcessOrderCreated worker updates stock

Each step can be retried independently if it fails.

### Event Idempotency

Product Service processes OrderCreated events idempotently:

1. **First event with ID ABC:**
   - Stock is deducted
   - Event is recorded in `processed_events` table
   - Status: Successful

2. **Duplicate event with same ID ABC:**
   - ProcessedEvent record is found
   - Stock is NOT deducted again
   - Event is skipped
   - Status: Skipped (logged as info)

This guarantees stock is never deducted twice, even if the event is processed multiple times.

### Structured Logging

All important events are logged with structured context:

```json
{
  "service": "order-service",
  "correlation_id": "550e8400-e29b-41d4-a716-446655440000",
  "user_id": 15,
  "order_id": 100,
  "event_id": "xyz-456",
  "message": "Order created successfully"
}
```

**Logged Events:**
- Order created
- Order creation failed
- Product Service timeout/failure
- OrderCreated event dispatched
- OrderCreated event published
- OrderCreated event processed
- Product stock updated
- Product stock update failed
- Queue job attempts
- Queue job failures

**Never Logged:**
- Passwords
- Authentication tokens
- API keys
- Database credentials
- Service secrets

### Error Handling

All services return consistent error responses:

#### Validation Error (422)
```json
{
  "success": false,
  "message": "Validation failed.",
  "errors": {
    "items": ["The items field is required."]
  }
}
```

#### Not Found (404)
```json
{
  "success": false,
  "message": "Resource not found."
}
```

#### Unauthenticated (401)
```json
{
  "success": false,
  "message": "Unauthenticated"
}
```

#### Service Unavailable (503)
```json
{
  "success": false,
  "message": "Service temporarily unavailable."
}
```

Errors are designed to be:
- **Safe**: Never expose stack traces, SQL queries, or internal details
- **Informative**: Provide enough detail for the client to act
- **Consistent**: All services use the same format

### Running Queue Workers

The system requires separate queue workers for each service:

**Order Service Worker:**
```bash
cd order-service
php artisan queue:work database --queue=order-processing
```

**Product Service Worker:**
```bash
cd product-service
php artisan queue:work database --queue=product-processing
```

Keep each worker running in a separate terminal or process manager (systemd, supervisor, etc.).

### Environment Configuration

Update `.env` files with timeout and queue configuration:

```env
# HTTP Timeouts
HTTP_TIMEOUT=10
HTTP_CONNECT_TIMEOUT=5

# Queue Configuration
QUEUE_CONNECTION=database
ORDER_QUEUE=order-processing
PRODUCT_QUEUE=product-processing

# Service URLs
PRODUCT_SERVICE_URL=http://127.0.0.1:8001
ORDER_SERVICE_URL=http://127.0.0.1:8002
AUTH_SERVICE_URL=http://127.0.0.1:8003
```

### Example Workflow

1. **Client creates order:**
   ```bash
   curl -X POST http://127.0.0.1:8000/api/v1/orders \
     -H "Authorization: Bearer <token>" \
     -H "Idempotency-Key: abc-123" \
     -H "X-Correlation-ID: xyz-789" \
     -H "Content-Type: application/json" \
     -d '{"items": [{"product_id": 1, "quantity": 2}]}'
   ```

2. **Order Service receives request:**
   - Validates authentication (via Gateway)
   - Validates order items
   - Checks Product Service for stock

3. **Order is created:**
   - Database transaction ensures atomicity
   - Idempotency response is stored
   - Correlation ID is preserved

4. **Queue job is dispatched:**
   - PublishOrderCreated job is queued
   - Correlation ID is included in payload

5. **Worker processes the job:**
   - Publishes OrderCreated event to Product Service
   - Logs with correlation ID
   - Retries if timeout (up to 3 times)

6. **Product Service receives event:**
   - Validates event signature
   - Processes event asynchronously
   - Checks for duplicate events

7. **Stock is updated:**
   - Database transaction ensures consistency
   - Negative stock is prevented
   - Processed event is recorded

8. **Response sent to client:**
   - Order created successfully
   - Includes X-Correlation-ID header
   - Client can track the order through logs

### Testing

Run the test suite:

```bash
# Order Service tests
cd order-service
php artisan test

# Product Service tests
cd product-service
php artisan test

# Auth Service tests
cd auth-service
php artisan test

# API Gateway tests
cd api-gateway
php artisan test
```

Test coverage includes:
- Correlation ID propagation
- Idempotency key handling
- Health endpoints
- Error response formatting
- Duplicate event handling
- Queue retry behavior

### Monitoring

Check service health:
```bash
# API Gateway
curl http://127.0.0.1:8000/api/health

# Auth Service
curl http://127.0.0.1:8003/api/health

# Product Service
curl http://127.0.0.1:8001/api/health

# Order Service
curl http://127.0.0.1:8002/api/health
```

Check failed jobs:
```bash
cd order-service
php artisan queue:failed

cd product-service
php artisan queue:failed
```

View logs:
```bash
# Recent logs
tail -f order-service/storage/logs/laravel.log
tail -f product-service/storage/logs/laravel.log
```

### Performance Considerations

- Order creation completes quickly (does not wait for stock processing)
- Stock updates happen asynchronously via queue workers
- Health checks are lightweight (only verify database connection)
- Idempotency keys have unique constraints for data consistency
- Timeouts prevent requests from hanging indefinitely

### Security Considerations

- Correlation IDs contain no sensitive data
- Service secrets are never logged
- Timeouts prevent denial-of-service attacks
- Failed authentication attempts are logged
- Invalid tokens are rejected immediately
- All inter-service communication includes service secret validation