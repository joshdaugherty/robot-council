<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RobotCouncil\Access\Ability;
use RobotCouncil\Access\Tokens;
use RobotCouncil\Http\Principal;
use RobotCouncil\Models\Lock;
use RobotCouncil\Models\LockAction;
use RobotCouncil\Support\Credentials;
use RobotCouncil\Support\Locks;
use RobotCouncil\Support\Outcome;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Takes, extends, and gives up the fleet's named leases.
 *
 * **The name is in the body, never in the path.** A Laravel route parameter does not match `/`, so
 * a lock named `branch:feature/foo` could not be addressed by a route that carried its name -- and
 * the names worth locking are exactly the ones with separators in them.
 *
 * **Validation runs before anything is written**, which is load-bearing rather than tidy. Acquiring
 * starts with an insert that ignores a unique-name conflict, and SQLite's `insert or ignore`
 * swallows a NOT NULL or CHECK violation too. A name or a TTL that should have been a 422 would
 * come back as a 409 -- an agent told to wait for a lock nobody holds.
 */
final class LockController
{
    /**
     * Do the thing the route named.
     *
     * @param  Request  $request  The incoming request.
     * @param  string  $action  The action, from the route.
     * @param  Locks  $locks  The lock store.
     * @param  Credentials  $credentials  The configured bounds.
     * @return JsonResponse The lease, or what stopped it.
     *
     * @throws AccessDeniedHttpException When the session lacks the ability this action needs.
     */
    public function __invoke(Request $request, string $action, Locks $locks, Credentials $credentials): JsonResponse
    {
        // The route's constraint is built from the enum, so only the four segments reach here and
        // no request can take this branch. It stays because the signature is `string`, and a route
        // registered by hand elsewhere would otherwise reach the store with nothing checked.
        // @pest-mutate-ignore
        $move = LockAction::tryFrom($action) ?? throw new AccessDeniedHttpException;

        $session = Principal::agentSession($request);
        $token = $session->currentAccessToken();

        if (! Tokens::allows($token, $move->ability())) {
            throw new AccessDeniedHttpException;
        }

        $request->validate([
            // The same narrow set every other agent-facing identifier carries, plus `:` and `/`
            // because `branch:feature/foo` is the kind of name worth locking. Printable ASCII was
            // not enough: a lock name goes into a feed event body that reaches every agent in the
            // fleet, and `locks:acquire` is an ability enrollment can ask for -- so 191 bytes of
            // arbitrary prose would be a cross-developer channel that routes around the
            // coordinator's ability, which is the one thing that is never requestable.
            //
            // The `D` modifier is load-bearing: without it `$` also matches before a trailing
            // newline, and a newline would reach that body.
            'name' => ['required', 'string', 'max:'.Lock::MAX_NAME, 'regex:/^[A-Za-z0-9._:\/-]+$/D'],

            'ttl' => $move->takesATtl()
                ? ['required', 'integer', 'min:1', 'max:'.$credentials->lockMaxTtlSeconds()]
                : ['prohibited'],
        ]);

        $name = $request->string('name')->value();
        $asCoordinator = Tokens::allows($token, Ability::CoordinatorDirect);

        $result = match ($move) {
            LockAction::Acquire => $locks->acquire($session, $name, $request->integer('ttl'), $asCoordinator),
            LockAction::Renew => $locks->renew($session, $name, $request->integer('ttl'), $asCoordinator),
            LockAction::Release => ['outcome' => $locks->release($session, $name, $asCoordinator), 'lock' => null],
            LockAction::ForceRelease => ['outcome' => $locks->forceRelease($session, $name), 'lock' => null],
        };

        $lock = $result['lock'];

        return new JsonResponse(array_filter([
            'name' => $name,
            'held' => $result['outcome'] === Outcome::Applied && $lock instanceof Lock,

            // A duration as well as an instant. The instant is what a fence-guarded action compares
            // against, and the duration is what a helper on a machine with a wrong clock can use.
            'fence' => $lock?->fence,
            'expires_at' => $lock?->expires_at?->toIso8601String(),

            // Derived from the lease rather than echoed from the request. The time spent taking the
            // lock row and the feed sentinel is inside the lease and outside the number the caller
            // asked for, so echoing the TTL would always be at least a little long -- and a helper
            // scheduling its renewal from it would schedule after the lease had lapsed.
            'expires_in' => $lock?->expires_at === null
                ? null
                : max(0, Carbon::now()->diffInSeconds($lock->expires_at, false)),
        ], static fn (mixed $value): bool => $value !== null), $result['outcome']->status());
    }
}
