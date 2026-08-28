# Order Service

Laravel 13 REST service for creating and reading orders. Product details are obtained from Product Service at `http://127.0.0.1:8001`. User authentication is managed through the API Gateway and Auth Service.

## Key Features

- Order management with user ownership
- User-isolated order listing (users only see their own orders)
- Order validation with Product Service integration
- Automatic user_id assignment from authenticated Gateway header
- **Event-driven asynchronous stock management via Laravel queues**
- **OrderCreated events dispatched after successful order creation**
- **Automatic event delivery to Product Service with retry logic**

## Setup

Requirements: PHP 8.3+, Composer, and MySQL.

```bash
composer install
copy .env.example .env
php artisan key:generate
```

Create a separate MySQL database named `order_db`. Configure `.env`:

```dotenv
APP_URL=http://127.0.0.1:8002
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=order_db
DB_USERNAME=root
DB_PASSWORD=
PRODUCT_SERVICE_URL=http://127.0.0.1:8001
QUEUE_CONNECTION=database
ORDER_QUEUE=order-processing
PRODUCT_SERVICE_EVENT_SECRET=local-development-secret-change-in-production
```

Run the migrations (creates orders, order_items, jobs, and failed_jobs tables):

```bash
php artisan migrate
```

Start Order Service on port 8002:

```bash
php artisan serve --port=8002
```

## Queue Configuration

This service uses Laravel's **database queue driver** for event-driven communication with Product Service.

### Queue Setup

- **Queue Connection**: `database` (not Redis, Kafka, or RabbitMQ)
- **Queue Name**: `order-processing`
- **Queue Tables**: `jobs`, `failed_jobs` (in `order_db`)

### Queue Environment Variables

```dotenv
QUEUE_CONNECTION=database
ORDER_QUEUE=order-processing
PRODUCT_SERVICE_URL=http://127.0.0.1:8001
PRODUCT_SERVICE_EVENT_SECRET=<local-secret-must-match-product-service>
```

### Running the Queue Worker

Start the queue worker in a separate terminal:

```bash
cd order-service
php artisan queue:work database --queue=order-processing
```

The worker will process `PublishOrderCreated` jobs and deliver OrderCreated events to Product Service.

### Retry Configuration

- **Max Tries**: 3 attempts
- **Backoff**: 60 seconds between retries
- After 3 failed attempts, the job moves to `failed_jobs`

### Failed Jobs

View failed jobs:

```bash
php artisan queue:failed
```

Retry a failed job:

```bash
php artisan queue:retry <id>
```

Forget a failed job:

```bash
php artisan queue:forget <id>
```

## Event-Driven Architecture

### OrderCreated Event

When an order is successfully created, an `OrderCreated` event is dispatched:

```json
{
    "event_id": "550e8400-e29b-41d4-a716-446655440000",
    "event": "OrderCreated",
    "order_id": 100,
    "user_id": 15,
    "items": [
        {
            "product_id": 1,
            "quantity": 2
        },
        {
            "product_id": 5,
            "quantity": 1
        }
    ],
    "total_amount": 2500.00
}
```

Each event has a unique `event_id` (UUID) to enable idempotent processing.

### Order Creation Flow

1. **Client Request** → Order API endpoint
2. **Validation** → Product Service stock check (synchronous)
3. **Order Saved** → Database transaction commits
4. **Event Dispatched** → `PublishOrderCreated` job queued
5. **API Response** → 201 Created (returns immediately)
6. **Queue Worker** → Processes job asynchronously
7. **HTTP Delivery** → Sends event to Product Service
8. **Product Service** → Receives and processes event asynchronously

### PublishOrderCreated Job

The `PublishOrderCreated` job is responsible for:

1. Receiving order details from OrderCreated event
2. Sending HTTP POST to `POST /api/internal/events/order-created` on Product Service
3. Including service authentication header `X-Service-Secret`
4. Handling network failures and timeouts
5. Allowing Laravel queue system to retry on failure

Service-to-service authentication:

