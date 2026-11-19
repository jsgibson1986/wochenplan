<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Sicherung: Mit gecachter Konfiguration (bootstrap/cache/config.php)
     * ignoriert Laravel die Testwerte aus phpunit.xml, die Tests liefen dann
     * gegen die ECHTE Datenbank und RefreshDatabase würde sie leeren.
     * Tests laufen auf dem Produktionsserver, deshalb vorher abbrechen.
     */
    public function createApplication()
    {
        if (file_exists(dirname(__DIR__).'/bootstrap/cache/config.php')) {
            throw new \RuntimeException('Konfiguration ist gecacht. Erst `php artisan config:clear` ausführen, sonst würden die Tests die echte Datenbank verwenden.');
        }

        return parent::createApplication();
    }
}
