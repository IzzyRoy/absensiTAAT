<?php

namespace Tests\Feature;

use App\Events\AttendanceGateUpdated;
use App\Events\AttendanceSubjectUpdated;
use Illuminate\Broadcasting\PrivateChannel;
use Tests\TestCase;

class AttendanceRealtimeBroadcastTest extends TestCase
{
    public function test_gate_event_uses_private_scoped_channels_and_minimal_payload(): void
    {
        $event = new AttendanceGateUpdated(42, 7, '2026-11-19', 19);

        $this->assertSame(
            [
                'private-attendance.admin',
                'private-attendance.student.7',
                'private-attendance.piket.19',
            ],
            array_map(
                static fn (PrivateChannel $channel): string => $channel->name,
                $event->broadcastOn(),
            ),
        );
        $this->assertSame(
            ['event_id', 'attendance_id', 'student_id', 'date'],
            array_keys($event->broadcastWith()),
        );
        $this->assertSame(42, $event->broadcastWith()['attendance_id']);
    }

    public function test_subject_event_is_limited_to_its_teacher(): void
    {
        $event = new AttendanceSubjectUpdated(12, 4, '2026-11-19');

        $this->assertSame(
            ['private-attendance.teacher.4'],
            array_map(
                static fn (PrivateChannel $channel): string => $channel->name,
                $event->broadcastOn(),
            ),
        );
        $this->assertSame(
            ['event_id', 'attendance_id', 'date'],
            array_keys($event->broadcastWith()),
        );
    }

    public function test_dashboard_client_has_no_periodic_polling(): void
    {
        $client = file_get_contents(
            base_path('resources/views/components/attendance-live-updates.blade.php'),
        );

        $this->assertStringContainsString('state_change', $client);
        $this->assertStringContainsString('attendance:student-selected', $client);
        $this->assertStringContainsString('visibilitychange', $client);
        $this->assertStringNotContainsString('setInterval', $client);
    }
}
