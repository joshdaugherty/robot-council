<?php

declare(strict_types=1);

namespace RobotCouncil\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\RobotCouncilServiceProvider;
use RobotCouncil\Support\HostUsers;
use RobotCouncil\Support\SlackMirror;
use RuntimeException;

/**
 * Posts one event to Slack, for humans to read.
 *
 * Slack is a one-way narration channel. Nothing in the package reads from it, so this job failing
 * costs visibility and never correctness -- which is why it runs on its own queue, away from
 * anything a request is waiting on.
 *
 * It carries an event's ID rather than the event. A queued job's payload is written to the jobs
 * table and copied into any failed-job record, so an event body, its metadata, and the webhook URL
 * would all be sitting in the database for as long as those rows live.
 */
#[Tries(5)]
final class MirrorEventToSlack implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * @param  int  $eventId  The event to mirror.
     */
    public function __construct(public readonly int $eventId) {}

    /**
     * Keep the fleet inside Slack's tolerance without every worker discovering it separately.
     *
     * @return array<int, object> The middleware this job runs through.
     */
    public function middleware(): array
    {
        return [new RateLimited(RobotCouncilServiceProvider::SLACK_LIMITER)];
    }

    /**
     * Post the event.
     *
     * @param  SlackMirror  $slack  Where to post, read now rather than carried here.
     * @param  HostUsers  $hostUsers  The developer behind the posting session.
     *
     * @throws RuntimeException When Slack could not be reached, or refused the message.
     */
    public function handle(SlackMirror $slack, HostUsers $hostUsers): void
    {
        $url = $slack->webhookUrl();

        // Configuration changed between queueing and running, which is a reason to stop rather
        // than to fail
        if ($url === null) {
            return;
        }

        $event = FleetEvent::query()->with('session')->find($this->eventId);

        if (! $event instanceof FleetEvent) {
            return;
        }

        try {
            $response = Http::asJson()->post($url, ['text' => $this->text($event, $hostUsers)]);
        } catch (ConnectionException) {
            // Rethrown without the original. Its message carries the full webhook URL, and an
            // exception is written to the failed-jobs table, to the log, and to whatever error
            // reporter the host application uses.
            throw new RuntimeException('robot-council could not reach the Slack webhook.');
        }

        if ($response->status() === 429) {
            $this->release($this->pauseFor($response->header('Retry-After')));

            return;
        }

        if ($response->failed()) {
            throw new RuntimeException(sprintf('Slack refused a mirrored event with HTTP %d.', $response->status()));
        }
    }

    /**
     * The one line Slack shows.
     *
     * Only the event's type, who is speaking, and what they said. Never `meta`, and never anything
     * a later event type might carry in it: a payload, a result, a token, or a code.
     *
     * @param  FleetEvent  $event  The event to describe.
     * @param  HostUsers  $hostUsers  The developer behind the posting session.
     * @return string The message text.
     */
    private function text(FleetEvent $event, HostUsers $hostUsers): string
    {
        $session = $event->session;

        $actor = $session === null
            ? 'robot-council'
            : ($hostUsers->githubLoginForKey($session->user_id) ?? 'an unknown developer');

        // Truncated before escaping, so a cut never lands inside an entity and leaves `&am`
        $body = Str::limit((string) $event->body, 500);

        return sprintf(
            '[%s] %s: %s',
            self::escape($event->type->value),
            self::escape($actor),
            self::escape($body)
        );
    }

    /**
     * Escape the three characters Slack reads as markup.
     *
     * The ampersand goes first, or the escapes this adds would be escaped again.
     *
     * @param  string  $text  The text to escape.
     * @return string The text, safe to place in a Slack message.
     */
    private static function escape(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }

    /**
     * How long Slack asked the fleet to wait.
     *
     * Deliberately not named `retryAfter` or `backoff`. Both are framework hooks on a queued job,
     * and Rector's Laravel set renames the first to the second -- which turned this private helper
     * into a method `Illuminate\Queue\Queue` calls itself, with a signature it does not have.
     *
     * @param  string|null  $header  Slack's `Retry-After`, in seconds.
     * @return int A delay in seconds, bounded so a malformed header cannot stall the queue.
     */
    private function pauseFor(?string $header): int
    {
        $seconds = \is_string($header) && ctype_digit($header) ? (int) $header : 0;

        return max(1, min($seconds, 300));
    }
}
