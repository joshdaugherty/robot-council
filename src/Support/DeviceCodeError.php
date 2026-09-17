<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

/**
 * Why an exchange at the token endpoint did not produce a credential. The values are the ones
 * RFC 8628 section 3.5 defines, and every one of them is returned with HTTP 400.
 *
 * A helper that does not hold the verifier learns only `invalid_grant`, whatever state the code is
 * in, so a stolen `device_code` cannot be used to watch for a developer's approval.
 */
enum DeviceCodeError: string
{
    /**
     * The code exists and no developer has decided it yet. The helper polls again.
     */
    case AuthorizationPending = 'authorization_pending';

    /**
     * A developer denied the request. The helper stops.
     */
    case AccessDenied = 'access_denied';

    /**
     * The code has expired, or it has already been exchanged. Enrollment starts again.
     */
    case ExpiredToken = 'expired_token';

    /**
     * No such code, or the verifier does not match the challenge it was requested with.
     */
    case InvalidGrant = 'invalid_grant';
}
