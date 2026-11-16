<?php

namespace Tests\Feature\Admin;

use App\Models\AcademicYear;
use App\Models\Major;
use App\Models\SchoolClass;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use App\Services\AcademicCalendarService;
use App\Services\AcademicYearService;
use App\Services\SemesterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AcademicCalendarTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'http://localhost']);
        URL::forceRootUrl('http://localhost');
        Role::firstOrCreate(['name' => 'super_admin']);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('super_admin');
    }

    public function test_admin_can_save_and_update_a_semester_break_period(): void
    {
        $year = $this->createYear('2026/2027', '2026-07-01', '2027-06-30');

        $this->actingAs($this->admin)
            ->post(route('admin.semesters.store'), [
                'academic_year_id' => $year->id,
                'name' => 'Genap',
                'semester_number' => 2,
                'start_date' => '2027-01-05',
                'end_date' => '2027-06-30',
                'holiday_start_date' => '2027-07-01',
                'holiday_end_date' => '2027-07-14',
            ])
            ->assertRedirect(route('admin.semesters.index'));

        $semester = Semester::where('academic_year_id', $year->id)->firstOrFail();
        $this->assertSame(2, $semester->semester_number);
        $this->assertSame('2027-07-01', $semester->holiday_start_date->toDateString());
        $this->assertSame('2027-07-14', $semester->holiday_end_date->toDateString());
    }

    public function test_semester_break_status_handles_dates_before_during_and_after_break(): void
    {
        $year = $this->createYear('2025/2026', '2025-07-01', '2026-06-30');
        $semester = $this->createSemester($year, 2, '2026-01-05', '2026-06-30', [
            'holiday_start_date' => '2026-07-01',
            'holiday_end_date' => '2026-07-14',
        ]);

        $this->assertSame('upcoming', $semester->breakStatusOn('2026-06-30'));
        $this->assertSame('holiday', $semester->breakStatusOn('2026-07-07'));
        $this->assertSame('finished', $semester->breakStatusOn('2026-07-15'));
    }

    public function test_calendar_uses_the_semester_and_year_that_match_the_requested_date(): void
    {
        $previousYear = $this->createYear('2025/2026', '2025-07-01', '2026-06-30');
        $this->createSemester($previousYear, 1, '2025-07-14', '2025-12-20');
        $previousGenap = $this->createSemester($previousYear, 2, '2026-01-05', '2026-06-30', [
            'holiday_start_date' => '2026-07-01',
            'holiday_end_date' => '2026-07-15',
        ]);

        $currentYear = $this->createYear('2026/2027', '2026-07-01', '2027-06-30');
        $currentGanjil = $this->createSemester($currentYear, 1, '2026-07-16', '2026-12-20');
        $this->createSemester($currentYear, 2, '2027-01-05', '2027-06-30');

        $calendar = app(AcademicCalendarService::class);
        $beforeBreak = $calendar->statusOn('2026-06-30');
        $duringBreak = $calendar->statusOn('2026-07-07');
        $afterBreak = $calendar->statusOn('2026-07-16');

        $this->assertSame('semester', $beforeBreak['type']);
        $this->assertSame($previousGenap->id, $beforeBreak['semester']->id);
        $this->assertSame('holiday', $duringBreak['type']);
        $this->assertSame($previousYear->id, $duringBreak['academic_year']->id);
        $this->assertSame('Libur Semester Genap', $duringBreak['label']);
        $this->assertSame('semester', $afterBreak['type']);
        $this->assertSame($currentYear->id, $afterBreak['academic_year']->id);
        $this->assertSame($currentGanjil->id, $afterBreak['semester']->id);
    }

    public function test_class_promotion_is_available_only_after_semester_two_ends(): void
    {
        $currentYear = $this->createYear('2026/2027', '2026-07-01', '2027-06-30', true);
        $this->createSemester($currentYear, 1, '2026-07-15', '2026-12-20');
        $semesterTwo = $this->createSemester($currentYear, 2, '2027-01-05', '2027-06-30');

        $nextYear = $this->createYear('2027/2028', '2027-07-01', '2028-06-30');
        $nextSemester = $this->createSemester($nextYear, 1, '2027-07-15', '2027-12-20');

        $calendar = app(AcademicCalendarService::class);
        $duringSemesterOne = $calendar->promotionContext($currentYear, $nextYear, '2026-12-20');
        $onLastDayOfSemesterTwo = $calendar->promotionContext($currentYear, $nextYear, '2027-06-30');
        $afterSemesterTwo = $calendar->promotionContext($currentYear, $nextYear, '2027-07-01');

        $this->assertFalse($duringSemesterOne['eligible']);
        $this->assertFalse($onLastDayOfSemesterTwo['eligible']);
        $this->assertSame($semesterTwo->id, $afterSemesterTwo['semester']->id);
        $this->assertSame($nextSemester->id, $afterSemesterTwo['next_semester']->id);
        $this->assertTrue($afterSemesterTwo['eligible']);
    }

    public function test_transition_pages_are_blocked_until_the_active_years_genap_is_complete(): void
    {
        $currentYear = $this->createYear('2026/2027', '2026-07-01', '2027-06-30', true);
        $this->createSemester($currentYear, 1, '2026-07-15', '2026-12-20');
        $this->createSemester($currentYear, 2, '2027-01-05', '2027-06-30');

        $nextYear = $this->createYear('2027/2028', '2027-07-01', '2028-06-30');
        $this->createSemester($nextYear, 1, '2027-07-15', '2027-12-20');

        $this->actingAs($this->admin)
            ->get(route('admin.academic-years.transition.index'))
            ->assertOk()
            ->assertSee('Kenaikan kelas belum dapat dimulai')
            ->assertSee('Semester Genap');

        $this->get(route('admin.academic-years.transition.grade10-to-11'))
            ->assertRedirect(route('admin.academic-years.transition.index'))
            ->assertSessionHas('warning');
    }

    public function test_activating_an_academic_year_uses_its_own_first_semester(): void
    {
        $previousYear = $this->createYear('2025/2026', '2025-07-01', '2026-06-30', true);
        $previousSemester = $this->createSemester(
            $previousYear,
            2,
            '2026-01-05',
            '2026-06-30',
            ['is_active' => true]
        );
        $nextYear = $this->createYear('2026/2027', '2026-07-01', '2027-06-30');
        $nextSemester = $this->createSemester($nextYear, 1, '2026-07-15', '2026-12-20');

        app(AcademicYearService::class)->update($nextYear, [
            'name' => $nextYear->name,
            'start_date' => $nextYear->start_date->toDateString(),
            'end_date' => $nextYear->end_date->toDateString(),
            'is_active' => true,
        ]);

        $this->assertFalse((bool) $previousYear->fresh()->is_active);
        $this->assertTrue((bool) $nextYear->fresh()->is_active);
        $this->assertFalse((bool) $previousSemester->fresh()->is_active);
        $this->assertTrue((bool) $nextSemester->fresh()->is_active);
    }

    public function test_switching_semester_does_not_change_a_students_class(): void
    {
        $year = $this->createYear('2026/2027', '2026-07-01', '2027-06-30');
        $semesterOne = $this->createSemester($year, 1, '2026-07-15', '2026-12-20', ['is_active' => true]);
        $semesterTwo = $this->createSemester($year, 2, '2027-01-05', '2027-06-30');
        $major = Major::create(['name' => 'RPL', 'code' => 'RPL', 'is_active' => true]);
        $class = SchoolClass::create([
            'academic_year_id' => $year->id,
            'major_id' => $major->id,
            'grade_level' => 10,
            'name' => 'X RPL 1',
            'capacity' => 36,
            'is_active' => true,
        ]);
        $student = Student::create([
            'user_id' => User::factory()->create()->id,
            'class_id' => $class->id,
            'nis' => 'CAL-001',
            'name' => 'Siswa Kalender',
            'gender' => 'L',
            'barcode_id' => 'CAL-001',
            'is_active' => true,
        ]);

        app(SemesterService::class)->update($semesterTwo, [
            ...$semesterTwo->only([
                'academic_year_id',
                'name',
                'semester_number',
                'start_date',
                'end_date',
                'holiday_start_date',
                'holiday_end_date',
            ]),
            'is_active' => true,
        ]);

        $this->assertFalse((bool) $semesterOne->fresh()->is_active);
        $this->assertTrue((bool) $semesterTwo->fresh()->is_active);
        $this->assertTrue((bool) $year->fresh()->is_active);
        $this->assertSame($class->id, $student->fresh()->class_id);
    }

    private function createYear(string $name, string $start, string $end, bool $active = false): AcademicYear
    {
        return AcademicYear::create([
            'name' => $name,
            'start_date' => $start,
            'end_date' => $end,
            'is_active' => $active,
        ]);
    }

    private function createSemester(AcademicYear $year, int $number, string $start, string $end, array $extra = []): Semester
    {
        return Semester::create([
            'academic_year_id' => $year->id,
            'name' => $number === 1 ? 'Ganjil' : 'Genap',
            'semester_number' => $number,
            'start_date' => $start,
            'end_date' => $end,
            'is_active' => false,
            ...$extra,
        ]);
    }
}
