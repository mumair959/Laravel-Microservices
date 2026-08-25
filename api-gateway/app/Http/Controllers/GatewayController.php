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
            $id
        );
    }

    public function forwardOrderRequest(Request $request, ?string $id = null)
    {
        return $this->forwardToService(
            config('services.order_service_url'),
            $request,
            $id
        );
    }

    private function forwardToService(string $serviceUrl, Request $request, ?string $id = null)
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
            // Create HTTP client with timeout
            $client = Http::withHeaders($this->getForwardingHeaders($request))
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

    private function getForwardingHeaders(Request $request): array
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
