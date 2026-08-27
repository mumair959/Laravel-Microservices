<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Request;

class ProductServiceClient
{
    public function getProduct(int $productId): Response
    {
        $correlationId = request()->attributes->get('correlation_id', '');
        
        return Http::timeout(config('services.http_timeout', 10))
            ->connectTimeout(config('services.http_connect_timeout', 5))
            ->acceptJson()
            ->when($correlationId, fn ($client) => $client->withHeaders(['X-Correlation-ID' => $correlationId]))
            ->get(rtrim(config('services.product_service.url'), '/')."/api/v1/products/{$productId}");
    }
}