<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Audit\ShowAuditDocumentFileRequest;
use App\Models\AuditLog;
use App\Models\VehicleDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AuditLogController extends ApiController
{
    public function documentFile(AuditLog $auditLog, ShowAuditDocumentFileRequest $request)
    {
        abort_unless($auditLog->entity_type === VehicleDocument::class, 404);
        $version = $request->validated('version');
        $values = $version === 'old' ? $auditLog->old_values : $auditLog->new_values;
        $path = $values['file_path'] ?? null;
        abort_unless($path && Storage::exists($path), 404);

        return Storage::response($path, basename($path));
    }

    public function index(Request $request)
    {
        $query = $this->query($request);

        if ($request->input('format') === 'csv') {
            abort_unless($request->user()->can('audit.export'), 403);

            return response()->streamDownload(function () use ($query) {
                $output = fopen('php://output', 'w');
                fputcsv($output, ['Date', 'User', 'Action', 'Entity', 'Entity ID', 'IP address', 'Old values', 'New values']);
                $query->latest('created_at')->each(function (AuditLog $log) use ($output) {
                    fputcsv($output, [
                        $log->created_at?->toIso8601String(),
                        $log->user?->name ?? 'System',
                        $log->action,
                        class_basename($log->entity_type),
                        $log->entity_id,
                        $log->ip_address,
                        json_encode($log->old_values),
                        json_encode($log->new_values),
                    ]);
                });
                fclose($output);
            }, 'vehiclehub-audit-log-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
        }

        $paginator = $query->latest('created_at')->paginate(min((int) $request->input('per_page', 20), 100));
        $paginator->getCollection()->transform(function (AuditLog $log) {
            $log->setAttribute('entity_name', Str::headline(class_basename($log->entity_type)));

            return $log;
        });

        return $this->paginated($paginator);
    }

    public function options(Request $request)
    {
        return $this->ok([
            'actions' => AuditLog::query()->distinct()->orderBy('action')->pluck('action'),
            'entities' => AuditLog::query()->distinct()->orderBy('entity_type')->pluck('entity_type')->map(fn ($type) => [
                'value' => $type,
                'label' => Str::headline(class_basename($type)),
            ])->values(),
        ]);
    }

    private function query(Request $request): Builder
    {
        $query = AuditLog::query()->with('user:id,name,email');
        if ($request->filled('search')) {
            $search = $request->string('search')->trim()->value();
            $query->where(function (Builder $builder) use ($search) {
                $builder->where('action', 'like', "%{$search}%")
                    ->orWhere('entity_id', $search)
                    ->orWhereHas('user', fn (Builder $user) => $user->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
            });
        }
        if ($request->filled('action')) {
            $query->where('action', $request->input('action'));
        }
        if ($request->filled('entity_type')) {
            $query->where('entity_type', $request->input('entity_type'));
        }
        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->date('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->date('to'));
        }

        return $query;
    }
}
