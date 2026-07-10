<?php

namespace App\Exceptions\Documents;

/**
 * Thrown by BillService::receive() when the bill has no due date set —
 * nullable at draft (Phase 4 T1/T2, payment-terms decision deferred), but
 * required to receive.
 */
class BillDueDateRequired extends DocumentPostException {}
