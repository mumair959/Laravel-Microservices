<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class HealthCheckService
{
    /**
     * Check if the service is healthy.
     *
     * @return array{success: bool, service: string, status: string}
     */
    public static function check(string $serviceName): array
    {
        try {
            // Attempt to query the database
            DB::connection()->getPdo();
            
            return [
                'success' => true,
                'service' => $serviceName,
                'status' => 'healthy',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'service' => $serviceName,
                'status' => 'unhealthy',
            ];
        }
    }
}
