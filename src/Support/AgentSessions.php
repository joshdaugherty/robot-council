<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RobotCouncil\Access\Tokens;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\AgentSessionStatus;
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
     */
    public function __construct(private readonly Credentials $credentials) {}

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
            $session = AgentSession::query()->create([
                'installation_id' => $installation->getKey(),

                // Copied from the installation rather than taken from the request, so a process
                // cannot start a session belonging to another developer
                'user_id' => $installation->user_id,
                'status' => AgentSessionStatus::Active,
                'last_seen_at' => Carbon::now(),
                'project_id' => $projectId,
            ]);

            return new IssuedCredential($session, $this->issueToken($session, $installation));
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
            // Delete first: a renewal that failed afterwards leaves a session with no token,
            // which the helper recovers from by starting a new session
            $session->tokens()->delete();

            $session->forceFill(['last_seen_at' => Carbon::now()])->save();

            return new IssuedCredential($session, $this->issueToken($session, $installation));
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
     * Issue one session token carrying the installation's abilities as they stand now.
     *
     * Read from the installation on every issue rather than copied at enrollment, so an ability an
     * admin revoked is gone from the next token even though the row was written weeks ago.
     *
     * @param  AgentSession  $session  The session the token authenticates as.
     * @param  Installation  $installation  The installation whose abilities the token carries.
     * @return string The plaintext token.
     */
    private function issueToken(AgentSession $session, Installation $installation): string
    {
        return $session->createToken(
            self::TOKEN_NAME,
            $installation->abilities(),
            $this->credentials->sessionTokenExpiry()
        )->plainTextToken;
    }
}
