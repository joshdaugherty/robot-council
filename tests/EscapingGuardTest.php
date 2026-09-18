<?php

declare(strict_types=1);

/**
 * The standing guarantee that agent-supplied text cannot become markup.
 *
 * Two halves. The structural half refuses an unescaped echo anywhere in the package's views, which
 * is the construct that turns a task title into a script tag. The rendered half drives the hostile
 * corpus through the pages that display such text and asserts nothing live survives.
 *
 * The structural half is what makes this durable rather than a snapshot. A page written today is
 * reviewed today; the risk is the one added next year, or a component copied out of a library whose
 * text prop is rendered unescaped -- measured shapes, not hypotheses, and the reason the check is a
 * test rather than a note. `resources/views/` has one file today, so the check grows with #30 rather
 * than needing to be remembered.
 *
 * @command  vendor/bin/pest --compact tests/EscapingGuardTest.php
 */

use RobotCouncil\Tests\Fixtures\HostileContent;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242]);

    $this->developer = $this->enrollDeveloper(4242);
});

it('detects an unescaped echo, and does not report one where there is none', function (): void {
    // The detector's own control, run on every invocation rather than by hand once. Both halves
    // matter: a detector that matched nothing would pass the structural check below while the views
    // were full of violations, and one that matched prose would have failed on this package's own
    // view, whose text says there is no such echo in it.
    $violations = unescapedEchoes(<<<'BLADE'
        <h3>{!! $title !!}</h3>
        <p>{{ $safe }}</p>
        BLADE);

    expect($violations)->toBe(['$title']);

    $clean = unescapedEchoes(<<<'BLADE'
        {{-- A comment mentioning {!! $ignored !!} is not a directive --}}
        <p>Every value is escaped. There is no `{!! !!}` here, and there never should be.</p>
        <p>{{ $safe }}</p>
        BLADE);

    expect($clean)->toBeEmpty();
});

it('renders every value in the package views escaped, with no unescaped echo anywhere', function (): void {
    $views = glob(__DIR__.'/../resources/views/*.blade.php') ?: [];

    // A count, printed rather than assumed: a glob that matched nothing would satisfy every
    // assertion in the loop below and prove the opposite of what it claims
    expect($views)->not->toBeEmpty();

    $offenders = [];

    foreach ($views as $view) {
        foreach (unescapedEchoes((string) file_get_contents($view)) as $expression) {
            $offenders[] = basename($view).': {!! '.$expression.' !!}';
        }
    }

    // Agent-supplied prose reaches these pages, and an unescaped echo is the one construct that
    // turns it into markup. A value that genuinely holds markup needs a reason recorded here first.
    expect($offenders)->toBeEmpty();
});

it('renders a hostile value inert whatever sink it was aimed at', function (string $payload, string $sink, array $forbidden, ?string $escaped): void {
    // Written past the endpoint's validation deliberately, exactly as the verification page's own
    // test does: `machine_label` is charset-limited so this string cannot arrive through the API,
    // and the page's escaping has to be its own guarantee rather than the validator's. The fields
    // #30 adds -- a task title, a description, an event body -- are prose and have no such limit,
    // so the page is the only place the guarantee can live for them.
    $enrollment = requestDeviceCode($this);

    $enrollment['record']->forceFill([
        'machine_label' => $payload,
        'harness' => $payload,
    ])->save();

    $response = $this->actingAs($this->developer, 'web')
        ->get(route('robot-council.enroll.show', ['user_code' => $enrollment['record']->user_code]));

    $response->assertOk();

    $html = (string) $response->getContent();

    // The payload reached the page at all: without this the assertions below pass on any page that
    // simply failed to render the value, which is the same output a broken fixture produces
    expect($html)->toContain($escaped ?? $payload);

    foreach ($forbidden as $live) {
        expect($html)->not->toContain($live);
    }
})->with(HostileContent::dataset());
