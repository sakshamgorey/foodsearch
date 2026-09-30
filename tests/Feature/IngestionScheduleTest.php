<?php

namespace Tests\Feature;

use App\Models\IngestionRun;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IngestionScheduleTest extends TestCase
{
    use RefreshDatabase;

    private function scheduled(string $command): ?Event
    {
        $this->app->make(Kernel::class)->bootstrap();

        return collect($this->app->make(Schedule::class)->events())
            ->first(fn (Event $event) => str_ends_with($event->command, $command));
    }

    public function test_weekly_run_is_incremental(): void
    {
        $event = $this->scheduled("'artisan' foodfacts:ingest");

        $this->assertNotNull($event);
        $this->assertSame('13 2 * * 0', $event->expression);
    }

    public function test_monthly_full_run_goes_past_the_watermark(): void
    {
        $event = $this->scheduled("'artisan' foodfacts:ingest --full");

        $this->assertNotNull($event);
        $this->assertSame('13 3 1 * *', $event->expression);
        $this->assertSame('Asia/Kolkata', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }

    public function test_full_run_uses_the_configured_page_cap(): void
    {
        Queue::fake();

        $this->artisan('foodfacts:ingest --full')->assertSuccessful();

        $run = IngestionRun::sole();
        $this->assertSame('full', $run->mode);
        $this->assertNull($run->cutoff);
        $this->assertSame(config('foodfacts.max_pages'), $run->max_pages);
    }
}
