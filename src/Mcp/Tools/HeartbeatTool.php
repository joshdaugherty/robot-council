<?php

declare(strict_types=1);

namespace RobotCouncil\Mcp\Tools;

use Illuminate\Http\Request as HttpRequest;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use RobotCouncil\Mcp\ActsAsAgent;
use RobotCouncil\Support\Credentials;

/**
 * Says this agent's process is still there.
 *
 * Contact is recorded for every authenticated call, so a session that uses any other tool is
 * already visible. This is for a process that has nothing else to send.
 */
final class HeartbeatTool extends Tool
{
    use ActsAsAgent;

    /**
     * The tool's name.
     *
     * @return string The name.
     */
    public function name(): string
    {
        return 'presence_heartbeat';
    }

    /**
     * What the tool does.
     *
     * @return string The description.
     */
    public function description(): string
    {
        return 'Say you are still running, when you have nothing else to send. Every other tool counts '
            .'as contact too, so you only need this while you are quiet -- during a long build, for '
            .'instance. Go quiet for longer than `gone_in` and your session ends, and whatever it '
            .'held is released.';
    }

    /**
     * Report the session's presence.
     *
     * @param  Request  $request  The tool call.
     * @param  HttpRequest  $http  The HTTP request it arrived on.
     * @param  Credentials  $credentials  The configured thresholds.
     * @return ResponseFactory The session's presence.
     */
    public function handle(Request $request, HttpRequest $http, Credentials $credentials): ResponseFactory
    {
        $session = $this->session($http);

        return Response::structured([
            'session_id' => $session->id,
            'status' => $session->status->value,
            'stale_in' => $credentials->staleAfterMinutes() * 60,
            'gone_in' => $credentials->goneAfterMinutes() * 60,
        ]);
    }
}
