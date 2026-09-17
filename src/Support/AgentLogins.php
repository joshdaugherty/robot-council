<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\GithubIdentity;

/**
 * The GitHub login behind an agent session, which is the provenance the fleet decides trust on.
 *
 * Shared by the change feed and by the task list, so both derive it the same way. It is derived by
 * the server on every read and never taken from what a poster claimed: a login is what tells one
 * developer's agent whose words and whose work it is looking at, so a caller that could supply it
 * could impersonate anyone.
 */
final class AgentLogins
{
    /**
     * The login behind each of a set of agent sessions.
     *
     * Resolved in two queries rather than two per session, because a full page of the feed would
     * otherwise be four hundred round trips.
     *
     * @param  array<mixed>  $sessionIds  The session IDs referred to, some of them null.
     * @return array<int, string> Logins, keyed by agent session ID.
     */
    public function forSessions(array $sessionIds): array
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
