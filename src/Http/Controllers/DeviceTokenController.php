<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RobotCouncil\Support\DeviceCodeError;
use RobotCouncil\Support\DeviceCodes;
use RobotCouncil\Support\Installations;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exchanges an approved device code for an installation credential. Unauthenticated, like the code
 * endpoint: the verifier is what proves the caller is the helper that started this enrollment.
 *
 * The helper polls this until it gets a credential or a reason to stop, so every unfinished state
 * answers with the RFC 8628 section 3.5 error for it, and every one of them is HTTP 400.
 */
final class DeviceTokenController
{
    /**
     * Claim an approved code, or say why it cannot be claimed.
     *
     * @param  Request  $request  The incoming request.
     * @param  DeviceCodes  $deviceCodes  The device-code store.
     * @param  Installations  $installations  The installation store.
     * @return JsonResponse The credential, or an RFC 8628 error.
     */
    public function __invoke(Request $request, DeviceCodes $deviceCodes, Installations $installations): JsonResponse
    {
        $request->validate([
            'device_code' => ['required', 'string', 'max:255'],
            'code_verifier' => ['required', 'string', 'max:255'],
        ]);

        $claimed = $deviceCodes->consume(
            $request->string('device_code')->value(),
            $request->string('code_verifier')->value()
        );

        if ($claimed instanceof DeviceCodeError) {
            return new JsonResponse(['error' => $claimed->value], Response::HTTP_BAD_REQUEST);
        }

        $issued = $installations->createFrom($claimed);
        $installation = $issued->owner;

        return new JsonResponse([
            'installation_id' => $installation->getKey(),
            'credential' => $issued->plainTextToken,

            // What session tokens will carry. The credential itself carries only `sessions:start`.
            'granted_abilities' => $installation->abilities(),
            'expires_at' => $installation->expires_at->toIso8601String(),
        ], Response::HTTP_CREATED);
    }
}
