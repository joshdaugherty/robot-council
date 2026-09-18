<?php

declare(strict_types=1);

/**
 * The queue as a developer sees it: what is shown, whose it is, and how far the page reaches.
 *
 * @command  vendor/bin/pest --compact tests/TaskBoardTest.php
 */

use Livewire\Livewire;
use RobotCouncil\Access\Ability;
use RobotCouncil\Livewire\TaskBoard;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\Task;
use RobotCouncil\Models\TaskStatus;
use RobotCouncil\Models\TaskTransition;
use RobotCouncil\Support\TaskList;
use RobotCouncil\Support\Tasks;
use RobotCouncil\Tests\TestCase;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242, 77]);

    $this->developer = $this->enrollDeveloper(4242);

    $this->installation = $this->approveInstallation($this->developer, [
        Ability::TasksCreate->value,
        Ability::TasksClaim->value,
    ]);

    [$this->session, $this->token] = $this->startAgentSession($this->installation);

    $this->actingAs($this->developer, 'web');
});

/**
 * A session belonging to a second developer, whose work is nobody else's to claim.
 *
 * @param  TestCase  $case  The test case.
 * @param  list<string>  $abilities  What the installation is granted.
 * @return array{AgentSession, string} The session and its token.
 */
function otherDeveloperSession(TestCase $case, array $abilities = [Ability::TasksCreate->value]): array
{
    $other = $case->enrollDeveloper(77, login: 'somebody-else');

    $installation = $case->approveInstallation($other, $abilities, machineLabel: 'their-box');

    return $case->startAgentSession($installation);
}

it('lists a task with its content, status, priority and both sides of its provenance', function (): void {
    [$theirs] = otherDeveloperSession($this);

    $tasks = app(Tasks::class);

    $task = $tasks->create($this->session, [
        'title' => 'Rebuild the search index',
        'priority' => 7,
        'project_id' => 'acme/search',
    ], withCoordinator: false);

    $tasks->transition($task->id, TaskTransition::Claim, $this->session, asCoordinator: false);

    Livewire::test(TaskBoard::class)
        ->assertSee('Rebuild the search index')
        ->assertSee('acme/search')
        ->assertSee(TaskStatus::Claimed->value)
        ->assertSee('7')
        ->assertSee('octodev');

    expect($theirs)->not->toBeNull();
});

it("shows another developer's task in full, which is what #73 decided", function (): void {
    // #25 redacts a task's content from an agent that cannot claim it, because an agent should not
    // read text that might instruct it. A human reading a dashboard is not vulnerable that way, and
    // a supervisor who cannot read the queue is not supervising -- so the board calls
    // `TaskList::everything()` and this is the assertion that says so.
    [$theirSession] = otherDeveloperSession($this);

    app(Tasks::class)->create($theirSession, [
        'title' => 'Something only they could claim',
        'description' => 'And the description too.',
    ], withCoordinator: false);

    // The control: the same task, read as this developer's own agent would read it, is redacted
    $asAgent = app(TaskList::class)
        ->page(null, 10, $this->session, asCoordinator: false);

    expect($asAgent['tasks'][0]['title'])->toBeNull()
        ->and($asAgent['tasks'][0]['readable'])->toBeFalse();

    Livewire::test(TaskBoard::class)
        ->assertSee('Something only they could claim')
        ->assertSee('somebody-else');
});

it('marks a coordinator-created task without making the reader check who filed it', function (): void {
    [$coordinator] = otherDeveloperSession($this, [Ability::CoordinatorDirect->value, Ability::TasksCreate->value]);

    app(Tasks::class)->create($coordinator, ['title' => 'Everyone stop'], withCoordinator: true);
    app(Tasks::class)->create($this->session, ['title' => 'An ordinary one'], withCoordinator: false);

    $rendered = Livewire::test(TaskBoard::class)->html();

    // The badge is rendered once, against the coordinator's task and not the other
    expect(substr_count($rendered, 'coordinator'))->toBe(1);
});

it('reaches a task beyond the first page through the cursor', function (): void {
    $tasks = app(Tasks::class);

    // One more than a page, all at the same priority, so the ordering falls to the id tiebreak
    foreach (range(1, TaskBoard::PER_PAGE + 1) as $n) {
        $tasks->create($this->session, ['title' => sprintf('Task number %d', $n)], withCoordinator: false);
    }

    $last = sprintf('Task number %d', TaskBoard::PER_PAGE + 1);

    $board = Livewire::test(TaskBoard::class);

    $board->assertSee('Task number 1')->assertDontSee($last);

    $cursor = Task::query()->orderBy('id')->skip(TaskBoard::PER_PAGE - 1)->first();

    $board->call('showNext', $cursor?->priority, $cursor?->id)
        ->assertSee($last)
        ->assertDontSee('Task number 1');

    // And back again, because a cursor that cannot be left is a trap
    $board->call('showFirst')->assertSee('Task number 1')->assertDontSee($last);
});

it('shows a task an agent files without the page being reloaded', function (): void {
    $board = Livewire::test(TaskBoard::class)->assertDontSee('Filed while the page was open');

    app(Tasks::class)->create($this->session, [
        'title' => 'Filed while the page was open',
    ], withCoordinator: false);

    // What `wire:poll` does on the interval, without a new request for the page itself
    $board->call('$refresh')->assertSee('Filed while the page was open');
});

it('renders a hostile title as text', function (): void {
    // Written past the endpoint's validation, because a title is prose and has no charset limit:
    // this is precisely the field #67 exists for, and the board is the page it lands on.
    $task = app(Tasks::class)->create($this->session, ['title' => 'safe'], withCoordinator: false);

    $task->forceFill(['title' => '<script>alert(1)</script>'])->save();

    $html = Livewire::test(TaskBoard::class)->html();

    expect($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->not->toContain('<script>alert(1)</script>');
});

it('narrows to one status and widens again, dropping the cursor', function (): void {
    $tasks = app(Tasks::class);

    $pending = $tasks->create($this->session, ['title' => 'Still waiting'], withCoordinator: false);
    $claimed = $tasks->create($this->session, ['title' => 'Already taken'], withCoordinator: false);

    $tasks->transition($claimed->id, TaskTransition::Claim, $this->session, asCoordinator: false);

    Livewire::test(TaskBoard::class)
        ->call('showStatus', TaskStatus::Claimed->value)
        ->assertSee('Already taken')
        ->assertDontSee('Still waiting')
        ->assertSet('afterId', null)
        ->call('showStatus', '')
        ->assertSee('Still waiting')
        ->assertSee('Already taken');

    expect($pending->id)->toBeLessThan($claimed->id);
});

it('widens to every task when asked for a status that is not one', function (): void {
    // `$status` is a public property and arrives from the client on every update, and it reaches a
    // `where`. An unknown value shows everything rather than throwing, because a filter nobody can
    // name is not worth a stack trace.
    app(Tasks::class)->create($this->session, ['title' => 'Visible regardless'], withCoordinator: false);

    Livewire::test(TaskBoard::class)
        ->set('status', 'not-a-status')
        ->assertSee('Visible regardless');
});

it('will not let a client move the cursor', function (): void {
    // The cursor is a position in an ordering the server computed. Harmless to read here, since the
    // reader may see every task, but it is the server's to issue.
    Livewire::test(TaskBoard::class)->set('afterId', 9999);
})->throws(Exception::class);
