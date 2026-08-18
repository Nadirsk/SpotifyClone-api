<?php

declare(strict_types=1);

namespace App\Contracts\Sms;

/**
 * A vendor that can deliver an SMS. `OtpService` depends on this, not on any
 * one vendor's HTTP shape — swapping providers later is a new class here, not
 * a change to how OTPs are generated, stored or rate-limited.
 */
interface SmsGateway
{
    /**
     * @return bool True once the vendor has accepted the message for delivery.
     *              Never throws on a vendor-side failure — a down SMS gateway
     *              is a routine, expected failure mode, not an exception.
     */
    public function send(string $phone, string $message): bool;
}
