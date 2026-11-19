<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Merkt sich, welche Termine schon in Untis eingetragen sind.
        // Schlüssel aus Quelle + UID + Beginn, weil `termine` bei jedem
        // Abruf neu geschrieben wird (IDs ändern sich).
        Schema::create('untis_vermerke', function (Blueprint $table) {
            $table->id();
            $table->string('schluessel', 64)->unique();
            $table->string('titel')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('untis_vermerke');
    }
};
