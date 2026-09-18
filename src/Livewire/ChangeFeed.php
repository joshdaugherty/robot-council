<?php

declare(strict_types=1);

namespace RobotCouncil\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RobotCouncil\Support\FleetFeed;

/**
 * The fleet's change feed, newest first.
 *
 * It reads through `Support\FleetFeed::latest()` rather than querying `robot_council_events`, so
 * the visibility rule stays in the one place #29 put it and cannot be reimplemented differently
 * here. `latest()` rather than `after()` because a signed-in developer sees every event unredacted
 * -- the decision on #73 -- and the method is named rather than flagged so that no agent-facing
 * call can reach it by passing a boolean.
 *
 * **Every body on this page was written by another developer's agent.** That is what #67 and #70
 * exist for, and why nothing here renders anything unescaped or puts a value in a URL.
 */
final class ChangeFeed extends Component
{
    /**
     * How many events the panel shows.
     */
    public const int PER_PAGE = 40;

    /**
     * The interval this panel refreshes on, in seconds.
     */
    #[Locked]
    public int $pollSeconds = Dashboard::DEFAULT_POLL_SECONDS;

    /**
     * Take the polling interval from the page that mounts this component.
     *
     * @param  int  $pollSeconds  The interval the dashboard resolved.
     */
    public function mount(int $pollSeconds = Dashboard::DEFAULT_POLL_SECONDS): void
    {
        $this->pollSeconds = $pollSeconds;
    }

    /**
     * Render the feed.
     *
     * @param  FleetFeed  $feed  The change feed.
     * @return View The panel.
     */
    public function render(FleetFeed $feed): View
    {
        // Pinned, because whether the analyzer can resolve a package view depends on whether it
        // could boot the application, which differs between a developer's machine and CI
        /** @var view-string $template */
        $template = 'robot-council::livewire.change-feed';

        return view($template, [
            'events' => array_map($this->withAge(...), $feed->latest(self::PER_PAGE)),
        ]);
    }

    /**
     * One event, with how long ago it happened rather than when.
     *
     * @param  array<string, mixed>  $event  The event as the store described it.
     * @return array<string, mixed> The event, with an `age`.
     */
    private function withAge(array $event): array
    {
        $at = $event['created_at'] ?? null;

        $event['age'] = \is_string($at) ? Carbon::parse($at)->diffForHumans() : null;

        return $event;
    }
}
