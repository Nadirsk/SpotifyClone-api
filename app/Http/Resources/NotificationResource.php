<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * One row of the in-app inbox.
 *
 * The stored `data` blob is flattened into the top level rather than nested
 * under a `data` key: the envelope already has a `data` key
 * (05_API_SPECIFICATION §2) and `data.data.title` would be nobody's idea of a
 * good contract. The payload's shape is guaranteed by
 * `Notifications\Concerns\RendersInAppPayload`.
 *
 * `type` is deliberately not exposed — it is a PHP FQCN, an implementation
 * detail the client must not branch on. `category` is the stable discriminator.
 *
 * @mixin DatabaseNotification
 */
final class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $payload */
        $payload = $this->data;

        return [
            'id' => $this->id,
            'category' => $payload['category'] ?? 'general',
            'title' => $payload['title'] ?? '',
            'body' => $payload['body'] ?? '',
            'href' => $payload['href'] ?? null,
            'image' => $payload['image'] ?? null,
            'meta' => (object) ($payload['meta'] ?? []),
            'read' => $this->read_at !== null,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
