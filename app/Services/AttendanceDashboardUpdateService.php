<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;

class AttendanceDashboardUpdateService
{
    public function revision(Builder $attendances): string
    {
        $snapshot = (clone $attendances)
            ->orderBy('id')
            ->get([
                'id',
                'student_id',
                'date',
                'time_in',
                'status',
                'method',
                'note',
                'scanned_by',
                'petugas_piket_id',
                'updated_at',
            ]);

        return hash('sha256', $snapshot->toJson());
    }
}
