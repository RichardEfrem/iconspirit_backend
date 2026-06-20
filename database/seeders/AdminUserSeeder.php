<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['username' => 'admin'],
            [
                'name'     => 'Admin',
                'email'    => 'admin@iconspirit.com',
                'password' => Hash::make('admin1234'),
                'role'     => User::ROLE_ADMIN,
                'status'   => User::STATUS_ACTIVE,
            ]
        );
    }
}
