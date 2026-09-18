<?php

declare(strict_types=1);

/**
 * The standing guarantee that agent-supplied text cannot become markup.
 *
 * Three parts, because the ways a value reaches the document unescaped are not one thing.
 *
 * The first refuses the template constructs that skip `e()` -- `{!! !!}`, a `@php` block, and a raw
 * PHP tag -- anywhere under `resources/views/`. The second refuses the package from ever handing
 * Blade a value that escapes itself: `e()` returns `toHtml()` **unescaped** for anything `Htmlable`,
 * so `{{ $x }}` emits live markup when `$x` is an `HtmlString`, which is what rendering a task
 * description as Markdown would produce. No pattern over a template can see that, because the
 * template is identical either way, so it is checked where such a value would be built. The third
 * drives a hostile corpus through the pages themselves.
 *
 * The structural parts are what make this durable rather than a snapshot. A page written today is
 * reviewed today; the risk is the one added next year. `resources/views/` has one file now, so the
 * check grows with #30 rather than needing to be remembered.
 *
 * What none of this covers is recorded on the issue rather than implied here: a third-party
 * component library's own views under `vendor/`, and views a host has published out of the package.
 *
 * @command  vendor/bin/pest --compact tests/EscapingGuardTest.php
 */

use RobotCouncil\Tests\Fixtures\HostileContent;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242]);

    $this->developer = $this->enrollDeveloper(4242);
});

it('finds every construct that reaches the document unescaped, and nothing that does not', function (): void {
    // The detector's own control, run on every invocation rather than by hand once. Each row was
    // measured against the real `BladeCompiler::compileString()`: where this expects a finding,
    // Blade compiles the construct into a raw echo, and where it expects none, it compiles no echo.
    // (The compiled shape is spelled out in `rawOutputIn()`'s docblock rather than here, because a
    // closing PHP tag inside a `//` comment ends PHP mode and takes the rest of the file with it.)
    expect(rawOutputIn('<h3>{!! $title !!}</h3><p>{{ $safe }}</p>'))
        ->toBe(['unescaped echo: {!! $title !!}']);

    // The same compiled output as the line above, with nothing about either that looks dangerous
    expect(rawOutputIn('@php echo $evil; @endphp'))->toBe(['@php block: echo $evil;']);
    expect(rawOutputIn('<p><?php echo $evil; ?></p>'))->toBe(['a raw PHP tag']);

    // `@verbatim` is the standard escape for Alpine and Vue mustaches. Blade stores those blocks
    // before it strips comments, so a `{{--` inside one cannot open a comment -- and a detector
    // that stripped comments first, over the whole file, would swallow the echo below along with it.
    expect(rawOutputIn("@verbatim\n{{-- literal\n@endverbatim\n<p>{!! \$evil !!}</p>\n{{-- real --}}"))
        ->toBe(['unescaped echo: {!! $evil !!}']);

    // A comment, and prose referring to the construct, are not findings. This package's own
    // verification page contains that sentence, so a detector without this reports the very file
    // it was written to protect.
    expect(rawOutputIn(<<<'BLADE'
        {{-- A comment mentioning {!! $ignored !!} is not a directive --}}
        <p>There is no `{!! !!}` here, and there never should be.</p>
        <p>{{ $safe }}</p>
        BLADE))->toBeEmpty();
});

it('finds a template at any depth, not only at the top of the tree', function (): void {
    // The scanner's control, and the reason it is not `glob`. A walk of the top level alone would
    // keep passing once #30 adds `resources/views/livewire/` -- green, and covering nothing in it.
    $root = $this->temporaryDirectory('views');

    mkdir($root.'/livewire/partials', 0o777, recursive: true);

    file_put_contents($root.'/top.blade.php', '<p>{{ $safe }}</p>');
    file_put_contents($root.'/livewire/board.blade.php', '<p>{{ $safe }}</p>');
    file_put_contents($root.'/livewire/partials/row.blade.php', '<td>{!! $title !!}</td>');

    $found = array_map(basename(...), bladeTemplatesIn($root));

    // Sorted here rather than relied on from the walk: `getPathname()` joins with
    // `DIRECTORY_SEPARATOR`, and `/` and `\` fall on opposite sides of the letters, so a directory
    // and a sibling file can order differently on the ubuntu and windows cells.
    sort($found);

    expect($found)->toBe(['board.blade.php', 'row.blade.php', 'top.blade.php']);

    $offenders = [];

    foreach (bladeTemplatesIn($root) as $template) {
        $offenders = [...$offenders, ...rawOutputIn((string) file_get_contents($template))];
    }

    expect($offenders)->toBe(['unescaped echo: {!! $title !!}']);
});

