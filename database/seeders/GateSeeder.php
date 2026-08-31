<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Gate;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Seeder;

final class GateSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->gateCodes() as $priority => $code) {
            Gate::query()->updateOrCreate(
                ['code' => $code],
                [
                    'is_active' => true,
                    'allocation_priority' => $priority + 1,
                ],
            );
        }

        $utc = new DateTimeZone('UTC');
        $b8 = Gate::query()->where('code', 'B8')->sole();

        $b8->unavailabilities()->updateOrCreate(
            [
                'starts_at' => new DateTimeImmutable('2025-01-10 00:00:00', $utc),
                'ends_at' => new DateTimeImmutable('2025-01-12 00:00:00', $utc),
            ],
            ['reason' => 'Scheduled gate repairs'],
        );
    }

    /** @return list<string> */
    private function gateCodes(): array
    {
        return [
            'A1', 'A2', 'A3', 'A4', 'A5',
            'A6', 'A7', 'A8', 'A9', 'A10',
            'B1', 'B2', 'B3', 'B4', 'B5',
            'B6', 'B7', 'B8', 'B9', 'B10',
        ];
    }
}
