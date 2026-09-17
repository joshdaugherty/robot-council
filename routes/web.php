<?php

declare(strict_types=1);

/**
 * The package's human-facing web routes: GitHub sign-in, and the page where a developer approves or
 * denies a machine's enrollment. `RobotCouncilServiceProvider` applies the configured prefix and
 * middleware group, and the `robot-council.` route-name prefix.
 *
 * Approve and deny accept POST only. A decision that could be made by following a link is a
 * decision an attacker can have a developer make for them.
 */

use Illuminate\Support\Facades\Route;
use RobotCouncil\Http\Controllers\EnrollmentDecisionController;
use RobotCouncil\Http\Controllers\EnrollmentPageController;
use RobotCouncil\Http\Controllers\GitHubCallbackController;
use RobotCouncil\Http\Controllers\GitHubRedirectController;
use RobotCouncil\Http\Middleware\EnsureAllowlistedDeveloper;
use RobotCouncil\RobotCouncilServiceProvider;

Route::get('auth/github/redirect', GitHubRedirectController::class)->name('auth.redirect');
Route::get('auth/github/callback', GitHubCallbackController::class)->name('auth.callback');

Route::middleware(EnsureAllowlistedDeveloper::class)->group(function (): void {
    Route::get('enroll', EnrollmentPageController::class)->name('enroll.show');

    Route::middleware('throttle:'.RobotCouncilServiceProvider::VERIFICATION_LIMITER)->group(function (): void {
        Route::post('enroll/approve', [EnrollmentDecisionController::class, 'approve'])->name('enroll.approve');
        Route::post('enroll/deny', [EnrollmentDecisionController::class, 'deny'])->name('enroll.deny');
    });
});
