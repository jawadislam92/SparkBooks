<?php

namespace App\Support;

/**
 * Redacts command-line secrets from free-text before it is logged or sent
 * to an external channel (alert email / Slack).
 *
 * The concrete leak this closes (Phase 6 Task 7 review): mysqldump's argv is
 * built with `--password=<live DB password>` inline (BackupDatabase::
 * dumpDatabase). When mysqldump times out or fails to start, Symfony's
 * ProcessTimedOutException / ProcessStartFailedException interpolate the FULL
 * command line — password and all — into their exception message. That
 * message used to flow verbatim into the backup-failure alert body and the
 * logs, so a production dump hang would email/Slack the cleartext DB
 * password. Every Process-derived message (and, defensively, every other
 * failure message) is scrubbed through here before it leaves the process.
 *
 * Scrubs the two ways a DB password appears on a command line:
 *   --password=<secret>   → --password=***   (mysqldump/mysql long form)
 *   -p<secret>            → -p***            (mysql short form; matched only
 *                                             at a token boundary so it never
 *                                             mangles the long form above)
 * Non-secret args (--host, --user, --port, the db name) are left intact, and
 * this only ever touches the error-message/log path — never the actual argv
 * handed to Process, so the real dump command is unaffected.
 */
class SecretScrubber
{
    public static function scrub(?string $text): string
    {
        if ($text === null || $text === '') {
            return (string) $text;
        }

        return (string) preg_replace(
            [
                '/(--password=)\S+/i',
                '/(?<!\S)(-p)[^\s]+/i',
            ],
            ['$1***', '$1***'],
            $text
        );
    }
}
