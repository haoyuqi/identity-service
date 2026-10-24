<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

final class ReadinessController extends Controller
{
    /**
     * Check runtime dependencies without disclosing connection details.
     */
    public function __invoke(Request $request): JsonResponse|Response
    {
        $failures = array_keys(array_filter([
            'database' => ! $this->databaseIsAvailable(),
            'redis' => ! $this->redisIsAvailable(),
        ]));

        $available = $failures === [];
        $status = $available ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE;

        if ($request->expectsJson()) {
            return response()->json([
                'status' => $available ? 'ready' : 'unavailable',
                'dependencies' => $available ? [] : $failures,
            ], $status);
        }

        return response(
            $available ? 'Service ready.' : 'Service unavailable.',
            $status,
        )->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    private function databaseIsAvailable(): bool
    {
        try {
            DB::connection()->select('select 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function redisIsAvailable(): bool
    {
        try {
            Redis::connection(config('session.connection'))->ping();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
