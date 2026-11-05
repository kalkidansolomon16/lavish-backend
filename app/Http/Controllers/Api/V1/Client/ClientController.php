<?php

namespace App\Http\Controllers\Api\V1\Client;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\Request;

/** Base for /client/* routes: resolves the signed-in user's client record. */
abstract class ClientController extends Controller
{
    protected function client(Request $request): Client
    {
        $client = $request->user()->client;
        abort_unless($client, 404, 'Client profile not found.');

        return $client;
    }

    /** 404 (not 403) for other people's records, so ids can't be probed. */
    protected function own(Request $request, $model): void
    {
        abort_unless($model->client_id === $this->client($request)->id, 404);
    }
}
