# Product Service

Laravel 13 REST service for managing products. Authentication is handled by the API Gateway.

## Overview

The Product Service manages the product catalog. It does NOT manage users - all authentication is handled through the API Gateway and Auth Service.

**New in Sprint 6**: Asynchronous stock updates via OrderCreated events from Order Service.

## Setup

Requirements: PHP 8.3+, Composer, and MySQL.

```bash
composer install
copy .env.example .env
php artisan key:generate
```

Create a MySQL database named `product_db`, then set these values in `.env`:

```dotenv
APP_URL=http://127.0.0.1:8001
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=product_db
DB_USERNAME=root
DB_PASSWORD=
QUEUE_CONNECTION=database
PRODUCT_QUEUE=product-processing
ORDER_SERVICE_EVENT_SECRET=local-development-secret-change-in-production
```

Run the migrations (creates products, jobs, failed_jobs, and processed_events tables):

```bash
php artisan migrate
```

Start the service on port 8001:

```bash
php artisan serve --port=8001
```

The service is available at `http://127.0.0.1:8001`.

## Queue Configuration

This service uses Laravel's **database queue driver** for asynchronous stock management.

### Queue Setup

- **Queue Connection**: `database` (not Redis, Kafka, or RabbitMQ)
- **Queue Name**: `product-processing`
- **Queue Tables**: `jobs`, `failed_jobs` (in `product_db`)

### Queue Environment Variables

```dotenv
QUEUE_CONNECTION=database
PRODUCT_QUEUE=product-processing
ORDER_SERVICE_EVENT_SECRET=<local-secret-must-match-order-service>
```

### Running the Queue Worker

Start the queue worker in a separate terminal:

```bash
cd product-service
php artisan queue:work database --queue=product-processing
```

The worker will process `ProcessOrderCreated` jobs and update product stock.

### Retry Configuration

- **Max Tries**: 3 attempts
- **Backoff**: 30 seconds between retries
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

## Authentication

All product endpoints (except health) require authentication through the API Gateway.

Routes are accessed via Gateway at:
- `GET    /api/v1/products` - List products
- `POST   /api/v1/products` - Create product
- `GET    /api/v1/products/{id}` - Get product
- `PUT    /api/v1/products/{id}` - Update product
- `DELETE /api/v1/products/{id}` - Delete product

Public health endpoint:
- `GET /api/health` - Service health check

The Gateway validates the Bearer token with Auth Service before forwarding requests.

## Internal Event Endpoint

The `/api/internal/events/order-created` endpoint is **NOT** for clients. It is used only by Order Service to deliver OrderCreated events.

### Service-to-Service Authentication

Request must include the `X-Service-Secret` header:

```
POST /api/internal/events/order-created HTTP/1.1
Host: 127.0.0.1:8001
Content-Type: application/json
X-Service-Secret: <ORDER_SERVICE_EVENT_SECRET>

{
    "event_id": "550e8400-e29b-41d4-a716-446655440000",
    "event": "OrderCreated",
    "order_id": 100,
    "user_id": 15,
    "items": [
        {"product_id": 1, "quantity": 2},
        {"product_id": 5, "quantity": 1}
    ],
    "total_amount": 2500.00
}
```

### Response Codes

- **202 Accepted**: Event received and queued for processing. Stock update will happen asynchronously.
- **401 Unauthorized**: Missing or invalid `X-Service-Secret` header.
- **422 Unprocessable Entity**: Invalid event payload (missing fields, invalid format, etc.).
- **500 Internal Server Error**: Failed to queue the event.

### Example cURL Request

```bash
curl -X POST http://127.0.0.1:8001/api/internal/events/order-created \
  -H "Content-Type: application/json" \
  -H "X-Service-Secret: local-development-secret-change-in-production" \
  -d '{
    "event_id": "550e8400-e29b-41d4-a716-446655440000",
    "event": "OrderCreated",
    "order_id": 100,
    "user_id": 15,
    "items": [
        {"product_id": 1, "quantity": 2}
    ],
    "total_amount": 200.00
  }'
```

## Event-Driven Stock Management

### OrderCreated Event Processing

When Order Service creates an order, it dispatches an `OrderCreated` event to Product Service asynchronously.

Product Service processes the event by:

1. **Authenticating** the request using `X-Service-Secret` header
2. **Validating** the event payload
3. **Checking for duplicates** using `event_id` against `processed_events` table
4. **Queueing** the `ProcessOrderCreated` job
5. **Returning** immediately with HTTP 202

The job then:

1. **Checks idempotency** - if event already processed, skips update
2. **Locks** product rows using `lockForUpdate()`
3. **Validates** sufficient stock exists
4. **Updates** product stock
5. **Records** the processed event
6. **Commits** the transaction

### Idempotency

**Critical**: The same OrderCreated event may be delivered multiple times due to:

