<?php

namespace App\Services;

use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\PrescriptionItem;
use App\Models\Product;
use App\Models\StockAlert;
use App\Models\StockMovement;
use App\Models\User;
use App\Notifications\LavishNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Per-branch stock. Every change writes a stock_movements row with the new total.
 * When a product comes back in stock, clients who asked to be notified are told, and
 * doctors with waiting prescriptions are reminded to suggest it again.
 */
class InventoryService
{
    public function __construct(private Notifier $notifier) {}

    public function create(array $data, User $by): Product
    {
        return DB::transaction(function () use ($data, $by) {
            $opening = (int) ($data['opening_stock'] ?? 0);
            unset($data['opening_stock']);
            $product = Product::create([
                ...$data,
                'slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(4)),
                'sku' => $data['sku'] ?? $this->nextSku($data['category']),
            ]);
            foreach (Branch::all() as $b) {
                BranchStock::create(['product_id' => $product->id, 'branch_id' => $b->id, 'quantity' => 0]);
            }
            if ($opening > 0) {
                $this->receive($product, Branch::main(), $opening, 'Opening stock', $by);
            }

            return $product;
        });
    }

    public function receive(Product $product, Branch $branch, int $qty, ?string $reference, ?User $by): StockMovement
    {
        $this->positive($qty);
        $wasEmpty = $product->stockTotal() === 0;

        $move = DB::transaction(function () use ($product, $branch, $qty, $reference, $by) {
            $row = $this->row($product, $branch);
            $row->increment('quantity', $qty);

            return $this->log($product, StockMovementType::In, $qty, "Stock received · {$branch->name}", $reference, $by, to: $branch);
        });

        if ($wasEmpty) {
            $this->backInStock($product->fresh());
        }

        return $move;
    }

    public function transfer(Product $product, Branch $from, Branch $to, int $qty, User $by): StockMovement
    {
        $this->positive($qty);
        if ($from->is($to)) {
            throw BusinessRuleException::make('same_branch', 'Choose two different branches.');
        }

        return DB::transaction(function () use ($product, $from, $to, $qty, $by) {
            $src = $this->row($product, $from);
            if ($src->quantity < $qty) {
                throw BusinessRuleException::make('insufficient_branch_stock', "Only {$src->quantity} at {$from->name}.", ['available' => $src->quantity]);
            }
            $src->decrement('quantity', $qty);
            $this->row($product, $to)->increment('quantity', $qty);

            return $this->log($product, StockMovementType::Transfer, 0, "{$qty} moved {$from->name} → {$to->name}", 'Transfer', $by, $from, $to, moved: $qty);
        });
    }

    /** Stock count at one branch: sets the branch quantity to what was counted. */
    public function adjust(Product $product, Branch $branch, int $counted, string $reason, User $by): ?StockMovement
    {
        if ($counted < 0) {
            throw BusinessRuleException::make('invalid_quantity', 'Quantity can’t be negative.');
        }
        $wasEmpty = $product->stockTotal() === 0;

        $move = DB::transaction(function () use ($product, $branch, $counted, $reason, $by) {
            $row = $this->row($product, $branch);
            $diff = $counted - $row->quantity;
            if ($diff === 0) {
                return null;
            }
            $row->update(['quantity' => $counted]);

            return $this->log($product, StockMovementType::Adjust, $diff, "{$reason} · {$branch->name}", 'Stock count', $by, $diff < 0 ? $branch : null, $diff > 0 ? $branch : null);
        });

        if ($move && $wasEmpty && $move->quantity > 0) {
            $this->backInStock($product->fresh());
        }

        return $move;
    }

    /** Takes units out for a delivered order, main branch first. */
    public function deduct(Product $product, int $qty, string $reason, string $reference, ?User $by): StockMovement
    {
        return DB::transaction(function () use ($product, $qty, $reason, $reference, $by) {
            $rows = BranchStock::query()->where('product_id', $product->id)
                ->join('branches', 'branches.id', '=', 'branch_stock.branch_id')
                ->orderByDesc('branches.is_main')->orderBy('branches.sort')->orderBy('branches.id')
                ->select('branch_stock.*')->lockForUpdate()->get();

            if ($rows->sum('quantity') < $qty) {
                throw BusinessRuleException::make('insufficient_stock', "Not enough stock for {$product->name} — restock first.", ['product_id' => $product->id]);
            }
            $left = $qty;
            foreach ($rows as $row) {
                $take = min($row->quantity, $left);
                if ($take > 0) {
                    $row->decrement('quantity', $take);
                    $left -= $take;
                }
            }

            return $this->log($product, StockMovementType::Sold, -$qty, $reason, $reference, $by);
        });
    }

    private function backInStock(Product $product): void
    {
        StockAlert::query()->with('client.user')->where('product_id', $product->id)->whereNull('notified_at')->get()
            ->each(function (StockAlert $alert) use ($product) {
                $this->notifier->client($alert->client, new LavishNotification('product.back_in_stock', 'Back in stock',
                    "{$product->name} is available again in the Lavish shop.", 'inventory_2', ['screen' => 'product', 'id' => $product->id]));
                $alert->update(['notified_at' => now()]);
            });

        PrescriptionItem::query()->with('prescription.doctor.user')
            ->where('product_id', $product->id)->where('was_out_of_stock', true)->whereNull('suggested_at')->get()
            ->groupBy(fn ($i) => $i->prescription->doctor_id)
            ->each(function ($items) use ($product) {
                $count = $items->pluck('prescription.client_id')->unique()->count();
                $this->notifier->doctor($items->first()->prescription->doctor, new LavishNotification('product.restocked_for_patients', 'Prescribed product is back',
                    "{$product->name} is back in stock — suggest it again to {$count} patient(s) who were waiting.", 'medication', null, '/prescriptions?waiting=1'));
            });
    }

    private function row(Product $product, Branch $branch): BranchStock
    {
        return BranchStock::query()->lockForUpdate()->firstOrCreate(['product_id' => $product->id, 'branch_id' => $branch->id], ['quantity' => 0]);
    }

    private function log(Product $product, StockMovementType $type, int $qty, string $reason, ?string $reference, ?User $by, ?Branch $from = null, ?Branch $to = null, int $moved = 0): StockMovement
    {
        return StockMovement::create([
            'product_id' => $product->id,
            'type' => $type,
            'quantity' => $qty,
            'moved' => $moved,
            'from_branch_id' => $from?->id,
            'to_branch_id' => $to?->id,
            'balance_after' => (int) BranchStock::query()->where('product_id', $product->id)->sum('quantity'),
            'reason' => $reason,
            'reference' => $reference,
            'user_id' => $by?->id,
        ]);
    }

    private function positive(int $qty): void
    {
        if ($qty <= 0) {
            throw BusinessRuleException::make('invalid_quantity', 'Quantity must be at least 1.');
        }
    }

    private function nextSku(string $category): string
    {
        $prefix = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $category), 0, 3)) ?: 'GEN';
        $n = Product::withTrashed()->count() + 1;
        do {
            $sku = sprintf('LV-%s-%03d', $prefix, $n++);
        } while (Product::withTrashed()->where('sku', $sku)->exists());

        return $sku;
    }
}
