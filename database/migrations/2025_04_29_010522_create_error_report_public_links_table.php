<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    return new class extends Migration {
        /**
         * Run the migrations.
         */
        public function up(): void
        {
            Schema::create('error_report_public_links', function ( Blueprint $table ) {
                $table->id();
                $table->unsignedBigInteger('error_report_id');
                $table->unsignedBigInteger('created_by');
                $table->string('token')->unique();
                $table->timestamps();
            });
        }

        /**
         * Reverse the migrations.
         */
        public function down(): void
        {
            Schema::dropIfExists('error_report_public_links');
        }
    };
