<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

Schedule::command('flights:fetch-and-allocate')
    ->name('flight-ingestion')
    ->everyFiveMinutes()
    ->withoutOverlapping(15)
    ->onOneServer();

Schedule::command('flights:audit')
    ->name('allocation-audit')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onOneServer();
