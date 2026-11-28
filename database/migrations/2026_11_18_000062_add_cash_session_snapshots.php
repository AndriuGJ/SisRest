<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('turnos_caja', function (Blueprint $table) {
            $table->uuid('clave_apertura')->nullable()->unique();
            $table->string('caja_nombre', 100)->nullable();
            $table->string('responsable_nombre')->nullable();
            $table->foreignId('abierta_por')->nullable()->constrained('users')->restrictOnDelete();
        });
        Schema::table('arqueos_caja', function (Blueprint $table) {
            $table->uuid('clave_idempotencia')->nullable()->unique();
            $table->json('resumen')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('arqueos_caja', function (Blueprint $table) {
            $table->dropUnique(['clave_idempotencia']);
            $table->dropColumn(['clave_idempotencia', 'resumen']);
        });
        Schema::table('turnos_caja', function (Blueprint $table) {
            $table->dropConstrainedForeignId('abierta_por');
            $table->dropUnique(['clave_apertura']);
            $table->dropColumn(['clave_apertura', 'caja_nombre', 'responsable_nombre']);
        });
    }
};
