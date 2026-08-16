<?php

declare(strict_types=1);

namespace App\Notifications\Concerns;

/**
 * The shared shape of every row in the in-app inbox.
 *
 * Notifications are stored as free-form JSON by the framework, which means
 * nothing stops two notification classes from disagreeing about what a payload
 * looks like — and the frontend renders them all through one component. This
 * trait is the contract: every `toDatabase()` returns `payload(...)`, so the
 * inbox can rely on `category`, `title`, `body`, `href` and `image` existing on
 * every row regardless of which class wrote it.
 *
 * Anything class-specific goes under `meta`, which the renderer ignores.
 *
 * ## Why none of these are queued
 *
 * CLAUDE.md's "queue all long-running tasks" does not apply: delivering to the
 * `database` channel is one INSERT, and wrapping it in a job would make the
 * inbox depend on a worker being up to show a row the request already had in
 * hand. A notification that appears minutes late — or not at all, which is
 * exactly what happened here with a `database` queue and no worker running — is
 * worse than one written inline. Add `ShouldQueue` per class if a mail or push
 * channel is ever added to `via()`; that is the case it exists for.
 */
trait RendersInAppPayload
{
    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    protected function payload(
        string $category,
        string $title,
        string $body,
        ?string $href = null,
        ?string $image = null,
        array $meta = [],
    ): array {
        return [
            'category' => $category,
            'title' => $title,
            'body' => $body,
            // A relative in-app path, never an absolute URL — the inbox routes
            // with the client-side router and an absolute one would full-reload.
            'href' => $href,
            'image' => $image,
            'meta' => $meta,
        ];
    }
}
