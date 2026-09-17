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
use RobotCouncil\Http\Controllers\AgentSessionController;
use RobotCouncil\Http\Controllers\DeviceCodeController;
use RobotCouncil\Http\Controllers\DeviceTokenController;
use RobotCouncil\Http\Controllers\SessionRenewController;
use RobotCouncil\Http\Controllers\SessionStartController;
use RobotCouncil\Http\Middleware\EnsureAgentSession;
use RobotCouncil\Http\Middleware\EnsureInstallation;
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
        Route::post('sessions/{session}/renew', SessionRenewController::class)->name('sessions.renew');
    });

Route::middleware(EnsureAgentSession::class)->group(function (): void {
    Route::get('agent/session', AgentSessionController::class)->name('agent.session');
});
