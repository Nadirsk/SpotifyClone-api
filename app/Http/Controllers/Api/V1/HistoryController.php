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

    /** POST /history */
    public function store(StoreHistoryRequest $request): JsonResponse
    {
        $entry = $this->history->record(
            $this->user($request),
            $request->songId(),
            $request->msPlayed(),
        );

        if ($entry === null) {
            // Inside the dedupe window. The client did nothing wrong, so this
            // is a success — the play simply was not logged a second time.
            return $this->respondSuccess(null, 'Play already recorded recently');
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
