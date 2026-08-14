<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Passwordless email login's one-time code — the email counterpart to
| phone_otps, but simpler: every row always has a user (there is no
| "signup" branch to leave `user_id` null for, unlike phone sign-up), so
| `user_id` is required, not nullable.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_login_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            // Hashed like phone_otps.otp_hash — a database leak should not
            // hand out active, unexpired codes.
            $table->string('code_hash');
            /*
             | dateTime(), not timestamp() — see phone_otps' own migration
             | comment. A bare TIMESTAMP with no explicit default gets this
             | box's MariaDB legacy implicit ON UPDATE CURRENT_TIMESTAMP,
             | which would silently reset `expires_at` on the same row's
             | later `verified_at` UPDATE.
             */
            $table->dateTime('expires_at');
            $table->dateTime('verified_at')->nullable();
            $table->timestamps();

            $table->index(['user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_login_codes');
    }
};
