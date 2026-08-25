# Order Service

Laravel 13 REST service for creating and reading orders. Product details are obtained from Product Service at `http://127.0.0.1:8001`.

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

| Method | Endpoint | Description |
| --- | --- | --- |
| GET | `/api/health` | Health check |
| GET | `/api/v1/orders` | List orders with pagination and items |
| POST | `/api/v1/orders` | Create an order |
| GET | `/api/v1/orders/{id}` | Show an order and its items |

Order creation expects one or more distinct product IDs and positive quantities. Product Service supplies the current name, price, and stock. Order Service stores product name and price snapshots in `order_items` and calculates item and order totals locally.

Successful reads return 200, creation returns 201, invalid requests and insufficient stock return 422, missing products or orders return 404, and Product Service connection/server failures return 503.

### Create request

```bash
curl -X POST http://127.0.0.1:8002/api/v1/orders \
  -H "Content-Type: application/json" \
  -d '{"items":[{"product_id":1,"quantity":2}]}'
```

### List orders

```bash
curl http://127.0.0.1:8002/api/v1/orders
```

### Show an order

```bash
curl http://127.0.0.1:8002/api/v1/orders/1
```

## Tests

Feature tests use the SQLite in-memory database and fake Product Service HTTP responses:

```bash
php artisan test
```
