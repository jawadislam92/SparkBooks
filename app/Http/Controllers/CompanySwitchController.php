<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Support\AuditLogger;
use App\Support\CompanyContext;
use Illuminate\Http\Request;

class CompanySwitchController extends Controller
{
    public function __invoke(Request $request, CompanyContext $context, AuditLogger $logger)
    {
        $validated = $request->validate(['company_id' => ['required', 'integer']]);
        $company = Company::query()->findOrFail($validated['company_id']);
        abort_unless($request->user()->belongsToCompany($company), 404);

        $previousCompanyId = $request->user()->current_company_id;
        $request->user()->forceFill(['current_company_id' => $company->id])->save();

        // Logged inside the TARGET company's context, so the row is tenant-scoped
        // to the company being switched into, not the one being left.
        $context->runFor($company, fn () => $logger->log(
            'company.switched',
            $company,
            before: ['company_id' => $previousCompanyId],
            after: ['company_id' => $company->id],
        ));

        return back();
    }
}