- Network retries
- Queue system retries
- Service restarts during processing

To prevent duplicate stock deductions:

1. Each OrderCreated event has a unique `event_id` (UUID)
2. Product Service stores processed event IDs in `processed_events` table
3. `event_id` column is `UNIQUE`
4. Before updating stock, `ProcessOrderCreated` checks if `event_id` exists
5. If already processed, the job skips the update and completes successfully

### Insufficient Stock Handling

If a product has insufficient stock:

- The job **does NOT** fail or throw an exception
- The stock is **not** updated (negative stock is prevented)
- The event is **still** recorded as processed
- A warning is logged for manual review

This prevents retry loops while maintaining data integrity.

### Example Flow

```
Time  Action
----  ------
T0:   Order Service creates order
T1:   PublishOrderCreated job dispatched to order-processing queue
T2:   Order worker picks up job
T3:   Order worker makes HTTP request to Product Service
T4:   Product Service receives OrderCreated event at /api/internal/events/order-created
T5:   Product Service queues ProcessOrderCreated job
T6:   Product Service returns HTTP 202 (event accepted)
T7:   Order worker completes (order creation API response was already sent at T0)
T8:   Product worker picks up ProcessOrderCreated job
T9:   Product worker checks if event_id exists in processed_events (first time, so it doesn't)
T10:  Product worker locks product rows with pessimistic locking
T11:  Product worker validates stock (sufficient)
T12:  Product worker updates product.stock -= quantity
T13:  Product worker inserts record into processed_events
T14:  Product worker commits transaction
T15:  Product worker completes

Later, if the same event is delivered again (duplicate):
T20:  Order worker retries and sends duplicate event
T21:  Product Service receives event
T22:  Product Service queues ProcessOrderCreated job (again)
T23:  Product Service returns HTTP 202
T24:  Product worker picks up job
T25:  Product worker checks if event_id exists in processed_events (already exists!)
T26:  Product worker skips stock update and completes successfully
```

## Database Schema

Product table:

```sql
CREATE TABLE products (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    sku VARCHAR(255) UNIQUE NOT NULL,
    description TEXT,
    price DECIMAL(10, 2) NOT NULL,
    stock INT UNSIGNED DEFAULT 0,
    status BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

Processed events table:

```sql
CREATE TABLE processed_events (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    event_id VARCHAR(255) UNIQUE NOT NULL,
    event_type VARCHAR(255) NOT NULL,
    order_id INT NOT NULL,
    user_id INT NOT NULL,
    payload JSON,
    processed_at TIMESTAMP,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

Queue tables (for database queue driver):

```sql
CREATE TABLE jobs (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    queue VARCHAR(255),
    payload LONGTEXT,
    attempts TINYINT UNSIGNED,
    reserved_at INT UNSIGNED,
    available_at INT UNSIGNED,
    created_at INT UNSIGNED,
    INDEX (queue)
);

CREATE TABLE failed_jobs (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    uuid VARCHAR(255) UNIQUE,
    connection TEXT,
    queue TEXT,
    payload LONGTEXT,
    exception LONGTEXT,
    failed_at TIMESTAMP
);
```

## API

All product endpoints use the `/api/v1/products` prefix.

| Method | Endpoint | Description |
| --- | --- | --- |
| GET | `/api/health` | Health check |
| GET | `/api/v1/products` | List products with pagination |
| POST | `/api/v1/products` | Create a product |
| GET | `/api/v1/products/{id}` | Show a product |
| PUT | `/api/v1/products/{id}` | Update a product |
| DELETE | `/api/v1/products/{id}` | Delete a product |

**Internal Endpoints** (service-to-service only, NOT for clients):

| Method | Endpoint | Description |
| --- | --- | --- |
| POST | `/api/internal/events/order-created` | Receive OrderCreated events from Order Service |

The list endpoint supports `?status=1`, `?status=0`, and `?search=laptop`. Search matches product name and SKU.

Successful reads and updates return HTTP 200, creation returns 201, deletion returns 204, missing products return 404, and validation failures return 422.

### Create example

```bash
curl -X POST http://127.0.0.1:8001/api/v1/products \
  -H "Content-Type: application/json" \
  -d '{"name":"Laptop","sku":"LAP-001","description":"Work laptop","price":999.99,"stock":10,"status":true}'
```

### List example

```bash
curl "http://127.0.0.1:8001/api/v1/products?status=1&search=laptop"
```

### Update example

```bash
curl -X PUT http://127.0.0.1:8001/api/v1/products/1 \
  -H "Content-Type: application/json" \
  -d '{"name":"Laptop Pro","sku":"LAP-001","description":null,"price":1299.99,"stock":8,"status":true}'
```

## Tests

Tests use the SQLite in-memory database configured in `phpunit.xml`:

```bash
php artisan test
```
