<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Contracts\Database\Eloquent\Builder;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Models\GithubIdentity;

/**
 * Reads the change feed for one agent session, applying the visibility rule decided in #29.
 *
 * **Narration is the only restricted kind.** An agent reads narration from its own developer's
 * sessions, and from any session that held `coordinator:direct` when it posted. State changes and
 * directives reach everyone, because they describe the fleet rather than one agent's opinion of it.
 *
 * The reason the rule is worth this much care: task, event, and directive content is untrusted
 * input to an agent that may have shell access. Narrowing whose words reach whom is what keeps one
 * developer's agent from putting instructions in front of another's.
 */
final class FleetFeed
{
    /**
     * The most events one read returns, whatever the caller asks for.
     */
    public const int MAX_PAGE = 200;

    /**
     * Read the events after a cursor that this session may see.
     *
     * **The page is a window of IDs, not a window of results.** Applying the limit after the
     * visibility filter would mean a reader whose window is entirely another developer's narration
     * gets an empty page and a cursor that cannot move -- so every later poll rescans the same
     * growing tail, for as long as the feed lives. Any holder of `events:post` could arrange that
     * for the whole fleet by narrating. Paging the ID space instead means a page may be short, or
     * empty, but the cursor always advances.
     *
     * @param  AgentSession  $reader  The session doing the reading.
     * @param  int  $after  The last event ID the reader has already seen.
     * @param  int  $limit  How many events to examine.
     * @return array{events: list<array<string, mixed>>, cursor: int} The visible events and where
     *                                                                to read from next.
     */
    public function after(AgentSession $reader, int $after, int $limit): array
    {
        $window = FleetEvent::query()
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit(max(1, min($limit, self::MAX_PAGE)))
            ->pluck('id');

        if ($window->isEmpty()) {
            return ['events' => [], 'cursor' => $after];
        }

        // Everything up to here has been examined, whether or not this reader may see it
        $highest = $window->max();

        $cursor = \is_int($highest) ? $highest : (int) (\is_numeric($highest) ? $highest : $after);

        $events = FleetEvent::query()
            ->whereKey($window->all())
            ->where(function (Builder $query) use ($reader): void {
                // Everything that is not narration, plus the narration this reader may see
                // Asked of the enum rather than hardcoded, so a later restricted type is
                // restricted by declaring itself so rather than by somebody remembering to edit
                // this clause. Getting that wrong fails open.
                $query->whereNotIn('type', FleetEventType::restrictedValues())
                    ->orWhere('posted_with_coordinator', true)
                    ->orWhereIn(
                        'agent_session_id',
                        AgentSession::query()->select('id')->where('user_id', $reader->user_id)
                    );
            })
            ->orderBy('id')
            ->get();

        $logins = $this->loginsFor($events->pluck('agent_session_id')->all());

        /** @var list<array<string, mixed>> $described */
        $described = $events->map(fn (FleetEvent $event): array => $this->describe($event, $logins))->values()->all();

        return ['events' => $described, 'cursor' => $cursor];
    }

    /**
     * One event as a reader sees it, with the provenance the fleet decides trust on.
     *
     * @param  FleetEvent  $event  The event.
     * @param  array<int, string>  $logins  GitHub logins, keyed by agent session ID.
     * @return array<string, mixed> The event.
     */
    private function describe(FleetEvent $event, array $logins): array
    {
        return [
            'id' => $event->id,
            'type' => $event->type->value,
            'body' => $event->body,
            'meta' => $event->meta,
            'created_at' => $event->created_at?->toIso8601String(),

            // Derived by the server on every read, never taken from what the poster claimed
            'actor' => [
                'session_id' => $event->agent_session_id,
                'github_login' => $event->agent_session_id === null
                    ? null
                    : ($logins[$event->agent_session_id] ?? null),
                'coordinator_direct' => $event->posted_with_coordinator,
            ],
        ];
    }

    /**
     * The GitHub login behind each of a set of agent sessions.
     *
     * Resolved in two queries rather than two per event, because a full page of narration would
     * otherwise be four hundred round trips.
     *
     * @param  array<mixed>  $sessionIds  The session IDs the page referred to, some of them null.
     * @return array<int, string> Logins, keyed by agent session ID.
     */
    private function loginsFor(array $sessionIds): array
    {
        $ids = array_values(array_unique(array_filter($sessionIds, is_int(...))));

        if ($ids === []) {
            return [];
        }

        $sessions = AgentSession::query()->whereKey($ids)->get(['id', 'user_id']);

        $byUser = GithubIdentity::query()
            ->whereIn('user_id', $sessions->pluck('user_id')->unique()->all())
            ->get(['user_id', 'github_login'])
            ->pluck('github_login', 'user_id');

        $logins = [];

        foreach ($sessions as $session) {
            $login = $byUser->get($session->user_id);

            if (\is_string($login)) {
                $logins[$session->id] = $login;
            }
        }

        return $logins;
    }
}
