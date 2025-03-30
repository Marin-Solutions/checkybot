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
            Schema::create('backup_histories', function ( Blueprint $table ) {
                $table->id();
                $table->unsignedBigInteger('backup_id');
                $table->string('filename');
                $table->integer('filesize');
                $table->tinyInteger('is_zipped')->default(0);
                $table->tinyInteger('is_uploaded')->default(0);
                $table->text('message')->nullable();
                $table->timestamps();
            });
        }

        /**
         * Reverse the migrations.
         */
        public function down(): void
        {
            Schema::dropIfExists('backup_histories');
        }
    };
