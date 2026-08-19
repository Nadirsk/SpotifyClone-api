<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTO\CatalogQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\History\StoreHistoryRequest;
use App\Http\Resources\HistoryResource;
use App\Models\User;
use App\Services\Library\HistoryService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 05_API_SPECIFICATION §11.
 *
 * Every operation is bound to the token's user; the request body never names an
 * account.
 */
final class HistoryController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly HistoryService $history,
    ) {}

    /** GET /history */
    public function index(Request $request): JsonResponse
    {
        /*
         | CatalogQuery is reused purely for its page/limit clamping against
         | config('music.pagination'); its filter and sort fields do not apply.
         */
        $query = CatalogQuery::fromRequest($request);

        return $this->respondPaginated(
            $this->history->paginate($this->user($request), $query->page, $query->limit),
            HistoryResource::class,
            'History retrieved',
        );
    }

    /**
     * POST /history
     *
     * The one write on this controller that is not authenticated: a signed-out
     * listener's plays count too, or the charts built on this table describe
     * subscribers while claiming to describe listening. A guest is identified by
     * the request's opaque `session_id`.
     */
    public function store(StoreHistoryRequest $request): JsonResponse
    {
        $user = $request->user();

        $entry = $this->history->record(
            $user instanceof User ? $user : null,
            $request->songId(),
            $request->msPlayed(),
            $request->sessionId(),
        );

        if ($entry === null) {
            /*
             | Either inside the dedupe window or below the listen threshold.
             | The client did nothing wrong in either case — it is reporting
             | honestly and the play simply does not count — so this is a
             | success, not a 4xx.
             */
            return $this->respondSuccess(null, 'Play not counted');
        }

        return $this->respondCreated(new HistoryResource($entry), 'Play recorded');
    }

    /** DELETE /history — clears the whole history for this user. */
    public function destroy(Request $request): JsonResponse
    {
        $this->history->clear($this->user($request));

        return $this->respondNoContent();
    }

    private function user(Request $request): User
    {
        /** @var User $user Guaranteed by the route's auth:sanctum middleware. */
        $user = $request->user();

        return $user;
    }
}
