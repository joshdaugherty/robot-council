<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use RobotCouncil\Support\DeviceCodes;

/**
 * The page a developer opens to approve or deny an enrollment.
 *
 * It is the only place a human sees an enrollment request, so it is where the phishing defense
 * lives: the page prints what the requester claimed as claims, shows how long ago the code was
 * asked for and from which address, and makes the developer confirm that the code is on a machine
 * they control before it will accept an approval.
 */
final class EnrollmentPageController
{
    /**
     * Show the code-entry form, and the request it names once a code is entered.
     *
     * @param  Request  $request  The incoming request.
     * @param  DeviceCodes  $deviceCodes  The device-code store.
     * @return View The verification page.
     */
    public function __invoke(Request $request, DeviceCodes $deviceCodes): View
    {
        $typed = $request->query('user_code');
        $typed = \is_string($typed) ? $typed : '';

        $code = $typed === '' ? null : $deviceCodes->findByUserCode($typed);

        return view('robot-council::enroll', [
            'userCode' => DeviceCodes::normalizeUserCode($typed),
            'code' => $code,

            // Told apart so the page can say "no live request by that code" rather than showing an
            // empty form again, which reads as the code having been accepted
            'searched' => $typed !== '',
            'approverIp' => $request->ip(),
        ]);
    }
}
