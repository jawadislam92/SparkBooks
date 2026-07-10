<?php

namespace App\Exceptions\Documents;

/** Thrown by ExpenseService::record()/BillService::receive() when the draft has no lines to post. */
class DocumentHasNoLines extends DocumentPostException {}
