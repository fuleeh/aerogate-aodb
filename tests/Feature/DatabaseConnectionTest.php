<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DatabaseConnectionTest extends TestCase
{
    public function test_the_application_uses_postgresql(): void
    {
        self::assertSame('pgsql', DB::connection()->getDriverName());
    }
}
