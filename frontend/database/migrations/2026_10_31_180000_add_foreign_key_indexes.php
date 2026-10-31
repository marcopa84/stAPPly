<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQLite non indicizza da solo le chiavi esterne: senza questi indici i messaggi di una
 * chat, gli agenti e le chat di un utente, e le cancellazioni a cascata delle sandbox,
 * scorrono le tabelle intere. conversations.agent_id è già coperto dall'unique composto.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->index('conversation_id');
        });

        Schema::table('agents', function (Blueprint $table) {
            $table->index('user_id');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['conversation_id']);
        });

        Schema::table('agents', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
        });
    }
};
