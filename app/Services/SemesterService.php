<?php

namespace App\Services;

use App\Models\Semester;
use App\Models\AcademicYear;
use Illuminate\Support\Facades\DB;

class SemesterService
{
    public function getAll(array $filters = [])
    {
        $query = Semester::with('academicYear');

        if (!empty($filters['search'])) {
            $query->where('name', 'like', '%' . $filters['search'] . '%');
        }

        if (!empty($filters['academic_year_id'])) {
            $query->where('academic_year_id', $filters['academic_year_id']);
        }

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        return $query->orderByDesc('start_date')->paginate(15);
    }

    public function store(array $data): Semester
    {
        return DB::transaction(function () use ($data) {
            if (!empty($data['is_active'])) {
                Semester::where('is_active', true)->update(['is_active' => false]);
                AcademicYear::where('is_active', true)->update(['is_active' => false]);
                AcademicYear::whereKey($data['academic_year_id'])->update(['is_active' => true]);
            }

            $semester = Semester::create($data);
            ActivityLogService::logCreate($semester, "Menambahkan Semester: {$semester->name}");

            return $semester;
        });
    }

    public function update(Semester $semester, array $data): Semester
    {
        return DB::transaction(function () use ($semester, $data) {
            $original = $semester->getAttributes();

            if (!empty($data['is_active'])) {
                Semester::where('id', '!=', $semester->id)
                    ->where('is_active', true)
                    ->update(['is_active' => false]);
                AcademicYear::where('is_active', true)
                    ->where('id', '!=', $data['academic_year_id'])
                    ->update(['is_active' => false]);
                AcademicYear::whereKey($data['academic_year_id'])->update(['is_active' => true]);
            }

            $semester->update($data);
            ActivityLogService::logUpdate($semester, $original, "Mengubah Semester: {$semester->name}");

            return $semester;
        });
    }

    public function delete(Semester $semester): void
    {
        DB::transaction(function () use ($semester) {
            ActivityLogService::logDelete($semester, "Menghapus Semester: {$semester->name}");
            $semester->delete();
        });
    }
}
