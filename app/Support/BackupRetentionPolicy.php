<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * THE retention rule for `sparkbooks:backup` (spec §11): "14 daily + 8
 * weekly, prune the rest." Pure and stateless by design, like
 * AttachmentContentSniffer/CategoryAccountGuard — no disk I/O, no clock
 * reads of its own (both the filename list and "today" are passed in), so
 * the whole rotation policy is testable with fabricated filenames and no
 * real files anywhere.
 *
 * Two tiers, evaluated in this order:
 *
 *   1. "Daily" — every backup dated within the most recent {@see $dailyDays}
 *      calendar days counting back from $today (inclusive of both ends) is
 *      kept, however many of them there are. This is calendar-anchored
 *      rather than a plain "top N filenames" cut so that a gap in the
 *      schedule (a missed day) doesn't silently pull an extra old backup
 *      into the fresh window, and so the boundary is exact: a backup dated
 *      exactly $dailyDays-1 days before $today is kept; one day older than
 *      that is not — it falls through to tier 2.
 *
 *   2. "Weekly" — among backups OLDER than the daily window, group by ISO
 *      (year, week). Take the {@see $weeklyCount} most-recent DISTINCT
 *      weeks present and keep only the single most-recent backup in each of
 *      those weeks. Everything else — weeks beyond that count, and any
 *      extra same-week backups — is marked for deletion.
 *
 * A filename this class can't parse a date out of (see {@see parseDate})
 * is conservatively marked for deletion: a rotation policy should never
 * silently retain something it can't date.
 */
class BackupRetentionPolicy
{
    public const DEFAULT_DAILY_DAYS = 14;

    public const DEFAULT_WEEKLY_COUNT = 8;

    /**
     * @param  array<int, string>  $filenames  e.g. "sparkbooks-backup-2026-07-10_020000.zip"
     * @return array{keep: array<int, string>, delete: array<int, string>}
     */
    public static function partition(
        array $filenames,
        CarbonInterface $today,
        int $dailyDays = self::DEFAULT_DAILY_DAYS,
        int $weeklyCount = self::DEFAULT_WEEKLY_COUNT,
    ): array {
        $dated = [];
        $undated = [];

        foreach ($filenames as $filename) {
            $date = self::parseDate($filename);

            if ($date === null) {
                $undated[] = $filename;

                continue;
            }

            $dated[] = ['filename' => $filename, 'date' => $date];
        }

        $dailyCutoff = $today->copy()->startOfDay()->subDays($dailyDays - 1);

        $daily = [];
        $remainder = [];

        foreach ($dated as $entry) {
            if ($entry['date']->greaterThanOrEqualTo($dailyCutoff)) {
                $daily[] = $entry;
            } else {
                $remainder[] = $entry;
            }
        }

        // Group the remainder by ISO (year, week) — zero-padded so the keys
        // sort correctly as plain strings.
        $weeks = [];
        foreach ($remainder as $entry) {
            $weekKey = sprintf('%04d-W%02d', $entry['date']->isoWeekYear, $entry['date']->isoWeek);
            $weeks[$weekKey][] = $entry;
        }

        // Most-recent week first.
        krsort($weeks);
        $selectedWeeks = array_slice(array_keys($weeks), 0, $weeklyCount);

        $weeklyKeep = [];
        $weeklyDelete = [];

        foreach ($weeks as $weekKey => $entries) {
            usort($entries, fn (array $a, array $b) => $b['date'] <=> $a['date']);

            if (in_array($weekKey, $selectedWeeks, true)) {
                // Keep only the most-recent backup in this week.
                $weeklyKeep[] = $entries[0];
                $weeklyDelete = [...$weeklyDelete, ...array_slice($entries, 1)];
            } else {
                $weeklyDelete = [...$weeklyDelete, ...$entries];
            }
        }

        $filename = fn (array $entry): string => $entry['filename'];

        return [
            'keep' => array_map($filename, [...$daily, ...$weeklyKeep]),
            'delete' => [...array_map($filename, $weeklyDelete), ...$undated],
        ];
    }

    /**
     * Parses the timestamp `sparkbooks:backup` embeds in every artifact
     * filename (`sparkbooks-backup-YYYY-MM-DD_HHMMSS...`). Returns null for
     * anything that doesn't match — callers treat that as "can't date this,
     * don't keep it."
     */
    public static function parseDate(string $filename): ?CarbonImmutable
    {
        if (! preg_match('/(\d{4}-\d{2}-\d{2})_(\d{2})(\d{2})(\d{2})/', $filename, $matches)) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat(
                'Y-m-d H:i:s',
                "{$matches[1]} {$matches[2]}:{$matches[3]}:{$matches[4]}",
            );
        } catch (\Exception) {
            return null;
        }
    }
}
