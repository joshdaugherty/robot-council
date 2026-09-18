<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RobotCouncil\Access\Ability;
use RobotCouncil\Support\Credentials;
use RobotCouncil\Support\DeviceCodes;
use RobotCouncil\Support\MachineIdentity;
use Symfony\Component\HttpFoundation\Response;

/**
 * Starts an enrollment. Unauthenticated by design: whoever runs the helper has no credential yet,
 * and the developer at a browser is the one who decides whether the request is theirs.
 *
 * Everything the request says about itself is a claim. The verification page repeats the claims as
 * claims, and the server -- not the request -- decides which abilities an approval grants.
 */
final class DeviceCodeController
{
    /**
     * Record an enrollment request and return what the helper needs to poll and to display.
     *
     * @param  Request  $request  The incoming request.
     * @param  DeviceCodes  $deviceCodes  The device-code store.
     * @param  Credentials  $credentials  The configured lifetimes.
     * @return JsonResponse The device code, the user code, and where to approve it.
     */
    public function __invoke(Request $request, DeviceCodes $deviceCodes, Credentials $credentials): JsonResponse
    {
        $requestable = Ability::values(Ability::requestable());

        $request->validate([
            // Restricted character sets, because both are printed on the verification page, and
            // because they are the only description a developer has of what they are approving
            // `/D`, so `$` cannot match before a trailing newline: without it a 32-character
            // harness plus a newline is 33 bytes into a 32-byte column, which is a 500 from an
            // unauthenticated endpoint on Postgres and on MySQL in strict mode. The `max:` rules
            // bound it a second way, in bytes the column can hold.
            'harness' => ['required', 'string', 'max:'.MachineIdentity::MAX_HARNESS, 'regex:'.MachineIdentity::HARNESS],
            'machine_label' => ['required', 'string', 'max:'.MachineIdentity::MAX_LABEL, 'regex:'.MachineIdentity::LABEL],

            // `*` and `coordinator:direct` are absent from this list, so neither can be asked for
            'requested_abilities' => ['required', 'array', 'min:1'],
            'requested_abilities.*' => ['string', Rule::in($requestable)],

            // The SHA-256 of a verifier only the helper holds, so a stolen device code is inert
            'code_challenge' => ['required', 'string', 'size:64', 'regex:/^[0-9a-f]{64}$/D'],
        ]);

        // Read back off the request rather than out of the validator's array, which is typed as
        // whatever the rules happened to admit
        $requested = $request->input('requested_abilities');

        $abilities = array_values(array_unique(array_filter(
            \is_array($requested) ? $requested : [],
            is_string(...)
        )));

        $issued = $deviceCodes->issue(
            $abilities,
            $request->string('harness')->value(),
            $request->string('machine_label')->value(),
            $request->string('code_challenge')->value(),
            $request->ip()
        );

        return new JsonResponse([
            'device_code' => $issued->deviceCode,
            'user_code' => $issued->record->user_code,
            'verification_uri' => $this->verificationUri(),
            'expires_in' => $credentials->deviceCodeTtlSeconds(),
            'interval' => $credentials->deviceCodeIntervalSeconds(),
        ], Response::HTTP_CREATED);
    }

    /**
     * Where the developer goes to approve the request.
     *
     * Built from the application's configured URL rather than from the request, whose host comes
     * from headers the requester sends. A helper that prints an attacker-supplied host would walk
     * the developer to the attacker's page.
     *
     * @return string The absolute verification URL.
     */
    private function verificationUri(): string
    {
        $configured = config('app.url');

        $root = \is_string($configured) ? rtrim($configured, '/') : '';

        return $root.route('robot-council.enroll.show', absolute: false);
    }
}
