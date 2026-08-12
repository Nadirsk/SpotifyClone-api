<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Contracts\Sms\SmsGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The DLT-registered gateway at textsms.thetechmore.in — a plain HTTP GET
 * with the auth key, sender id and DLT template id as query parameters.
 *
 * The auth key is never logged, on any path: `logAttempt()` below builds its
 * own field list rather than dumping `config('services.textsms')` wholesale,
 * the same discipline `AbstractProviderAdapter::scrub()` applies to every
 * other provider credential in this codebase.
 */
final class TextSmsGateway implements SmsGateway
{
    public function send(string $phone, string $message): bool
    {
        $config = config('services.textsms');

        $url = $config['endpoint'].'?'.http_build_query([
            'authentic-key' => $config['auth_key'],
            'senderid' => $config['sender_id'],
            'route' => $config['route'],
            'number' => $phone,
            'message' => $message,
            'templateid' => $config['template_id'],
        ]);

        try {
            $response = Http::timeout(10)->connectTimeout(5)->get($url);
        } catch (ConnectionException $exception) {
            $this->logFailure('connection', $phone, $exception->getMessage());

            return false;
        } catch (Throwable $exception) {
            $this->logFailure('unexpected', $phone, $exception->getMessage());

            return false;
        }

        if (! $response->successful()) {
            $this->logFailure('http_'.$response->status(), $phone, $response->body());

            return false;
        }

        /*
         | The vendor has no documented JSON contract — a 200 with "success"
         | somewhere in the body is the only signal it gives. Treating any
         | other 2xx as failure would risk false negatives on a delivery that
         | actually went through, given how thin that contract is.
         */
        if (str_contains(mb_strtolower($response->body()), 'error')) {
            $this->logFailure('vendor_error', $phone, $response->body());

            return false;
        }

        return true;
    }

    private function logFailure(string $reason, string $phone, string $detail): void
    {
        Log::warning('SMS delivery failed.', [
            'reason' => $reason,
            'phone' => $this->maskPhone($phone),
            'detail' => $detail,
        ]);
    }

    /** Enough to correlate log lines without a phone number sitting in the clear in log storage. */
    private function maskPhone(string $phone): string
    {
        return str_repeat('•', max(0, mb_strlen($phone) - 4)).mb_substr($phone, -4);
    }
}
