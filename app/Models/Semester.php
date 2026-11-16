<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Semester extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'academic_year_id',
        'name',
        'semester_number',
        'start_date',
        'end_date',
        'holiday_start_date',
        'holiday_end_date',
        'is_active',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
        'semester_number' => 'integer',
        'holiday_start_date' => 'date',
        'holiday_end_date' => 'date',
    ];

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function getPeriodLabelAttribute(): string
    {
        return $this->semester_number === 1 ? 'Ganjil' : 'Genap';
    }

    public function breakStatusOn(\Carbon\CarbonInterface|string $date): string
    {
        if (!$this->holiday_start_date || !$this->holiday_end_date) {
            return 'not_configured';
        }

        $date = \Illuminate\Support\Carbon::parse($date)->toDateString();

        if ($date < $this->holiday_start_date->toDateString()) {
            return 'upcoming';
        }

        if ($date > $this->holiday_end_date->toDateString()) {
            return 'finished';
        }

        return 'holiday';
    }
}
