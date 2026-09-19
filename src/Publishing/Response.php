<?php

declare(strict_types=1);

namespace Mizbanha\SmsCloud\Publishing;

/**
 * What SMS Cloud said, classified for retry policy.
 *
 * The rule of thumb: keep data on anything that might be temporary or fixable by
 * an operator (network, 5xx, 429, 401, environment mismatch); drop data only when
 * the Cloud has said this exact batch can never be accepted (413, 422, a batch-id
 * conflict) — retrying those forever would be the infinite loop the brief forbids.
 */
final readonly class Response
{
    /**
     * @param  int  $status  HTTP status, or 0 when no response arrived
     * @param  array<string, mixed>  $json
     */
    public function __construct(
        public int $status,
        public ?string $code,
        public ?int $retryAfter,
        public array $json,
    ) {}

    public function accepted(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** The batch itself is unacceptable. Retrying it cannot succeed. */
    public function permanentlyRejected(): bool
    {
        return in_array($this->status, [400, 413, 415, 422], true)
            || ($this->status === 409 && $this->code === 'batch_conflict');
    }

    /** Stop publishing for a while; the problem is not this batch. */
    public function shouldPause(): bool
    {
        return in_array($this->status, [401, 403, 429], true)
            || ($this->status === 409 && $this->code === 'environment_mismatch')
            || ($this->status === 422 && $this->code === 'unsupported_protocol_version');
    }

    /** A short, log-safe label for `last_error`. */
    public function label(): string
    {
        if ($this->status === 0) {
            return (string) ($this->code ?? 'network');
        }

        return $this->code !== null ? substr($this->code, 0, 32) : 'http_'.$this->status;
    }
}
