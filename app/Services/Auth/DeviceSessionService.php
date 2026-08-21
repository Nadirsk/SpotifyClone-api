<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Exceptions\DomainException;
use App\Exceptions\SessionLimitReachedException;
use App\Http\Resources\DeviceSessionResource;
use App\Models\User;
use App\Services\Billing\SubscriptionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The device-session cap: how many machines may hold a live token for one
 * account at the same time.
 *
 * Every token this app mints goes through `issueFor()`, which is what makes the
 * cap real rather than advisory — `AuthService` no longer calls `createToken()`
 * itself, so a new login path cannot accidentally bypass the limit by
 * forgetting to check it.
 *
 * ## What it caps
 *
 * Logins, not playback. See the long note in `config/plans.php`: a
 * concurrent-*stream* limit can only be counted by whoever serves the audio,
 * and this platform never does. Two devices inside the cap can play at the same
 * time and nothing here can tell.
 *
 * ## The conflict flow
 *
 * Hitting the cap is not a failure, it is a fork:
 *
 *   1. Credentials check out, but the account is full → `issueFor()` throws
 *      {@see SessionLimitReachedException} carrying the live devices and a
 *      single-use ticket. No token is minted.
 *   2. The caller picks which devices to sign out and posts them back with
 *      the ticket to `POST /auth/sessions/resolve`. How many are needed is in
 *      the payload as `sessions_to_free` — usually one, but an account sitting
 *      several over its cap has to clear all of the excess in one go.
 *   3. `resolveConflict()` spends the ticket, kills those devices, and the
 *      login completes.
 *
 * ## Known race
 *
 * Two logins arriving in the same instant can both pass the count check and
 * both mint a token, leaving the account one session over its cap. Deliberately
 * not locked: `personal_access_tokens` has no index on `tokenable`, so holding
 * a gap lock wide enough to stop the phantom insert would serialise every login
 * in the app to protect against a window of a few milliseconds. The consequence
 * self-heals — the account is over its cap until the next login, which lists
 * all of them and makes the owner clear one.
 *
 * `Request` is constructor-injected only to read the User-Agent for the device
 * label. This class is transient (never bound as a singleton), so it receives
 * the request it was resolved during, and tests can bind a fake.
 */
final class DeviceSessionService
{
    /**
     * How long a conflict ticket stays spendable. Long enough to read a list of
     * devices and decide, short enough that a leaked one is close to worthless
     * — the ticket re-issues a session, so it is credential-grade while it
     * lives.
     */
    private const TICKET_TTL_SECONDS = 300;

