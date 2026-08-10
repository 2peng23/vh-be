<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\VehicleExpense;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends ApiController
{
    public function expenses(Request $request)
    {
        $filters = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'vehicle_id' => 'nullable|integer',
            'category' => 'nullable|string',
            'format' => 'nullable|in:json,csv',
        ]);

        $query = $this->expenseQuery($filters);

        if (($filters['format'] ?? 'json') === 'csv') {
            abort_unless($request->user()->can('reports.export'), 403);

            return response()->streamDownload(function () use ($query) {
                $output = fopen('php://output', 'w');
                fputcsv($output, ['Date', 'Vehicle', 'Category', 'Vendor', 'Amount']);

                $query->orderBy('expense_date')->each(fn ($expense) => fputcsv($output, [
                    $expense->expense_date,
                    $expense->vehicle?->plate_number,
                    $expense->category,
                    $expense->vendor,
                    $expense->amount,
                ]));

                fclose($output);
            }, 'vehicle-expenses.csv', ['Content-Type' => 'text/csv']);
        }

        $rows = (clone $query)->latest('expense_date')->get();
        $summary = (clone $query)
            ->without('vehicle')
            ->select('category', DB::raw('SUM(amount) as total'))
            ->groupBy('category')
            ->orderBy('category')
            ->get();

        return $this->ok([
            'rows' => $rows,
            'summary' => $summary,
        ]);
    }

    private function expenseQuery(array $filters): Builder
    {
        return VehicleExpense::query()
            ->with('vehicle')
            ->when(isset($filters['from']), fn (Builder $query) => $query->whereDate('expense_date', '>=', $filters['from']))
            ->when(isset($filters['to']), fn (Builder $query) => $query->whereDate('expense_date', '<=', $filters['to']))
            ->when(isset($filters['vehicle_id']), fn (Builder $query) => $query->where('vehicle_id', $filters['vehicle_id']))
            ->when(isset($filters['category']), fn (Builder $query) => $query->where('category', $filters['category']));
    }
}
