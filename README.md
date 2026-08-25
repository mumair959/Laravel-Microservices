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