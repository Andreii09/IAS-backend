<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditController extends Controller
{
    /**
     * Filterable audit trail.
     */
    public function index(Request $request)
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Administrator access required.');

        return $this->filtered($request)
            ->paginate(max(1, min(200, $request->integer('per_page', 25))));
    }

    public function export(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Administrator access required.');

        $filename = 'audit-log-' . now()->toDateString() . '.csv';

        return response()->stream(function () use ($request): void {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [
                'id',
                'user_id',
                'action',
                'entity_type',
                'entity_id',
                'old_values',
                'new_values',
                'ip',
                'user_agent',
                'prev_hash',
                'hash',
                'created_at',
            ]);

            $this->filtered($request)->chunkById(500, function ($logs) use ($handle): void {
                foreach ($logs as $log) {
                    /** @var AuditLog $log */
                    fputcsv($handle, [
                        $log->getKey(),
                        $log->user_id,
                        $log->action,
                        $log->entity_type,
                        $log->entity_id,
                        $log->getAttributes()['old_values'] ?? '',
                        $log->getAttributes()['new_values'] ?? '',
                        $log->ip,
                        $log->user_agent,
                        $log->prev_hash,
                        $log->hash,
                        $log->getAttributes()['created_at'] ?? '',
                    ]);
                }
            });

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    public function verify(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Administrator access required.');

        return response()->json(AuditLogger::verify());
    }

    private function filtered(Request $request)
    {
        return AuditLog::query()
            ->when($request->filled('user') || $request->filled('user_id'), function ($query) use ($request): void {
                $query->where('user_id', $request->input('user_id', $request->input('user')));
            })
            ->when($request->filled('action'), fn ($query) => $query->where('action', $request->input('action')))
            ->when($request->filled('entity'), function ($query) use ($request): void {
                $entity = (string) $request->input('entity');

                if (str_contains($entity, ':')) {
                    [$type, $id] = explode(':', $entity, 2);
                    $query->where('entity_type', $type)->where('entity_id', $id);
                } else {
                    $query->where('entity_type', $entity);
                }
            })
            ->when($request->filled('entity_type'), fn ($query) => $query->where('entity_type', $request->input('entity_type')))
            ->when($request->filled('entity_id'), fn ($query) => $query->where('entity_id', $request->input('entity_id')))
            ->when($request->filled('date_from'), fn ($query) => $query->whereDate('created_at', '>=', $request->input('date_from')))
            ->when($request->filled('date_to'), fn ($query) => $query->whereDate('created_at', '<=', $request->input('date_to')))
            ->orderByDesc('id');
    }
}
