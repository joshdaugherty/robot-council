<?php

declare(strict_types=1);

/**
 * The package's human-facing web routes: GitHub sign-in. `RobotCouncilServiceProvider` applies the
 * configured prefix and middleware group, and the `robot-council.` route-name prefix.
 */

use Illuminate\Support\Facades\Route;
use RobotCouncil\Http\Controllers\GitHubCallbackController;
use RobotCouncil\Http\Controllers\GitHubRedirectController;

Route::get('auth/github/redirect', GitHubRedirectController::class)->name('auth.redirect');
Route::get('auth/github/callback', GitHubCallbackController::class)->name('auth.callback');
