<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ApiController extends Controller
{
    protected function ok(mixed $data = null, string $message = 'OK', int $status = 200)
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }

    protected function paginated(LengthAwarePaginator $p, array $additionalMeta = [])
    {
        return response()->json(['success' => true, 'message' => 'OK', 'data' => $p->items(), 'meta' => array_merge(['current_page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'per_page' => $p->perPage(), 'total' => $p->total()], $additionalMeta)]);
    }
}