it('renders every value in the package views escaped', function (): void {
    $views = bladeTemplatesIn(__DIR__.'/../resources/views');

    // A scan that matched nothing would satisfy the assertion below and prove the opposite of what
    // it claims. `not->toBeEmpty()` is too weak alone, because one top-level view keeps it true
    // however much of the tree goes unexamined -- which is why the walk's recursion is controlled
    // in the test above rather than trusted.
    expect($views)->not->toBeEmpty();

    $offenders = [];

    foreach ($views as $view) {
        foreach (rawOutputIn((string) file_get_contents($view)) as $finding) {
            $offenders[] = basename($view).' -- '.$finding;
        }
    }

    // The message carries the offenders, because `toBeEmpty()` alone reports only "Failed asserting
    // that an array is empty" and leaves the reader to re-derive which file and which expression
    expect($offenders)->toBeEmpty('Unescaped output in package views:'.PHP_EOL.implode(PHP_EOL, $offenders));
});

it('never hands Blade a value that escapes itself', function (): void {
    // `e()` returns `$value->toHtml()` unescaped for anything `Htmlable` or
    // `DeferringDisplayableValue`, so `{{ $x }}` emits live markup for an `HtmlString` -- measured:
    // `{{ $x }}` given `new HtmlString('<img src=x onerror=alert(1)>')` renders the live tag. The
    // structural check above cannot see it, because the template is identical either way.
    //
    // The realistic route is Markdown. `Str::markdown()` returns raw HTML from whatever it was
    // given, so rendering a task description that way carries an agent's `<img onerror=...>`
    // straight through. If #30 wants Markdown, that has to be answered here with a sanitizer rather
    // than by deleting this test.
    $sources = [
        ...bladeTemplatesIn(__DIR__.'/../resources/views'),
        ...phpSourcesIn(__DIR__.'/../src'),
    ];

    expect($sources)->not->toBeEmpty();

    $selfEscaping = ['HtmlString', 'Htmlable', '->toHtml(', 'Str::markdown', '->markdown(', '@markdown'];

    $offenders = [];

    foreach ($sources as $source) {
        $contents = (string) file_get_contents($source);

        foreach ($selfEscaping as $construct) {
            if (str_contains($contents, $construct)) {
                $offenders[] = basename($source).' -- '.$construct;
            }
        }
    }

    expect($offenders)->toBeEmpty(
        'These build markup that `{{ }}` will not escape:'.PHP_EOL.implode(PHP_EOL, $offenders)
    );
});

it('renders a hostile value inert whatever sink it was aimed at', function (string $payload, array $forbidden, ?string $escaped): void {
    // `machine_label` alone, because `harness` is `varchar(32)` and the script-breakout payload is
    // 34 characters. Postgres refuses an overlong value with `22001` where SQLite stores it, so
    // writing both passed every local run and failed only in the `postgres` job. Asserted rather
    // than left as a comment, so a payload added later that does not fit fails on every driver.
    expect(mb_strlen($payload))->toBeLessThanOrEqual(64);

    // Written past the endpoint's validation deliberately: `machine_label` is charset-limited, so
    // this string cannot arrive through the API and the page's escaping has to be its own guarantee
    // rather than the validator's. The fields #30 adds are prose and have no such limit.
    $enrollment = requestDeviceCode($this);

    $enrollment['record']->forceFill(['machine_label' => $payload])->save();

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
