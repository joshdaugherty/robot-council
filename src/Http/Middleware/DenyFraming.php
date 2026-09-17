<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses to let the enrollment pages be framed.
 *
 * The approval page is a checkbox and a button that hand a machine long-lived access to the fleet,
 * which is the shape a UI-redress attack is for: frame the page with the attacker's own user code
 * in the query string, overlay two bait targets, and the developer's session and CSRF token do the
 * rest. Laravel's `web` group ships no frame protection, and the only thing standing in the way by
 * default is `session.same_site` being `lax` -- a host setting that exists for an unrelated reason
 * and that a cross-origin front end turns off.
 */
final class DenyFraming
{
    /**
     * Send the response with framing refused, by both the old header and the current directive.
     *
     * @param  Request  $request  The incoming request.
     * @param  Closure(Request): Response  $next  The rest of the pipeline.
     * @return Response The response, refusing to be framed.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY', replace: false);
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'none'", replace: false);

        return $response;
    }
}
