<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CheckerOwnership
{
    public function goOwns(string $kind): bool
    {
        $value = DB::table('checker_settings')
            ->where('setting_key', $kind.'_owner')
            ->value('setting_value');

        return $value === 'go';
    }

    /**
     * Null means the caller must not run the check and must not release a lock.
     * A string token is the lock owner and must be released.
     */
    public function acquire(string $kind, string $lockKey, int $leaseSeconds): ?string
    {
        if ($this->goOwns($kind)) {
            return null;
        }

        $token = (string) Str::uuid();

        return $this->claim($lockKey, $token, $leaseSeconds) ? $token : null;
    }

    public function claim(string $lockKey, string $owner, int $leaseSeconds): bool
    {
        $leaseSeconds = max(1, $leaseSeconds);

        if (DB::getDriverName() === 'mysql') {
            DB::statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }

        try {
            return DB::transaction(function () use ($lockKey, $owner, $leaseSeconds): bool {
                $row = DB::table('checker_locks')->where('lock_key', $lockKey)->lockForUpdate()->first();
                $now = CarbonImmutable::now('UTC');
                $expires = $now->addSeconds($leaseSeconds);

                if ($row === null) {
                    DB::table('checker_locks')->insert([
                        'lock_key' => $lockKey,
                        'owner' => $owner,
                        'expires_at' => $expires->format('Y-m-d H:i:s.u'),
                        'created_at' => $now->format('Y-m-d H:i:s.u'),
                        'updated_at' => $now->format('Y-m-d H:i:s.u'),
                    ]);

                    return true;
                }

                $rowExpires = CarbonImmutable::parse($row->expires_at, 'UTC');
                if ($rowExpires->greaterThan($now) && $row->owner !== $owner) {
                    return false;
                }

                DB::table('checker_locks')->where('lock_key', $lockKey)->update([
                    'owner' => $owner,
                    'expires_at' => $expires->format('Y-m-d H:i:s.u'),
                    'updated_at' => $now->format('Y-m-d H:i:s.u'),
                ]);

                return true;
            });
        } catch (QueryException $exception) {
            if ($this->isDuplicate($exception)) {
                return false;
            }

            throw $exception;
        }
    }

    public function release(string $lockKey, string $owner): void
    {
        DB::table('checker_locks')
            ->where('lock_key', $lockKey)
            ->where('owner', $owner)
            ->delete();
    }

    private function isDuplicate(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        return $sqlState === '23000' || $driverCode === 1062 || $driverCode === 19;
    }
}
