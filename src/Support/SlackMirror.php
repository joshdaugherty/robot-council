<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Contracts\Config\Repository;
use RobotCouncil\Jobs\MirrorEventToSlack;
use RobotCouncil\Models\FleetEvent;

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
     * Queue one event's mirror, once the surrounding transaction commits.
     *
     * @param  FleetEvent  $event  The event to mirror.
     */
    public function mirror(FleetEvent $event): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        // The event's ID rather than the event: the job re-reads it, so no body, no metadata, and
        // no webhook URL is written into the queue's payload
        MirrorEventToSlack::dispatch($event->id)->onQueue($this->queue())->afterCommit();
    }
}
