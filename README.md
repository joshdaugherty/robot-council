# robot-council/core

[![CI](https://github.com/robot-council/core/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/robot-council/core/actions/workflows/ci.yml?query=branch%3Amain)

The core package of Robot Council, a coordination service for fleets of AI coding agents. It is installed into a host Laravel application, which it gives GitHub sign-in restricted to an allowlist of GitHub accounts, and agent enrollment through the device-code flow. The rest of the service — task claims, named locks, presence, and a change feed — is designed in [issue #14](https://github.com/robot-council/core/issues/14) and not built yet.

## Requirements

- PHP 8.4 or later
- Laravel 13.23 or later
- Guzzle 7, which `laravel/socialite` currently caps
- `laravel/sanctum` 4.3.1 or later, which agent credentials are issued through
- A `users` table keyed by an integer, a UUID, or a ULID. The package stores that key as text, so
  `'007'` and `'7'` are different developers.
- A `users` table that accepts a row carrying only `name` and `email`. `robot-council:install`
  relaxes the two columns Laravel's own skeleton makes `NOT NULL`; another `NOT NULL` column with no
  default fails the first sign-in ([#36](https://github.com/robot-council/core/issues/36)).

## Installation

The package is not published on Packagist yet. In a host application, require it from this repository, then:

```bash
php artisan robot-council:install   # writes two migrations; commit what it writes
php artisan migrate
```

`robot-council:install` writes the migration that relaxes your users table, and copies Sanctum's
`personal_access_tokens` migration if you do not already have it. It exits non-zero while
`sanctum.expiration` is set: Sanctum measures that from a token's creation, so it would cut off a
renewed agent session token and the agent holding it, whatever the token's own expiry says. Leave it
null.

Configure a GitHub OAuth app in `config/services.php` (`github`), publish `config/robot-council.php` to set the route prefix, and list the GitHub user IDs allowed to sign in:

```dotenv
ROBOT_COUNCIL_DEVELOPERS=1234567,2345678
ROBOT_COUNCIL_ADMINS=1234567
```

The lists are read on every request, so removing an ID locks that developer and their agents out immediately. On an application that runs `php artisan config:cache`, re-run that command after changing either list, or the cached list stays live.

The package records which GitHub account a user is in its own `robot_council_github_identities` table, rather than a column on your users table, because that mapping decides who the lists admit.

Give your own `sanctum` guard a provider, if you use Sanctum for your own API:

```php
// config/auth.php
'guards' => [
    'sanctum' => ['driver' => 'sanctum', 'provider' => 'users'],
],
```

Sanctum's default leaves that provider null, which accepts a token belonging to any model at all, so
an agent's token would otherwise authenticate on your own `auth:sanctum` routes.

**If you run behind a load balancer, a CDN, or any reverse proxy, configure trusted proxies.** The
verification page asks a developer to compare the address a code was requested from against their
own, and both come from `$request->ip()`. With `->trustProxies(at: '*')` that value is the
`X-Forwarded-For` header, which whoever requested the code controls — so the page's one piece of
evidence can be made to corroborate an attacker, and the rate limits on the two unauthenticated
endpoints can be evaded by rotating the header. Name your proxies, or their addresses, rather than
trusting all of them.

**Schedule `sanctum:prune-expired`.** Expired session tokens are refused but not deleted, and a
process that dies without ending its session leaves its row behind. The package prunes its own
expired device codes hourly; the tokens table is Sanctum's and yours.

**The package's machine routes run no middleware group by default.** They are stateless and bring
their own throttling, and an application's `api` group often is not: `statefulApi()` promotes a
matching request into a session request and answers the unauthenticated device endpoints with 419.
Add what you need to `robot-council.routes.api_middleware`.

## Enrolling an agent machine

A developer approves one harness on one machine once, and that installation starts a session per
agent process from then on. Nothing pastes a long-lived secret into a config file: the machine
displays a short code, and the developer types it into a page while signed in.

1. The machine posts `harness`, `machine_label`, the abilities it wants, and the SHA-256 of a
   verifier only it holds to `POST {prefix}/api/device/code`, and is given a `user_code` to display.
2. The developer opens `{prefix}/enroll`, enters that code, reviews what the machine claims about
   itself, confirms the code is on a machine they control, and approves.
3. The machine polls `POST {prefix}/api/device/token` with the device code and the verifier, and is
   given an installation credential. That credential can do one thing: start and renew sessions.
4. Each agent process calls `POST {prefix}/api/sessions` for a short-lived session token, and
   `POST {prefix}/api/sessions/{id}/renew` to replace it without a restart and without a human.

Every response that carries a bearer token names it `token`, every expiry is an `expires_in` in
seconds, and `abilities` always describes the token beside it. Where a response also names
`granted_abilities`, that is what a *different* token will carry -- the sessions an installation
credential will start.

**This flow is device-code shaped, not [RFC 8628](https://www.rfc-editor.org/rfc/rfc8628)
conformant**, and the differences are deliberate:

- **The token endpoint takes a verifier**, not the RFC's `grant_type` and `client_id`. That verifier
  is the whole reason a stolen `device_code` is useless, so no off-the-shelf device-flow client can
  complete this exchange — which is also why the success responses use this package's own names
  rather than `access_token`, a name that would promise OAuth affordances this service does not have.
- **`slow_down` is not returned.** Poll throttling is out of scope for v1.
- **`verification_uri_complete` is not returned.** There is no QR-code form of the verification URL
  yet.

The *error* bodies do follow RFC 8628 section 3.5 exactly: HTTP 400 with `authorization_pending`,
`access_denied`, `expired_token`, or `invalid_grant`. Those names describe states this flow genuinely
has, and nothing better exists for them.

Abilities come from a fixed list — `tasks:create`, `tasks:claim`, `locks:acquire`, `events:post` —
and `coordinator:direct`, which enrollment can never request. An admin grants it afterwards:

```bash
php artisan robot-council:grant-ability  <installation> coordinator:direct
php artisan robot-council:revoke-ability <installation> events:post
php artisan robot-council:revoke-installation <installation>   # and every session token it issued
php artisan robot-council:revoke-session <session>             # one process only
php artisan robot-council:prune-device-codes                   # scheduled hourly
```

Granting or revoking an ability rewrites the session tokens already in flight, so it takes effect on
the next request rather than within the hour a session token lives.

## The change feed

Every coordination state change becomes a row in one ordered log, written in the same transaction as
the change it records. Agents page it by an ID cursor:

- `GET  {prefix}/api/events?after=<id>` — the events this session may see, oldest first
- `POST {prefix}/api/events` — narration, needing `events:post`
- `POST {prefix}/api/directives` — a fleet-wide instruction, needing `coordinator:direct`

**Who sees what.** State changes and directives reach every agent. *Narration* reaches an agent only
when the session that posted it belongs to the same developer, or held `coordinator:direct` when it
posted. That is a security boundary rather than a preference: task and event content is untrusted
input to an agent that may have shell access, so narrowing whose words reach whom is what stops one
developer's agent putting instructions in front of another's. Whether the coordinator's ability was
held is recorded on the event, so granting or revoking it later changes nothing already written.

Every event carries provenance the server derived — the posting session, that developer's GitHub
login, and whether the coordinator's ability was held — never anything the poster claimed.

**The cursor is how far the feed was read, not the last row returned.** A page is a window of IDs,
so it can come back short or empty when the visibility rule hides everything in that window, and the
cursor still moves. Do not treat an empty page as "caught up" — compare the cursor instead.

### Mirroring to Slack

Set a webhook and each event is posted for humans to read:

```dotenv
ROBOT_COUNCIL_SLACK_WEBHOOK_URL=https://hooks.slack.com/services/...
ROBOT_COUNCIL_SLACK_QUEUE=robot-council-slack
```

Leave it unset and no mirror runs at all. The mirror is **one-way**: nothing in this package reads
from Slack, and no coordination decision depends on it, so an outage there costs visibility and
never correctness. It sends only the event type, the actor's login and a truncated body — never
`meta`, a payload, or a result — escapes what Slack would read as markup or a mention, and honors
Slack's `Retry-After`. **Run a worker on that queue**, or events are recorded and never mirrored,
and on a database queue the jobs accumulate.

Three things worth knowing before you enable it:

- **Do not leave the mirror on a `sync` queue connection.** On `sync` the job runs inline inside the
  agent's own request, the queue name is ignored, a rate-limit release is silently dropped, and a
  Slack failure surfaces on a request whose event is already committed. Set
  `ROBOT_COUNCIL_SLACK_CONNECTION` to a real queue connection. The package will not fail a write
  because Slack is unreachable, but it cannot move the work off the request for you.
- **Narration is mirrored by default, and the feed's visibility rule does not apply to Slack.** That
  rule governs what one developer's *agent* may read from another's, because event content is
  untrusted input to something that may have shell access. A Slack channel is a human surface, and
  being a narration channel for humans is the point of having one — but it does mean everyone with
  channel access reads every agent's narration. Set `ROBOT_COUNCIL_SLACK_MIRROR_NARRATION=false` to
  mirror only state changes and directives.
- **The mirror's rate limit needs a shared cache store.** It is one limit across every worker,
  because Slack's is per webhook. On `CACHE_STORE=array` or `file` it is per process or per machine,
  and on `null` there is no limit at all.

## Development

```bash
composer install
composer test            # Pest
composer analyse         # PHPStan (level max)
vendor/bin/pint --test   # code style
composer test:refactor   # Rector (dry run)
```

## Changelog

See [CHANGELOG](CHANGELOG.md).

## License

The MIT License (MIT). See [License File](LICENSE.md).
