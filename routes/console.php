<?php

use Illuminate\Support\Facades\Schedule;

// Weekly ingest: Sunday 02:13 IST. The command only *queues* page 1; a
// `queue:work --queue=ingestion` worker does the fetching.
Schedule::command('foodfacts:ingest')
    ->weeklyOn(0, '02:13')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping()
    ->onOneServer();

// Cheap safety net: resumes a run whose worker died or whose next-page
// dispatch was lost. A no-op when nothing is stalled.
Schedule::command('foodfacts:ingest --resume-only')
    ->hourly()
    ->onOneServer();

Schedule::command('queue:prune-failed --hours=336')->weekly();
