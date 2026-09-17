<?php

declare(strict_types=1);

namespace RobotCouncil\Access;

use Illuminate\Contracts\Config\Repository;

/**
 * The name of the guard the package signs developers in on, and checks them against. The host
 * application's default guard is not assumed: a host with several guards may default to another
 * one, and then signing in on one guard while reading another loops the developer through sign-in
 * forever.
 */
final class Guard
{
    /**
     * @param  Repository  $config  The host application's configuration repository.
     */
    public function __construct(private readonly Repository $config) {}

    /**
     * The configured guard's name.
     *
     * @return string The guard the package uses for every sign-in, check, and sign-out.
     */
    public function name(): string
    {
        $name = $this->config->get('robot-council.auth.guard', 'web');

        return \is_string($name) && $name !== '' ? $name : 'web';
    }
}
