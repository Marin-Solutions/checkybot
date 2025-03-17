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
            Schema::create('backups', function ( Blueprint $table ) {
                $table->id();
                $table->unsignedBigInteger('server_id');
                $table->text('dir_path');
                $table->unsignedBigInteger('remote_storage_id');
                $table->string('remote_storage_path')->default('/');
                $table->string('interval_id');
                $table->dateTime('first_run_at')->nullable();
                $table->unsignedInteger('max_amount_backups')->default(0)->comment('0 = unlimited amount');
                $table->text('exclude_folder_files')->nullable();
                $table->string('password')->nullable();
                $table->string('backup_filename')->nullable();
                $table->string('compression_type')->default('zip');
                $table->unsignedTinyInteger('delete_local_on_fail')->default(0);
                $table->timestamps();
            });
        }

        /**
         * Reverse the migrations.
         */
        public function down(): void
        {
            Schema::dropIfExists('backups');
        }
    };
