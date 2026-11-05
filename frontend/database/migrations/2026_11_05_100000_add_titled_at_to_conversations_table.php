<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le chat non si aprono più con un nome: nascono al primo messaggio con un titolo provvisorio,
 * poi il modello ne scrive uno, e l'utente lo può cambiare. Due chat possono chiamarsi uguali,
 * quindi il nome non è più unico; titled_at dice da quando il titolo non è più provvisorio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropUnique(['agent_id', 'user_id', 'title']);
            $table->timestamp('titled_at')->nullable();
        });

        // Le chat che esistono già hanno un nome scelto a mano: il titolo automatico non lo tocca.
        DB::table('conversations')->update(['titled_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('titled_at');
            $table->unique(['agent_id', 'user_id', 'title']);
        });
    }
};
