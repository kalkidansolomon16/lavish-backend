<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Resources\ProductResource;
use App\Http\Resources\StockMovementResource;
use App\Models\Branch;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Products & inventory. Prices go live in the app immediately. */
class ProductController extends StaffController
{
    public const CATEGORIES = ['Skincare', 'Sun care', 'Aftercare', 'Hair', 'Supplements'];

    public function __construct(private InventoryService $inventory) {}

    /** Filters: q (name / SKU), category, stock=low|out, include_inactive=1. meta.stock_value needs finance.revenue. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $products = Product::query()->withStock()->with('stocks.branch')
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('active', true))
            ->when($request->string('category')->value(), fn ($q, $c) => $q->where('category', $c))
            ->when($request->string('q')->trim()->value(), fn ($q, $t) => $q->where(fn ($w) => $w->where('name', 'like', "%{$t}%")->orWhere('sku', 'like', "%{$t}%")))
            ->orderBy('name')->get()
            ->when($request->string('stock')->value() === 'low', fn ($c) => $c->filter(fn ($p) => $p->stockTotal() > 0 && $p->stockTotal() <= $p->reorder_level))
            ->when($request->string('stock')->value() === 'out', fn ($c) => $c->filter(fn ($p) => $p->stockTotal() === 0))
            ->values();

        $meta = ['categories' => self::CATEGORIES, 'branches' => Branch::ordered()->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])];
        if ($request->user()->hasPermission('finance.revenue')) {
            $meta['stock_value'] = (int) $products->sum(fn ($p) => $p->stockTotal() * $p->price);
        }

        return ProductResource::collection($products)->additional(['meta' => $meta]);
    }

    public function show(Product $product): ProductResource
    {
        return new ProductResource($this->fresh($product));
    }

    public function store(Request $request): ProductResource
    {
        $data = $request->validate($this->rules() + [
            'name' => ['required', 'string', 'max:160'],
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'price' => ['required', 'integer', 'min:0'],
            'sku' => ['nullable', 'string', 'max:32', 'unique:products,sku'],
            'opening_stock' => ['nullable', 'integer', 'min:0'],
        ]);

        return new ProductResource($this->fresh($this->inventory->create($data, $request->user())));
    }

    public function update(Request $request, Product $product): ProductResource
    {
        $data = $request->validate($this->rules() + [
            'name' => ['sometimes', 'string', 'max:160'],
            'category' => ['sometimes', Rule::in(self::CATEGORIES)],
            'price' => ['sometimes', 'integer', 'min:0'],
            'active' => ['sometimes', 'boolean'],
        ]);
        $product->update($data);

        return new ProductResource($this->fresh($product));
    }

    public function uploadImage(Request $request, Product $product): ProductResource
    {
        $request->validate(['image' => ['required', 'image', 'max:4096']]);
        $product->update(['image_path' => $request->file('image')->store('products', 'public')]);

        return new ProductResource($this->fresh($product));
    }

    /** Removed from the shop (soft delete — order history keeps the product name). */
    public function destroy(Product $product): JsonResponse
    {
        $product->delete();

        return response()->json(['message' => 'Product removed.']);
    }

    /** Body: branch_id, quantity, reference (supplier / invoice). */
    public function receive(Request $request, Product $product): ProductResource
    {
        $data = $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);
        $this->inventory->receive($product, Branch::findOrFail($data['branch_id']), $data['quantity'], $data['reference'] ?? null, $request->user());

        return new ProductResource($this->fresh($product));
    }

    /** Body: from_branch_id, to_branch_id, quantity. */
    public function transfer(Request $request, Product $product): ProductResource
    {
        $data = $request->validate([
            'from_branch_id' => ['required', 'exists:branches,id'],
            'to_branch_id' => ['required', 'exists:branches,id', 'different:from_branch_id'],
            'quantity' => ['required', 'integer', 'min:1'],
        ]);
        $this->inventory->transfer($product, Branch::findOrFail($data['from_branch_id']), Branch::findOrFail($data['to_branch_id']), $data['quantity'], $request->user());

        return new ProductResource($this->fresh($product));
    }

    /** Stock count at one branch. Body: branch_id, counted, reason. */
    public function adjust(Request $request, Product $product): ProductResource
    {
        $data = $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
            'counted' => ['required', 'integer', 'min:0'],
            'reason' => ['required', Rule::in(['Count correction', 'Damaged', 'Expired', 'Returned by client', 'Sample / internal use'])],
        ]);
        $this->inventory->adjust($product, Branch::findOrFail($data['branch_id']), $data['counted'], $data['reason'], $request->user());

        return new ProductResource($this->fresh($product));
    }

    /** Stock history. Filters: product_id, type. */
    public function movements(Request $request): AnonymousResourceCollection
    {
        return StockMovementResource::collection(
            StockMovement::query()->with('product', 'fromBranch', 'toBranch', 'user')
                ->when($request->integer('product_id'), fn ($q, $p) => $q->where('product_id', $p))
                ->when($request->string('type')->value(), fn ($q, $t) => $q->where('type', $t))
                ->latest('id')->paginate($this->perPage($request, 50))
        );
    }

    private function rules(): array
    {
        return [
            'size' => ['sometimes', 'nullable', 'string', 'max:32'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'usage' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'reorder_level' => ['sometimes', 'integer', 'min:0'],
            'hue' => ['sometimes', 'nullable', 'integer', 'between:0,360'],
        ];
    }

    private function fresh(Product $product): Product
    {
        return Product::query()->withStock()->with('stocks.branch')->findOrFail($product->id);
    }
}
