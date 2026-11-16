<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Semester;
use App\Services\AcademicYearTransitionService;
use App\Services\AcademicCalendarService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AcademicYearTransitionController extends Controller
{
    protected $transitionService;
    protected AcademicCalendarService $academicCalendar;

    public function __construct(
        AcademicYearTransitionService $transitionService,
        AcademicCalendarService $academicCalendar
    ) {
        $this->transitionService = $transitionService;
        $this->academicCalendar = $academicCalendar;
    }

    /**
     * Find the next academic year after the given one (by start_date ordering).
     */
    protected function findNextYear(AcademicYear $currentYear): ?AcademicYear
    {
        return AcademicYear::where('start_date', '>', $currentYear->start_date)
            ->orderBy('start_date')
            ->first();
    }

    /**
     * Show the academic year transition wizard
     */
    public function index()
    {
        $currentYear = AcademicYear::where('is_active', true)->first();

        if (!$currentYear) {
            return redirect()->route('admin.academic-years.index')
                ->with('error', 'Tidak ada tahun ajaran aktif. Silakan aktifkan tahun ajaran terlebih dahulu.');
        }

        $nextYear = $this->findNextYear($currentYear);

        if (!$nextYear) {
            return redirect()->route('admin.academic-years.create')
                ->with('info', 'Silakan buat tahun ajaran baru terlebih dahulu sebelum memulai proses kenaikan kelas.');
        }

        $promotionContext = $this->academicCalendar->promotionContext($currentYear, $nextYear);

        return view('admin.academic-years.transition.index', compact('currentYear', 'nextYear', 'promotionContext'));
    }

    /**
     * Step 1: Grade 10 → 11 (Major Selection)
     */
    public function grade10To11()
    {
        if ($redirect = $this->requireCompletedSemesterTwo()) {
            return $redirect;
        }

        $currentYear = AcademicYear::where('is_active', true)->first();
        $nextYear    = $this->findNextYear($currentYear);

        $data = $this->transitionService->getGrade10StudentsForMajorSelection($currentYear);

        return view('admin.academic-years.transition.grade10-to-11', array_merge($data, [
            'currentYear' => $currentYear,
            'nextYear'    => $nextYear,
        ]));
    }

    /**
     * Get Grade 11 classes for selected major (AJAX)
     * Expects: year_id (next year's id) + major_id
     */
    public function getGrade11Classes(Request $request)
    {
        if ($redirect = $this->requireCompletedSemesterTwo()) {
            return $redirect;
        }

        $nextYear = $this->requestedNextYear($request->year_id);
        $majorId  = $request->major_id;

        if (!$nextYear) {
            return response()->json(['classes' => []]);
        }

        $classes = $this->transitionService->getGrade11ClassesByMajor($nextYear, $majorId);

        return response()->json(['classes' => $classes]);
    }

    /**
     * Process Grade 10 → 11 transition
     */
    public function processGrade10To11(Request $request)
    {
        if ($redirect = $this->requireCompletedSemesterTwo()) {
            return $redirect;
        }

        $request->validate([
            'students'               => 'required|array',
            'students.*.student_id'  => 'required|exists:students,id',
            'students.*.major_id'    => 'required|exists:majors,id',
            'students.*.class_id'    => 'required|exists:classes,id',
        ]);

        $nextYear = $this->requestedNextYear($request->next_year_id);

        if (!$nextYear) {
            return back()->with('error', 'Tahun ajaran tujuan tidak ditemukan.');
        }

        try {
            // Transform students[ID][...] format to flat assignments array
            $assignments = array_values($request->students);

            $results = $this->transitionService->processGrade10To11Transition(
                $assignments,
                $nextYear
            );

            $message = sprintf(
                'Proses penjurusan selesai. Berhasil: %d siswa, Gagal: %d siswa',
                count($results['success']),
                count($results['failed'])
            );

            return redirect()->route('admin.academic-years.transition.grade11-to-12')
                ->with('success', $message)
                ->with('transition_results', $results);
        } catch (\Exception $e) {
            Log::error('Grade 10→11 transition error: ' . $e->getMessage());
            return back()->with('error', 'Terjadi kesalahan saat memproses penjurusan: ' . $e->getMessage());
        }
    }

    /**
     * Step 2: Grade 11 → 12 (Auto-mapping with review)
     */
    public function grade11To12()
    {
        if ($redirect = $this->requireCompletedSemesterTwo()) {
            return $redirect;
        }

        $currentYear = AcademicYear::where('is_active', true)->first();
        $nextYear    = $this->findNextYear($currentYear);

        $data = $this->transitionService->getGrade11StudentsWithAutoMapping($currentYear, $nextYear);

        return view('admin.academic-years.transition.grade11-to-12', array_merge($data, [
            'currentYear' => $currentYear,
            'nextYear'    => $nextYear,
        ]));
    }

    /**
     * Process Grade 11 → 12 transition
     */
    public function processGrade11To12(Request $request)
    {
        if ($redirect = $this->requireCompletedSemesterTwo()) {
            return $redirect;
        }

        $request->validate([
            'assignments'              => 'required|array',
            'assignments.*.student_id' => 'required|exists:students,id',
            'assignments.*.class_id'   => 'required|exists:classes,id',
        ]);

        $nextYear = $this->requestedNextYear($request->next_year_id);

        if (!$nextYear) {
            return back()->with('error', 'Tahun ajaran tujuan tidak ditemukan.');
        }

        try {
            $results = $this->transitionService->processGrade11To12Transition(
                $request->assignments,
                $nextYear
            );

            $message = sprintf(
                'Kenaikan kelas 11→12 selesai. Berhasil: %d siswa, Gagal: %d siswa',
                count($results['success']),
                count($results['failed'])
            );

            return redirect()->route('admin.academic-years.transition.grade12-graduate')
                ->with('success', $message)
                ->with('transition_results', $results);
        } catch (\Exception $e) {
            Log::error('Grade 11→12 transition error: ' . $e->getMessage());
            return back()->with('error', 'Terjadi kesalahan saat memproses kenaikan kelas: ' . $e->getMessage());
        }
    }

    /**
     * Step 3: Grade 12 → Graduate
     */
    public function grade12Graduate()
    {
        if ($redirect = $this->requireCompletedSemesterTwo()) {
            return $redirect;
        }

        $currentYear = AcademicYear::where('is_active', true)->first();
        $nextYear    = $this->findNextYear($currentYear);

        $data = $this->transitionService->getGrade12StudentsForGraduation($currentYear);

        return view('admin.academic-years.transition.grade12-graduate', array_merge($data, [
            'currentYear' => $currentYear,
            'nextYear'    => $nextYear,
        ]));
    }

    /**
     * Process Grade 12 → Graduate transition
     */
    public function processGrade12Graduate(Request $request)
    {
        if ($redirect = $this->requireCompletedSemesterTwo()) {
            return $redirect;
        }

        $request->validate([
            'assignments'              => 'required|array',
            'assignments.*.student_id' => 'required|exists:students,id',
            'assignments.*.status'     => 'required|in:Lulus,Tinggal Kelas',
            'assignments.*.class_id'   => 'nullable|exists:classes,id',
        ]);

        $nextYear = $this->requestedNextYear($request->next_year_id);

        if (!$nextYear) {
            return back()->with('error', 'Tahun ajaran tujuan tidak ditemukan.');
        }

        try {
            $results = $this->transitionService->processGrade12ToGraduateTransition(
                $request->assignments,
                $nextYear
            );

            $message = sprintf(
                'Proses kelulusan selesai. Lulus: %d siswa, Tinggal Kelas: %d siswa, Gagal: %d siswa',
                count($results['graduated']),
                count($results['repeated']),
                count($results['failed'])
            );

            return redirect()->route('admin.academic-years.transition.finalize')
                ->with('success', $message)
                ->with('transition_results', $results);
        } catch (\Exception $e) {
            Log::error('Grade 12→Graduate transition error: ' . $e->getMessage());
            return back()->with('error', 'Terjadi kesalahan saat memproses kelulusan: ' . $e->getMessage());
        }
    }

    /**
     * Final step: Activate new academic year
     */
    public function finalize()
    {
        if ($redirect = $this->requireCompletedSemesterTwo()) {
            return $redirect;
        }

        $currentYear = AcademicYear::where('is_active', true)->first();
        $nextYear    = $this->findNextYear($currentYear);

        return view('admin.academic-years.transition.finalize', compact('currentYear', 'nextYear'));
    }

    /**
     * Activate the new academic year
     */
    public function activateNewYear(Request $request)
    {
        if ($redirect = $this->requireCompletedSemesterTwo()) {
            return $redirect;
        }

        $currentYear = AcademicYear::where('is_active', true)->first();
        $nextYear    = $currentYear ? $this->findNextYear($currentYear) : null;

        if (!$nextYear || (int) $request->next_year_id !== $nextYear->id) {
            return back()->with('error', 'Tahun ajaran baru tidak ditemukan.');
        }

        try {
            $nextSemester = $nextYear->semesters()->where('semester_number', 1)->firstOrFail();

            DB::transaction(function () use ($currentYear, $nextYear, $nextSemester) {
                $currentYear->update(['is_active' => false]);
                $nextYear->update(['is_active' => true]);
                Semester::where('is_active', true)->update(['is_active' => false]);
                $nextSemester->update(['is_active' => true]);
            });

            return redirect()->route('admin.academic-years.index')
                ->with('success', 'Tahun ajaran baru berhasil diaktifkan! Selamat tahun ajaran ' . $nextYear->name);
        } catch (\Exception $e) {
            Log::error('Activate new year error: ' . $e->getMessage());
            return back()->with('error', 'Terjadi kesalahan saat mengaktifkan tahun ajaran baru: ' . $e->getMessage());
        }
    }

    private function requireCompletedSemesterTwo(): ?RedirectResponse
    {
        $currentYear = AcademicYear::where('is_active', true)->first();
        if (!$currentYear) {
            return redirect()->route('admin.academic-years.index')
                ->with('error', 'Tidak ada tahun ajaran aktif.');
        }

        $nextYear = $this->findNextYear($currentYear);
        $context = $this->academicCalendar->promotionContext($currentYear, $nextYear);

        if (!$context['eligible']) {
            return redirect()->route('admin.academic-years.transition.index')
                ->with('warning', $context['reason']);
        }

        return null;
    }

    private function requestedNextYear(mixed $requestedId): ?AcademicYear
    {
        $currentYear = AcademicYear::where('is_active', true)->first();
        $nextYear = $currentYear ? $this->findNextYear($currentYear) : null;

        return $nextYear && (int) $requestedId === $nextYear->id
            ? $nextYear
            : null;
    }
}