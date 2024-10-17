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
            Schema::create('server_log_file_histories', function ( Blueprint $table ) {
                $table->id();
                $table->unsignedBigInteger('server_log_category_id');
                $table->string('log_file_name');
                $table->timestamps();
                $table->timestamp('deleted_at')->nullable();
            });
        }

        /**
         * Reverse the migrations.
         */
        public function down(): void
        {
            Schema::dropIfExists('server_log_file_histories');
        }
    };
