<?php

declare(strict_types=1);

namespace Mizbanha\SmsCloud\Publishing;

use Illuminate\Http\Client\Factory;
use Mizbanha\SmsCloud\Support\Settings;
use Throwable;

/**
 * The HTTP conversation with SMS Cloud, reduced to a verdict.
 *
 * ⚠️ Never throws. A DNS failure, a TLS error, a timeout, a 500, a body that is
 * not JSON — every one of them becomes a `Response` the caller can decide on.
 */
final class CloudApi
{
    public const PROTOCOL = 1;

    public function __construct(
        private readonly Settings $settings,
        private readonly Factory $http,
    ) {}

    /**
     * @param  array<string, mixed>  $body
     */
    public function post(string $path, array $body): Response
    {
        try {
            $response = $this->http
                ->withToken($this->settings->token())
                ->withHeaders(['X-Sms-Cloud-Protocol' => (string) self::PROTOCOL])
                ->acceptJson()
                ->asJson()
                ->connectTimeout($this->settings->int('http.connect_timeout', 3))
                ->timeout($this->settings->int('http.timeout', 10))
                ->post($this->settings->endpoint().$path, $body);

            $json = $response->json();
            $code = is_array($json) && is_string($json['error']['code'] ?? null) ? $json['error']['code'] : null;
            $retryAfter = $response->header('Retry-After');

            return new Response(
                $response->status(),
                $code,
                is_numeric($retryAfter) ? (int) $retryAfter : null,
                is_array($json) ? $json : [],
            );
        } catch (Throwable $exception) {
            // Name the category, never the message: an exception message can carry
            // the URL, and the URL is ours to keep out of logs anyway.
            $category = str_contains(strtolower($exception::class), 'connection') || str_contains(strtolower($exception->getMessage()), 'timed out')
                ? 'network'
                : 'client_error';

            return new Response(0, $category, null, []);
        }
    }
}