```
POST /api/internal/events/order-created HTTP/1.1
Host: 127.0.0.1:8001
Content-Type: application/json
X-Service-Secret: <PRODUCT_SERVICE_EVENT_SECRET>

{
    "event_id": "...",
    "event": "OrderCreated",
    "order_id": 100,
    ...
}
```

### Eventual Consistency

**Important**: Stock updates are **NOT** synchronous.

- Order creation succeeds immediately
- Product stock is updated asynchronously
- There is a time lag between order creation and stock reduction
- This is intentional for performance and resilience

Example timeline:

```
T0: Order created (API returns 201)
T1: OrderCreated job queued
T2: Queue worker processes job
T3: Event sent to Product Service
T4: Product Service receives event
T5: Product Service queues stock update job
T6: Product Service worker updates stock
```

Users are not expected to see updated stock immediately after order creation.

## API

All endpoints require authentication via Gateway except `/api/health`.

| Method | Endpoint | Description | Auth Required |
| --- | --- | --- | --- |
| GET | `/api/health` | Health check | No |
| GET | `/api/v1/orders` | List user's orders with pagination | Yes |
| POST | `/api/v1/orders` | Create an order for authenticated user | Yes |
| GET | `/api/v1/orders/{id}` | Show user's order (returns 404 if not owner) | Yes |

## User Ownership

**Important**: This service does NOT have a users table. Users are owned and authenticated by Auth Service.

### Order Ownership Rules

- Orders are automatically assigned to the authenticated user via `X-User-Id` header
- Users can only see/create their own orders
- Client cannot specify `user_id` in request body
- Requesting another user's order returns 404 (no information leakage)

### Order Creation

Request body contains only order items, NOT user_id:

```json
{
    "items": [
        {
            "product_id": 1,
            "quantity": 2
        }
    ]
}
```

The authenticated `user_id` comes from the Gateway header `X-User-Id`.

Order is created with:
- `user_id` = X-User-Id header value (from Gateway)
- `status` = 'pending'
- `total_amount` = calculated from items

### Order Listing

`GET /api/v1/orders` returns only orders where `user_id = X-User-Id` header value.

Pagination applied. Each order includes items.

### Order Details

`GET /api/v1/orders/{id}` returns the order only if:
- Order exists AND
- Order's `user_id` = X-User-Id header value

Otherwise returns 404.

## Authentication Headers

All requests through the Gateway include trusted headers:

```
X-User-Id: 15
X-User-Email: john@example.com
```

These headers are:
- Created by the Gateway after authentication
- NOT provided by the client
- Used to determine order ownership

## Order Request Examples

### Create Order (via Gateway)

```bash
curl -X POST http://127.0.0.1:8000/api/v1/orders \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{
    "items": [
      {"product_id": 1, "quantity": 2},
      {"product_id": 2, "quantity": 1}
    ]
  }'
```

Gateway validates token, then forwards with:
- `X-User-Id: <authenticated_user_id>`
- `X-User-Email: <authenticated_user_email>`

### List Orders (via Gateway)

```bash
curl http://127.0.0.1:8000/api/v1/orders \
  -H "Authorization: Bearer <token>"
```

Returns paginated list of authenticated user's orders only.

### Show Order (via Gateway)

```bash
curl http://127.0.0.1:8000/api/v1/orders/1 \
  -H "Authorization: Bearer <token>"
```

Returns order only if authenticated user owns it.

## Database Schema

```sql
CREATE TABLE orders (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT NOT NULL,
    status VARCHAR(255) DEFAULT 'pending',
    total_amount DECIMAL(10, 2),
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);

CREATE TABLE order_items (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    order_id BIGINT NOT NULL,
    product_id BIGINT NOT NULL,
    product_name VARCHAR(255),
    quantity INT,
    unit_price DECIMAL(10, 2),
    total_price DECIMAL(10, 2),
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id)
);
```

Note: `user_id` in orders table is NOT a foreign key. It's a logical reference to `auth_db.users.id`.

## Tests

Feature tests use the SQLite in-memory database and fake Product Service HTTP responses:

```bash
php artisan test
```

