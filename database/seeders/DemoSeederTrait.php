<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\FactoryLocation;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderItem;
use App\Models\ProductionOrderItemMaterial;
use App\Models\ProductionOrderItemSection;
use App\Models\Spk;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Shared helpers for all Demo*Seeder classes.
 *
 * Each seeder that uses this trait must declare:
 *   protected int $seq = NNN;   ← unique starting sequence number
 */
trait DemoSeederTrait
{
    private Collection $mat;
    private Collection $prd;
    private Collection $cust;
    private ?FactoryLocation $fac;

    private function boot(): bool
    {
        $this->mat  = Material::all()->keyBy('kode_material');
        $this->prd  = Product::all()->keyBy('kode_product');
        $this->cust = Customer::all()->values();
        $this->fac  = FactoryLocation::first();

        if ($this->prd->isEmpty() || $this->cust->isEmpty()) {
            $this->command->error('Prerequisite master data missing. Run base seeders first: php artisan db:seed');
            return false;
        }

        $this->command->info(sprintf(
            'Prerequisites OK — %d products, %d customers, factory: %s',
            $this->prd->count(),
            $this->cust->count(),
            $this->fac?->nama_factory ?? '(none)'
        ));

        return true;
    }

    private function mkOrder(int $custIdx, Carbon $date, array $opts): ProductionOrder
    {
        $c = $this->cust[$custIdx % $this->cust->count()];
        $s = str_pad($this->seq++, 3, '0', STR_PAD_LEFT);

        return ProductionOrder::create([
            'customer_id'         => $c->id,
            'order_id'            => "{$date->format('dm')} / PH-ICN / {$s} / {$date->format('Y')}",
            'nama_customer'       => $c->nama,
            'alamat_customer'     => $c->alamat,
            'tanggal_order'       => $date,
            'status_id'           => $opts['status']   ?? 'await_material',
            'is_urgent'           => $opts['urgent']   ?? false,
            'production_deadline' => $opts['deadline'] ?? null,
            'material_eta'        => $opts['eta']      ?? null,
        ]);
    }

    private function mkSection(ProductionOrder $o, string $name): ProductionOrderItemSection
    {
        return ProductionOrderItemSection::create([
            'production_order_id' => $o->id,
            'name'                => $name,
        ]);
    }

    private function mkItem(
        ProductionOrder $o,
        ProductionOrderItemSection $sec,
        string $productCode,
        string $spec,
        int $panjang,
        int $tinggi,
        int $qty,
        ?string $note = null
    ): ProductionOrderItem {
        return ProductionOrderItem::create([
            'production_order_id'              => $o->id,
            'production_order_item_section_id' => $sec->id,
            'product_id'                       => $this->prd->get($productCode)->id,
            'panjang'                          => $panjang,
            'tinggi'                           => $tinggi,
            'quantity'                         => $qty,
            'keterangan'                       => $note,
        ]);
    }

    private function mkMaterials(ProductionOrderItem $item, array $list, bool $deducted = false): void
    {
        foreach ($list as $kode => [$qty, $cost]) {
            $m = $this->mat->get($kode);
            if (!$m) {
                continue;
            }
            ProductionOrderItemMaterial::create([
                'production_order_item_id' => $item->id,
                'material_id'              => $m->id,
                'quantity'                 => max(1, $qty),
                'cost'                     => $cost,
                'is_deducted'             => $deducted,
            ]);
        }
    }

    private function mkSpk(ProductionOrder $o, Carbon $date): Spk
    {
        return Spk::create([
            'nomor_spk'           => 'SPK/DEMO/' . $date->format('Y') . '/' . str_pad($o->id, 4, '0', STR_PAD_LEFT),
            'tanggal_terbit'      => $date,
            'production_order_id' => $o->id,
            'assigned_factory'    => $this->fac?->id ?? 1,
        ]);
    }
}
