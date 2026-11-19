<?php

use App\Models\Teacher;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('attendance.admin', fn (User $user): bool => $user->hasRole('super_admin'));

Broadcast::channel('attendance.piket.{petugasPiketId}', function (User $user, int $petugasPiketId): bool {
    return $user->hasRole('guru_piket')
        && (int) session('piket_petugas_id') === $petugasPiketId;
});

Broadcast::channel('attendance.student.{studentId}', function (User $user, int $studentId): bool {
    if ($user->hasRole('siswa')) {
        return $user->student?->id === $studentId;
    }

    return $user->hasRole('parent')
        && $user->parent?->students()->whereKey($studentId)->exists();
});

Broadcast::channel('attendance.teacher.{teacherId}', function (User $user, int $teacherId): bool {
    return $user->hasRole('guru')
        && Teacher::query()
            ->whereKey($teacherId)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->exists();
});
