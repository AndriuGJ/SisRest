<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('combo_componentes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('combo_id')->constrained('productos')->restrictOnDelete();
            $table->foreignId('producto_id')->constrained('productos')->restrictOnDelete();
            $table->decimal('cantidad', 16, 4);
            $table->unique(['combo_id', 'producto_id']);
            $table->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE combo_componentes ADD CONSTRAINT combo_componentes_check_1 CHECK (cantidad > 0)');
            DB::statement('ALTER TABLE combo_componentes ADD CONSTRAINT combo_componentes_check_2 CHECK (combo_id <> producto_id)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('combo_componentes');
    }
};
