<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Phone sign-up accounts have no email at all — `email` has to stop being
| NOT NULL for that to be representable. Laravel's fluent `change()` needs
| doctrine/dbal, which this project doesn't install, so this drops to a raw
| ALTER for that one column, same escape hatch already used for the
| phone_otps timestamp bug.
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE users MODIFY email VARCHAR(255) NULL');

        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->unique()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('phone');
        });

        DB::statement('ALTER TABLE users MODIFY email VARCHAR(255) NOT NULL');
    }
};
