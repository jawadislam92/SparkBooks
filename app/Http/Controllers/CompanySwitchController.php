<?php
namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\Request;

class CompanySwitchController extends Controller
{
    public function __invoke(Request $request)
    {
        $validated = $request->validate(['company_id' => ['required', 'integer']]);
        $company = Company::query()->findOrFail($validated['company_id']);
        abort_unless($request->user()->belongsToCompany($company), 404);
        $request->user()->forceFill(['current_company_id' => $company->id])->save();
        return back();
    }
}
