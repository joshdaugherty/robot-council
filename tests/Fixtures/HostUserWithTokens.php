<?php

declare(strict_types=1);

namespace RobotCouncil\Tests\Fixtures;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Foundation\Auth\User;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * A host application's user model that issues its own API tokens, which is what a host running
 * Sanctum for its own API has.
 *
 * It exists because it is the only configuration in which Sanctum's guard hands a signed-in human a
 * `TransientToken`: `Guard::supportsTokens()` checks for this very trait, and Testbench's default
 * user model does not use it. Without this fixture the package's whole defense against a browser
 * session reaching a machine route is unreachable from the suite, and a test asserting 401 would be
 * passing on the principal's class alone.
 *
 * @phpstan-use HasApiTokens<PersonalAccessToken>
 */
#[Table(name: 'users')]
class HostUserWithTokens extends User
{
    /** @use HasApiTokens<PersonalAccessToken> */
    use HasApiTokens;
}
