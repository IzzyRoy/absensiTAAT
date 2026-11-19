<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Str;

class AttendanceSubjectUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    public readonly string $eventId;

    public function __construct(
        public readonly int $attendanceId,
        public readonly int $teacherId,
        public readonly string $date,
    ) {
        $this->eventId = (string) Str::uuid();
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('attendance.teacher.'.$this->teacherId)];
    }

    public function broadcastAs(): string
    {
        return 'attendance.subject.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'event_id' => $this->eventId,
            'attendance_id' => $this->attendanceId,
            'date' => $this->date,
        ];
    }
}
