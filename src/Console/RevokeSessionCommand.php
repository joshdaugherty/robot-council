<?php

declare(strict_types=1);

namespace RobotCouncil\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Support\SessionPresence;

/**
 * Revokes one agent session, leaving every other session on the same installation alone.
 *
 * The session is marked gone as well as stripped of its tokens. Deleting the tokens alone would
 * not be revocation: the installation still holds its own credential, and would renew the session
 * straight back into service.
 */
#[Description('Revoke one robot-council agent session')]
#[Signature("robot-council:revoke-session {session : The agent session's ID}")]
final class RevokeSessionCommand extends Command
{
    /**
     * End the session.
     *
     * @param  SessionPresence  $presence  The presence store, which owns ending a session.
     * @return int The command's exit code.
     */
    public function handle(SessionPresence $presence): int
    {
        $id = $this->argument('session');

        // With the installation, which the `session.gone` event names
        $session = AgentSession::query()->with('installation')->whereKey($id)->first();

        if (! $session instanceof AgentSession) {
            $this->components->error(sprintf('No agent session with ID %s.', Argument::text($id)));

            return self::FAILURE;
        }

        $deleted = $presence->revoke($session);

        $this->components->info(sprintf(
            'Ended agent session %d on installation %d. %d token(s) deleted.',
            $session->id,
            $session->installation_id,
            $deleted
        ));

        return self::SUCCESS;
    }
}
