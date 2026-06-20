<?php

namespace Database\Seeders;

use App\Models\Customer;
use Illuminate\Database\Seeder;

class CustomerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $faker = \Faker\Factory::create('id_ID');

        $customers = [
            [
                'nama' => 'Rika Maryati S.E.',
                'alamat' => 'Jln. Sampangan No. 705, Bima 85187, Bengkulu',
                'nomor_telp' => '0815-123-4567',
                'email' => 'rika@example.com',
            ],
            [
                'nama' => 'PT Maju Jaya Indonesia',
                'alamat' => 'Jl. Raya Bogor No. 123, Jakarta 12860',
                'nomor_telp' => '021-555-0123',
                'email' => 'info@majujaya.co.id',
            ],
            [
                'nama' => 'Budi Santoso',
                'alamat' => 'Jl. Gatot Subroto No. 45, Bandung 40123',
                'nomor_telp' => '022-555-0456',
                'email' => 'budi.santoso@email.com',
            ],
            [
                'nama' => 'Siti Nurhaliza',
                'alamat' => 'Jl. Merdeka No. 789, Surabaya 60123',
                'nomor_telp' => '031-555-0789',
                'email' => 'siti.nurhaliza@email.com',
            ],
            [
                'nama' => 'Arif Rahman',
                'alamat' => 'Jl. Ahmad Yani No. 321, Medan 20123',
                'nomor_telp' => '061-555-1234',
                'email' => 'arif.rahman@email.com',
            ],
        ];

        // Create additional fake customers
        for ($i = 0; $i < 5; $i++) {
            $customers[] = [
                'nama' => $faker->company,
                'alamat' => $faker->address,
                'nomor_telp' => $faker->phoneNumber,
                'email' => $faker->email,
            ];
        }

        foreach ($customers as $customer) {
            Customer::updateOrCreate(
                ['nomor_telp' => $customer['nomor_telp']],
                $customer
            );
        }
    }
}
