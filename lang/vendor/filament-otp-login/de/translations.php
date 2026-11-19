<?php

return [
    'otp_code' => 'Code',

    'mail' => [
        'subject' => 'Dein Anmeldecode',
        'greeting' => 'Hallo!',
        'line1' => 'Dein Anmeldecode lautet: :code',
        'line2' => 'Dieser Code ist :seconds Sekunden lang gültig.',
        'line3' => 'Falls du keinen Code angefordert hast, kannst du diese E-Mail ignorieren.',
        'salutation' => 'Viele Grüße, :app_name',
    ],

    'view' => [
        'time_left' => 'Sekunden verbleibend',
        'resend_code' => 'Code erneut senden',
        'verify' => 'Bestätigen',
        'go_back' => 'Zurück',
    ],

    'notifications' => [
        'title' => 'Code gesendet',
        'body' => 'Der Bestätigungscode wurde an deine E-Mail-Adresse gesendet. Er ist :seconds Sekunden lang gültig.',
    ],

    'validation' => [
        'invalid_code' => 'Der eingegebene Code ist ungültig.',
        'expired_code' => 'Der eingegebene Code ist abgelaufen.',
        'too_many_attempts' => 'Zu viele Versuche. Bitte warte kurz und versuche es erneut.',
    ],
];
