<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;

trait ApiResponse
{
    /**
     * Standard Success Response
     */
    // protected function successResponse($data, string $message = null, int $code = 200): JsonResponse
    // {
    //     return response()->json([
    //         'success' => true,
    //         'message' => $message,
    //         'data'    => $data->items(),
    //     ], $code);
    // }

    protected function successResponse($data, string $message = null, int $code = 200): JsonResponse
    {
        // Check if the data comes from a Resource Collection with Pagination
        if (
            $data instanceof \Illuminate\Http\Resources\Json\AnonymousResourceCollection &&
            $data->resource instanceof \Illuminate\Pagination\LengthAwarePaginator
        ) {

            $paginator = $data->resource;

            return response()->json([
                'success' => true,
                'message' => $message,
                'data'    => $data->resolve(), // resolve() removes the extra "data" wrapper
                'meta'    => [
                    'current_page' => $paginator->currentPage(),
                    'last_page'    => $paginator->lastPage(),
                    'total'        => $paginator->total(),
                ],
                'links'   => [
                    'first' => $paginator->url(1),
                    'last'  => $paginator->url($paginator->lastPage()),
                    'prev'  => $paginator->previousPageUrl(),
                    'next'  => $paginator->nextPageUrl(),
                ]
            ], $code);
        }

        // Standard response for non-paginated data or single resources
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $code);
    }

    /**
     * Standard Error Response
     */
    protected function errorResponse(string $message, int $code, $errors = null): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors'  => $errors,
        ], $code);
    }
}
