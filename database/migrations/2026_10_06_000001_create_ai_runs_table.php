<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('app_slug', 64)->nullable();
            $table->string('domain', 64);
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('company_sso_id', 64)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('sop_version_id')->nullable();
            $table->string('model', 64)->nullable();
            $table->json('input_snapshot')->nullable();
            $table->string('prompt_hash', 64)->nullable();
            $table->json('recommendation')->nullable();
            $table->longText('explanation')->nullable();
            $table->string('summary', 255)->nullable();
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->string('status', 16)->default('running');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->json('outcome')->nullable();
            $table->unsignedBigInteger('proposal_id')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'domain', 'created_at']);
            $table->index('sop_version_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_runs');
    }
};
