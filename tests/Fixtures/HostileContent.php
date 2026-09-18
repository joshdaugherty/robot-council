<?php

declare(strict_types=1);

namespace RobotCouncil\Tests\Fixtures;

/**
 * The payloads every page that displays agent-supplied prose is tested against.
 *
 * One corpus rather than a string per test, because the fields that carry it are written by another
 * developer's agent and read on this developer's screen, and a test that invents its own payload
 * tends to invent the one shape it already knows is handled. Four of the package's fields are prose
 * and cannot be charset-limited the way `harness`, `machine_label`, `project_id`, and a lock's name
 * are: a task's title and description, and an event's and a directive's body. An agent legitimately
 * writes `handle a < b`, so the guarantee has to be at the renderer.
 *
 * Each payload names the sink it is aimed at, because escaping is a property of the sink rather than
 * of the value: `htmlspecialchars` makes a string inert in HTML text and does nothing for a
 * `javascript:` URL, and a `<script>` body needs JSON escaping instead. The sinks here are the ones
 * measured in real Blade component libraries, not hypotheses.
 */
final class HostileContent
{
    /**
     * Every payload, keyed by the name a dataset row should carry.
     *
     * `forbidden` holds the substrings that must not survive into the rendered document. `escaped`
     * is what the payload has to become where one exists -- a `javascript:` URL has no character
     * `htmlspecialchars` alters, so its defense is never reaching an attribute rather than being
     * transformed, and it carries none.
     *
     * @return array<string, array{payload: string, sink: string, forbidden: list<string>, escaped: string|null}>
     */
    public static function payloads(): array
    {
        return [
            'a script tag in HTML text' => [
                'payload' => '<script>alert(1)</script>',
                'sink' => 'HTML text',
                'forbidden' => ['<script>alert(1)'],
                'escaped' => '&lt;script&gt;alert(1)&lt;/script&gt;',
            ],

            // The shape that defeats escaping applied only to angle brackets: it closes the
            // attribute it landed in and opens a tag that needs no closing one
            'an attribute breakout' => [
                'payload' => '"><img src=x onerror=alert(1)>',
                'sink' => 'an HTML attribute',
                // The quote is the half that performs the breakout, so it is asserted through
                // `escaped` rather than through `forbidden`. Measured with `ENT_NOQUOTES`, which
                // escapes the tag and leaves the quote to close the surrounding attribute, the row
                // passes on the forbidden list alone and fails on `&quot;`. Listing `">` as
                // forbidden instead does not work at document scope: it occurs 24 times in the
                // verification page's own markup, so that assertion could never pass.
                'forbidden' => ['<img src=x', 'onerror=alert(1)>'],
                'escaped' => '&quot;&gt;&lt;img src=x',
            ],

            // Nothing about this string is altered by `htmlspecialchars`, so a page is safe from it
            // only by never putting agent text where a URL is read
            'a javascript URL' => [
                'payload' => 'javascript:alert(1)',
                'sink' => 'an href or src attribute',
                'forbidden' => ['href="javascript:', "href='javascript:", 'href=javascript:', 'src="javascript:'],
                'escaped' => null,
            ],

            // A value interpolated into a script body escapes it by closing the element, which HTML
            // escaping inside a script does not prevent
            'a script element breakout' => [
                'payload' => '</script><script>alert(1)</script>',
                'sink' => 'a script body',
                'forbidden' => ['</script><script>'],
                'escaped' => '&lt;/script&gt;',
            ],
        ];
    }

    /**
     * The payloads as a Pest dataset.
     *
     * `sink` is deliberately not passed through. It records which construct each payload is aimed
     * at, which belongs beside the payload rather than in a test signature that never reads it.
     *
     * @return array<string, array{string, list<string>, string|null}>
     */
    public static function dataset(): array
    {
        $rows = [];

        foreach (self::payloads() as $name => $case) {
            $rows[$name] = [$case['payload'], $case['forbidden'], $case['escaped']];
        }

        return $rows;
    }
}
