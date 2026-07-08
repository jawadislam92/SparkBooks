<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Role;
use App\Support\CompanyContext;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Per-request memoization of roleIn() results, keyed by company id (not
     * by "current company"). This lets repeated Gate checks against the
     * same company within a single request avoid re-querying company_user
     * each time.
     *
     * Keying by company id — rather than a single "current role" slot —
     * means switching the active company mid-request cannot serve a stale
     * cached role for a DIFFERENT company: each company id gets its own
     * cache slot, populated lazily and independently, so a switch simply
     * means a new key gets used. This deliberately does not attempt to
     * invalidate a slot if the underlying company_user row changes
     * mid-request (e.g. another process changes this user's role); that is
     * out of scope for a single request's lifetime.
     *
     * @var array<int, Role|null>
     */
    protected array $roleCache = [];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class)->withPivot('role')->withTimestamps();
    }

    public function currentCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'current_company_id');
    }

    public function roleIn(Company $company): ?Role
    {
        if (array_key_exists($company->id, $this->roleCache)) {
            return $this->roleCache[$company->id];
        }

        $pivot = $this->companies()->whereKey($company->id)->first()?->pivot;

        return $this->roleCache[$company->id] = $pivot ? Role::from($pivot->role) : null;
    }

    /**
     * Convenience accessor for the role this user holds in whatever company
     * is currently active in the request (per CompanyContext). Returns null
     * if no company context has been set, rather than throwing.
     */
    public function currentRole(): ?Role
    {
        $context = app(CompanyContext::class);

        if (! $context->has()) {
            return null;
        }

        return $this->roleIn($context->get());
    }

    public function belongsToCompany(Company $company): bool
    {
        return $this->roleIn($company) !== null;
    }
}
