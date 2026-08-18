<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CrawlStatus;
use App\Enums\CrawlType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One unit of discovery work — see the `catalog_crawl_targets` migration for
 * what the frontier is and why it is a table.
 *
 * @property string $provider
 * @property CrawlType $type
 * @property string $identifier
 * @property CrawlStatus $status
 * @property int $priority
 * @property int $cursor_page
 * @property int|null $total_expected
 * @property int $items_synced
 * @property int $attempts
 * @property string|null $last_error
 * @property Carbon|null $leased_until
 * @property Carbon|null $last_crawled_at
 * @property Carbon|null $completed_at
 */
class CrawlTarget extends Model
{
    use HasUuids;

    protected $table = 'catalog_crawl_targets';

    /** @var list<string> */
    protected $fillable = [
        'provider',
        'type',
        'identifier',
        'status',
        'priority',
        'cursor_page',
        'total_expected',
        'items_synced',
        'attempts',
        'last_error',
        'leased_until',
        'last_crawled_at',
        'completed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => CrawlType::class,
            'status' => CrawlStatus::class,
            'priority' => 'integer',
            'cursor_page' => 'integer',
            'total_expected' => 'integer',
            'items_synced' => 'integer',
            'attempts' => 'integer',
            'leased_until' => 'datetime',
            'last_crawled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Targets a worker may claim right now: never-started ones, plus ones whose
     * lease has expired because the worker holding it died.
     *
     * The expired-lease arm is what makes a killed crawl self-healing. Without
     * it, every target in flight when a worker was SIGKILLed would sit in
     * `running` forever and the frontier would leak work on every crash.
     *
     * @param  Builder<CrawlTarget>  $query
     */
    public function scopeClaimable(Builder $query): void
    {
        $query->where(function (Builder $query): void {
            $query->where('status', CrawlStatus::Pending)
                ->orWhere(function (Builder $query): void {
                    $query->where('status', CrawlStatus::Running)
                        ->where('leased_until', '<', Carbon::now());
                });
        });
    }

    /**
     * Completed targets old enough to be worth re-walking for new content.
     *
     * @param  Builder<CrawlTarget>  $query
     */
    public function scopeDueForRevisit(Builder $query, Carbon $before): void
    {
        $query->where('status', CrawlStatus::Completed)
            ->where('completed_at', '<', $before);
    }
}
