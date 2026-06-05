<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

abstract class MetaBaseController extends Controller
{
    protected function jsonError(string $message, int $status = 500): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => $message,
        ], $status);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function jsonSuccess(array $data = [], int $status = 200): JsonResponse
    {
        return response()->json(array_merge(['status' => true], $data), $status);
    }
}
