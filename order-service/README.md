# Order Service

Laravel 13 REST service for creating and reading orders. Product details are obtained from Product Service at `http://127.0.0.1:8001`. User authentication is managed through the API Gateway and Auth Service.

## Key Features

- Order management with user ownership
- User-isolated order listing (users only see their own orders)
- Order validation with Product Service integration
- Automatic user_id assignment from authenticated Gateway header

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
```

Run the migrations:

```bash
php artisan migrate
```

Start Order Service on port 8002:

```bash
php artisan serve --port=8002
```

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
