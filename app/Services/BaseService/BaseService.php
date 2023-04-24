<?php

namespace App\Services\BaseService;

use Illuminate\Http\JsonResponse;

class BaseService
{
    protected function sendMessageWithError(string $message, int $code): JsonResponse
    {
        return response()->json(["message" => $message, "code" => $code], $code);
    }
}
