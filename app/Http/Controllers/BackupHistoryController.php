<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBackupHistoryRequest;
use App\Models\Backup;
use App\Models\BackupHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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
    public function store(StoreBackupHistoryRequest $request)
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
        ]);

        $server->recordReporterMetadata($request);

        return response()->json($backupHistory, 200);
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
