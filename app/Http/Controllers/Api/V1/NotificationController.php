<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTO\CatalogQuery;
use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\User;
use App\Services\User\NotificationService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The in-app inbox.
 *
 * No source in 01–11 — added on request alongside the notification bell. Every
 * route is scoped to the token's own user; there is no addressable notification
 * belonging to anyone else, which is why an id that is not yours 404s rather
 * than 403s (see `NotificationService::markRead()`).
 */
final class NotificationController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    /**
     * GET /notifications
     *
     * `?unread=1` narrows to unread. The unread count rides along on every
     * response so the bell badge never needs a second request.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        // Reused for its page/limit clamping only — see FavoriteController.
        $query = CatalogQuery::fromRequest($request);

        $page = $this->notifications->paginate(
            $user,
            $query->page,
            $query->limit,
            $request->boolean('unread'),
        );

        $response = $this->respondPaginated($page, NotificationResource::class, 'Notifications retrieved');

        /** @var array<string, mixed> $body */
        $body = $response->getData(true);
        $body['unread_count'] = $this->notifications->unreadCount($user);

        return $response->setData($body);
    }

    /** GET /notifications/unread-count — for polling without pulling the list. */
    public function unreadCount(Request $request): JsonResponse
    {
        return $this->respondSuccess(
            ['unread_count' => $this->notifications->unreadCount($this->user($request))],
            'Unread count retrieved',
        );
    }

    /** POST /notifications/{id}/read */
    public function markRead(Request $request, string $id): JsonResponse
    {
        if (! $this->notifications->markRead($this->user($request), $id)) {
            throw new NotFoundHttpException('Notification not found.');
        }

        return $this->respondSuccess(null, 'Notification marked as read');
    }

    /** POST /notifications/read-all */
    public function markAllRead(Request $request): JsonResponse
    {
        return $this->respondSuccess(
            ['marked' => $this->notifications->markAllRead($this->user($request))],
            'All notifications marked as read',
        );
    }

    /** DELETE /notifications/{id} */
    public function destroy(Request $request, string $id): JsonResponse
    {
        if (! $this->notifications->delete($this->user($request), $id)) {
            throw new NotFoundHttpException('Notification not found.');
        }

        return $this->respondNoContent();
    }

    /** DELETE /notifications */
    public function clear(Request $request): JsonResponse
    {
        $this->notifications->clear($this->user($request));

        return $this->respondNoContent();
    }

    private function user(Request $request): User
    {
        /** @var User $user Guaranteed by the route's auth:sanctum middleware. */
        $user = $request->user();

        return $user;
    }
}
