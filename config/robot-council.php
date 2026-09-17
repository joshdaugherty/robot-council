<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Access
    |--------------------------------------------------------------------------
    |
    | The GitHub accounts that may sign in, as numeric GitHub user IDs. Numeric
    | IDs rather than logins, because a login can be renamed and then claimed
    | by someone else. Admins are developers with the `robot-council-admin`
    | ability as well. Both lists accept a comma-separated string or an array,
    | and both are read on every request.
    |
    */

    'access' => [
        'developers' => env('ROBOT_COUNCIL_DEVELOPERS', ''),
        'admins' => env('ROBOT_COUNCIL_ADMINS', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | Where the package mounts its human-facing routes in the host application,
    | and the middleware group they run in. The routes need a session, so the
    | group has to start a session and verify CSRF tokens.
    |
    */

    'routes' => [
        'web_prefix' => env('ROBOT_COUNCIL_WEB_PREFIX', 'robot-council'),
        'web_middleware' => ['web'],
    ],

];
