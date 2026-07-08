<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Support\CompanyContext;
use App\Support\IdempotencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_operation_runs_once_and_replays_snapshot(): void
    {
        app(CompanyContext::class)->set(Company::factory()->create());
        $service = app(IdempotencyService::class);
        $key = (string) Str::uuid();
        $runs = 0;

        $first = $service->execute($key, 'invoice.send', function () use (&$runs) {
            $runs++;
            return ['invoice_id' => 42];
        });
        $second = $service->execute($key, 'invoice.send', function () use (&$runs) {
            $runs++;
            return ['invoice_id' => 99];
        });

        $this->assertSame(1, $runs);
        $this->assertSame(['invoice_id' => 42], $first);
        $this->assertSame(['invoice_id' => 42], $second);
    }

    public function test_failed_operation_releases_the_key(): void
    {
        app(CompanyContext::class)->set(Company::factory()->create());
        $service = app(IdempotencyService::class);
        $key = (string) Str::uuid();

        try {
            $service->execute($key, 'invoice.send', fn () => throw new \RuntimeException('boom'));
        } catch (\RuntimeException) {
        }

        $result = $service->execute($key, 'invoice.send', fn () => ['ok' => true]);
        $this->assertSame(['ok' => true], $result);
    }

    public function test_same_key_in_different_companies_is_independent(): void
    {
        [$a, $b] = Company::factory()->count(2)->create();
        $service = app(IdempotencyService::class);
        $ctx = app(CompanyContext::class);
        $key = (string) Str::uuid();

        $ctx->set($a);
        $ra = $service->execute($key, 'x', fn () => 'from-a');
        $rb = $ctx->runFor($b, fn () => $service->execute($key, 'x', fn () => 'from-b'));

        $this->assertSame('from-a', $ra);
        $this->assertSame('from-b', $rb);
    }
}
