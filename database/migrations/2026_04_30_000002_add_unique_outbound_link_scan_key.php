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
        DB::statement(<<<'SQL'
            DELETE FROM outbound_link
            WHERE id IN (
                SELECT id
                FROM (
                    SELECT
                        id,
                        ROW_NUMBER() OVER (
                            PARTITION BY website_id, found_on, outgoing_url
                            ORDER BY last_checked_at DESC, updated_at DESC, id DESC
                        ) AS duplicate_position
                    FROM outbound_link
                    WHERE found_on IS NOT NULL
                        AND outgoing_url IS NOT NULL
                ) AS duplicate_outbound_links
                WHERE duplicate_position > 1
            )
        SQL);
    }
};
