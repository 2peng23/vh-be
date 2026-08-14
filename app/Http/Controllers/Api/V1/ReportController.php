<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\VehicleExpense;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ReportController extends ApiController
{
    public function expenses(Request $request)
    {
        $filters = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'vehicle_id' => 'nullable|integer',
            'vehicle_code' => 'nullable|string|max:100',
            'category' => 'nullable|string',
            'format' => 'nullable|in:json,csv,xlsx',
            'per_page' => 'nullable|integer|in:10,20,50,100',
        ]);

        $query = $this->expenseQuery($filters);

        if (($filters['format'] ?? 'json') === 'xlsx') {
            abort_unless($request->user()->can('reports.export'), 403);
            return $this->generateXlsx($query);
        }

        if (($filters['format'] ?? 'json') === 'csv') {
            abort_unless($request->user()->can('reports.export'), 403);

            return response()->streamDownload(function () use ($query) {
                $output = fopen('php://output', 'w');
                fputcsv($output, ['Date', 'Vehicle', 'Vehicle Code', 'Category', 'Vendor', 'Amount']);

                $query->orderBy('expense_date')->each(fn ($expense) => fputcsv($output, [
                    $expense->expense_date,
                    $expense->vehicle?->plate_number,
                    $expense->vehicle?->vehicle_code,
                    $expense->category,
                    $expense->vendor,
                    $expense->amount,
                ]));

                fclose($output);
            }, 'vehicle-expenses.csv', ['Content-Type' => 'text/csv']);
        }

        $rows = (clone $query)->latest('expense_date')->paginate($filters['per_page'] ?? 20);
        $summary = (clone $query)
            ->without('vehicle')
            ->select('category', DB::raw('SUM(amount) as total'))
            ->groupBy('category')
            ->orderBy('category')
            ->get();
        $categoryFilters = $filters;
        unset($categoryFilters['category']);
        $availableCategories = $this->expenseQuery($categoryFilters)
            ->without('vehicle')
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');

        return $this->ok([
            'rows' => $rows->items(),
            'summary' => $summary,
            'available_categories' => $availableCategories,
            'pagination' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    private function expenseQuery(array $filters): Builder
    {
        return VehicleExpense::query()
            ->with('vehicle')
            ->when(isset($filters['from']), fn (Builder $query) => $query->whereDate('expense_date', '>=', $filters['from']))
            ->when(isset($filters['to']), fn (Builder $query) => $query->whereDate('expense_date', '<=', $filters['to']))
            ->when(isset($filters['vehicle_id']), fn (Builder $query) => $query->where('vehicle_id', $filters['vehicle_id']))
            ->when(filled($filters['vehicle_code'] ?? null), function (Builder $query) use ($filters) {
                $vehicleCode = trim($filters['vehicle_code']);

                $query->whereHas('vehicle', fn (Builder $vehicleQuery) => $vehicleQuery
                    ->where('vehicle_code', 'like', "%{$vehicleCode}%"));
            })
            ->when(isset($filters['category']), fn (Builder $query) => $query->where('category', $filters['category']));
    }

    private function generateXlsx(Builder $query)
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Title
        $sheet->setCellValue('A1', 'Report Expense');
        $sheet->mergeCells('A1:F1');
        $titleStyle = $sheet->getStyle('A1');
        $titleStyle->getFont()->setBold(true)->setSize(16)->setColor(new Color(Color::COLOR_WHITE));
        $titleStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF1F6B8C');
        $titleStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(30);

        // Generated date
        $sheet->setCellValue('A2', 'Generated: ' . now()->format('F d, Y h:i A'));
        $sheet->mergeCells('A2:F2');
        $sheet->getStyle('A2')->getFont()->setItalic(true)->setSize(10)->setColor(new Color('FF7E8C97'));

        // Empty row
        $sheet->getRowDimension(3)->setRowHeight(5);

        // Headers
        $headers = ['Date', 'Vehicle', 'Vehicle Code', 'Category', 'Vendor', 'Amount'];
        $headerRow = 4;
        foreach ($headers as $col => $header) {
            $cell = $sheet->getCellByColumnAndRow($col + 1, $headerRow);
            $cell->setValue($header);
            $style = $sheet->getStyleByColumnAndRow($col + 1, $headerRow);
            $style->getFont()->setBold(true)->setColor(new Color(Color::COLOR_WHITE))->setSize(11);
            $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF4A90E2');
            $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
            $style->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        }
        $sheet->getRowDimension($headerRow)->setRowHeight(20);

        // Data rows
        $expenses = $query->orderBy('expense_date')->get();
        $dataRow = $headerRow + 1;
        $total = 0;

        foreach ($expenses as $expense) {
            $amount = $expense->amount;
            $total += $amount;

            $sheet->setCellValue('A' . $dataRow, $expense->expense_date);
            $sheet->setCellValue('B' . $dataRow, $expense->vehicle?->plate_number ?? '');
            $sheet->setCellValue('C' . $dataRow, $expense->vehicle?->vehicle_code ?? '');
            $sheet->setCellValue('D' . $dataRow, $expense->category ?? '');
            $sheet->setCellValue('E' . $dataRow, $expense->vendor ?? '');
            $sheet->setCellValue('F' . $dataRow, $amount);

            // Style data row
            for ($col = 1; $col <= 6; $col++) {
                $style = $sheet->getStyleByColumnAndRow($col, $dataRow);
                $style->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('FFE8EEF5'));
                if ($col === 6) {
                    $style->getNumberFormat()->setFormatCode('#,##0.00');
                    $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                }
            }

            // Alternate row colors
            if ($dataRow % 2 === 0) {
                for ($col = 1; $col <= 6; $col++) {
                    $sheet->getStyleByColumnAndRow($col, $dataRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF5F8FB');
                }
            }

            $dataRow++;
        }

        // Empty row before total
        $dataRow++;

        // Total row
        $sheet->setCellValue('E' . $dataRow, 'TOTAL');
        $sheet->setCellValue('F' . $dataRow, $total);

        $totalStyle = $sheet->getStyle('E' . $dataRow . ':F' . $dataRow);
        $totalStyle->getFont()->setBold(true)->setSize(12)->setColor(new Color(Color::COLOR_WHITE));
        $totalStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF27AE60');
        $totalStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT)->setVertical(Alignment::VERTICAL_CENTER);
        $totalStyle->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_MEDIUM)->setColor(new Color('FF27AE60'));
        $sheet->getStyleByColumnAndRow(6, $dataRow)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getRowDimension($dataRow)->setRowHeight(22);

        // Set column widths
        $sheet->getColumnDimension('A')->setWidth(24);
        $sheet->getColumnDimension('B')->setWidth(18);
        $sheet->getColumnDimension('C')->setWidth(16);
        $sheet->getColumnDimension('D')->setWidth(16);
        $sheet->getColumnDimension('E')->setWidth(16);
        $sheet->getColumnDimension('F')->setWidth(16);

        // Generate file
        $writer = new Xlsx($spreadsheet);
        $timestamp = now()->format('Y-m-d_His');
        $filename = "Report_Expense_{$timestamp}.xlsx";

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
