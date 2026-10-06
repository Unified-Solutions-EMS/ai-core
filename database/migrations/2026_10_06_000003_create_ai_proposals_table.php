<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_proposals', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('app_slug', 64)->nullable();
            $table->string('domain', 64);
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('status', 16)->default('drafted');
            $table->json('plan');
            $table->string('plan_hash', 64);
            $table->string('approved_plan_hash', 64)->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_instruction')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->json('results')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->unsignedBigInteger('rejected_by')->nullable();
            $table->text('rejected_reason')->nullable();
            $table->unsignedBigInteger('run_id')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'domain', 'status']);
            $table->index('run_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_proposals');
    }
};
