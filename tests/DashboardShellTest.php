<?php

declare(strict_types=1);

/**
 * The dashboard's shell: who reaches it, what it serves, and whether the stylesheet it ships
 * actually styles what the pages render.
 *
 * @command  vendor/bin/pest --compact tests/DashboardShellTest.php
 */

use Illuminate\Contracts\Config\Repository;
use RobotCouncil\Http\Controllers\DashboardStylesheetController;
use RobotCouncil\Livewire\Dashboard;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242]);

    $this->developer = $this->enrollDeveloper(4242);
});

it('shows the dashboard to an allowlisted developer', function (): void {
    $this->actingAs($this->developer, 'web')
        ->get(route('robot-council.dashboard'))
        ->assertOk()
        ->assertSee('Robot Council');
});

it('refuses a signed-in user who is not on the access list', function (): void {
    $stranger = $this->enrollDeveloper(9999, login: 'stranger');

    $this->actingAs($stranger, 'web')
        ->get(route('robot-council.dashboard'))
        ->assertForbidden();
});

it('sends an unauthenticated visitor to GitHub sign-in', function (): void {
    $this->get(route('robot-council.dashboard'))
        ->assertRedirect(route('robot-council.auth.redirect'));
});

it('serves the compiled stylesheet without asking anyone to sign in', function (): void {
    // Public deliberately: a page that needed authentication to load its own styling would render
    // unstyled to exactly the people being told to sign in.
    $response = $this->get(route('robot-council.dashboard.stylesheet'));

    $response->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('css');
});

it('serves a stylesheet that carries the classes the pages actually use', function (): void {
    // Asserted against the built artifact rather than the Tailwind configuration, because the
    // configuration is a statement of intent and the artifact is what a browser receives. Tailwind
    // emits only what it finds by scanning, and Mary's components live under `vendor/`, which it
    // skips by default because `/vendor` is gitignored -- so this is the assertion that the
    // `@source` directives reach them.
    $stylesheet = (string) file_get_contents(__DIR__.'/../resources/dist/dashboard.css');

    expect($stylesheet)->not->toBeEmpty();

    // Literal substrings, not selector-shaped patterns: daisyUI emits `.table-zebra tbody tr:...`,
    // and a regex requiring `{`, `,` or `:` straight after the class name reports it missing.
    foreach (['.btn', '.card', '.menu', '.input', '.checkbox', 'table-zebra'] as $class) {
        expect($stylesheet)->toContain($class);
    }

    // The control. A file this large contains a great many strings, so an assertion that everything
    // searched for is present proves nothing unless something that should be absent is absent.
    expect($stylesheet)->not->toContain('rc-definitely-not-a-class');
});

it('refuses a poll interval a host could not have meant', function (mixed $configured, int $expected): void {
    // The value becomes a `wire:poll` interval. A zero asks the browser to poll as fast as it can,
    // and a non-integer renders an attribute the browser ignores, leaving a page that never
    // refreshes and never says so.
    $this->rebootWith('robot-council.dashboard.poll_seconds', $configured);

    $component = new Dashboard;

    $component->mount(app(Repository::class));

    expect($component->pollSeconds)->toBe($expected);
})->with([
    'the configured value' => [30, 30],
    'zero' => [0, Dashboard::DEFAULT_POLL_SECONDS],
    'negative' => [-5, Dashboard::DEFAULT_POLL_SECONDS],
    'absurd' => [99999, Dashboard::DEFAULT_POLL_SECONDS],
    'a string' => ['fast', Dashboard::DEFAULT_POLL_SECONDS],
    'null' => [null, Dashboard::DEFAULT_POLL_SECONDS],
]);

it('tells a browser it may keep the stylesheet, and answers a revalidation cheaply', function (): void {
    $first = $this->get(route('robot-council.dashboard.stylesheet'));

    $first->assertOk();

    $etag = $first->headers->get('ETag');

    expect($etag)->not->toBeNull()
        ->and($first->headers->get('Cache-Control'))->toContain('max-age='.DashboardStylesheetController::MAX_AGE);

    // The same file, asked for again with what the browser was given
    $this->withHeaders(['If-None-Match' => (string) $etag])
        ->get(route('robot-council.dashboard.stylesheet'))
        ->assertStatus(304);
});
