<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitor_api_assertions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_api_id')->constrained('monitor_apis')->cascadeOnDelete();
            $table->string('data_path');
            $table->enum('assertion_type', [
                'type_check',      // Check if value is of specific type
                'value_compare',   // Compare value with expected value
                'exists',         // Check if value exists
                'not_exists',     // Check if value does not exist
                'array_length',   // Check array length
                'regex_match'     // Match against regex pattern
            ]);
            $table->string('expected_type')->nullable(); // For type_check: string, integer, boolean, array, object
            $table->enum('comparison_operator', ['=', '>', '<', '>=', '<=', '!=', 'contains'])->nullable(); // For value_compare
            $table->string('expected_value')->nullable(); // For value_compare and array_length
            $table->text('regex_pattern')->nullable(); // For regex_match
            $table->integer('sort_order')->default(0); // For ordering multiple assertions
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_api_assertions');
    }
};
