<?php

declare(strict_types=1);

namespace RobotCouncil\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RobotCouncil\Support\FleetPresence as Presence;

/**
 * Who is alive in the fleet, and what they are holding.
 *
 * Sessions and locks on one panel, because they answer the same question from opposite ends: a
 * session is a process that might be stuck, and a lock is what a stuck process is blocking.
 *
 * Both the harness and the machine label are charset-limited at the edge, so neither can carry a
 * `<` -- but that is a second line rather than a first, for the reason #70 records about URLs. What
 * makes this page safe is that nothing here is rendered unescaped.
 */
final class FleetPresence extends Component
{
    /**
     * How many sessions the panel lists.
     */
    public const int SESSIONS = 50;

    /**
     * How many locks the panel lists.
     */
    public const int LOCKS = 50;

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
     * Render the panel.
     *
     * @param  Presence  $presence  The presence store.
     * @return View The panel.
     */
    public function render(Presence $presence): View
    {
        $locks = $presence->locks(self::LOCKS);

        // Pinned, because whether the analyzer can resolve a package view depends on whether it
        // could boot the application, which differs between a developer's machine and CI
        /** @var view-string $template */
        $template = 'robot-council::livewire.fleet-presence';

        return view($template, [
            'sessions' => $presence->sessions(self::SESSIONS),
            'locks' => array_map($this->withLapse(...), $locks),
        ]);
    }

    /**
     * One lock, with how its lease stands rather than only when it ends.
     *
     * A lease that has run out while the row still names a holder is the state a developer is
     * looking for, so it is said in words rather than left to be worked out from a timestamp.
     *
     * @param  array<string, mixed>  $lock  The lock as the store described it.
     * @return array<string, mixed> The lock, with a `lease`.
     */
    private function withLapse(array $lock): array
    {
        $expires = $lock['expires_at'] ?? null;

        $lock['lease'] = match (true) {
            ! \is_string($expires) => 'never held',
            $lock['held'] === true => 'expires '.Carbon::parse($expires)->diffForHumans(),
            default => 'lapsed '.Carbon::parse($expires)->diffForHumans(),
        };

        return $lock;
    }
}
