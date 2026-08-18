<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\LoginWithPhoneRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\RegisterWithPhoneRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\SendEmailLoginCodeRequest;
use App\Http\Requests\Auth\VerifyEmailLoginCodeRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\AuthService;
use App\Traits\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Authentication endpoints (05_API_SPECIFICATION §4).
 */
final class AuthController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AuthService $auth,
    ) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $result = $this->auth->register($request->validated());

        return $this->respondCreated($this->tokenPayload($result), 'Registration successful');
    }

    public function registerWithPhone(RegisterWithPhoneRequest $request): JsonResponse
    {
        $result = $this->auth->registerWithPhone($request->validated());

        return $this->respondCreated($this->tokenPayload($result), 'Registration successful');
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->auth->login(
            (string) $request->validated('email'),
            (string) $request->validated('password'),
            (string) $request->ip(),
        );

        return $this->respondSuccess($this->tokenPayload($result), 'Login successful');
    }

    public function loginWithPhone(LoginWithPhoneRequest $request): JsonResponse
    {
        $result = $this->auth->loginWithPhone((string) $request->validated('phone'));

        return $this->respondSuccess($this->tokenPayload($result), 'Login successful');
    }

    /**
     * Fixed response regardless of whether the address has an account —
     * same anti-enumeration shape as `forgotPassword()` below.
     */
    public function sendEmailLoginCode(SendEmailLoginCodeRequest $request): JsonResponse
    {
        $this->auth->sendEmailLoginCode((string) $request->validated('email'));

        return $this->respondSuccess(
            null,
            'If an account exists for that email, a login code has been sent.',
        );
    }

    public function verifyEmailLoginCode(VerifyEmailLoginCodeRequest $request): JsonResponse
    {
        $result = $this->auth->loginWithEmailCode(
            (string) $request->validated('email'),
            (string) $request->validated('code'),
        );

        return $this->respondSuccess($this->tokenPayload($result), 'Login successful');
    }

    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout($this->currentUser($request));

        return $this->respondSuccess(null, 'Logged out successfully');
    }

    public function me(Request $request): JsonResponse
    {
        return $this->respondSuccess(
            new UserResource($this->currentUser($request)),
            'Request successful',
        );
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $this->auth->forgotPassword((string) $request->validated('email'));

        /*
         | Fixed message and status regardless of whether the address exists —
         | any variation here is an account-enumeration oracle.
         */
        return $this->respondSuccess(
            null,
            'If an account exists for that email, a password reset link has been sent.',
        );
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        /** @var array{email: string, token: string, password: string, password_confirmation: string} $credentials */
        $credentials = $request->only('email', 'token', 'password', 'password_confirmation');

        $this->auth->resetPassword($credentials);

        return $this->respondSuccess(null, 'Password reset successfully. Please sign in again.');
    }

    /**
     * Returns the consent URL as JSON instead of a 302 — the API is stateless
     * and the browser navigation belongs to the client.
     */
    public function googleRedirect(): JsonResponse
    {
        return $this->respondSuccess(
            ['redirect_url' => $this->auth->googleRedirectUrl()],
            'Request successful',
        );
    }

    public function googleCallback(): JsonResponse
    {
        $result = $this->auth->loginWithGoogle();

        return $this->respondSuccess($this->tokenPayload($result), 'Login successful');
    }

    /**
     * @param  array{user: User, token: string}  $result
     * @return array<string, mixed>
     */
    private function tokenPayload(array $result): array
    {
        return [
            'user' => (new UserResource($result['user']))->resolve(),
            'token' => $result['token'],
            'token_type' => 'Bearer',
        ];
    }

    /**
     * Guard clause only: these routes sit behind `auth:sanctum`, so a null user
     * means the middleware was omitted rather than a real anonymous request.
     */
    private function currentUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }
}
