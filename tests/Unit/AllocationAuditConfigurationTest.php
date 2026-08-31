<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\Auditing\AllocationAuditConfiguration;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AllocationAuditConfigurationTest extends TestCase
{
    public function test_environment_style_integer_is_normalized(): void
    {
        $configuration = AllocationAuditConfiguration::fromArray([
            'stale_unassigned_after_minutes' => '15',
        ]);

        $this->assertSame(15, $configuration->staleUnassignedAfterMinutes);
    }

    public function test_threshold_must_be_positive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AllocationAuditConfiguration(0);
    }
}
