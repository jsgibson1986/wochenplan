<?php

return [
    // Geheimer Token für den KAS-Cronjob: /system/sync?token=...
    'sync_token' => env('SYNC_TOKEN'),

    // Höchstzahl Wochen pro Druckauftrag.
    'druck_max_wochen' => 26,
];
