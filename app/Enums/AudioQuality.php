<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Stream/download quality tiers, named after the labels the UI shows.
 *
 * The bitrates are JioSaavn's own ladder — its `downloadUrl` array comes back
 * as `[{quality: "12kbps"|"48kbps"|"96kbps"|"160kbps"|"320kbps", url}]`, so
 * these cases map one-to-one onto variants the provider actually serves. This
 * platform does not transcode (11_PROVIDER_INTEGRATION), so a tier that the
 * provider has no variant for cannot be invented; `closestTo()` degrades to the
 * best available instead of failing.
 *
 * `Lossless` has no provider variant today and is listed only because the
 * Platinum plan advertises it — `closestTo()` resolves it down to 320kbps until
 * a provider that serves FLAC is wired in. Callers must never assume the tier
 * they asked for is the tier they got; read the resolved value off the response.
 */
enum AudioQuality: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case VeryHigh = 'very_high';
    case Lossless = 'lossless';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Nominal bitrate in kbps. `Lossless` reports the ceiling it degrades to. */
    public function bitrate(): int
    {
        return match ($this) {
            self::Low => 48,
            self::Normal => 96,
            self::High => 160,
            self::VeryHigh => 320,
            self::Lossless => 320,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Low',
            self::Normal => 'Normal',
            self::High => 'High',
            self::VeryHigh => 'Very high',
            self::Lossless => 'Lossless',
        };
    }

    /** Ordering helper — higher means better. */
    public function rank(): int
    {
        return match ($this) {
            self::Low => 0,
            self::Normal => 1,
            self::High => 2,
            self::VeryHigh => 3,
            self::Lossless => 4,
        };
    }

    public function isAtMost(self $ceiling): bool
    {
        return $this->rank() <= $ceiling->rank();
    }

    /** The requested tier, clamped to what the caller's plan allows. */
    public function clampTo(self $ceiling): self
    {
        return $this->isAtMost($ceiling) ? $this : $ceiling;
    }
}
