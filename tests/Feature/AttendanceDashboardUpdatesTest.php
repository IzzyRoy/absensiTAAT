<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\AttendanceGate;
use App\Models\AttendanceSubject;
use App\Models\AttendanceSubjectDetail;
use App\Models\Major;
use App\Models\PetugasPiket;
use App\Models\Schedule;
use App\Models\SchoolClass;
use App\Models\Semester;
use App\Models\Student;
use App\Models\StudentParent;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AttendanceDashboardUpdatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'http://localhost']);
        URL::forceRootUrl('http://localhost');
    }

    public function test_dashboard_polling_is_scoped_and_never_creates_attendance(): void
    {
        foreach (['super_admin', 'siswa', 'parent', 'guru_piket', 'guru'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $year = AcademicYear::create([
            'name' => '2026/2027',
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'is_active' => true,
        ]);
        $semester = Semester::create([
            'academic_year_id' => $year->id,
            'name' => 'Ganjil',
            'semester_number' => 1,
            'start_date' => '2026-07-01',
            'end_date' => '2026-12-31',
            'is_active' => true,
        ]);
        $major = Major::create(['name' => 'RPL', 'code' => 'RPL', 'is_active' => true]);
        $class = SchoolClass::create([
            'academic_year_id' => $year->id,
            'major_id' => $major->id,
            'grade_level' => 10,
            'name' => 'X RPL 1',
            'capacity' => 36,
            'is_active' => true,
        ]);

        $parentUser = User::factory()->create(['is_active' => true]);
        $parentUser->assignRole('parent');
        $parent = StudentParent::create([
            'user_id' => $parentUser->id,
            'name' => 'Orang Tua',
            'phone' => '08123456789',
            'nik' => '3201234567890001',
            'relationship' => 'Ayah',
        ]);

        $studentUsers = [];
        $students = [];
        foreach (['A', 'B'] as $suffix) {
            $studentUsers[$suffix] = User::factory()->create(['is_active' => true]);
            $studentUsers[$suffix]->assignRole('siswa');
            $students[$suffix] = Student::create([
                'user_id' => $studentUsers[$suffix]->id,
                'parent_id' => $parent->id,
                'class_id' => $class->id,
                'nis' => 'NIS-' . $suffix,
                'name' => 'Siswa ' . $suffix,
                'gender' => 'L',
                'barcode_id' => 'BARCODE-' . $suffix,
                'is_active' => true,
            ]);
        }

        $petugas = PetugasPiket::create(['nama_lengkap' => 'Guru Piket']);
        $attendanceA = AttendanceGate::create([
            'student_id' => $students['A']->id,
            'academic_year_id' => $year->id,
            'semester_id' => $semester->id,
            'date' => now()->toDateString(),
            'time_in' => '07:00:00',
            'status' => 'hadir',
            'method' => 'qr_code',
            'petugas_piket_id' => $petugas->id,
        ]);
        AttendanceGate::create([
            'student_id' => $students['B']->id,
            'academic_year_id' => $year->id,
            'semester_id' => $semester->id,
            'date' => now()->toDateString(),
            'time_in' => '07:01:00',
            'status' => 'hadir',
            'method' => 'qr_code',
        ]);

        $response = $this->actingAs($studentUsers['A'])
            ->getJson('/attendance/updates');
        $this->assertSame(200, $response->status(), $response->getContent());
        $response
            ->assertJsonPath('event', 'attendance.gate.updated')
            ->assertJsonPath('data.attendance_id', $attendanceA->id)
            ->assertJsonPath('data.student_id', $students['A']->id)
            ->assertJsonPath('data.date', now()->toDateString())
            ->assertJsonPath('data.session_id', null);

        $this->actingAs($studentUsers['B'])
            ->getJson('/attendance/updates')
            ->assertOk()
            ->assertJsonPath('data.student_id', $students['B']->id)
            ->assertJsonPath('data.attendance_id', AttendanceGate::where('student_id', $students['B']->id)->value('id'));

        $this->actingAs($parentUser)
            ->withSession(['parent_selected_student' => $students['A']->id])
            ->getJson('/attendance/updates')
            ->assertOk()
            ->assertJsonPath('data.attendance_id', $attendanceA->id)
            ->assertJsonPath('data.student_id', $students['A']->id);

        $piketUser = User::factory()->create(['is_active' => true]);
        $piketUser->assignRole('guru_piket');
        $this->actingAs($piketUser)
            ->withSession(['piket_petugas_id' => $petugas->id])
            ->getJson('/attendance/updates')
            ->assertOk()
            ->assertJsonPath('data.attendance_id', $attendanceA->id);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('super_admin');
        $this->actingAs($admin)
            ->getJson('/attendance/updates')
            ->assertOk()
            ->assertJsonPath('data.student_id', $students['B']->id);

        $teacherUser = User::factory()->create(['is_active' => true]);
        $teacherUser->assignRole('guru');
        $teacher = Teacher::create([
            'user_id' => $teacherUser->id,
            'name' => 'Guru Pengampu',
            'gender' => 'L',
            'is_active' => true,
        ]);
        $subject = Subject::create(['name' => 'Matematika', 'code' => 'MTK', 'is_active' => true]);
        $schedule = Schedule::create([
            'academic_year_id' => $year->id,
            'semester_id' => $semester->id,
            'teacher_id' => $teacher->id,
            'subject_id' => $subject->id,
            'class_id' => $class->id,
            'day' => 'senin',
            'start_time' => '07:00',
            'end_time' => '08:00',
            'is_active' => true,
        ]);
        $subjectAttendance = AttendanceSubject::create([
            'schedule_id' => $schedule->id,
            'teacher_id' => $teacher->id,
            'academic_year_id' => $year->id,
            'semester_id' => $semester->id,
            'date' => now()->toDateString(),
            'status' => 'draft',
            'method' => 'realtime',
        ]);
        $detail = AttendanceSubjectDetail::create([
            'attendance_subject_id' => $subjectAttendance->id,
            'student_id' => $students['A']->id,
            'status' => 'hadir',
        ]);

        $teacherResponse = $this->actingAs($teacherUser)
            ->getJson('/attendance/updates')
            ->assertOk()
            ->assertJsonPath('event', 'attendance.subject.updated')
            ->assertJsonPath('data.attendance_id', $subjectAttendance->id);
        $teacherRevision = $teacherResponse->json('revision');

        $detail->update(['status' => 'izin']);

        $updatedTeacherRevision = $this->getJson('/attendance/updates')
            ->assertOk()
            ->assertJsonPath('event', 'attendance.subject.updated')
            ->json('revision');
        $this->assertNotSame($teacherRevision, $updatedTeacherRevision);

        $this->actingAs($teacherUser)
            ->get(route('teacher.dashboard'))
            ->assertOk()
            ->assertSee('data-attendance-live-updates', false);
        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('data-attendance-live-updates', false);
        $this->actingAs($studentUsers['A'])
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('data-attendance-live-updates', false);
        $this->actingAs($piketUser)
            ->withSession([
                'piket_petugas_id' => $petugas->id,
                'piket_nama_lengkap' => $petugas->nama_lengkap,
            ])
            ->get(route('piket.dashboard'))
            ->assertOk()
            ->assertSee('data-attendance-live-updates', false);
        $this->actingAs($parentUser)
            ->withSession(['parent_selected_student' => $students['A']->id])
            ->get(route('parent.dashboard'))
            ->assertOk()
            ->assertSee('data-attendance-live-updates', false);

        $pastAttendance = AttendanceGate::create([
            'student_id' => $students['A']->id,
            'academic_year_id' => $year->id,
            'semester_id' => $semester->id,
            'date' => now()->subDays(2)->toDateString(),
            'time_in' => '07:00:00',
            'status' => 'hadir',
            'method' => 'qr_code',
        ]);
        $studentRevisionBefore = $this->actingAs($studentUsers['A'])
            ->getJson('/attendance/updates')
            ->json('revision');
        $parentRevisionBefore = $this->actingAs($parentUser)
            ->withSession(['parent_selected_student' => $students['A']->id])
            ->getJson('/attendance/updates')
            ->json('revision');
        $adminRevisionBefore = $this->actingAs($admin)
            ->getJson('/attendance/updates')
            ->json('revision');

        $pastAttendance->update(['status' => 'terlambat']);

        $this->assertNotSame(
            $studentRevisionBefore,
            $this->actingAs($studentUsers['A'])->getJson('/attendance/updates')->json('revision')
        );
        $this->assertNotSame(
            $parentRevisionBefore,
            $this->actingAs($parentUser)
                ->withSession(['parent_selected_student' => $students['A']->id])
                ->getJson('/attendance/updates')
                ->json('revision')
        );
        $this->assertNotSame(
            $adminRevisionBefore,
            $this->actingAs($admin)->getJson('/attendance/updates')->json('revision')
        );

        $studentRevision = app(\App\Services\AttendanceDashboardUpdateService::class)
            ->revision(AttendanceGate::query()->where('student_id', $students['A']->id));
        $attendanceA->update(['status' => 'terlambat']);
        $this->assertNotSame(
            $studentRevision,
            app(\App\Services\AttendanceDashboardUpdateService::class)
                ->revision(AttendanceGate::query()->where('student_id', $students['A']->id))
        );

        $beforePolling = AttendanceGate::count();
        $revision = app(\App\Services\AttendanceDashboardUpdateService::class)
            ->revision(AttendanceGate::query()->whereDate('date', now()->toDateString()));

        $this->getJson('/attendance/updates')->assertOk();
        $this->getJson('/attendance/updates')->assertOk();

        $this->assertSame($beforePolling, AttendanceGate::count());
        $this->assertSame(
            $revision,
            app(\App\Services\AttendanceDashboardUpdateService::class)
                ->revision(AttendanceGate::query()->whereDate('date', now()->toDateString()))
        );
    }
}
