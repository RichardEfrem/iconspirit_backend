<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $products = [
            ['kode_product' => 'P01', 'nama_product' => 'Divider'],
            ['kode_product' => 'P02', 'nama_product' => 'Wallpanel'],
            ['kode_product' => 'P03', 'nama_product' => 'Meja'],
            ['kode_product' => 'P04', 'nama_product' => 'Display Cabinet'],
            ['kode_product' => 'P05', 'nama_product' => 'Wardrobe'],
            ['kode_product' => 'P06', 'nama_product' => 'Meja Island'],
            ['kode_product' => 'P07', 'nama_product' => 'Kitchen Cabinet Bawah'],
            ['kode_product' => 'P08', 'nama_product' => 'Kitchen Cabinet Atas'],
            ['kode_product' => 'P09', 'nama_product' => 'Kitchen Tall Cabinet'],
            ['kode_product' => 'P10', 'nama_product' => 'Pantry Tall Cabinet'],
            ['kode_product' => 'P11', 'nama_product' => 'Pantry Cabinet Bawah'],
            ['kode_product' => 'P12', 'nama_product' => 'Pantry Cabinet Atas'],
            ['kode_product' => 'P13', 'nama_product' => 'Pintu Kamuflase'],
            ['kode_product' => 'P14', 'nama_product' => 'Divan'],
        ];

        foreach ($products as $product) {
            DB::table('product')->insert($product);
        }
    }
}
