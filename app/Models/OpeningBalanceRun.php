<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OpeningBalanceRun extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = ['books_start_date', 'status', 'created_by'];

    protected function casts(): array
    {
        return ['books_start_date' => 'date'];
    }

    public function openingDocuments(): HasMany
    {
        return $this->hasMany(OpeningDocument::class);
    }
}
