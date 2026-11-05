<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Models\Client;
use App\Models\Doctor;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Command palette (Ctrl+K): GET /staff/search?q=… — only what the user may open. */
class SearchController extends StaffController
{
    public function __invoke(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q'));
        if (mb_strlen($term) < 2) {
            return response()->json(['data' => []]);
        }
        $user = $request->user();
        $like = "%{$term}%";
        $results = collect();

        if ($user->hasPermission('page.clients')) {
            $scope = $user->hasPermission('appointments.approve', 'clients.history') ? null : $user->doctor?->id;
            Client::query()->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('phone', 'like', $like))
                ->when($scope, fn ($q) => $q->where(fn ($w) => $w->where('primary_doctor_id', $scope)->orWhereHas('appointments', fn ($a) => $a->where('doctor_id', $scope))))
                ->limit(6)->get()
                ->each(fn ($c) => $results->push(['type' => 'client', 'id' => $c->id, 'title' => $c->name, 'subtitle' => $c->phone, 'path' => '/clients?open='.$c->id]));
        }
        Doctor::query()->with('user')->whereHas('user', fn ($u) => $u->where('name', 'like', $like))->orWhere('specialty', 'like', $like)->limit(4)->get()
            ->each(fn ($d) => $results->push(['type' => 'doctor', 'id' => $d->id, 'title' => $d->name, 'subtitle' => $d->specialty, 'path' => $user->hasPermission('page.staff') ? '/staff' : null]));
        if ($user->hasPermission('page.products')) {
            Product::query()->where('name', 'like', $like)->orWhere('sku', 'like', $like)->limit(5)->get()
                ->each(fn ($p) => $results->push(['type' => 'product', 'id' => $p->id, 'title' => $p->name, 'subtitle' => $p->sku, 'path' => '/products?open='.$p->id]));
        }
        if ($user->hasPermission('page.orders') && ($id = (int) preg_replace('/\D/', '', $term))) {
            Order::query()->with('client')->whereKey($id)->get()
                ->each(fn ($o) => $results->push(['type' => 'order', 'id' => $o->id, 'title' => 'Order #'.$o->id, 'subtitle' => $o->client->name, 'path' => '/orders?open='.$o->id]));
        }

        return response()->json(['data' => $results->values()]);
    }
}
