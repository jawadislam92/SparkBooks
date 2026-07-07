<?php
namespace App\Support;

use App\Exceptions\MissingCompanyContext;
use App\Models\Company;
use Closure;

class CompanyContext
{
    private ?Company $company = null;

    public function set(Company $company): void
    {
        $this->company = $company;
    }

    public function clear(): void
    {
        $this->company = null;
    }

    public function has(): bool
    {
        return $this->company !== null;
    }

    public function get(): Company
    {
        return $this->company ?? throw new MissingCompanyContext();
    }

    public function id(): int
    {
        return $this->get()->id;
    }

    public function runFor(Company $company, Closure $callback): mixed
    {
        $previous = $this->company;
        $this->set($company);
        try {
            return $callback();
        } finally {
            $this->company = $previous;
        }
    }
}
