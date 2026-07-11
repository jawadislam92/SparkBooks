<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use App\Support\AuditHumanizer;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 6 Task 2 (spec §7.13) — a READ-ONLY viewer over the append-only
 * `activity_log` that every mutating action across the app already writes
 * to (AuditLogger, PROJECT_MAP §9 rule 5). This controller adds NO new
 * logging — viewing the log is not itself an audited mutation, so
 * AuditLogger is never called here. Gated `can:audit.view` at the route
 * (Owner/Accountant only, config/permissions.php) — same middleware pattern
 * HealthController already uses for its own audit.view-gated screen.
 *
 * The list payload is deliberately humanized (via the shared
 * AuditHumanizer, so this can never drift from DashboardController's own
 * recent-activity widget) and NEVER carries the row's raw before/after JSON
 * diff — that column can hold another user's full field values, and this
 * screen is reachable by anyone holding audit.view, not just the actor who
 * made the change.
 */
class AuditLogController extends Controller
{
    /** Rows per page — the same paginate-and-render shape ManualJournalController::index already uses. */
    private const PER_PAGE = 50;

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'module' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $logs = ActivityLog::query()
            ->with('user:id,name')
            ->when($filters['user_id'] ?? null, fn ($query, $userId) => $query->where('user_id', $userId))
            // A trailing literal '.' after the module name is what keeps this
            // an exact-prefix match rather than a loose substring one — e.g.
            // module=bill must NOT also pick up bill_payment.* rows (they
            // share the "bill" substring but not the "bill." prefix).
            ->when($filters['module'] ?? null, fn ($query, $module) => $query->where('action', 'like', $module.'.%'))
            ->when(
                $filters['from'] ?? null,
                fn ($query, $from) => $query->where('created_at', '>=', CarbonImmutable::parse($from)->startOfDay())
            )
            ->when(
                $filters['to'] ?? null,
                fn ($query, $to) => $query->where('created_at', '<=', CarbonImmutable::parse($to)->endOfDay())
            )
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (ActivityLog $log): array => [
                'id' => $log->id,
                'created_at' => $log->created_at->toIso8601String(),
                'user_id' => $log->user_id,
                'user_name' => $log->user?->name,
                'action' => AuditHumanizer::action($log->action),
                'subject' => AuditHumanizer::subject($log->subject_type, $log->subject_id),
                'ip' => $log->ip,
                // NOTE: deliberately no 'before'/'after' keys here — see the
                // class docblock. Never add them to this default list payload.
            ]);

        return Inertia::render('Audit/Index', [
            'logs' => $logs,
            'filters' => [
                'user_id' => isset($filters['user_id']) ? (int) $filters['user_id'] : null,
                'module' => $filters['module'] ?? null,
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
            ],
            'users' => $this->filterableUsers(),
            'modules' => $this->filterableModules(),
        ]);
    }

    /**
     * Every user who has at least one activity_log row for THIS company —
     * naturally tenant-scoped because it's derived from ActivityLog's own
     * BelongsToCompany-scoped rows, not a raw User::all().
     *
     * @return list<array{id:int, name:string}>
     */
    private function filterableUsers(): array
    {
        $userIds = ActivityLog::query()->whereNotNull('user_id')->distinct()->pluck('user_id');

        return User::query()
            ->whereIn('id', $userIds)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])
            ->all();
    }

    /**
     * The distinct set of modules (the action prefix before the first dot)
     * actually present in this company's log — derived from the real data
     * rather than a hand-maintained list, so it can never drift from
     * whatever AuditLogger calls actually exist across the app.
     *
     * @return list<string>
     */
    private function filterableModules(): array
    {
        return ActivityLog::query()
            ->select('action')
            ->distinct()
            ->pluck('action')
            ->map(fn (string $action): string => AuditHumanizer::module($action))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
