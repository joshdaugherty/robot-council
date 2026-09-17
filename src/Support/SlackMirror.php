<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RobotCouncil\Jobs\MirrorEventToSlack;
use RobotCouncil\Models\FleetEvent;
use Throwable;

/**
 * Whether the fleet's events are mirrored to Slack, and where the job that does it runs.
 *
 * Slack is a one-way narration channel for humans. Nothing in the package reads from it, and no
 * coordination decision depends on it, so a Slack outage costs visibility and never correctness.
 */
final class SlackMirror
{
    /**
     * @param  Repository  $config  The host application's configuration repository.
     */
    public function __construct(private readonly Repository $config) {}

    /**
     * Whether a webhook is configured at all.
     *
     * @return bool True when events should be mirrored.
     */
    public function isEnabled(): bool
    {
        return $this->webhookUrl() !== null;
    }

    /**
     * The configured webhook URL.
     *
     * Read at the moment it is needed rather than held anywhere, so it does not travel in a queued
     * job's payload, where it would sit in the jobs table and in any failed-job record.
     *
     * @return string|null The URL, or null when no mirror is configured.
     */
    public function webhookUrl(): ?string
    {
        $url = $this->config->get('robot-council.slack.webhook_url');

        return \is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * The queue the mirror job runs on.
     *
     * @return string The queue name.
     */
    public function queue(): string
    {
        $queue = $this->config->get('robot-council.slack.queue');

        return \is_string($queue) && $queue !== '' ? $queue : 'robot-council-slack';
    }

    /**
     * The queue connection the mirror runs on, or null for the application's default.
     *
     * @return string|null The connection name.
     */
    public function connection(): ?string
    {
        $connection = $this->config->get('robot-council.slack.connection');

        return \is_string($connection) && $connection !== '' ? $connection : null;
    }

    /**
     * Whether an event of this kind is mirrored at all.
     *
     * Narration is restricted in the feed by reader, and that rule is about what one developer's
     * agent may read from another's. Slack is a human surface rather than an agent one, so the
     * default mirrors it -- being a narration channel for humans is the reason to have one -- but
     * it is a real widening, and a host that does not want it can say so.
     *
     * @param  FleetEvent  $event  The event in question.
     * @return bool True when it should be mirrored.
     */
    public function mirrors(FleetEvent $event): bool
    {
        if (! $event->type->isRestricted()) {
            return true;
        }

        return $this->config->get('robot-council.slack.mirror_restricted', true) !== false;
    }

    /**
     * Queue one event's mirror, once the surrounding transaction commits.
     *
     * @param  FleetEvent  $event  The event to mirror.
     */
    public function mirror(FleetEvent $event): void
    {
        if (! $this->isEnabled() || ! $this->mirrors($event)) {
            return;
        }

        $id = $event->id;

        // Queued from a callback this class owns, rather than by `dispatch()->afterCommit()`, so
        // that the try/catch is inside it.
        //
        // `DatabaseTransactionRecord::executeCallbacks()` has no try/catch of its own, and Laravel
        // runs it AFTER `DB::transaction()` has committed. Anything thrown there escapes the
        // transaction with the row already durably written and no rollback available -- so a queue
        // backend that is unreachable, or a queue that was never provisioned, would turn a
        // committed agent enrollment into a 500 whose session row and token already exist and were
        // never returned. Mirroring is best-effort visibility; it does not get to fail a write.
        //
        // With no transaction open the callback runs immediately, so this is the same shape either
        // way.
        DB::afterCommit(function () use ($id): void {
            try {
                MirrorEventToSlack::dispatch($id)
                    ->onConnection($this->connection())
                    ->onQueue($this->queue());
            } catch (Throwable $throwable) {
                // The class, never the message: a queue driver's exception can carry a connection
                // string, a queue URL, or credentials
                Log::warning('robot-council could not queue a Slack mirror.', [
                    'event_id' => $id,
                    'exception' => $throwable::class,
                ]);
            }
        });
    }
}
