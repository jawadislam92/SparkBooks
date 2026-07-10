<?php

namespace App\Exceptions\Documents;

/** Thrown by ExpenseService::record()/BillService::receive() when the document has already left draft status. */
class DocumentNotDraft extends DocumentPostException {}
