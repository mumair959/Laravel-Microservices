<?php

namespace App\Services;

use App\Models\IdempotencyKey;
use Illuminate\Http\Request;

class IdempotencyService
{
    /**
     * Store an idempotency response.
     */
    public static function storeResponse(
        Request $request,
        int $statusCode,
        array $responseBody
    ): void {
        $idempotencyKey = $request->attributes->get('idempotency_key');
        $requestHash = $request->attributes->get('request_hash');
        $userId = $request->header('X-User-Id');

        if (!$idempotencyKey || !$requestHash || !$userId) {
            return;
        }

        IdempotencyKey::create([
            'user_id' => $userId,
            'key' => $idempotencyKey,
            'request_hash' => $requestHash,
            'response_status' => $statusCode,
            'response_body' => $responseBody,
        ]);
    }
}
