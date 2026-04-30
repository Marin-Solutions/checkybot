<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX_NAME = 'outbound_link_website_found_outgoing_unique';

    public function up(): void
    {
        $this->deleteDuplicateOutboundLinks();

        Schema::table('outbound_link', function (Blueprint $table) {
            $table->unique(['website_id', 'found_on', 'outgoing_url'], self::INDEX_NAME);
        });
    }

    public function down(): void
    {
        Schema::table('outbound_link', function (Blueprint $table) {
            $table->dropUnique(self::INDEX_NAME);
        });
    }

    private function deleteDuplicateOutboundLinks(): void
    {
        DB::table('outbound_link')
            ->whereNotNull('found_on')
            ->whereNotNull('outgoing_url')
            ->orderBy('website_id')
            ->orderBy('found_on')
            ->orderBy('outgoing_url')
            ->orderByDesc('last_checked_at')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get(['id', 'website_id', 'found_on', 'outgoing_url'])
            ->groupBy(fn (object $link): string => json_encode([
                $link->website_id,
                $link->found_on,
                $link->outgoing_url,
            ]))
            ->each(function ($links): void {
                $duplicateIds = $links->skip(1)->pluck('id');

                if ($duplicateIds->isNotEmpty()) {
                    DB::table('outbound_link')->whereIn('id', $duplicateIds)->delete();
                }
            });
    }
};
