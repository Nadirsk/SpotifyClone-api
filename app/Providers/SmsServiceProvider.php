<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\Sms\SmsGateway;
use App\Services\Sms\TextSmsGateway;
use Illuminate\Support\ServiceProvider;

/**
 * One binding today, but kept separate from RepositoryServiceProvider: an SMS
 * vendor is an external integration like the music providers, not a data
 * repository, and a second vendor (a fallback gateway, say) is a new class
 * plus one line here, not a change to OtpService.
 */
class SmsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SmsGateway::class, TextSmsGateway::class);
    }
}
