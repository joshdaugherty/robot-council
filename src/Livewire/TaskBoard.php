<?php

declare(strict_types=1);

namespace RobotCouncil\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use RobotCouncil\Models\TaskStatus;
use RobotCouncil\Support\TaskList;

/**
 * The queue, as a developer sees it.
 *
 * It reads through `Support\TaskList::everything()` rather than querying `robot_council_tasks`, so
 * the ordering and the keyset predicate stay in one place and cannot drift from what an agent pages
 * through. `everything()` rather than `page()` because a signed-in developer sees the whole fleet
 * unredacted -- the decision on robot-council/core#73 -- and the method is named rather than flagged
 * so that no agent-facing call can reach it by passing a boolean.
 *
 * **This component displays another developer's agent's prose.** A task's title and description are
 * written by a machine on somebody else's laptop and rendered on this developer's screen, which is
 * why the guards in #67 and #70 exist and why nothing here renders anything unescaped.
 */
final class TaskBoard extends Component
{
    /**
     * How many tasks one page holds.
     *
     * Well under `TaskList::MAX_PAGE`, because this is a screen rather than an API response and a
     * hundred rows is not a thing anybody reads.
     */
    public const int PER_PAGE = 25;

    /**
     * The status being shown, or an empty string for every task.
     *
     * Deliberately not `#[Locked]`: this is the page's own filter and the developer sets it. It is
     * validated on the way into the query instead, because a public property arrives from the
     * client and this one reaches a `where`.
     */
    #[Url(as: 'status', keep: false)]
    public string $status = '';

    /**
     * The cursor's priority half, or null at the head of the queue.
     */
    #[Locked]
    public ?int $afterPriority = null;

    /**
     * The cursor's id half, or null at the head of the queue.
     *
     * Locked with its partner. Together they are a position in an ordering the server computed, and
     * a client that could set them could page to a row of its choosing -- harmless here, since the
     * reader may see every task anyway, but the cursor is the server's to issue and there is no
     * reason for it to be writable.
     */
    #[Locked]
    public ?int $afterId = null;

    /**
     * The interval this page refreshes on, in seconds.
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
     * Show the next page, from the cursor the last read handed back.
     *
     * @param  int  $priority  The cursor's priority half.
     * @param  int  $id  The cursor's id half.
     */
    public function showNext(int $priority, int $id): void
    {
        $this->afterPriority = $priority;
        $this->afterId = $id;
    }

    /**
     * Go back to the head of the queue.
     */
    public function showFirst(): void
    {
        $this->afterPriority = null;
        $this->afterId = null;
    }

    /**
     * Narrow to one status, or widen to every task.
     *
     * The cursor is dropped, because a position in one filtered ordering means nothing in another.
     *
     * @param  string  $status  The status to show, or an empty string for all of them.
     */
    public function showStatus(string $status): void
    {
        $this->status = $status;

        $this->showFirst();
    }

    /**
     * Render the queue.
     *
     * @param  TaskList  $tasks  The task store.
     * @return View The board.
     */
    public function render(TaskList $tasks): View
    {
        $page = $tasks->everything(
            $this->selectedStatus(),
            self::PER_PAGE,
            $this->afterPriority === null || $this->afterId === null
                ? null
                : ['priority' => $this->afterPriority, 'id' => $this->afterId],
        );

        // Pinned, because whether the analyzer can resolve a package view depends on whether it
        // could boot the application, which differs between a developer's machine and CI
        /** @var view-string $template */
        $template = 'robot-council::livewire.task-board';

        return view($template, [
            'tasks' => $page['tasks'],
            'cursor' => $page['cursor'],
            'statuses' => TaskStatus::cases(),
        ]);
    }

    /**
     * The status to filter by, refusing anything that is not one.
     *
     * `$status` is a public property, so it arrives from the client on every update and reaches a
     * `where`. `tryFrom` rather than `from`, so an unknown value widens to every task rather than
     * throwing -- a filter nobody can name is not an error worth a stack trace.
     *
     * @return TaskStatus|null The status, or null for every task.
     */
    private function selectedStatus(): ?TaskStatus
    {
        return $this->status === '' ? null : TaskStatus::tryFrom($this->status);
    }
}
