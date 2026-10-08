<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_proposals', function (Blueprint $table): void {
            $table->timestamp('executing_at')->nullable()->after('approval_instruction');
            $table->unsignedBigInteger('executing_by')->nullable()->after('executing_at');
            $table->text('failed_reason')->nullable()->after('results');
            $table->index(['status', 'executing_at']);
        });
    }

    public function down(): void
    {
        Schema::table('ai_proposals', function (Blueprint $table): void {
            $table->dropIndex(['status', 'executing_at']);
            $table->dropColumn(['executing_at', 'executing_by', 'failed_reason']);
        });
    }
};
