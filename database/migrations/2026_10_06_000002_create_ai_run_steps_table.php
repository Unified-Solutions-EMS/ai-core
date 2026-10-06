<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_run_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('ai_runs')->cascadeOnDelete();
            $table->unsignedInteger('seq');
            $table->string('role', 16);
            $table->string('tool_name', 64)->nullable();
            $table->json('arguments')->nullable();
            $table->json('result')->nullable();
            $table->unsignedInteger('tokens')->default(0);
            $table->timestamps();

            $table->unique(['run_id', 'seq']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_run_steps');
    }
};
