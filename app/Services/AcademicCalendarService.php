<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\Semester;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class AcademicCalendarService
{
    public function __construct(private DateTimeService $dateTimeService) {}

    /**
     * Resolve a term only when both the term and its owning academic year contain the date.
     *
     * @return array{academic_year: AcademicYear, semester: Semester}|null
     */
    public function periodOn(CarbonInterface|string|null $date = null): ?array
    {
        $date = $this->date($date)->toDateString();

        $semester = Semester::query()
            ->with('academicYear')
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->whereHas('academicYear', fn ($query) => $query
                ->whereDate('start_date', '<=', $date)
                ->whereDate('end_date', '>=', $date))
            ->orderByDesc(
                AcademicYear::query()
                    ->select('start_date')
                    ->whereColumn('academic_years.id', 'semesters.academic_year_id')
                    ->limit(1)
            )
            ->orderByDesc('semester_number')
            ->first();

        if (!$semester || !$semester->academicYear) {
            return null;
        }

        return ['academic_year' => $semester->academicYear, 'semester' => $semester];
    }

    /**
     * Report the configured semester break or current date-matched academic term.
     *
     * @return array{type: string, label: string, academic_year: ?AcademicYear, semester: ?Semester}
     */
    public function statusOn(CarbonInterface|string|null $date = null): array
    {
        $date = $this->date($date);
        $dateString = $date->toDateString();

        $period = $this->periodOn($date);
        if ($period) {
            return [
                'type' => 'semester',
                'label' => 'Semester ' . $period['semester']->period_label,
                ...$period,
            ];
        }

        $breakSemester = Semester::query()
            ->with('academicYear')
            ->whereNotNull('holiday_start_date')
            ->whereNotNull('holiday_end_date')
            ->whereDate('holiday_start_date', '<=', $dateString)
            ->whereDate('holiday_end_date', '>=', $dateString)
            ->whereHas('academicYear', fn ($query) => $query
                ->whereDate('start_date', '<=', $dateString))
            ->orderByDesc(
                AcademicYear::query()
                    ->select('start_date')
                    ->whereColumn('academic_years.id', 'semesters.academic_year_id')
                    ->limit(1)
            )
            ->first();

        if ($breakSemester && $breakSemester->academicYear) {
            return [
                'type' => 'holiday',
                'label' => 'Libur Semester ' . $breakSemester->period_label,
                'academic_year' => $breakSemester->academicYear,
                'semester' => $breakSemester,
            ];
        }

        return [
            'type' => 'outside_period',
            'label' => 'Di luar periode semester',
            'academic_year' => null,
            'semester' => null,
        ];
    }

    /**
     * Promotion is tied to the end of semester 2 in the selected year, never to semester 1.
     *
     * @return array{eligible: bool, academic_year: AcademicYear, next_academic_year: ?AcademicYear, semester: ?Semester, reason: ?string}
     */
    public function promotionContext(
        AcademicYear $academicYear,
        ?AcademicYear $nextAcademicYear,
        CarbonInterface|string|null $date = null
    ): array {
        $semester = $academicYear->semesters()
            ->where('semester_number', 2)
            ->first();
        $nextSemester = $nextAcademicYear?->semesters()
            ->where('semester_number', 1)
            ->first();
        $today = $this->date($date)->startOfDay();
        $semesterEnd = $semester?->end_date?->copy()->startOfDay();

        $reason = match (true) {
            !$semester => 'Tanggal akhir Semester Genap belum diatur untuk tahun ajaran ini.',
            !$nextAcademicYear => 'Tahun ajaran berikutnya belum tersedia.',
            !$nextSemester => 'Semester Ganjil untuk tahun ajaran berikutnya belum diatur.',
            !$semesterEnd || !$today->greaterThan($semesterEnd) => 'Proses kenaikan kelas baru dapat dimulai setelah Semester Genap berakhir.',
            default => null,
        };

        return [
            'eligible' => $reason === null,
            'academic_year' => $academicYear,
            'next_academic_year' => $nextAcademicYear,
            'semester' => $semester,
            'next_semester' => $nextSemester,
            'reason' => $reason,
        ];
    }

    private function date(CarbonInterface|string|null $date): Carbon
    {
        if ($date instanceof CarbonInterface) {
            return Carbon::instance($date);
        }

        return $date === null
            ? $this->dateTimeService->today()
            : Carbon::parse($date)->startOfDay();
    }
}
