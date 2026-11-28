<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 50)->nullable()->unique();
            $table->boolean('activo')->default(true);
            $table->string('email')->nullable()->change();
        });

        // Preserve existing accounts when applying this migration to a starter installation.
        DB::table('users')->orderBy('id')->eachById(function (object $user): void {
            DB::table('users')->where('id', $user->id)->update(['username' => 'usuario'.$user->id]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 50)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn(['username', 'activo']);
        });
    }
};
