<?php

namespace App\Http\Controllers;

use App\Models\AttendanceGate;
use App\Models\Student;
use App\Services\AttendanceDashboardUpdateService;
use App\Services\DateTimeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AttendanceDashboardUpdateController extends Controller
{
    public function __invoke(
        Request $request,
        AttendanceDashboardUpdateService $updates,
        DateTimeService $dateTimeService
    ): JsonResponse {
        $user = $request->user();
        $date = $dateTimeService->currentDate();
        $attendances = AttendanceGate::query()->whereDate('date', $date);
        $scopeStudentId = null;

        if ($user->hasRole('super_admin')) {
            // Admin dashboard observes all gate attendance for the current school date.
        } elseif ($user->hasRole('siswa')) {
            $student = $user->student;
            abort_unless($student, 404, 'Data siswa tidak ditemukan.');
            $attendances->where('student_id', $student->id);
            $scopeStudentId = $student->id;
        } elseif ($user->hasRole('guru_piket')) {
            $petugasPiketId = $request->session()->get('piket_petugas_id');
            abort_unless($petugasPiketId, 403, 'Sesi piket tidak valid.');
            $attendances->where('petugas_piket_id', $petugasPiketId);
        } elseif ($user->hasRole('parent')) {
            $studentId = $request->session()->get('parent_selected_student');
            $parent = $user->parent;

            if ($studentId && $parent) {
                $student = $parent->students()->whereKey($studentId)->first();
                abort_unless($student, 403);
                Gate::authorize('viewDashboard', $student);
                $attendances->where('student_id', $student->id);
                $scopeStudentId = $student->id;
            } else {
                $attendances->whereRaw('1 = 0');
            }
        } else {
            abort(403);
        }

        $latest = (clone $attendances)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first(['id', 'student_id', 'date', 'status']);

        return response()->json([
            'event' => 'attendance.gate.updated',
            'data' => $latest ? [
                'attendance_id' => $latest->id,
                'student_id' => $latest->student_id,
                'date' => $latest->date->format('Y-m-d'),
                'session_id' => null,
                'status' => $latest->status,
            ] : [
                'attendance_id' => null,
                'student_id' => $scopeStudentId,
                'date' => $date,
                'session_id' => null,
                'status' => null,
            ],
            'revision' => $updates->revision($attendances),
        ])->header('Cache-Control', 'no-store, private');
    }
}
