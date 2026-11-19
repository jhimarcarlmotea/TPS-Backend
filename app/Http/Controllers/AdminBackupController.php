<?php

namespace App\Http\Controllers;

use App\Exceptions\BackupOperationException;
use App\Models\ActivityLog;
use App\Services\AdminBackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AdminBackupController extends Controller
{
    public function __construct(private readonly AdminBackupService $backups) {}

    public function index(): JsonResponse
    {
        try {
            return response()->json(['backups' => $this->backups->backups()]);
        } catch (BackupOperationException $exception) {
            return $this->failure($exception);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $backup = $this->backups->create();
            $admin = $request->user();
            ActivityLog::log(
                $admin,
                'backup_created',
                'backup',
                'Created Database Backup',
                "{$admin->first_name} {$admin->last_name} created a manual database and uploads backup.",
                null,
                ['backup_id' => $backup['id'], 'size_bytes' => $backup['size_bytes']]
            );

            return response()->json([
                'message' => 'Backup created successfully.',
                'backup' => $backup,
            ], 201);
        } catch (BackupOperationException $exception) {
            return $this->failure($exception);
        }
    }

    public function download(string $id): BinaryFileResponse|JsonResponse
    {
        try {
            $path = $this->backups->downloadPath($id);

            return response()->download($path, basename($path));
        } catch (BackupOperationException $exception) {
            return $this->failure($exception);
        }
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'confirmation' => ['required', 'in:RESTORE'],
        ]);

        try {
            $result = $this->backups->restore($id);
            $admin = $request->user();
            ActivityLog::log(
                $admin,
                'backup_restored',
                'backup',
                'Restored Database Backup',
                "{$admin->first_name} {$admin->last_name} restored database and uploaded files from backup {$id}.",
                null,
                [
                    'backup_id' => $id,
                    'safety_backup_id' => $result['safety_backup']['id'],
                ]
            );

            return response()->json([
                'message' => 'Backup restored successfully.',
                ...$result,
            ]);
        } catch (BackupOperationException $exception) {
            return $this->failure($exception);
        }
    }

    public function settings(): JsonResponse
    {
        try {
            return response()->json(['settings' => $this->backups->settings()]);
        } catch (BackupOperationException $exception) {
            return $this->failure($exception);
        }
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'frequency' => ['required', Rule::in(['daily', 'weekly', 'monthly'])],
            'time' => ['required', 'date_format:H:i'],
        ]);

        try {
            return response()->json([
                'message' => 'Backup schedule saved.',
                'settings' => $this->backups->updateSettings(
                    (bool) $data['enabled'],
                    $data['frequency'],
                    $data['time'],
                ),
            ]);
        } catch (BackupOperationException $exception) {
            return $this->failure($exception);
        }
    }

    private function failure(BackupOperationException $exception): JsonResponse
    {
        return response()->json(['message' => $exception->getMessage()], $exception->status);
    }
}
