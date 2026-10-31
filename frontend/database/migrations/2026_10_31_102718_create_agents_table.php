<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // agent_id per il backend (docs/api.md): un ULID in minuscolo, o demo-* per gli agenti demo.
            $table->string('backend_id', 50)->unique();
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            $table->text('instructions');
            $table->boolean('is_demo')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agents');
    }
};
