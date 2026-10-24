<?php

namespace App\Services\Event;

/**
 * Signature of a broker webhook: `Opti-Webhook-Signature: v1=<hex HMAC-SHA256(secret, "<id>.<timestamp>.<body>")>`.
 * Receivers recompute it with their subscription secret, compare in constant time, refuse a timestamp older than five
 * minutes and de-duplicate on `event_id`.
 */
class WebhookSigner
{
    /** @return array<string, string> */
    public function headers(string $deliveryId, string $eventId, string $secret, string $body, ?int $timestamp = null): array
    {
        $timestamp ??= time();

        return [
            'Opti-Webhook-Id' => $deliveryId,
            'Opti-Event-Id' => $eventId,
            'Opti-Webhook-Timestamp' => (string) $timestamp,
            'Opti-Webhook-Signature' => 'v1='.$this->sign($secret, $deliveryId, $timestamp, $body),
        ];
    }

    public function sign(string $secret, string $deliveryId, int $timestamp, string $body): string
    {
        return hash_hmac('sha256', "{$deliveryId}.{$timestamp}.{$body}", $secret);
    }
}