    private const TICKET_PREFIX = 'device-session-conflict:';

    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly Request $request,
    ) {}

    /**
     * Mint a token for this device, or refuse because the account is full.
     *
     * @throws SessionLimitReachedException when the plan's device cap is already met
     */
    public function issueFor(User $user): string
    {
        $max = $this->subscriptions->maxSessionsFor($user);

        if ($max !== null) {
            $active = $this->activeFor($user);

            if ($active->count() >= $max) {
                throw $this->conflict($user, $active, $max);
            }
        }

        return $user->createToken($this->deviceLabel())->plainTextToken;
    }

    /**
     * Live sessions, oldest first.
     *
     * Oldest first because that ordering is the one the UI wants: the session
     * someone is most likely to be done with sits at the top of the list.
     * Expired tokens are excluded — they are already dead as credentials, so
     * counting them towards the cap would lock an account out over nothing.
     *
     * @return Collection<int, PersonalAccessToken>
     */
    public function activeFor(User $user): Collection
    {
        /** @var Collection<int, PersonalAccessToken> $tokens */
        $tokens = $user->tokens()
            ->where(static fn (Builder $query): Builder => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()))
            ->orderBy('created_at')
            ->get();

        return $tokens;
    }

    /**
     * The device list as the API returns it.
     *
     * @return list<array<string, mixed>>
     */
    public function listFor(User $user, ?int $currentId = null): array
    {
        return $this->activeFor($user)
            ->map(static fn (PersonalAccessToken $token): array => (new DeviceSessionResource($token, $currentId))->resolve())
            ->values()
            ->all();
    }

    /**
     * Sign one device out.
     *
     * @throws DomainException 404 when the id is not one of this user's live sessions
     */
    public function revoke(User $user, int $sessionId): void
    {
        $this->revokeMany($user, [$sessionId]);
    }

    /**
     * Sign several devices out at once.
     *
     * Every id is checked against `activeFor()` *before* anything is deleted, so
     * a list with one bad id changes nothing at all. The alternative — delete as
     * you go, throw on the first miss — leaves the caller having lost some
     * sessions, holding an error that says the operation failed, with no way to
     * tell which half happened.
     *
     * Scoped through `activeFor()` rather than looking ids up globally: a session
     * id is a plain auto-increment integer, so an unscoped delete would let any
     * authenticated caller sign out other accounts' devices by guessing.
     *
     * @param  list<int>  $sessionIds
     * @return int How many were signed out
     *
     * @throws DomainException 404 when any id is not one of this user's live sessions
     */
    public function revokeMany(User $user, array $sessionIds): int
    {
        $wanted = array_values(array_unique(array_map('intval', $sessionIds)));

        if ($wanted === []) {
            return 0;
        }

        $live = $this->activeFor($user)
            ->map(static fn (PersonalAccessToken $token): int => (int) $token->id)
            ->all();

        if (array_diff($wanted, $live) !== []) {
            throw DomainException::deviceSessionNotFound();
        }

        return PersonalAccessToken::query()->whereIn('id', $wanted)->delete();
    }

    /**
     * Sign every *other* device out, keeping the caller's own session alive.
     *
     * @return int How many were signed out
     */
    public function revokeOthers(User $user, ?int $keepId): int
    {
        $doomed = $this->activeFor($user)
            ->reject(static fn (PersonalAccessToken $token): bool => $keepId !== null && (int) $token->id === $keepId);

        if ($doomed->isEmpty()) {
            return 0;
        }

        return PersonalAccessToken::query()
            ->whereIn('id', $doomed->map(static fn (PersonalAccessToken $token): int => (int) $token->id)->all())
            ->delete();
    }

    /**
     * Spend a conflict ticket: sign the chosen devices out and hand back the
     * account they belonged to, ready for a token.
     *
     * Takes a *list*, not one id, because one is not always enough. An account
     * can sit well over its cap — every account did the moment the cap shipped,
     * and a downgrade from three devices to one does it again — and freeing a
     * single slot per round-trip would mean a fresh ticket, a fresh list and
     * another click for each session over the line. `sessions_to_free` in the
     * 409 tells the caller how many to send.
     *
     * The ticket dies here rather than on the way in, so a caller who names a
     * session that is not theirs gets another go at the same list instead of
     * being sent back to the password screen.
     *
     * @param  list<int>  $sessionIds
     *
     * @throws DomainException 422 when the ticket is unknown, spent or expired
     * @throws DomainException 404 when any chosen session is not one of theirs
     */
    public function resolveConflict(string $ticket, array $sessionIds): User
    {
        $userId = Cache::get(self::TICKET_PREFIX.$ticket);

        if (! is_string($userId)) {
            throw DomainException::deviceSessionTicketExpired();
        }

        $user = User::query()->find($userId);

        if (! $user instanceof User) {
            throw DomainException::deviceSessionTicketExpired();
        }

        $this->revokeMany($user, $sessionIds);

        Cache::forget(self::TICKET_PREFIX.$ticket);

        return $user;
    }

    /**
     * @param  Collection<int, PersonalAccessToken>  $active
     */
    private function conflict(User $user, Collection $active, int $max): SessionLimitReachedException
    {
        $plan = $this->subscriptions->planFor($user);
        $ticket = Str::random(64);

        Cache::put(self::TICKET_PREFIX.$ticket, (string) $user->getKey(), self::TICKET_TTL_SECONDS);

        $devices = $max === 1 ? 'one device' : "{$max} devices";

        /*
         | How many sessions have to go before this login fits. Usually 1 — but
         | an account that is several over its cap has to clear all of the
         | excess, and the client cannot work that out from `max_sessions` alone
         | without re-deriving the same arithmetic and getting the off-by-one
         | wrong. `+ 1` because the new session needs a slot of its own.
         */
        $toFree = $active->count() - $max + 1;

        return new SessionLimitReachedException(
            $toFree === 1
                ? "Your {$plan->label()} plan covers {$devices}. Sign out of one to continue here."
                : "Your {$plan->label()} plan covers {$devices}. Sign out of {$toFree} to continue here.",
            [
                'plan' => $plan->value,
                'plan_label' => $plan->label(),
                'max_sessions' => $max,
                'sessions_to_free' => $toFree,
                'resolution_token' => $ticket,
                'expires_in' => self::TICKET_TTL_SECONDS,
                /*
                 | No `current` flag on any of these: the caller holds no token
                 | yet, so none of the listed devices is theirs.
                 */
                'sessions' => $active
                    ->map(static fn (PersonalAccessToken $token): array => (new DeviceSessionResource($token))->resolve())
                    ->values()
                    ->all(),
            ],
        );
    }

    /**
     * A human-readable name for the device logging in, from its User-Agent.
     *
     * Intentionally coarse. This label exists so someone can recognise their
     * own laptop in a list of two or three, not to fingerprint a browser, so it
     * resolves to "browser on platform" and stops there. Order matters in both
     * ladders: Edge and Opera both claim to be Chrome, Chrome claims to be
     * Safari, and every mobile OS string also contains its desktop ancestor's
     * name.
     */
    private function deviceLabel(): string
    {
        $agent = trim((string) $this->request->userAgent());

        if ($agent === '') {
            return 'Unknown device';
        }

        $browser = $this->matchFirst($agent, [
            'Edg' => 'Edge',
            'OPR' => 'Opera',
            'Opera' => 'Opera',
            'SamsungBrowser' => 'Samsung Internet',
            'Firefox' => 'Firefox',
            'Chrome' => 'Chrome',
            'Safari' => 'Safari',
        ]);

        $platform = $this->matchFirst($agent, [
            'iPhone' => 'iPhone',
            'iPad' => 'iPad',
            'Android' => 'Android',
            'Windows' => 'Windows',
            'Macintosh' => 'Mac',
            'Mac OS X' => 'Mac',
            'CrOS' => 'ChromeOS',
            'Linux' => 'Linux',
        ]);

        return match (true) {
            $browser !== null && $platform !== null => "{$browser} on {$platform}",
            $browser !== null => $browser,
            $platform !== null => $platform,
            default => 'Unknown device',
        };
    }

    /**
     * @param  array<string, string>  $needles  Fragment to look for => label to use.
     */
    private function matchFirst(string $agent, array $needles): ?string
    {
        foreach ($needles as $needle => $label) {
            if (str_contains($agent, $needle)) {
                return $label;
            }
        }

        return null;
    }
}
