<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class MeridianAiService
{
    private string $baseUrl;
    private ?string $token;
    private int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.meridian_ai.url', 'http://127.0.0.1:9000'), '/');
        $this->token   = config('services.meridian_ai.token');
        $this->timeout = (int) config('services.meridian_ai.timeout', 90);
    }

    public function isReachable(): bool
    {
        try {
            return Http::timeout(3)->get("{$this->baseUrl}/health")->ok();
        } catch (\Throwable) {
            return false;
        }
    }

    public function generateItinerary(array $payload): array
    {
        return $this->post('/generate_itinerary', $payload);
    }

    public function suggestQuestions(array $payload): array
    {
        return $this->post('/suggest_questions', $payload);
    }

    public function suggestResponse(array $payload): array
    {
        return $this->post('/suggest_response', $payload);
    }

    public function recommendStay(array $payload): array
    {
        return $this->post('/recommend_stay', $payload);
    }

    private function post(string $path, array $payload): array
    {
        $client = Http::timeout($this->timeout)->acceptJson();

        if ($this->token) {
            $client = $client->withToken($this->token);
        }

        $response = $client->post("{$this->baseUrl}{$path}", $payload);

        if ($response->failed()) {
            $body = $response->json('error.message') ?? $response->body();
            throw new RuntimeException("meridian-ai {$path} failed [{$response->status()}]: {$body}");
        }

        return $response->json();
    }
}
