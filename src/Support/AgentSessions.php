<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RobotCouncil\Access\Tokens;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\AgentSessionStatus;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Models\Installation;

/**
 * Starting, renewing, and ending the session one agent process runs under.
 *
 * A session token is short-lived on purpose: the helper renews it without a restart and without a
 * human, so the window a leaked one is useful in is minutes rather than the installation's month.
 */
final class AgentSessions
{
    /**
     * The name Sanctum records against a session token.
     */
    public const string TOKEN_NAME = 'robot-council agent session';

    /**
     * @param  Credentials  $credentials  The configured lifetimes.
     * @param  FleetEvents  $events  The change feed.
     */
    public function __construct(
        private readonly Credentials $credentials,
        private readonly FleetEvents $events
    ) {}

    /**
     * Start a session for one agent process, and issue its first token.
     *
     * @param  Installation  $installation  The installation the process is running under.
     * @param  string|null  $projectId  The repository or workspace the process named, if any.
     * @return IssuedCredential<AgentSession> The session and its plaintext token.
     */
    public function start(Installation $installation, ?string $projectId): IssuedCredential
    {
        return DB::transaction(function () use ($installation, $projectId): IssuedCredential {
            $current = $this->locked($installation);

            $abilities = $current->abilities();

            $session = AgentSession::query()->create([
                'installation_id' => $installation->getKey(),

                // Copied from the installation rather than taken from the request, so a process
                // cannot start a session belonging to another developer
                'user_id' => $current->user_id,
                'status' => AgentSessionStatus::Active,
                'last_seen_at' => Carbon::now(),
                'project_id' => $projectId,
            ]);

            // In the same transaction as the session it describes, so a failure here leaves
            // neither the session nor a feed entry claiming one exists
            $this->events->record(
                FleetEventType::SessionEnrolled,
                $session,
                sprintf('%s on %s started a session.', $current->harness, $current->machine_label),
                ['installation_id' => $current->id, 'project_id' => $projectId]
            );

            return new IssuedCredential($session, $this->issueToken($session, $abilities), $abilities);
        });
    }

    /**
     * Replace a session's token with a fresh one, and refuse the old one from then on.
     *
     * @param  Installation  $installation  The installation the session belongs to.
     * @param  AgentSession  $session  The session to renew.
     * @return IssuedCredential<AgentSession> The session and its new plaintext token.
     */
    public function renew(Installation $installation, AgentSession $session): IssuedCredential
    {
        return DB::transaction(function () use ($installation, $session): IssuedCredential {
            $current = $this->locked($installation);

            $abilities = $current->abilities();

            // Delete first: a renewal that failed afterwards leaves a session with no token,
            // which the helper recovers from by starting a new session
            $session->tokens()->delete();

            $session->forceFill(['last_seen_at' => Carbon::now()])->save();

            return new IssuedCredential($session, $this->issueToken($session, $abilities), $abilities);
        });
    }

    /**
     * End a session: its tokens stop working, and it can never be renewed.
     *
     * @param  AgentSession  $session  The session to end.
     * @return int How many tokens were deleted.
     */
    public function end(AgentSession $session): int
    {
        return DB::transaction(function () use ($session): int {
            $deleted = Tokens::deleted($session->tokens()->delete());

            // Marked gone as well as stripped of tokens, because an installation that still holds
            // its own credential could otherwise renew the session straight back into service
            $session->forceFill(['status' => AgentSessionStatus::Gone])->save();

            return $deleted;
        });
    }

    /**
     * Re-read the installation inside the transaction, holding its row.
     *
     * The instance a request arrives with was loaded by the guard before any of this ran, so its
     * abilities are whatever they were then. Without the lock, an admin revoking an ability can
     * commit between that load and this insert: the revoking command's own loop sees no session to
     * rewrite, the new token is minted from the stale attributes, and the operator is told the
     * revocation touched every live token while one carrying the revoked ability has just been
     * issued for the next hour.
     *
     * @param  Installation  $installation  The installation the request authenticated as.
     * @return Installation The row as it stands now, held until the transaction ends.
     */
    private function locked(Installation $installation): Installation
    {
        $current = Installation::query()->whereKey($installation->getKey())->lockForUpdate()->first();

        return $current instanceof Installation ? $current : $installation;
    }

    /**
     * Issue one session token.
     *
     * The abilities are read from the installation on every issue rather than copied at enrollment,
     * so one an admin revoked is gone from the next token even though the row was written weeks ago.
     *
     * @param  AgentSession  $session  The session the token authenticates as.
     * @param  list<string>  $abilities  The abilities to mint it with.
     * @return string The plaintext token.
     */
    private function issueToken(AgentSession $session, array $abilities): string
    {
        return $session->createToken(
            self::TOKEN_NAME,
            $abilities,
            $this->credentials->sessionTokenExpiry()
        )->plainTextToken;
    }
}
