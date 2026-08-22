<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\DomainException;
use App\Models\Blend;
use App\Services\Blend\BlendGenerationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;

/**
 * "Blend updates periodically based on new listening activity"
 * (12_SCOPE_OF_WORK §18). Re-runs {@see BlendGenerationService} for every
 * active Blend (two or more members) — a full replace of `blend_songs`, not
 * an incremental update, same as every other regeneration path.
 *
 * `chunkById` rather than loading every Blend at once, per this project's own
 * "never eager-load the whole catalog in one statement" rule. Safe here
 * specifically because nothing this job does changes `blends.id` or Blend
 * membership mid-run — the trap that rule warns about is a filter whose
 * result set shifts under the cursor, and `has('members', '>=', 2)` cannot
 * shift from this job's own writes (it only touches `blend_songs` and the
 * three summary columns on `blends`).
 */
final class RegenerateBlendsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    private const CHUNK_SIZE = 50;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(BlendGenerationService $generation, LoggerInterface $logger): void
    {
        $regenerated = 0;
        $skipped = 0;

        Blend::query()
            ->has('members', '>=', 2)
            ->chunkById(self::CHUNK_SIZE, function ($blends) use ($generation, $logger, &$regenerated, &$skipped): void {
                foreach ($blends as $blend) {
                    try {
                        $generation->generate($blend);
                        $regenerated++;
                    } catch (DomainException $e) {
                        // A membership change raced this batch (e.g. the
                        // second-to-last member just left) — skip, not fail;
                        // the next scheduled run picks it up if it is still
                        // active then.
                        $skipped++;
                    } catch (\Throwable $e) {
                        $skipped++;
                        $logger->warning('Blend regeneration failed', [
                            'blend_id' => $blend->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        $logger->info('Blends regenerated', ['regenerated' => $regenerated, 'skipped' => $skipped]);
    }
}
