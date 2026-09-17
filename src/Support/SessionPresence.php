<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RobotCouncil\Access\Tokens;
use RobotCouncil\Events\SessionGone;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\AgentSessionStatus;
use RobotCouncil\Models\FleetEventType;

/**
 * Which agent sessions are alive: what contact updates, what the sweep changes, and how a session
 * ends.
 *
 * **No harness is asked to keep a process running.** Presence is inferred from contact, which every
 * authenticated agent request is, so a harness that only ever reads the feed is as visible as one
 * that narrates. A process with nothing to send posts a heartbeat instead.
 *
 * **Every transition is a conditional update, and the count of changed rows is the decision.** Each
 * one names the status it is moving from and, where a clock decides it, the contact time it read.
 * So a request that lands between the sweep's read and its write keeps its session active, a second
 * end changes nothing, and two sweeps running at once produce one transition and one event between
 * them. Nothing here takes a lock, and nothing needs one: the database's own row-level write is the
 * serialization point, and losing a race is always the outcome of doing nothing.
 */
final class SessionPresence
{
    /**
     * The reason recorded against a session the process itself ended.
     */
    public const string ENDED = 'ended';

    /**
     * The reason recorded against a session an admin revoked.
     */
    public const string REVOKED = 'revoked';

    /**
     * The reason recorded against a session the sweep found silent.
     */
    public const string TIMEOUT = 'timeout';

    /**
     * How many sessions one read of the sweep's candidates holds.
     */
    private const int CHUNK = 100;

    /**
     * @param  Credentials  $credentials  The configured thresholds.
     * @param  FleetEvents  $events  The change feed.
     * @param  SessionReleases  $releases  What runs at the end of every sweep.
     * @param  Dispatcher  $dispatcher  The application's event dispatcher.
     */
    public function __construct(
        private readonly Credentials $credentials,
        private readonly FleetEvents $events,
        private readonly SessionReleases $releases,
        private readonly Dispatcher $dispatcher
    ) {}

    /**
     * Record that a session's process is alive, and bring it back if it had gone quiet.
     *
     * Called for every authenticated agent request, so it is the one write on the hot path: an
     * active session costs a single update and nothing else. A session that has gone is left
     * exactly as it is -- its claims were released, so nothing it sends afterwards can be acted on,
     * and its token is refused before this is reached.
     *
     * @param  AgentSession  $session  The session the request authenticated as.
     */
    public function sighted(AgentSession $session): void
    {
        if ($session->hasGone()) {
            return;
        }

        if ($session->hasGoneQuiet() && $this->resume($session)) {
            return;
        }

        // Also the path a session takes when it lost the race to resume, which means another
        // request of its own made it active a moment ago and wrote the event for it
        $session->forceFill(['last_seen_at' => Carbon::now()])->save();
    }

    /**
     * Mark the sessions that have stopped answering, then run the release steps.
     *
     * The gone pass runs first, so a session that has been silent past both thresholds moves
     * straight to gone and writes one event rather than two in the same run.
     *
     * @return array{stale: int, gone: int} How many sessions each pass changed.
     */
    public function sweep(): array
    {
        $gone = $this->pass($this->credentials->goneCutoff(), AgentSessionStatus::Gone);
        $stale = $this->pass($this->credentials->staleCutoff(), AgentSessionStatus::Stale);

        // After the passes, and on every sweep whether or not either of them changed anything:
        // the steps exist to catch what a missed `SessionGone` left holding
        $this->releases->run();

        return ['stale' => $stale, 'gone' => $gone];
    }

    /**
     * End a session because its process said so.
     *
     * @param  AgentSession  $session  The session to end.
     * @return int How many tokens were deleted, and none when it had already gone.
     */
    public function end(AgentSession $session): int
    {
        return $this->goesNow($session, self::ENDED, null) ?? 0;
    }

    /**
     * End a session because an admin revoked it.
     *
     * @param  AgentSession  $session  The session to revoke.
     * @return int How many tokens were deleted, and none when it had already gone.
     */
    public function revoke(AgentSession $session): int
    {
        return $this->goesNow($session, self::REVOKED, null) ?? 0;
    }

    /**
     * Move every session silent since a cutoff into one state.
     *
     * Read in chunks and written one at a time, because the write has to be conditional on what the
     * read saw: a single `update ... where last_seen_at <= ?` would be atomic and would still be
     * wrong, because it could not say which sessions it changed, and `SessionGone` has to be
     * dispatched once per session rather than once per sweep.
     *
     * @param  Carbon  $cutoff  The contact time at or before which a session qualifies.
     * @param  AgentSessionStatus  $into  The state to move qualifying sessions into.
     * @return int How many sessions changed.
     */
    private function pass(Carbon $cutoff, AgentSessionStatus $into): int
    {
        $changed = 0;

        $this->candidates($cutoff, $into)->chunkById(
            self::CHUNK,
            function (Collection $sessions) use ($cutoff, $into, &$changed): void {
                foreach ($sessions as $session) {
                    $changed += $this->move($session, $cutoff, $into) ? 1 : 0;
                }
            }
        );

        return $changed;
    }

    /**
     * The sessions a pass should look at.
     *
     * A session moves to stale only from active, and to gone from either -- a process silent for
     * longer than the gone threshold has gone whether or not a sweep ever saw it stale.
     *
     * @param  Carbon  $cutoff  The contact time at or before which a session qualifies.
     * @param  AgentSessionStatus  $into  The state the pass is moving sessions into.
     * @return Builder<AgentSession> The candidate query.
     */
    private function candidates(Carbon $cutoff, AgentSessionStatus $into): Builder
    {
        return AgentSession::query()
            ->with('installation')
            ->whereIn('status', array_map(
                static fn (AgentSessionStatus $status): string => $status->value,
                $this->movesFrom($into)
            ))
            ->where('last_seen_at', '<=', $cutoff);
    }

