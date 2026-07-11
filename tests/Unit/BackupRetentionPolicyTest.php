<?php

namespace Tests\Unit;

use App\Support\BackupRetentionPolicy;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Phase 6 Task 6 (spec §11): "14 daily + 8 weekly, prune the rest." Pure
 * function, fabricated filenames, no real files anywhere — this is the
 * load-bearing testable unit for the whole backup/retention feature.
 *
 * Filenames follow the exact shape `sparkbooks:backup` produces:
 * `sparkbooks-backup-YYYY-MM-DD_HHMMSS.zip`.
 */
class BackupRetentionPolicyTest extends TestCase
{
    private function filenameFor(CarbonImmutable $date): string
    {
        return 'sparkbooks-backup-'.$date->format('Y-m-d_His').'.zip';
    }

    public function test_fewer_than_daily_window_keeps_everything(): void
    {
        $today = CarbonImmutable::parse('2026-07-10');

        $filenames = [
            $this->filenameFor($today),
            $this->filenameFor($today->subDays(1)),
            $this->filenameFor($today->subDays(5)),
        ];

        $result = BackupRetentionPolicy::partition($filenames, $today);

        sort($result['keep']);
        $expected = $filenames;
        sort($expected);

        $this->assertSame($expected, $result['keep']);
        $this->assertSame([], $result['delete']);
    }

    public function test_exactly_daily_window_keeps_all_fourteen_none_deleted(): void
    {
        $today = CarbonImmutable::parse('2026-07-10');

        // Exactly 14 days: today back through today-13.
        $filenames = [];
        for ($i = 0; $i < 14; $i++) {
            $filenames[] = $this->filenameFor($today->subDays($i));
        }

        $result = BackupRetentionPolicy::partition($filenames, $today);

        $this->assertCount(14, $result['keep']);
        $this->assertSame([], $result['delete']);
    }

    public function test_boundary_day_fourteen_is_kept_day_fifteen_falls_to_weekly_tier(): void
    {
        $today = CarbonImmutable::parse('2026-07-10');

        // Exactly on the daily cutoff (today - 13 days) — must be kept.
        $onCutoff = $this->filenameFor($today->subDays(13));
        // One day older than the cutoff — must NOT be in the daily tier.
        // It's alone in its ISO week, so under the weekly tier it should
        // still survive (as that week's representative) rather than vanish
        // silently — this proves it fell through to tier 2, not that it
        // was dropped outright.
        $justOutside = $this->filenameFor($today->subDays(14));

        $result = BackupRetentionPolicy::partition([$onCutoff, $justOutside], $today);

        $this->assertContains($onCutoff, $result['keep']);
        $this->assertContains($justOutside, $result['keep']);
        $this->assertSame([], $result['delete']);
    }

    public function test_spanning_many_weeks_keeps_only_eight_most_recent_distinct_weeks(): void
    {
        $today = CarbonImmutable::parse('2026-07-10');

        // 20 backups, one every 7 days, all older than the daily window.
        $filenames = [];
        for ($i = 1; $i <= 20; $i++) {
            $filenames[] = $this->filenameFor($today->subDays(13 + $i * 7));
        }

        $result = BackupRetentionPolicy::partition($filenames, $today);

        // None of these fall in the 14-day daily window, so every kept
        // filename here came from the weekly tier — exactly 8 of them.
        $this->assertCount(8, $result['keep']);
        $this->assertCount(12, $result['delete']);

        // The 8 kept must be the 8 MOST RECENT (the first 8 generated).
        $expectedKeep = array_slice($filenames, 0, 8);
        sort($expectedKeep);
        $actualKeep = $result['keep'];
        sort($actualKeep);
        $this->assertSame($expectedKeep, $actualKeep);
    }

    public function test_keeps_only_most_recent_backup_within_a_shared_week(): void
    {
        $today = CarbonImmutable::parse('2026-07-10');

        // Two backups, 20 days ago (older than the daily window) and 19
        // days ago, in the SAME ISO week (2026-06-20 and 2026-06-21 — a
        // Saturday and Sunday of the same ISO week).
        $older = $this->filenameFor($today->subDays(20));
        $newer = $this->filenameFor($today->subDays(19));

        $result = BackupRetentionPolicy::partition([$older, $newer], $today);

        $this->assertSame([$newer], $result['keep']);
        $this->assertSame([$older], $result['delete']);
    }

    public function test_unparseable_filenames_are_marked_for_deletion(): void
    {
        $today = CarbonImmutable::parse('2026-07-10');

        $result = BackupRetentionPolicy::partition(['not-a-backup.zip', $this->filenameFor($today)], $today);

        $this->assertSame([$this->filenameFor($today)], $result['keep']);
        $this->assertSame(['not-a-backup.zip'], $result['delete']);
    }

    public function test_empty_input_keeps_and_deletes_nothing(): void
    {
        $result = BackupRetentionPolicy::partition([], CarbonImmutable::parse('2026-07-10'));

        $this->assertSame([], $result['keep']);
        $this->assertSame([], $result['delete']);
    }

    public function test_custom_retention_numbers_are_honored(): void
    {
        $today = CarbonImmutable::parse('2026-07-10');

        // 5 daily-window backups + 10 weekly candidates (one per week).
        $filenames = [];
        for ($i = 0; $i < 5; $i++) {
            $filenames[] = $this->filenameFor($today->subDays($i));
        }
        for ($i = 1; $i <= 10; $i++) {
            $filenames[] = $this->filenameFor($today->subDays(4 + $i * 7));
        }

        $result = BackupRetentionPolicy::partition($filenames, $today, dailyDays: 5, weeklyCount: 3);

        $this->assertCount(5 + 3, $result['keep']);
        $this->assertCount(7, $result['delete']);
    }
}
