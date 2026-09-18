<?php

declare(strict_types=1);

namespace RobotCouncil\Livewire;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The dashboard's index.
 *
 * It renders the shell and nothing else yet. The task board, presence and locks, and the change
 * feed arrive as their own slices and mount inside this page; what this component exists to prove
 * is that the whole path works -- the route, the allowlist gate, Livewire, Mary's components, and
 * the stylesheet this package compiles and serves.
 *
 * The polling interval is read here rather than in the view, so a page that displays it and a
 * component that polls on it cannot disagree about what it is.
 */
#[Layout('robot-council::layouts.dashboard')]
final class Dashboard extends Component
{
    /**
     * The interval this page refreshes on, in seconds.
     */
    public int $pollSeconds = self::DEFAULT_POLL_SECONDS;

    /**
     * The interval used when a host has configured something unusable.
     */
    public const int DEFAULT_POLL_SECONDS = 5;

    /**
     * The longest interval a host may configure.
     */
    public const int MAX_POLL_SECONDS = 3600;

    /**
     * Read the configured interval once, when the component mounts.
     *
     * @param  Repository  $config  The application's configuration.
     */
    public function mount(Repository $config): void
    {
        $configured = $config->get('robot-council.dashboard.poll_seconds', self::DEFAULT_POLL_SECONDS);

        // A host may put anything in a published config file, and this value becomes a `wire:poll`
        // interval: a zero would ask the browser to poll as fast as it can, and a non-integer would
        // render an attribute the browser silently ignores, leaving a page that never refreshes and
        // says nothing about it
        $this->pollSeconds = \is_int($configured) && $configured >= 1 && $configured <= self::MAX_POLL_SECONDS
            ? $configured
            : self::DEFAULT_POLL_SECONDS;
    }

    /**
     * Render the page.
     *
     * @return View The dashboard index.
     */
    public function render(): View
    {
        return view('robot-council::livewire.dashboard');
    }
}