    /**
     * The states a session may be in to enter another one.
     *
     * @param  AgentSessionStatus  $into  The state being entered.
     * @return list<AgentSessionStatus> The states it may be entered from.
     */
    private function movesFrom(AgentSessionStatus $into): array
    {
        return match ($into) {
            AgentSessionStatus::Stale => [AgentSessionStatus::Active],
            AgentSessionStatus::Gone => [AgentSessionStatus::Active, AgentSessionStatus::Stale],
            AgentSessionStatus::Active => [AgentSessionStatus::Stale],
        };
    }

    /**
     * Move one session the sweep read, if it is still where the read found it.
     *
     * @param  AgentSession  $session  The session the sweep read.
     * @param  Carbon  $cutoff  The contact time the read qualified it against.
     * @param  AgentSessionStatus  $into  The state to move it into.
     * @return bool True when this call was the transition.
     */
    private function move(AgentSession $session, Carbon $cutoff, AgentSessionStatus $into): bool
    {
        if ($into === AgentSessionStatus::Gone) {
            return $this->goesNow($session, self::TIMEOUT, $cutoff) !== null;
        }

        return DB::transaction(function () use ($session, $cutoff, $into): bool {
            if ($this->conditionally($session, $into, $cutoff) !== 1) {
                return false;
            }

            $this->events->record(
                FleetEventType::SessionStale,
                $session,
                sprintf('%s stopped answering.', $this->describe($session)),
                ['installation_id' => $session->installation_id, 'quiet_since' => $session->last_seen_at->toIso8601String()]
            );

            return true;
        });
    }

    /**
     * Bring a stale session back, if it is still stale.
     *
     * @param  AgentSession  $session  The session that made contact.
     * @return bool True when this call was the transition.
     */
    private function resume(AgentSession $session): bool
    {
        return DB::transaction(function () use ($session): bool {
            if ($this->conditionally($session, AgentSessionStatus::Active, null) !== 1) {
                return false;
            }

            $this->events->record(
                FleetEventType::SessionResumed,
                $session,
                sprintf('%s is answering again.', $this->describe($session)),
                ['installation_id' => $session->installation_id]
            );

            return true;
        });
    }

    /**
     * End a session, if it has not already ended.
     *
     * The tokens go with the transition rather than beside it. A session marked gone is already
     * refused on every route, so deleting them is not what stops it; it is what stops the rows
     * accumulating for sessions nobody will authenticate again.
     *
     * @param  AgentSession  $session  The session to end.
     * @param  string  $reason  What ended it.
     * @param  Carbon|null  $cutoff  The contact time the sweep qualified it against, when a sweep
     *                               is what is ending it.
     * @return int|null How many tokens were deleted, or null when it had already gone.
     */
    private function goesNow(AgentSession $session, string $reason, ?Carbon $cutoff): ?int
    {
        return DB::transaction(function () use ($session, $reason, $cutoff): ?int {
            if ($this->conditionally($session, AgentSessionStatus::Gone, $cutoff) !== 1) {
                return null;
            }

            $deleted = Tokens::deleted($session->tokens()->delete());

            $this->events->record(
                FleetEventType::SessionGone,
                $session,
                sprintf('%s ended.', $this->describe($session)),
                ['installation_id' => $session->installation_id, 'reason' => $reason]
            );

            // After the commit, so a listener never releases what a rollback would have kept, and
            // exactly once, because only the call that changed the row reaches this line
            $this->dispatcher->dispatch(new SessionGone($session, $reason));

            return $deleted;
        });
    }

    /**
     * Write one transition, and say whether the row was still where the caller last saw it.
     *
     * The in-memory session is updated only when the row was, so a caller that lost the race keeps
     * reading the state it actually has rather than the one it tried to write.
     *
     * @param  AgentSession  $session  The session to move.
     * @param  AgentSessionStatus  $into  The state to move it into.
     * @param  Carbon|null  $cutoff  The contact time to require, when a clock decides the move.
     * @return int How many rows changed, which is one or none.
     */
    private function conditionally(AgentSession $session, AgentSessionStatus $into, ?Carbon $cutoff): int
    {
        $query = AgentSession::query()
            ->whereKey($session->getKey())
            ->whereIn('status', array_map(
                static fn (AgentSessionStatus $status): string => $status->value,
                $this->movesFrom($into)
            ));

        if ($cutoff instanceof Carbon) {
            // The half of the condition the sweep needs: a session heard from since the read is a
            // session that is answering, whatever the read said a moment ago
            $query->where('last_seen_at', '<=', $cutoff);
        }

        $values = ['status' => $into->value];

        // Only a resumption sets the contact time, because that is the one transition contact
        // caused. A sweep must leave it alone: it is the clock the next threshold measures.
        if ($into === AgentSessionStatus::Active) {
            $values['last_seen_at'] = Carbon::now();
        }

        $changed = $query->update($values);

        if ($changed === 1) {
            $session->forceFill($values)->syncOriginal();
        }

        return $changed;
    }

    /**
     * How a session reads in the feed: the harness and machine a human would recognize.
     *
     * @param  AgentSession  $session  The session to describe.
     * @return string The description.
     */
    private function describe(AgentSession $session): string
    {
        return sprintf('%s on %s', $session->installation->harness, $session->installation->machine_label);
    }
}
