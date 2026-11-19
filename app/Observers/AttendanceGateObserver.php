<?php

namespace App\Observers;

use App\Events\AttendanceGateUpdated;
use App\Models\AttendanceGate;

class AttendanceGateObserver
{
    public function created(AttendanceGate $attendance): void
    {
        $this->dispatchUpdate($attendance);
    }

    public function updated(AttendanceGate $attendance): void
    {
        $this->dispatchUpdate($attendance);
    }

    private function dispatchUpdate(AttendanceGate $attendance): void
    {
        event(new AttendanceGateUpdated(
            (int) $attendance->id,
            (int) $attendance->student_id,
            $attendance->date->toDateString(),
            $attendance->petugas_piket_id === null ? null : (int) $attendance->petugas_piket_id,
        ));
    }
}
