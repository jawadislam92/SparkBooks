<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCompanyRequest;
use App\Services\CompanySetupService;
use App\Support\Currencies;
use Illuminate\Http\Request;
use Inertia\Inertia;

class OnboardingController extends Controller
{
    public function create(Request $request)
    {
        if ($request->user()->companies()->exists()) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('Onboarding/CreateCompany', ['currencies' => Currencies::COMMON]);
    }

    public function store(StoreCompanyRequest $request, CompanySetupService $setup)
    {
        if ($request->user()->companies()->exists()) {
            return redirect()->route('dashboard');
        }

        $setup->create($request->user(), $request->validated());

        return redirect()->route('dashboard');
    }
}
