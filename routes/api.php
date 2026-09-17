<?php

declare(strict_types=1);

/**
 * The package's machine-facing routes. `RobotCouncilServiceProvider` applies the configured prefix
 * and middleware group, and the `robot-council.` route-name prefix.
 *
 * The two device endpoints are unauthenticated, because a machine enrolling has no credential yet.
 * What stands in for authentication there is the verifier only the helper holds, and the developer
 * at a browser who has to approve the request.
 *
 * Everything else names its principal middleware explicitly. A Sanctum guard alone does not say
 * what authenticated: it falls back to the `web` guard first, so a signed-in human in a browser
 * reaches these URLs as themselves.
 */

use Illuminate\Support\Facades\Route;
use RobotCouncil\Access\Ability;
use RobotCouncil\Http\Controllers\AgentHeartbeatController;
use RobotCouncil\Http\Controllers\AgentSessionController;
use RobotCouncil\Http\Controllers\DeviceCodeController;
use RobotCouncil\Http\Controllers\DeviceTokenController;
use RobotCouncil\Http\Controllers\FleetFeedController;
use RobotCouncil\Http\Controllers\PostDirectiveController;
use RobotCouncil\Http\Controllers\PostNarrationController;
use RobotCouncil\Http\Controllers\SessionEndController;
use RobotCouncil\Http\Controllers\SessionRenewController;
use RobotCouncil\Http\Controllers\SessionStartController;
use RobotCouncil\Http\Middleware\EnsureAgentSession;
use RobotCouncil\Http\Middleware\EnsureInstallation;
use RobotCouncil\Http\Middleware\RequireAbility;
use RobotCouncil\RobotCouncilServiceProvider;

Route::post('device/code', DeviceCodeController::class)
    ->middleware('throttle:'.RobotCouncilServiceProvider::DEVICE_CODE_LIMITER)
    ->name('device.code');

Route::post('device/token', DeviceTokenController::class)
    ->middleware('throttle:'.RobotCouncilServiceProvider::DEVICE_TOKEN_LIMITER)
    ->name('device.token');

// The limiter resolves the installation itself: the router sorts `ThrottleRequests` ahead of any
// middleware outside its priority list, so this group's order is not the order they run in
Route::middleware([EnsureInstallation::class, 'throttle:'.RobotCouncilServiceProvider::SESSIONS_LIMITER])
    ->group(function (): void {
        Route::post('sessions', SessionStartController::class)->name('sessions.start');
        // Constrained, so an id no bigint can hold is a 404 rather than a 500. `whereNumber` is
        // `[0-9]+`, which bounds the character set and not the magnitude, and Postgres raises
        // `22P02` for a non-numeric id and `22003` for an overlong one where SQLite quietly
        // matches no rows. Eighteen digits is inside a signed 64-bit integer whatever they are.
        Route::post('sessions/{session}/renew', SessionRenewController::class)
            ->where('session', RobotCouncilServiceProvider::ROUTE_ID)
            ->name('sessions.renew');

        // Ending a session is the installation's to do, not the session's: the token belonging to
        // the process that just died is the one thing that may no longer work
        Route::delete('sessions/{session}', SessionEndController::class)
            ->where('session', RobotCouncilServiceProvider::ROUTE_ID)
            ->name('sessions.end');
    });

// Every agent route is limited per session, so one runaway process cannot crowd out the fleet
Route::middleware([EnsureAgentSession::class, 'throttle:'.RobotCouncilServiceProvider::AGENT_LIMITER])
    ->group(function (): void {
        Route::get('agent/session', AgentSessionController::class)->name('agent.session');

        // Contact is recorded for every route in this group, so this one is for a process that has
        // nothing else to send rather than the only thing that keeps a session alive
        Route::post('agent/heartbeat', AgentHeartbeatController::class)->name('agent.heartbeat');

        // Reading the feed needs no ability: what a session may see is decided by whose narration
        // it is, not by what the session was granted
        Route::get('events', FleetFeedController::class)->name('events.index');

        Route::post('events', PostNarrationController::class)
            ->middleware(RequireAbility::class.':'.Ability::EventsPost->value)
            ->name('events.store');

        // The one ability enrollment can never ask for, granted only by an admin afterwards
        Route::post('directives', PostDirectiveController::class)
            ->middleware(RequireAbility::class.':'.Ability::CoordinatorDirect->value)
            ->name('directives.store');
    });
