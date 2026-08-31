<?php

declare(strict_types=1);

return [
    'occupancy_duration_minutes' => env('GATE_OCCUPANCY_DURATION_MINUTES', 90),

    'ingestion' => [
        'airport_icao' => env('AIRPORT_ICAO', 'EDDF'),
        'query_window_minutes' => env('FLIGHT_QUERY_WINDOW_MINUTES', 120),
        'query_delay_minutes' => env('FLIGHT_QUERY_DELAY_MINUTES', 1_440),
    ],

    'audit' => [
        'stale_unassigned_after_minutes' => env('AUDIT_STALE_UNASSIGNED_AFTER_MINUTES', 15),
    ],
];
