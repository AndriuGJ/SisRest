<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('envios_cocina', function (Blueprint $table) {
            $table->timestamp('terminado_at')->nullable();
            $table->foreignId('terminado_por')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('envios_cocina', function (Blueprint $table) {
            $table->dropConstrainedForeignId('terminado_por');
            $table->dropColumn('terminado_at');
        });
    }
};
