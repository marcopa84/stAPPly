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
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 100);
            // session_id per il backend (docs/api.md): la memoria della chat sta lì, sotto <agent_id>:<session_id>.
            $table->string('session_id', 64)->unique();
            $table->timestamps();

            // Lo stesso nome riapre la stessa conversazione: è così che l'agente "ricorda".
            $table->unique(['agent_id', 'user_id', 'title']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
