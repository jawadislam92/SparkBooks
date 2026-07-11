<?php

namespace Tests\Unit;

use App\Support\SecretScrubber;
use PHPUnit\Framework\TestCase;

/**
 * Phase 6 Task 7 review-fix: App\Support\SecretScrubber, the single redaction
 * point that keeps a DB password out of any logged / alerted Process error
 * message (see BackupDatabase's dumpDatabase()). Pure, no framework needed.
 */
class SecretScrubberTest extends TestCase
{
    public function test_it_redacts_the_long_form_password_flag(): void
    {
        $message = 'The process "mysqldump --host=127.0.0.1 --password=hunter2 sparkbooks" exceeded the timeout of 300 seconds.';

        $scrubbed = SecretScrubber::scrub($message);

        $this->assertStringNotContainsString('hunter2', $scrubbed);
        $this->assertStringContainsString('--password=***', $scrubbed);
        // Non-secret args survive — the message stays diagnostically useful.
        $this->assertStringContainsString('--host=127.0.0.1', $scrubbed);
        $this->assertStringContainsString('exceeded the timeout', $scrubbed);
    }

    public function test_it_redacts_the_short_form_password_flag_at_a_token_boundary(): void
    {
        $scrubbed = SecretScrubber::scrub('mysql -uroot -phunter2 sparkbooks');

        $this->assertStringNotContainsString('hunter2', $scrubbed);
        $this->assertStringContainsString('-p***', $scrubbed);
        // -uroot is not a password flag and must be left intact.
        $this->assertStringContainsString('-uroot', $scrubbed);
    }

    public function test_it_does_not_mangle_the_long_form_when_scrubbing_short_form(): void
    {
        // The short-form rule must not fire inside "--password=" (the "-p"
        // there is not at a token boundary) — only the long-form rule does.
        $scrubbed = SecretScrubber::scrub('cmd --password=hunter2');

        $this->assertSame('cmd --password=***', $scrubbed);
    }

    public function test_it_is_a_no_op_for_secret_free_and_empty_text(): void
    {
        $this->assertSame('', SecretScrubber::scrub(null));
        $this->assertSame('', SecretScrubber::scrub(''));
        $this->assertSame('mysqldump failed (exit 1): could not connect', SecretScrubber::scrub('mysqldump failed (exit 1): could not connect'));
    }
}
