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
        Schema::table('messages', function (Blueprint $table) {
            // Solo per le domande: quando una richiesta reply() l'ha presa in carico. Così
            // ogni domanda contata dal limite arriva al backend una volta sola (vedi Chat).
            $table->timestamp('reply_started_at')->nullable()->after('sources');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('reply_started_at');
        });
    }
};
