<?php

namespace Tests\Unit;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class BackupScheduleTest extends TestCase
{
    public function test_backup_commands_are_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->map(fn ($event) => $event->command);

        $this->assertTrue($events->contains(fn ($command) => str_contains((string) $command, 'backup:run')));
        $this->assertTrue($events->contains(fn ($command) => str_contains((string) $command, 'backup:clean')));
    }
}
