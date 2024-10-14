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
            Schema::create('outbound_link', function ( Blueprint $table ) {
                $table->id();
                $table->unsignedBigInteger('website_id');
                $table->string('found_on')->nullable();
                $table->string('outgoing_url')->nullable();
//                $table->date('ssl_expiry_date');
                $table->integer('http_status_code')->nullable();
//                $table->integer('speed_ms');
                $table->timestamps();
                $table->timestamp('last_checked_at')->nullable();
            });
        }

        /**
         * Reverse the migrations.
         */
        public function down(): void
        {
            Schema::dropIfExists('outbound_link');
        }
    };
