<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SendOtpRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Services\Auth\OtpService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Phone sign-up's OTP send/verify — kept separate from AuthController
 * (05_API_SPECIFICATION §4) since this isn't part of that documented surface;
 * see the phone sign-up screens' docblock for why it exists at all.
 */
final class OtpController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly OtpService $otp,
    ) {}

    public function send(SendOtpRequest $request): JsonResponse
    {
        $this->otp->send((string) $request->validated('phone'));

        // Never echoes the code back — see TextSmsGateway/OtpService docblocks.
        return $this->respondSuccess(null, 'Code sent.');
    }

    public function verify(VerifyOtpRequest $request): JsonResponse
    {
        $this->otp->verify(
            (string) $request->validated('phone'),
            (string) $request->validated('otp'),
        );

        return $this->respondSuccess(null, 'Phone number verified.');
    }
}
