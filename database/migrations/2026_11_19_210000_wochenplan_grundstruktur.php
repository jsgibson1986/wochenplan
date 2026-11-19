<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('ist_admin')->default(false);
            $table->boolean('aktiv')->default(true);
            // Pro Nutzer gemerkte Ansicht: ausgeblendete Quellen, Wochenende an/aus.
            $table->json('einstellungen')->nullable();
        });

        Schema::create('quellen', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // caldav | ical | ferien | feiertage
            $table->string('typ');
            $table->text('url')->nullable();
            $table->string('benutzername')->nullable();
            // Verschlüsselt über den Model-Cast (APP_KEY).
            $table->text('passwort')->nullable();
            $table->string('farbe', 7)->default('#2563eb');
            $table->boolean('aktiv')->default(true);
            $table->unsignedInteger('reihenfolge')->default(0);
            $table->timestamp('letzter_abruf')->nullable();
            $table->timestamp('letzter_erfolg')->nullable();
            $table->text('letzter_fehler')->nullable();
            $table->unsignedInteger('anzahl_termine')->default(0);
            $table->timestamps();
        });

        Schema::create('termine', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quelle_id')->constrained('quellen')->cascadeOnDelete();
            $table->string('uid')->nullable();
            $table->string('titel');
            $table->text('beschreibung')->nullable();
            $table->string('ort')->nullable();
            $table->text('link')->nullable();
            // Lokale Zeit (Europe/Berlin). Bei ganztägigen Terminen ist
            // `ende` wie in iCal exklusiv (Folgetag 00:00).
            $table->dateTime('beginn');
            $table->dateTime('ende');
            $table->boolean('ganztaegig')->default(false);
            $table->timestamps();

            $table->index(['beginn', 'ende']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('termine');
        Schema::dropIfExists('quellen');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['ist_admin', 'aktiv', 'einstellungen']);
        });
    }
};
