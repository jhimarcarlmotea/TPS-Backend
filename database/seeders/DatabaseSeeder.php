<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Database\Seeders\ServicesSeeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            ServicesSeeder::class,
        ]);
    }
}
