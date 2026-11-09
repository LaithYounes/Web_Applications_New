<?php

namespace App\Http;

use Illuminate\Http\JsonResponse;

final class ApiErrorResponse
{
    public static function make(
        int $status,
        string $message,
        ?array $errors = null,
        array $headers = [],
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => null,
            'errors' => $errors,
        ], $status, $headers);
    }
}
