<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

class GatewayController extends Controller
{
    public function health()
    {
        return response()->json([
            'service' => 'api-gateway',
            'status' => 'healthy'
        ]);
    }

    public function forwardProductRequest(Request $request, ?string $id = null)
    {
        return $this->forwardToService(
            config('services.product_service_url'),
            $request,
            $id,
            authenticated: true
        );
    }

    public function forwardOrderRequest(Request $request, ?string $id = null)
    {
        return $this->forwardToService(
            config('services.order_service_url'),
            $request,
            $id,
            authenticated: true
        );
    }

    public function forwardAuthRequest(Request $request)
    {
        return $this->forwardToService(
            config('services.auth_service_url'),
            $request,
            null,
            authenticated: false
        );
    }

    private function forwardToService(string $serviceUrl, Request $request, ?string $id = null, bool $authenticated = false)
    {
        // Build the full URL to forward to
        $path = $request->getPathInfo();
        // Remove /api prefix
        $path = preg_replace('~^/api~', '', $path);
        $url = rtrim($serviceUrl, '/') . $path;

        // Add query parameters
        if ($request->getQueryString()) {
            $url .= '?' . $request->getQueryString();
        }

        try {
            // Get forwarding headers with authenticated user context
            $headers = $this->getForwardingHeaders($request, $authenticated);
            
            // Create HTTP client with timeout
            $client = Http::withHeaders($headers)
                ->timeout(30);

            // Forward the request based on method
            $response = match ($request->getMethod()) {
                'GET' => $client->get($url),
                'POST' => $client->post($url, $this->getRequestBody($request)),
                'PUT' => $client->put($url, $this->getRequestBody($request)),
                'DELETE' => $client->delete($url),
                'PATCH' => $client->patch($url, $this->getRequestBody($request)),
                default => throw new \Exception('Unsupported HTTP method'),
            };

            // Return the downstream response
            return response(
                $response->body(),
                $response->status(),
                $this->getResponseHeaders($response)
            );
        } catch (ConnectionException $e) {
            // Service unavailable
            return response()->json(
                ['message' => 'Service unavailable'],
                Response::HTTP_SERVICE_UNAVAILABLE
            );
        } catch (RequestException $e) {
            // Check if it's a timeout
            if ($e->getCode() === 28) { // CURL_OPERATION_TIMEDOUT
                return response()->json(
                    ['message' => 'Gateway timeout'],
                    Response::HTTP_GATEWAY_TIMEOUT
                );
            }

            // Other request exceptions
            if ($e->response) {
                return response(
                    $e->response->body(),
                    $e->response->status(),
                    $this->getResponseHeaders($e->response)
                );
            }

            return response()->json(
                ['message' => 'Service unavailable'],
                Response::HTTP_SERVICE_UNAVAILABLE
            );
        } catch (\Exception $e) {
            // Unexpected error
            return response()->json(
                ['message' => 'Internal server error'],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    private function getForwardingHeaders(Request $request, bool $authenticated = false): array
    {
        $headersToForward = [
            'content-type',
            'accept',
            'authorization',
        ];

        $headers = [];
        foreach ($headersToForward as $header) {
            if ($request->hasHeader($header)) {
                $headers[$header] = $request->header($header);
            }
        }

        // Strip any client-provided identity headers
        unset($headers['x-user-id'], $headers['x-user-email'], $headers['x-authenticated-user']);

        // Add trusted user context headers only if authenticated
        if ($authenticated && $request->has('authenticated_user_id')) {
            $headers['x-user-id'] = $request->input('authenticated_user_id');
            if ($request->has('authenticated_user_email')) {
                $headers['x-user-email'] = $request->input('authenticated_user_email');
            }
        }

        return $headers;
    }

    private function getRequestBody(Request $request): array
    {
        $body = [];

        if ($request->getContent()) {
            try {
                // Try to decode as JSON
                $decoded = json_decode($request->getContent(), true);
                if ($decoded !== null) {
                    $body = $decoded;
                }
            } catch (\Exception $e) {
                // Fall back to raw content
                $body = ['data' => $request->getContent()];
            }
        }

        return $body;
    }

    private function getResponseHeaders($response): array
    {
        $headersToInclude = [
            'content-type',
            'content-length',
        ];

        $headers = [];
        foreach ($headersToInclude as $header) {
            if ($response->hasHeader($header)) {
                $headers[$header] = $response->header($header);
            }
        }

        return $headers;
    }
}
