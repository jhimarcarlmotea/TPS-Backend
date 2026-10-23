<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ServicesSeeder extends Seeder
{
    public function run(): void
    {
        $services = [
            ['code' => 'PC', 'name' => 'Psychotherapy/Counseling', 'duration_minutes' => 60, 'price' => 0.00, 'is_active' => true],
            ['code' => 'ABA', 'name' => 'ABA Therapy', 'duration_minutes' => 120, 'price' => 0.00, 'is_active' => true],
            ['code' => 'AC', 'name' => 'Assessment Consultation', 'duration_minutes' => 60, 'price' => 0.00, 'is_active' => true],
        ];

        foreach ($services as $service) {
            DB::table('services')->updateOrInsert(
                ['code' => $service['code']],
                $service
            );
        }
    }
}
