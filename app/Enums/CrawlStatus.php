<?php

declare(strict_types=1);

namespace App\Enums;

enum CrawlStatus: string
{
    /** Waiting to be claimed. */
    case Pending = 'pending';

    /** Leased by a worker. A lease that has lapsed is claimable again. */
    case Running = 'running';

    /**
     * Fully walked. Kept rather than deleted so the target can be revisited
     * later for new releases, and so re-discovering it stays a no-op upsert.
     */
    case Completed = 'completed';

    /** Gave up after `providers.crawl.max_attempts` consecutive failures. */
    case Failed = 'failed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
