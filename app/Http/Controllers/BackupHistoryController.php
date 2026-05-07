<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBackupHistoryRequest;
use App\Models\Backup;
use App\Models\BackupHistory;
use App\Services\HealthEventNotificationService;
use Illuminate\Http\Request;

class BackupHistoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreBackupHistoryRequest $request, HealthEventNotificationService $notifications)
    {
        $id = $request->input('bi');
        $backup = Backup::query()->where('id', $id)->first();

        if (! $backup) {
            return response()->json(['message' => __('The backup not found')], 404);
        }

        $server = $backup->server;
        $token = $request->bearerToken();

        if (! $server) {
            return response()->json(['message' => __('The server id is not in this DB')], 404);
        }

        if (! $server->hasReporterToken($token)) {
            return response()->json(['message' => __('Error: Unauthorized')], 401);
        }

        $backupHistory = BackupHistory::create([
            'backup_id' => $backup->id,
            'filename' => $request->input('nf'),
            'filesize' => $request->input('sf'),
            'is_zipped' => $request->input('iz'),
            'is_uploaded' => $request->input('iu'),
            'message' => $request->input('msg'),
        ]);

        $server->recordReporterMetadata($request);

        if (! $backupHistory->is_zipped || ! $backupHistory->is_uploaded) {
            $notifications->notifyBackup(
                $backup,
                'backup_failed',
                'danger',
                $this->failureSummary($backupHistory),
            );
        }

        return response()->json($backupHistory, 200);
    }

    private function failureSummary(BackupHistory $backupHistory): string
    {
        if (! $backupHistory->is_zipped) {
            return 'Backup archive creation failed before upload. File: '.$backupHistory->filename.'.';
        }

        return 'Backup archive was created but upload failed. File: '.$backupHistory->filename.'.';
    }

    /**
     * Display the specified resource.
     */
    public function show(BackupHistory $backupHistory)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(BackupHistory $backupHistory)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, BackupHistory $backupHistory)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(BackupHistory $backupHistory)
    {
        //
    }
}
