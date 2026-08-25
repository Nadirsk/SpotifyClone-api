<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\AudioQuality;
use App\Enums\SubscriptionPlan;
use App\Exceptions\DomainException;
use App\Models\Song;
use App\Models\User;
use App\Services\Billing\PlanCatalog;
use App\Services\Billing\SubscriptionService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Playback and download, with the plan gate applied.
 *
 * Everything quality- or entitlement-shaped about audio funnels through here so
 * there is exactly one place that decides "which bytes may this listener have".
 * Controllers pass a user and a request; they never compare plan names.
 *
 * ## On proxying download bytes
 *
 * `download()` streams provider audio through this server, which
 * 01_PRODUCT_REQUIREMENTS §3 and 11_PROVIDER_INTEGRATION otherwise forbid — the
 * platform is specified never to host, cache, proxy or re-serve provider audio,
 * because provider terms prohibit it. It is implemented anyway on an explicit,
 * recorded product decision to ship offline downloads. Two consequences worth
 * being clear-eyed about:
 *
 * - it is a licensing exposure, not a technical shortcut, and it does not
 *   become compliant by being behind a paywall;
 * - it puts this server in the delivery path, so download traffic is bandwidth
 *   this platform pays for and has to scale.
 *
 * A directly-licensed catalogue is what makes this legitimate. Until then,
 * `AudioProxy`-shaped code lives only in this one method so it is trivial to
 * excise if the decision is reversed.
 */
final class PlaybackService
{
    /** How long the upstream fetch may take to *start* before we give up. */
    private const UPSTREAM_TIMEOUT_SECONDS = 15;

    public function __construct(
        private readonly SongService $songs,
        private readonly AudioSourceResolver $resolver,
        private readonly SubscriptionService $subscriptions,
        private readonly PlanCatalog $plans,
    ) {}

    /**
     * Where to stream a track from, at the best quality this listener may have.
     *
     * Works for guests: `$user` null means the free tier's ceiling, which is
     * what lets a signed-out visitor still hear something. The response always
     * reports the quality actually resolved — see `AudioSourceResolver` for why
     * that can differ from what was asked for.
     *
     * @return array<string, mixed>
     *
     * @throws DomainException
     */
    public function stream(string $songId, ?User $user, ?AudioQuality $requested = null): array
    {
        $song = $this->songs->find($songId);
        $quality = $this->grantedQuality($user, $requested);

        $resolved = $this->resolver->resolve($song, $quality);

        if ($resolved === null) {
            throw DomainException::noAudioSource();
        }

        return [
            'id' => (string) $song->getKey(),
            'title' => (string) $song->title,
            'duration' => $song->duration,
            'url' => $resolved['url'],
            'quality' => $resolved['quality']->value,
            'quality_label' => $resolved['quality']->label(),
            'bitrate_kbps' => $resolved['quality']->bitrate(),
            /*
             | False means the provider's URL was not in the shape the ladder is
             | derived from, so this is simply whatever was synced. The client
             | uses it to avoid advertising a tier it did not actually get.
             */
            'variant_derived' => $resolved['derived'],
            'max_quality' => $this->ceilingFor($user)->value,
        ];
    }

    /**
     * The quality ladder to offer this listener in the settings UI, with the
     * ones their plan cannot reach marked rather than hidden — a locked row the
     * listener can see is what makes the upgrade prompt make sense.
     *
     * @return list<array<string, mixed>>
     */
    public function qualityOptions(?User $user): array
    {
        $ceiling = $this->ceilingFor($user);

        return array_map(static fn (AudioQuality $quality): array => [
            'value' => $quality->value,
            'label' => $quality->label(),
            'bitrate_kbps' => $quality->bitrate(),
            'available' => $quality->isAtMost($ceiling),
        ], AudioQuality::cases());
    }

    /**
     * Stream a track's bytes to the caller as a file download.
     *
     * Premium only. Chunked through a generator rather than buffered, so a
     * 10 MB track does not become 10 MB of PHP memory per concurrent download.
     *
     * @throws DomainException
     */
    public function download(string $songId, User $user, ?AudioQuality $requested = null): StreamedResponse
    {
        $this->subscriptions->authorize($user, 'download');

        $song = $this->songs->find($songId);
        $song->loadMissing('artist');

        $resolved = $this->resolver->resolve($song, $this->grantedQuality($user, $requested));

        if ($resolved === null) {
            throw DomainException::noAudioSource();
        }

        return $this->streamFrom(
            $resolved['url'],
            $this->resolver->downloadFilename($song, $resolved['url']),
            $resolved['quality'],
        );
    }

    /**
     * What the listener asked for, clamped to their plan's ceiling. A guest is
     * treated as free — `SubscriptionService` needs a user, and the free
     * ceiling is the honest answer for someone with no account.
     */
    private function grantedQuality(?User $user, ?AudioQuality $requested): AudioQuality
    {
        if ($user === null) {
            return ($requested ?? AudioQuality::Normal)->clampTo($this->ceilingFor(null));
        }

        return $this->subscriptions->effectiveQualityFor($user, $requested);
    }

    private function ceilingFor(?User $user): AudioQuality
    {
        if ($user === null) {
            return $this->plans->maxQuality(SubscriptionPlan::Free);
        }

        return AudioQuality::from(
            $this->subscriptions->entitlementsFor($user)['max_audio_quality'],
        );
    }

    /**
     * @throws DomainException When the provider will not serve the file.
     */
    private function streamFrom(string $url, string $filename, AudioQuality $quality): StreamedResponse
    {
        try {
            $upstream = Http::timeout(self::UPSTREAM_TIMEOUT_SECONDS)->withOptions(['stream' => true])->get($url);
        } catch (ConnectionException) {
            throw DomainException::noAudioSource();
        }

        if (! $upstream->successful()) {
            throw DomainException::noAudioSource();
        }

        $body = $upstream->toPsrResponse()->getBody();

        $response = new StreamedResponse(function () use ($body): void {
            while (! $body->eof()) {
                echo $body->read(1024 * 256);
                flush();
            }

            $body->close();
        });

        $response->headers->set('Content-Type', $upstream->header('Content-Type') ?: 'audio/mp4');
        $response->headers->set(
            'Content-Disposition',
            // Symfony handles the RFC 6266 quoting and the ASCII fallback for
            // a filename with non-Latin characters, which a Bollywood catalog
            // has plenty of.
            $response->headers->makeDisposition('attachment', $filename, $this->asciiFallback($filename)),
        );
        $response->headers->set('X-Audio-Quality', $quality->value);

        if ($upstream->header('Content-Length') !== '') {
            $response->headers->set('Content-Length', $upstream->header('Content-Length'));
        }

        /*
         | The client caches this in a Service Worker for offline playback, and
         | the URL it is keyed by never changes for a given (song, quality) —
         | so a shared cache would be wrong (it is entitlement-gated) but the
         | listener's own may hold it.
         */
        $response->headers->set('Cache-Control', 'private, max-age=31536000');

        return $response;
    }

    /** `makeDisposition` rejects a fallback with any non-ASCII byte in it. */
    private function asciiFallback(string $filename): string
    {
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $filename) ?? '';
        // It also rejects '%', and a percent-escape surviving from a URL-ish
        // title would trip that.
        $ascii = str_replace('%', '_', $ascii);

        return trim($ascii) === '' ? 'track.mp4' : $ascii;
    }
}
