<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| One row per OTP sent, not one row per phone: an audit trail of every send
| and verify attempt is worth more than an upsert, and phone sign-up has no
| account yet to hang the row off (`user_id` stays null until, if ever,
| registration lands).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_otps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone', 20);
            // Hashed like a password, not stored in the clear — a database
            // leak should not hand out active, unexpired codes.
            $table->string('otp_hash');
            $table->string('type', 20)->default('signup');
            /*
             | dateTime(), not timestamp(): a bare TIMESTAMP column with no
             | explicit default gets MySQL/MariaDB's legacy implicit
             | `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` applied
             | to the *first* one in the table when `explicit_defaults_for_timestamp`
             | is off, which is this box's MariaDB (its my.ini has already been
             | hand-tuned once before, for FULLTEXT's min token size). That
             | silently reset `expires_at` to "now" on every later UPDATE to
             | the row, including the one
             | `verify()` itself performs to set `verified_at` — the two ended
             | up holding the exact same value. DATETIME has no such behaviour.
             */
            $table->dateTime('expires_at');
            $table->dateTime('verified_at')->nullable();
            $table->timestamps();

            $table->index(['phone', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_otps');
    }
};
