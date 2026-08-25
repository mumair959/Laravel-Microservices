<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class ProductServiceClient
{
    public function getProduct(int $productId): Response
    {
        return Http::timeout(5)
            ->acceptJson()
            ->get(rtrim(config('services.product_service.url'), '/')."/api/v1/products/{$productId}");
    }
}