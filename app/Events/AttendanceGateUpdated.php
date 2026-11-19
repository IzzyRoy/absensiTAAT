<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Str;

class AttendanceGateUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    public readonly string $eventId;

    public function __construct(
        public readonly int $attendanceId,
        public readonly int $studentId,
        public readonly string $date,
        public readonly ?int $petugasPiketId,
    ) {
        $this->eventId = (string) Str::uuid();
    }

    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('attendance.admin'),
            new PrivateChannel('attendance.student.'.$this->studentId),
        ];

        if ($this->petugasPiketId !== null) {
            $channels[] = new PrivateChannel('attendance.piket.'.$this->petugasPiketId);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'attendance.gate.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'event_id' => $this->eventId,
            'attendance_id' => $this->attendanceId,
            'student_id' => $this->studentId,
            'date' => $this->date,
        ];
    }
}
