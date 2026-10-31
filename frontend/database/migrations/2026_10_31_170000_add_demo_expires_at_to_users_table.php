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
        Schema::table('users', function (Blueprint $table) {
            // Valorizzato solo per le sandbox della demo: dopo questo momento la sandbox
            // non si usa più e stapply:prune-demo la cancella. Indice per il prune e per
            // il conteggio delle sandbox attive.
            $table->timestamp('demo_expires_at')->nullable()->index()->after('remember_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['demo_expires_at']);
            $table->dropColumn('demo_expires_at');
        });
    }
};
