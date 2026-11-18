<?php

namespace App\Services;

use App\Models\AttendanceSubjectDetail;
use Illuminate\Database\Eloquent\Builder;

class AttendanceDashboardUpdateService
{
    public function revision(Builder $attendances): string
    {
        $snapshot = (clone $attendances)
            ->orderBy('id')
            ->toBase()
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

    public function subjectRevision(Builder $attendances): string
    {
        $subjects = (clone $attendances)
            ->orderBy('id')
            ->toBase()
            ->get([
                'id',
                'schedule_id',
                'date',
                'status',
                'note',
                'submitted_at',
                'updated_at',
            ]);

        $details = AttendanceSubjectDetail::query()
            ->whereIn('attendance_subject_id', $subjects->pluck('id'))
            ->orderBy('id')
            ->toBase()
            ->get([
                'id',
                'attendance_subject_id',
                'student_id',
                'status',
                'note',
                'updated_at',
            ]);

        return hash('sha256', $subjects->toJson().$details->toJson());
    }
}
