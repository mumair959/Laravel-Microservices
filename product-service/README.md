# Product Service

Laravel 13 REST service for managing products.

## Setup

Requirements: PHP 8.3+, Composer, and MySQL.

```bash
composer install
copy .env.example .env
php artisan key:generate
```

Create a MySQL database named `product_db`, then set these values in `.env`:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=product_db
DB_USERNAME=root
DB_PASSWORD=
```

Run the migrations:

```bash
php artisan migrate
```

Start the service on port 8001:

```bash
php artisan serve --port=8001
```

The service is available at `http://127.0.0.1:8001`.

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
