<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Passwordless login's one-time code — the email counterpart to the phone
 * flow's SMS. Sent synchronously from EmailLoginCodeService, not queued: a
 * login code the visitor is actively waiting on is exactly the kind of send
 * that must not silently sit behind an idle queue worker, the same reasoning
 * OtpService::send() already applies to the SMS gateway call.
 */
final class LoginCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $code,
        public readonly int $ttlMinutes,
    ) {}

    /**
     * The product name comes from `app.name`, not a literal — the subject
     * sits next to a From name built from `mail.from.name`, and the two
     * disagreeing (as they did) is visible to the recipient.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->code.' – your '.config('app.name').' login code',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.login-code',
        );
    }
}
