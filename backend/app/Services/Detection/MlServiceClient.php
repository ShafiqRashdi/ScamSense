<?php

declare(strict_types=1);

namespace App\Services\Detection;

use App\DataTransferObjects\MlPrediction;
use App\Enums\SubmissionType;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The internal hop to the Flask ML service.
 *
 * **This class never throws.** Every failure path returns
 * {@see MlPrediction::unavailable()}, because AGENTS.md §2 rule 4 requires the
 * user to receive a verdict labelled partial rather than a 500 when the model
 * is unreachable. A client that throws would push that decision into the
 * controller, where it would eventually be forgotten.
 *
 * Requests are signed with an HMAC over `timestamp + "." + body` and rejected
 * by Flask if older than 60 seconds (docs/api-contract.md).
 */
class MlServiceClient
{
    private const SIGNATURE_HEADER = 'X-ScamSense-Signature';

    private const TIMESTAMP_HEADER = 'X-ScamSense-Timestamp';

    public function predictUrl(string $url, ?string $requestId = null): MlPrediction
    {
        return $this->predict('/api/v1/predict/url', ['url' => $url], $requestId);
    }

    public function predictMessage(string $text, ?string $requestId = null): MlPrediction
    {
        return $this->predict('/api/v1/predict/message', ['text' => $text], $requestId);
    }

    public function predictFor(SubmissionType $type, string $content, ?string $requestId = null): MlPrediction
    {
        return match ($type) {
            SubmissionType::Url => $this->predictUrl($content, $requestId),
            SubmissionType::Message => $this->predictMessage($content, $requestId),
        };
    }

    /**
     * Whether the service answers its health check.
     *
     * Used by the admin dashboard, not on the request path — a health check
     * before every prediction would double the latency budget for no gain.
     */
    public function isHealthy(): bool
    {
        try {
            return Http::timeout(2)->get($this->baseUrl().'/health')->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, string>  $payload
     */
    private function predict(string $path, array $payload, ?string $requestId): MlPrediction
    {
        $secret = (string) config('services.ml_service.secret');

        if ($secret === '') {
            // Refusing to call is right: an unsigned request would be rejected
            // anyway, and a missing secret is a deployment fault worth surfacing
            // rather than retrying into.
            Log::error('ML_SERVICE_SECRET is not configured; skipping prediction.');

            return MlPrediction::unavailable('ml_service_misconfigured');
        }

        $payload['request_id'] = $requestId ?? (string) Str::uuid();

        // Encode once and sign these exact bytes. Re-encoding for the request
        // could reorder keys and produce a signature Flask cannot verify.
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) time();

        try {
            $response = Http::withHeaders([
                self::TIMESTAMP_HEADER => $timestamp,
                self::SIGNATURE_HEADER => hash_hmac('sha256', $timestamp.'.'.$body, $secret),
                'Content-Type' => 'application/json',
            ])
                ->timeout((int) config('services.ml_service.timeout', 2))
                ->retry(
                    (int) config('services.ml_service.retries', 1),
                    (int) config('services.ml_service.retry_delay_ms', 200),
                    throw: false,
                )
                ->withBody($body, 'application/json')
                ->post($this->baseUrl().$path);
        } catch (ConnectionException $exception) {
            Log::warning('ML service unreachable.', ['reason' => $exception->getMessage()]);

            return MlPrediction::unavailable('ml_service_unreachable');
        } catch (Throwable $exception) {
            // Deliberately broad. Anything unexpected here must still degrade
            // to a partial verdict rather than reaching the user as an error.
            Log::error('ML service call failed.', ['reason' => $exception->getMessage()]);

            return MlPrediction::unavailable('ml_service_error');
        }

        if ($response->status() === 503) {
            // The service is up but has no model for this task — currently the
            // permanent state of the message classifier (ADR 0005).
            return MlPrediction::unavailable('model_unavailable');
        }

        if (! $response->successful()) {
            Log::warning('ML service returned an error status.', [
                'status' => $response->status(),
                // The URL itself is deliberately not logged: it is user input,
                // and AGENTS.md §3 forbids writing unredacted submissions to
                // any log line.
                'path' => $path,
            ]);

            return MlPrediction::unavailable('ml_service_error');
        }

        /** @var array<string, mixed> $decoded */
        $decoded = $response->json() ?? [];

        if (! isset($decoded['label'], $decoded['confidence'])) {
            Log::warning('ML service returned a malformed body.', ['path' => $path]);

            return MlPrediction::unavailable('ml_service_malformed_response');
        }

        return MlPrediction::fromResponse($decoded);
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.ml_service.url'), '/');
    }
}
