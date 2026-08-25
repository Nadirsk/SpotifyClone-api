<?php

declare(strict_types=1);

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Distinguishes admin-panel staff from ordinary listeners on the same table.
 * Deliberately not `$fillable` on the model — see App\Models\User — so it can
 * only ever be set with `forceFill()` from trusted code (seeder/console
 * command), never through a public request payload.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', UserRole::values())
                ->default(UserRole::Listener->value)
                ->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
