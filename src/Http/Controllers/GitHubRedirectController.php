<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Controllers;

use Laravel\Socialite\Contracts\Factory;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Starts GitHub's OAuth flow. Socialite puts the state parameter in the session, and validates it
 * when GitHub sends the visitor back to the callback.
 */
final class GitHubRedirectController
{
    /**
     * Send the visitor to GitHub's authorization page.
     *
     * @param  Factory  $socialite  Socialite's provider factory.
     * @return RedirectResponse The redirect to GitHub.
     */
    public function __invoke(Factory $socialite): RedirectResponse
    {
        return $socialite->driver('github')->redirect();
    }
}
