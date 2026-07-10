<?php

namespace App\Exceptions\Documents;

/**
 * Thrown by BillService::receive() when the bill's contact isn't a vendor
 * (or both). StoreBillRequest already enforces this at draft-write time
 * (Phase 4 T2) — this is the caller-independent re-guard, mirroring
 * InvoiceService::guard's contact re-check (Phase 3 T3): the contact's type
 * may have changed since the draft was created, or a row may reach receive()
 * some other way.
 */
class BillContactNotEligible extends DocumentPostException {}