Test coverage includes:
- Order creation with authenticated user
- User cannot override user_id in request
- User can list only their own orders
- User cannot see another user's orders
- User can view only their own order
- Missing X-User-Id header returns 401

## Observability & Monitoring

### Correlation IDs

Every request includes a unique correlation ID that flows through the entire system:

```bash
# Request with custom correlation ID
curl -X POST http://127.0.0.1:8000/api/v1/orders \
  -H "Authorization: Bearer <token>" \
  -H "X-Correlation-ID: my-trace-123" \
  -H "Content-Type: application/json" \
  -d '{"items": [{"product_id": 1, "quantity": 2}]}'
```

The correlation ID is:
- Generated if not provided
- Included in all logs
- Forwarded to Product Service
- Preserved in queue jobs
- Returned in response headers

### Structured Logging

All important operations log with structured context:

**Order creation:**
```
service: order-service
correlation_id: abc-123
user_id: 15
order_id: 100
message: Order created successfully
```

**Event dispatch:**
```
service: order-service
correlation_id: abc-123
order_id: 100
message: OrderCreated event dispatched
```

**Service-to-service call:**
```
service: order-service
correlation_id: abc-123
target_service: product-service
status: 200
duration_ms: 45
message: Service-to-service request succeeded
```

### Request Logging

Every HTTP request is logged with:
- HTTP method (POST, GET, etc.)
- Path (/api/v1/orders)
- Response status
- Duration in milliseconds
- Correlation ID

Health check requests are excluded to avoid log spam.

### Queue Job Monitoring

The `PublishOrderCreated` job logs:
1. Job started
2. Event delivery initiated
3. Event delivery succeeded/failed
4. Retry attempts
5. Job completion or failure

Example:
```
Publishing OrderCreated event started
Sending event to Product Service
OrderCreated event delivered successfully
```

### Health Check

```bash
curl http://127.0.0.1:8002/api/health
```

Healthy response:
```json
{
  "success": true,
  "service": "order-service",
  "status": "healthy"
}
```

Unhealthy response (when database is unavailable):
```json
{
  "success": false,
  "service": "order-service",
  "status": "unhealthy"
}
```

### Viewing Logs

View recent logs:
```bash
tail -f storage/logs/laravel.log
```

Search logs by correlation ID:
```bash
grep "abc-123" storage/logs/laravel.log
```

Search for errors:
```bash
grep "error\|Error\|ERROR" storage/logs/laravel.log
```

### Failed Job Monitoring

View failed queue jobs:
```bash
php artisan queue:failed
```

Example output:
```
  ID    Queue       Connection  Failed At
  1     order-processing  database    2024-01-15 10:30:45
  2     order-processing  database    2024-01-15 10:45:12
```

Inspect a failed job:
```bash
php artisan queue:failed --id=1
```

Retry a failed job:
```bash
php artisan queue:retry 1
```

Retry all failed jobs:
```bash
php artisan queue:retry all
```

Delete a failed job:
```bash
php artisan queue:forget 1
```

### Service-to-Service Error Handling

When Product Service is unavailable:

1. **Request fails with connection error**
2. **Error is logged with correlation ID**
3. **Queue job retries (up to 3 times)**
4. **If retries exhaust, job moves to failed_jobs**
5. **Manual retry is possible once service recovers**

Log example:
```
Service-to-service request connection failed
target_service: product-service
correlation_id: abc-123
error: Connection timeout
duration_ms: 5001
```

### Configuration

Service name is configured in `.env`:
```env
SERVICE_NAME=order-service
```

Request and queue logging are built-in and cannot be disabled (health checks are always excluded).

### Tracing a Complete Order

1. **Create order and capture correlation ID from response header**
2. **Search order-service logs with that ID**
3. **Look for "Order created successfully" and "OrderCreated event dispatched"**
4. **Watch the queue worker process the PublishOrderCreated job**
5. **Check product-service logs with the same correlation ID**
6. **Verify "OrderCreated event processing completed successfully"**

All logs with the same correlation ID tell the complete story of that order.
