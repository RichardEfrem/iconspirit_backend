<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MaterialSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $materials_kayu = [
            // Page 1: Items 1 - 30
            ['kode_material' => '13.00', 'nama_material' => 'AMBALAN JEPIT KACA (ISI 2)', 'satuan' => 'SET'],
            ['kode_material' => 'B0001', 'nama_material' => 'AMBALAN KACA', 'satuan' => 'BH'],
            ['kode_material' => '369.00', 'nama_material' => 'BRAKET L (ISI 2)', 'satuan' => 'SET'],
            ['kode_material' => '376.00', 'nama_material' => 'BLOCKBOARD 18MM', 'satuan' => 'LEMBAR'],
            ['kode_material' => '413.00', 'nama_material' => 'BLOCKBOARD 15MM', 'satuan' => 'LEMBAR'],
            ['kode_material' => '471.00', 'nama_material' => 'BLUMOTION', 'satuan' => 'PCS'],
            ['kode_material' => '491.00', 'nama_material' => 'BRAKET JOIN T', 'satuan' => 'PCS'],
            ['kode_material' => '88.00', 'nama_material' => 'BESI T DAN L', 'satuan' => 'LJR'],
            ['kode_material' => 'B0232', 'nama_material' => 'BAUT JP', 'satuan' => 'PCS'],
            ['kode_material' => 'B0017', 'nama_material' => 'BRAKET HANGER (ISI 2)', 'satuan' => 'SET'],
            ['kode_material' => 'B0098', 'nama_material' => 'DOUBLE TAPE', 'satuan' => 'ROLL'],
            ['kode_material' => '115.00', 'nama_material' => 'ENGSEL LURUS BIASA BLUM', 'satuan' => 'PCS'],
            ['kode_material' => '181.00', 'nama_material' => 'ENGSEL LURUS BIASA HAFELE (3CM)', 'satuan' => 'PCS'],
            ['kode_material' => '222.00', 'nama_material' => 'ENGSEL GRASS LURUS', 'satuan' => 'PCS'],
            ['kode_material' => '224.00', 'nama_material' => 'ENGSEL GRASS LURUS (3CM)', 'satuan' => 'PCS'],
            ['kode_material' => '251.00', 'nama_material' => 'ENGSEL LURUS MOTION HAFELE (3CM)', 'satuan' => 'PCS'],
            ['kode_material' => '259.00', 'nama_material' => 'ENGSEL LURUS GRASS', 'satuan' => 'PCS'],
            ['kode_material' => 'B0018', 'nama_material' => 'ENGSEL 1/2 BUNGKUK BIASA HAFELE', 'satuan' => 'PCS'],
            ['kode_material' => 'B0019', 'nama_material' => 'ENGSEL 1/2 BUNGKUK MOTION HAFELE', 'satuan' => 'PCS'],
            ['kode_material' => 'B0020', 'nama_material' => 'ENGSEL LURUS BIASA HAFELE', 'satuan' => 'PCS'],
            ['kode_material' => 'B0021', 'nama_material' => 'ENGSEL LURUS MOTION HAFELE', 'satuan' => 'PCS'],
            ['kode_material' => 'B0227', 'nama_material' => 'ENGSEL DEER (ISI 2)', 'satuan' => 'SET'],
            ['kode_material' => 'B0261', 'nama_material' => 'EDGING 100 AA 2CM', 'satuan' => 'MTR'],
            ['kode_material' => 'B0262', 'nama_material' => 'EDGING 100 AA 4CM', 'satuan' => 'MTR'],
            ['kode_material' => '280.00', 'nama_material' => 'EDGING 134 AA 2CM', 'satuan' => 'MTR'],
            ['kode_material' => '281.00', 'nama_material' => 'EDGING 134 AA 4CM', 'satuan' => 'MTR'],
            ['kode_material' => 'B0140', 'nama_material' => 'ENGSEL TANAM PIVOT', 'satuan' => 'PCS'],
            ['kode_material' => '455.00', 'nama_material' => 'FRAME C2639H DB2850', 'satuan' => 'BTG'],
            ['kode_material' => '456.00', 'nama_material' => 'FRAME C2626 DB2850', 'satuan' => 'BTG'],
            ['kode_material' => '458.00', 'nama_material' => 'FRAME RM 213 DARK BROWN', 'satuan' => 'BTG'],

            // Page 2: Items 31 - 62
            ['kode_material' => '94.00', 'nama_material' => 'FRAME BOX LAMP', 'satuan' => 'BTG'],
            ['kode_material' => 'B0024', 'nama_material' => 'FISHER S6 (ISI : 100)', 'satuan' => 'DOS'],
            ['kode_material' => 'B0109', 'nama_material' => 'FRAME HUBEN DOFF', 'satuan' => 'LJR'],
            ['kode_material' => 'B0301', 'nama_material' => 'FRAME DEVIDER U PINTU HUBEN DOFF', 'satuan' => 'LJR'],
            ['kode_material' => '246.00', 'nama_material' => 'FRAME HUBEN GLOSSY', 'satuan' => 'LJR'],
            ['kode_material' => 'B0161', 'nama_material' => 'FOAM SIT', 'satuan' => 'MTR'],
            ['kode_material' => 'B0102', 'nama_material' => 'GELAS RUTER', 'satuan' => 'PCS'],
            ['kode_material' => '128.00', 'nama_material' => 'GASSPRING N 170', 'satuan' => 'PCS'],
            ['kode_material' => 'B0136', 'nama_material' => 'GASSPRING N 80', 'satuan' => 'PCS'],
            ['kode_material' => 'B0241', 'nama_material' => 'GASSPRING N 188', 'satuan' => 'PCS'],
            ['kode_material' => 'B0290', 'nama_material' => 'GASSPRING N 100', 'satuan' => 'PCS'],
            ['kode_material' => '121.00', 'nama_material' => 'HPL TACO 868 LU', 'satuan' => 'LEMBAR'],
            ['kode_material' => '123.00', 'nama_material' => 'HPL TACO 186 AA', 'satuan' => 'LEMBAR'],
            ['kode_material' => '366.00', 'nama_material' => 'HPL TACO 872 RE', 'satuan' => 'LEMBAR'],
            ['kode_material' => '178.00', 'nama_material' => 'HPL TACO 317 H', 'satuan' => 'LEMBAR'],
            ['kode_material' => '313.00', 'nama_material' => 'HPL TACO 1225', 'satuan' => 'LEMBAR'],
            ['kode_material' => '124.00', 'nama_material' => 'HPL TACO 824 J', 'satuan' => 'LEMBAR'],
            ['kode_material' => '365.00', 'nama_material' => 'HPL TACO 906 J', 'satuan' => 'LEMBAR'],
            ['kode_material' => '396.00', 'nama_material' => 'HPL TACO 861 TM', 'satuan' => 'LEMBAR'],
            ['kode_material' => '330.00', 'nama_material' => 'HPL TACO 853 TM', 'satuan' => 'LEMBAR'],
            ['kode_material' => '493.00', 'nama_material' => 'HPL TACO TH 887 JG', 'satuan' => 'LEMBAR'],
            ['kode_material' => '494.00', 'nama_material' => 'HPL TACO TH 022 D', 'satuan' => 'LEMBAR'],
            ['kode_material' => '506.00', 'nama_material' => 'HPL TACO TH 003 KM', 'satuan' => 'LEMBAR'],
            ['kode_material' => '154.00', 'nama_material' => 'HPL TACO TH 852 J', 'satuan' => 'LEMBAR'],
            ['kode_material' => '356.00', 'nama_material' => 'HPL TACO TH 1251 FA', 'satuan' => 'LEMBAR'],
            ['kode_material' => '305.00', 'nama_material' => 'HPL TACO TH 1205', 'satuan' => 'LEMBAR'],
            ['kode_material' => '355.00', 'nama_material' => 'HPL TACO TH 1252 FA', 'satuan' => 'LEMBAR'],
            ['kode_material' => '392.00', 'nama_material' => 'HPL TACO TH 66 WM', 'satuan' => 'LEMBAR'],
            ['kode_material' => '156.00', 'nama_material' => 'HPL SPLENDOR 7607', 'satuan' => 'LEMBAR'],
            ['kode_material' => '406.00', 'nama_material' => 'HPL SPLENDOR 7629', 'satuan' => 'LEMBAR'],
            ['kode_material' => '210.00', 'nama_material' => 'HPL SPLENDOR 7630', 'satuan' => 'LEMBAR'],
            ['kode_material' => '424.00', 'nama_material' => 'HPL SPLENDOR 7632', 'satuan' => 'LEMBAR'],

            // Page 3: Items 63 - 94
            ['kode_material' => '303.00', 'nama_material' => 'HPL SPLENDOR 7633', 'satuan' => 'LEMBAR'],
            ['kode_material' => '439.00', 'nama_material' => 'HPL SPLENDOR 7638', 'satuan' => 'LEMBAR'],
            ['kode_material' => '213.00', 'nama_material' => 'HPL SPLENDOR 7642', 'satuan' => 'LEMBAR'],
            ['kode_material' => '399.00', 'nama_material' => 'HPL SPLENDOR 7640 SCU', 'satuan' => 'LEMBAR'],
            ['kode_material' => '360.00', 'nama_material' => 'HPL SPLENDOR 7428 MT', 'satuan' => 'LEMBAR'],
            ['kode_material' => '467.00', 'nama_material' => 'HPL SPLENDOR 7517', 'satuan' => 'LEMBAR'],
            ['kode_material' => '264.00', 'nama_material' => 'HPL SPLENDOR 7529', 'satuan' => 'LEMBAR'],
            ['kode_material' => '117.00', 'nama_material' => 'HPL SPLENDOR 1039', 'satuan' => 'LEMBAR'],
            ['kode_material' => '83.00', 'nama_material' => 'HPL SPLENDOR 1057', 'satuan' => 'LEMBAR'],
            ['kode_material' => '214.00', 'nama_material' => 'HPL SPLENDOR 1090', 'satuan' => 'LEMBAR'],
            ['kode_material' => '405.00', 'nama_material' => 'HPL SPLENDOR 1097', 'satuan' => 'LEMBAR'],
            ['kode_material' => '402.00', 'nama_material' => 'HPL SPLENDOR 1299', 'satuan' => 'LEMBAR'],
            ['kode_material' => '318.00', 'nama_material' => 'HPL SPLENDOR 1551', 'satuan' => 'LEMBAR'],
            ['kode_material' => '273.00', 'nama_material' => 'HPL SPLENDOR R 2951', 'satuan' => 'LEMBAR'],
            ['kode_material' => '513.00', 'nama_material' => 'HPL SPLENDOR FT 1566 PN', 'satuan' => 'LEMBAR'],
            ['kode_material' => '473.00', 'nama_material' => 'HPL SPLENDOR WT 7643 SCU', 'satuan' => 'LEMBAR'],
            ['kode_material' => '490.00', 'nama_material' => 'HPL SPLENDOR WT 7634 SCU', 'satuan' => 'LEMBAR'],
            ['kode_material' => '496.00', 'nama_material' => 'HPL SPLENDOR WT 1038 SCU', 'satuan' => 'LEMBAR'],
            ['kode_material' => '480.00', 'nama_material' => 'HPL SPLENDOR WT 1011 ORG', 'satuan' => 'LEMBAR'],
            ['kode_material' => '361.00', 'nama_material' => 'HPL ECO P 018', 'satuan' => 'LEMBAR'],
            ['kode_material' => '306.00', 'nama_material' => 'HPL ECO P 023', 'satuan' => 'LEMBAR'],
            ['kode_material' => '395.00', 'nama_material' => 'HPL ECO P 049', 'satuan' => 'LEMBAR'],
            ['kode_material' => '427.00', 'nama_material' => 'HPL ECO P 095', 'satuan' => 'LEMBAR'],
            ['kode_material' => '377.00', 'nama_material' => 'HPL CARTA 1728', 'satuan' => 'LEMBAR'],
            ['kode_material' => '33.00', 'nama_material' => 'HMR 3 MM', 'satuan' => 'LEMBAR'],
            ['kode_material' => '147.00', 'nama_material' => 'HMR 6 MM', 'satuan' => 'LEMBAR'],
            ['kode_material' => '227.00', 'nama_material' => 'HMR 9 MM', 'satuan' => 'LEMBAR'],
            ['kode_material' => '146.00', 'nama_material' => 'HMR 18 MM', 'satuan' => 'LEMBAR'],
            ['kode_material' => '509.00', 'nama_material' => 'HMR MELAMINTO 2.7', 'satuan' => 'LEMBAR'],
            ['kode_material' => '287.00', 'nama_material' => 'HANDLE MATRIK @ 3M', 'satuan' => 'BTG'],
            ['kode_material' => '18.00', 'nama_material' => 'HANDLE L @ 3M', 'satuan' => 'BTG'],
            ['kode_material' => '209.00', 'nama_material' => 'HANDLE GARASI / HANDLE KAMUFLASE @ 3M', 'satuan' => 'BTG'],

            // Page 4: Items 95 - 127
            ['kode_material' => '367.00', 'nama_material' => 'HANDLE FRAME J @ 3M', 'satuan' => 'BTG'],
            ['kode_material' => '368.00', 'nama_material' => 'HANDLE FRAME C @ 3M', 'satuan' => 'BTG'],
            ['kode_material' => '247.00', 'nama_material' => 'HANDLE FRAME KOTAK GLOSSY HUBEN @ 3M', 'satuan' => 'BTG'],
            ['kode_material' => 'B0110', 'nama_material' => 'HANDLE FRAME KOTAK DOFF HUBEN @ 3M', 'satuan' => 'BTG'],
            ['kode_material' => 'B0237', 'nama_material' => 'HANDLE POLOS @ 3M', 'satuan' => 'BTG'],
            ['kode_material' => '495.00', 'nama_material' => 'HARDENER LEM', 'satuan' => 'KG'],
            ['kode_material' => '19.00', 'nama_material' => 'KECES', 'satuan' => 'PCS'],
            ['kode_material' => '221.00', 'nama_material' => 'KUNCI LACI CYBERLOCK', 'satuan' => 'PCS'],
            ['kode_material' => '8.00', 'nama_material' => 'KUNCI CENTRAL SAMPING', 'satuan' => 'PCS'],
            ['kode_material' => 'B0045', 'nama_material' => 'KAKI PLASTIK 10 CM (1 SET : 4)', 'satuan' => 'SET'],
            ['kode_material' => 'B0046', 'nama_material' => 'KUNCI LACI HAFELE', 'satuan' => 'PCS'],
            ['kode_material' => 'B0278', 'nama_material' => 'KUNCI EKSPANYOLET', 'satuan' => 'PCS'],
            ['kode_material' => '261.00', 'nama_material' => 'LIST T GLOSSY @ 3M', 'satuan' => 'LJR'],
            ['kode_material' => 'B0244', 'nama_material' => 'LIST T 3M @ 3M', 'satuan' => 'LJR'],
            ['kode_material' => '40.00', 'nama_material' => 'LEM PRESTO 3710 @ 20KG', 'satuan' => 'DOS'],
            ['kode_material' => 'B0050', 'nama_material' => 'LEM PRESTO 2395 @ 20KG', 'satuan' => 'DOS'],
            ['kode_material' => '75.00', 'nama_material' => 'LEM KUNING PRIMA D @ 14KG', 'satuan' => 'KLG'],
            ['kode_material' => 'B0049', 'nama_material' => 'LEM KUNING 168 @ 10KG', 'satuan' => 'KLG'],
            ['kode_material' => 'B0276', 'nama_material' => 'LUBANG ANGIN', 'satuan' => 'PCS'],
            ['kode_material' => '299.00', 'nama_material' => 'MOTION GRASS', 'satuan' => 'PCS'],
            ['kode_material' => 'B0204', 'nama_material' => 'MOHER', 'satuan' => 'MTR'],
            ['kode_material' => '219.00', 'nama_material' => 'MATA ROUTER', 'satuan' => 'PCS'],
            ['kode_material' => '114.00', 'nama_material' => 'PAKU F10', 'satuan' => 'DOS'],
            ['kode_material' => 'B0057', 'nama_material' => 'PAKU F15', 'satuan' => 'DOS'],
            ['kode_material' => 'B0104', 'nama_material' => 'PAKU F20', 'satuan' => 'DOS'],
            ['kode_material' => 'B0058', 'nama_material' => 'PAKU F25', 'satuan' => 'DOS'],
            ['kode_material' => 'B0059', 'nama_material' => 'PAKU F30', 'satuan' => 'DOS'],
            ['kode_material' => '74.00', 'nama_material' => 'PAKU STAPLES', 'satuan' => 'PCS'],
            ['kode_material' => 'B0060', 'nama_material' => 'PAKU ROTAN', 'satuan' => 'PCS'],

            // Page 5: Items 161 - 174
            ['kode_material' => 'B0072', 'nama_material' => 'SKRUP 4', 'satuan' => 'PCS'],
            ['kode_material' => 'B0073', 'nama_material' => 'SKRUP 5/8', 'satuan' => 'PCS'],
            ['kode_material' => 'B0074', 'nama_material' => 'SKRUP S6', 'satuan' => 'PCS'],
            ['kode_material' => 'B0197', 'nama_material' => 'SWITCH KULKAS', 'satuan' => 'PCS'],
            ['kode_material' => 'B0090', 'nama_material' => 'SILICONE WHITE', 'satuan' => 'PCS'],
            ['kode_material' => 'B0137', 'nama_material' => 'SILICONE CLEAR', 'satuan' => 'PCS'],
            ['kode_material' => 'B0147', 'nama_material' => 'SILICONE BLACK', 'satuan' => 'PCS'],
            ['kode_material' => '109.00', 'nama_material' => 'SILICON BROWN', 'satuan' => 'PCS'],
            ['kode_material' => '269.00', 'nama_material' => 'SILICON GREY', 'satuan' => 'PCS'],
            ['kode_material' => 'B0078', 'nama_material' => 'TRIPLEK 15 MM SEMI', 'satuan' => 'LEMBAR'],
            ['kode_material' => 'B0087', 'nama_material' => 'TRIPLEK 9 MM SEMI', 'satuan' => 'LEMBAR'],
            ['kode_material' => 'B0152', 'nama_material' => 'TRIPLEK 9MM MERANTI (7MM)', 'satuan' => 'LEMBAR'],
            ['kode_material' => 'B0153', 'nama_material' => 'TRIPLEK 15MM MERANTI', 'satuan' => 'LEMBAR'],
            ['kode_material' => '472.00', 'nama_material' => 'VENEERR', 'satuan' => 'MTR'],
        ];

        $data_kayu = array_map(function ($item) {
            return array_merge($item, [
                'category'   => 'kayu',
                'jumlah'     => rand(1, 50),
                'harga'      => rand(10000, 500000),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, $materials_kayu);

        // Data dari foto 1-4: Kategori Tukang Cat (Items 1 - 123)
        $materials_cat = [
            // Image 1: Items 1 - 30
            ['kode_material' => 'B0015', 'nama_material' => 'AMPLAS 80 M', 'satuan' => 'MTR'],
            ['kode_material' => 'B0004', 'nama_material' => 'AMPLAS 120 M', 'satuan' => 'MTR'],
            ['kode_material' => 'B0005', 'nama_material' => 'AMPLAS 150 M', 'satuan' => 'MTR'],
            ['kode_material' => 'B0007', 'nama_material' => 'AMPLAS 180 M', 'satuan' => 'MTR'],
            ['kode_material' => 'B0011', 'nama_material' => 'AMPLAS 240 M', 'satuan' => 'MTR'],
            ['kode_material' => 'B0010', 'nama_material' => 'AMPLAS 240', 'satuan' => 'LEMBAR'],
            ['kode_material' => 'B0012', 'nama_material' => 'AMPLAS 320', 'satuan' => 'LEMBAR'],
            ['kode_material' => 'B0013', 'nama_material' => 'AMPLAS 360', 'satuan' => 'LEMBAR'],
            ['kode_material' => 'B0014', 'nama_material' => 'AMPLAS 400', 'satuan' => 'LEMBAR'],
            ['kode_material' => 'B0002', 'nama_material' => 'AMPLAS 1000', 'satuan' => 'LEMBAR'],
            ['kode_material' => 'B0008', 'nama_material' => 'AMPLAS 2000', 'satuan' => 'LEMBAR'],
            ['kode_material' => 'B0314', 'nama_material' => 'BUBUK BROWN MAS (TUA & MUDA)', 'satuan' => 'BKS'],
            ['kode_material' => 'B0103', 'nama_material' => 'BULU ROL', 'satuan' => 'BH'],
            ['kode_material' => '370.00', 'nama_material' => 'B DARK BROWN (PAL)', 'satuan' => 'LTR'],
            ['kode_material' => '422.00', 'nama_material' => 'B DARK BROWN (PAL 1) LITER', 'satuan' => 'LTR'],
            ['kode_material' => '481.00', 'nama_material' => 'CRISTAL COAT', 'satuan' => 'SET'],
            ['kode_material' => 'B0158', 'nama_material' => 'CATALYST MILESI', 'satuan' => 'KG'],
            ['kode_material' => 'B0124', 'nama_material' => 'COMPOUND PUTIH', 'satuan' => 'KG'],
            ['kode_material' => 'B0095', 'nama_material' => 'CAT DASAR PUTIH', 'satuan' => 'GALON'],
            ['kode_material' => 'B0138', 'nama_material' => 'CAT TEMBOK ABU ABU', 'satuan' => 'GALON'],
            ['kode_material' => 'B0146', 'nama_material' => 'CAT GLOSSY 9715', 'satuan' => 'GALON'],
            ['kode_material' => 'B0242', 'nama_material' => 'CAT SUZUKA GOLD', 'satuan' => 'BH'],
            ['kode_material' => 'B0312', 'nama_material' => 'CAT WINSTON', 'satuan' => 'BH'],
            ['kode_material' => 'B0023', 'nama_material' => 'EPOXY', 'satuan' => 'SET'],
            ['kode_material' => '79.00', 'nama_material' => 'FOIL SILVER', 'satuan' => 'PCS'],
            ['kode_material' => 'B0043', 'nama_material' => 'ISOLASI KERTAS', 'satuan' => 'PCS'],
            ['kode_material' => '337.00', 'nama_material' => 'IMPRA COFFEE BROWN', 'satuan' => 'KG'],
            ['kode_material' => '338.00', 'nama_material' => 'IMPRA ROTAN BROWN', 'satuan' => 'KG'],
            ['kode_material' => 'B0091', 'nama_material' => 'IMPRA WALNUT BROWN', 'satuan' => 'KG'],

            // Image 2: Items 31 - 63
            ['kode_material' => 'B0155', 'nama_material' => 'IMPRA TEA BROWN', 'satuan' => 'KG'],
            ['kode_material' => 'B0166', 'nama_material' => 'IMPRA COCOA BROWN', 'satuan' => 'KG'],
            ['kode_material' => 'B0179', 'nama_material' => 'IMPRA RED MAHONI', 'satuan' => 'KG'],
            ['kode_material' => 'B0234', 'nama_material' => 'IMPRA BROWN KJ', 'satuan' => 'KG'],
            ['kode_material' => 'B0235', 'nama_material' => 'IMPRA ROTAN GREY', 'satuan' => 'KG'],
            ['kode_material' => 'B0326', 'nama_material' => 'IMPRA SALAK BROWN', 'satuan' => 'KG'],
            ['kode_material' => 'B0044', 'nama_material' => 'KAIN POP', 'satuan' => 'BH'],
            ['kode_material' => 'B0123', 'nama_material' => 'KAIN MAJUN', 'satuan' => 'KG'],
            ['kode_material' => 'B0175', 'nama_material' => 'KIT KUNING', 'satuan' => 'BH'],
            ['kode_material' => 'B0120', 'nama_material' => 'KUAS TUSIR/LUKIS', 'satuan' => 'BH'],
            ['kode_material' => 'B0099', 'nama_material' => 'KUAS 1"', 'satuan' => 'BH'],
            ['kode_material' => 'B0100', 'nama_material' => 'KUAS 2"', 'satuan' => 'BH'],
            ['kode_material' => 'B0105', 'nama_material' => 'KUAS 2.5"', 'satuan' => 'BH'],
            ['kode_material' => 'B0106', 'nama_material' => 'KUAS 3"', 'satuan' => 'BH'],
            ['kode_material' => 'B0101', 'nama_material' => 'KUAS 4"', 'satuan' => 'BH'],
            ['kode_material' => 'B0119', 'nama_material' => 'KARTON PACKAGING', 'satuan' => 'MTR'],
            ['kode_material' => 'B0231', 'nama_material' => 'LEM EPOXY', 'satuan' => 'SET'],
            ['kode_material' => 'B0048', 'nama_material' => 'LEM G', 'satuan' => 'BH'],
            ['kode_material' => 'B0051', 'nama_material' => 'LEM PUTIH RAJAWALI', 'satuan' => 'KG'],
            ['kode_material' => 'B0297', 'nama_material' => 'LASUR PROPAN NATURAL DOFF', 'satuan' => 'KG'],
            ['kode_material' => '10.00',  'nama_material' => 'MILESI LKR 0177', 'satuan' => 'KG'],
            ['kode_material' => '217.00', 'nama_material' => 'MILESI LKR 0026', 'satuan' => 'KG'],
            ['kode_material' => '310.00', 'nama_material' => 'MILESI LKR 912', 'satuan' => 'KG'],
            ['kode_material' => '489.00', 'nama_material' => 'MILESI LKR 0180', 'satuan' => 'KG'],
            ['kode_material' => '502.00', 'nama_material' => 'MILESI LKR 21537', 'satuan' => 'KG'],
            ['kode_material' => '92.00',  'nama_material' => 'MILESI LKR 0265', 'satuan' => 'KG'],
            ['kode_material' => '404.00', 'nama_material' => 'MILESI LKR 19730', 'satuan' => 'KG'],
            ['kode_material' => 'B0296', 'nama_material' => 'MILESI LKR 0122', 'satuan' => 'KG'],
            ['kode_material' => 'B0299', 'nama_material' => 'MILESI LKR 0125', 'satuan' => 'KG'],
            ['kode_material' => '257.00', 'nama_material' => 'MILESI LHR 0004', 'satuan' => 'KG'],
            ['kode_material' => 'B0311', 'nama_material' => 'MILESI LHR 0150', 'satuan' => 'KG'],
            ['kode_material' => 'B0157', 'nama_material' => 'MILESI LHR 0202', 'satuan' => 'KG'],
            ['kode_material' => '431.00', 'nama_material' => 'MILESI LUA 99', 'satuan' => 'KG'],

            // Image 3: Items 64 - 96
            ['kode_material' => '16.00',  'nama_material' => 'MILESI LUA 463', 'satuan' => 'KG'],
            ['kode_material' => '434.00', 'nama_material' => 'MILESI LUA 468', 'satuan' => 'KG'],
            ['kode_material' => '417.00', 'nama_material' => 'MILESI 18990', 'satuan' => 'KG'],
            ['kode_material' => '93.00',  'nama_material' => 'MILESI KDA 1', 'satuan' => 'KG'],
            ['kode_material' => 'B0092', 'nama_material' => 'MSS 123', 'satuan' => 'KG'],
            ['kode_material' => 'B0156', 'nama_material' => 'MEL CLEAR DOFF', 'satuan' => 'KG'],
            ['kode_material' => 'B0176', 'nama_material' => 'MEL SEMI GLOSS', 'satuan' => 'KG'],
            ['kode_material' => 'B0250', 'nama_material' => 'MILESI HITAM SEMI', 'satuan' => 'KG'],
            ['kode_material' => 'B0254', 'nama_material' => 'MEL 131 CLEAR GLOSS', 'satuan' => 'KG'],
            ['kode_material' => 'B0217', 'nama_material' => 'NIPPE 006', 'satuan' => 'KG'],
            ['kode_material' => 'B0169', 'nama_material' => 'NIPPE 017', 'satuan' => 'KG'],
            ['kode_material' => 'B0239', 'nama_material' => 'NIPPE 019', 'satuan' => 'KG'],
            ['kode_material' => 'B0133', 'nama_material' => 'NIPPE 031', 'satuan' => 'KG'],
            ['kode_material' => 'B0086', 'nama_material' => 'NIPPE 046', 'satuan' => 'KG'],
            ['kode_material' => 'B0201', 'nama_material' => 'NIPPE 196', 'satuan' => 'KG'],
            ['kode_material' => '331.00', 'nama_material' => 'NIPPE 315', 'satuan' => 'KG'],
            ['kode_material' => 'B0200', 'nama_material' => 'NIPPE 319', 'satuan' => 'KG'],
            ['kode_material' => '373.00', 'nama_material' => 'NIPPE 373', 'satuan' => 'KG'],
            ['kode_material' => 'B0240', 'nama_material' => 'NIPPE 441', 'satuan' => 'KG'],
            ['kode_material' => 'B0220', 'nama_material' => 'NIPPE 449', 'satuan' => 'KG'],
            ['kode_material' => 'B0143', 'nama_material' => 'NIPPE 480', 'satuan' => 'KG'],
            ['kode_material' => 'B0198', 'nama_material' => 'NIPPE 492', 'satuan' => 'KG'],
            ['kode_material' => 'B0170', 'nama_material' => 'NIPPE 517', 'satuan' => 'KG'],
            ['kode_material' => 'B0202', 'nama_material' => 'NIPPE 614', 'satuan' => 'KG'],
            ['kode_material' => 'B0221', 'nama_material' => 'NIPPE 1006', 'satuan' => 'KG'],
            ['kode_material' => 'B0219', 'nama_material' => 'NIPPE 1013', 'satuan' => 'KG'],
            ['kode_material' => 'B0132', 'nama_material' => 'NIPPE 1017', 'satuan' => 'KG'],
            ['kode_material' => 'B0178', 'nama_material' => 'NIPPE 1033', 'satuan' => 'KG'],
            ['kode_material' => 'B0313', 'nama_material' => 'NIPPE 318 T', 'satuan' => 'KG'],
            ['kode_material' => 'B0309', 'nama_material' => 'NIPPE 320 T', 'satuan' => 'KG'],
            ['kode_material' => 'B0190', 'nama_material' => 'NIPPE 322 T', 'satuan' => 'KG'],
            ['kode_material' => 'B0134', 'nama_material' => 'NIPPE 331 T', 'satuan' => 'KG'],
            ['kode_material' => 'B0135', 'nama_material' => 'NIPPE 360 T', 'satuan' => 'KG'],

            // Image 4: Items 97 - 123
            ['kode_material' => 'B0139', 'nama_material' => 'NYL CLEAR DOFF', 'satuan' => 'KG'],
            ['kode_material' => 'B0093', 'nama_material' => 'NYL CLEAR GLOSS', 'satuan' => 'KG'],
            ['kode_material' => 'B0304', 'nama_material' => 'NYL SEMI GLOSS', 'satuan' => 'KG'],
            ['kode_material' => '268.00', 'nama_material' => 'OSMO 3062', 'satuan' => 'KG'],
            ['kode_material' => '39.00',  'nama_material' => 'OBAT RAYAP', 'satuan' => 'BH'],
            ['kode_material' => '235.00', 'nama_material' => 'OBAT DOFF', 'satuan' => 'BH'],
            ['kode_material' => '274.00', 'nama_material' => 'PROPAN 160-4 CANE POLE', 'satuan' => 'KG'],
            ['kode_material' => '297.00', 'nama_material' => 'PROPAN 786-2 DOFF (PROPAN WHITE DOFF)', 'satuan' => 'KG'],
            ['kode_material' => '335.00', 'nama_material' => 'PROPAN ULTRAN TEAK OIL', 'satuan' => 'KG'],
            ['kode_material' => '350.00', 'nama_material' => 'PROPAN PVC 786 DOFF 165-3', 'satuan' => 'KG'],
            ['kode_material' => '435.00', 'nama_material' => 'PROPAN PU 197-6', 'satuan' => 'KG'],
            ['kode_material' => '437.00', 'nama_material' => 'PROPAN PU 198-5', 'satuan' => 'KG'],
            ['kode_material' => '438.00', 'nama_material' => 'PROPAN PU 198-6', 'satuan' => 'KG'],
            ['kode_material' => '464.00', 'nama_material' => 'PROPAN PU 136-6', 'satuan' => 'KG'],
            ['kode_material' => 'B0117', 'nama_material' => 'PU INSULATOR', 'satuan' => 'KG'],
            ['kode_material' => 'B0062', 'nama_material' => 'PLAKBAN', 'satuan' => 'ROLL'],
            ['kode_material' => '144',    'nama_material' => 'PLAKBAN PLAFON', 'satuan' => 'ROLL'],
            ['kode_material' => 'B0067', 'nama_material' => 'SANPOLAK', 'satuan' => 'GALON'],
            ['kode_material' => 'B0238', 'nama_material' => 'SHP BROWN IMPRA', 'satuan' => 'KG'],
            ['kode_material' => 'B0083', 'nama_material' => 'THINNER DASAR', 'satuan' => 'LTR'],
            ['kode_material' => 'B0084', 'nama_material' => 'THINNER SPECIAL', 'satuan' => 'LTR'],
            ['kode_material' => 'B0085', 'nama_material' => 'THINNER PU', 'satuan' => 'LTR'],
            ['kode_material' => 'B0222', 'nama_material' => 'VIP REMOVER', 'satuan' => 'BH'],
            ['kode_material' => '225.00', 'nama_material' => 'WS BLACK IMPRA', 'satuan' => 'GALON'],
            ['kode_material' => 'B0274', 'nama_material' => 'WS CANDY BROWN', 'satuan' => 'GALON'],
            ['kode_material' => 'B0236', 'nama_material' => 'WS SUNGKAI', 'satuan' => 'GALON'],
            ['kode_material' => 'B0229', 'nama_material' => 'WA DARK BROWN', 'satuan' => 'GALON'],
        ];

        $data_cat = array_map(function ($item) {
            return array_merge($item, [
                'category'   => 'cat',
                'jumlah'     => rand(1, 50),
                'harga'      => rand(10000, 500000),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, $materials_cat);

        // Data dari foto: Kategori Tukang Listrik (Items 1 - 18)
        $materials_listrik = [
            ['kode_material' => 'B0126', 'nama_material' => 'ISOLASI LISTRIK', 'satuan' => 'ROLL'], // Item 1
            ['kode_material' => '252.00', 'nama_material' => 'KABEL 2*20', 'satuan' => 'MTR'],   // Item 2
            ['kode_material' => '308.00', 'nama_material' => 'KABEL 2*0.5', 'satuan' => 'MTR'],  // Item 3
            ['kode_material' => '96.00', 'nama_material' => 'KABEL 3 X 2.5', 'satuan' => 'MTR'], // Item 4
            ['kode_material' => 'B0128', 'nama_material' => 'KABEL 3 X 1.5', 'satuan' => 'MTR'], // Item 5
            ['kode_material' => 'B0129', 'nama_material' => 'KABEL 2 X 0.75', 'satuan' => 'MTR'], // Item 6
            ['kode_material' => 'B0193', 'nama_material' => 'KABEL 2*2.5', 'satuan' => 'MTR'],   // Item 7
            ['kode_material' => 'B0194', 'nama_material' => 'KABEL 2*1.5', 'satuan' => 'MTR'],   // Item 8
            ['kode_material' => 'B0195', 'nama_material' => 'KABEL TELEPONE', 'satuan' => 'MTR'], // Item 9
            ['kode_material' => 'B0196', 'nama_material' => 'KABEL KOM', 'satuan' => 'MTR'],      // Item 10
            ['kode_material' => 'B0199', 'nama_material' => 'KABEL TV', 'satuan' => 'MTR'],       // Item 11
            ['kode_material' => '7.00', 'nama_material' => 'LAMPU HALOGEN', 'satuan' => 'PCS'],    // Item 12
            ['kode_material' => 'B0047', 'nama_material' => 'LAMPU LED', 'satuan' => 'PCS'],        // Item 13
            ['kode_material' => '197.00', 'nama_material' => 'STOP KONTAK LUBANG 4', 'satuan' => 'PCS'], // Item 14
            ['kode_material' => '198.00', 'nama_material' => 'STEKER UTICON', 'satuan' => 'PCS'],   // Item 15
            ['kode_material' => '420.00', 'nama_material' => 'SWITCH SENSOR KNOCKER', 'satuan' => 'PCS'], // Item 16
            ['kode_material' => '295.00', 'nama_material' => 'TRAVO 10A', 'satuan' => 'PCS'],       // Item 17
            ['kode_material' => 'B0125', 'nama_material' => 'TRAVO 5A', 'satuan' => 'PCS'],        // Item 18
        ];

        $data_listrik = array_map(function ($item) {
            $jumlah = rand(0, 50); // allow 0 for OUT_OF_STOCK

            if ($jumlah === 0) {
                $status = 'OUT_OF_STOCK';
            } elseif ($jumlah <= 10) {
                $status = 'LOW_STOCK';
            } else {
                $status = 'IN_STOCK';
            }

            return array_merge($item, [
                'category'   => 'listrik',
                'jumlah'     => $jumlah,
                'harga'      => rand(10000, 500000),
                'status'     => $status,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, $materials_listrik);

        DB::table('material')->insert($data_kayu);
        DB::table('material')->insert($data_cat);
        DB::table('material')->insert($data_listrik);
    }
}
