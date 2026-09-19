<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The local outbox: events waiting to be aggregated, and aggregated batches
 * waiting to be accepted by SMS Cloud.
 *
 * ⚠️ Neither table holds anything that identifies a person or a message. Events
 * carry a gateway key, a driver, an outcome, a failure kind and a duration —
 * never a recipient, a body, a message id or provider text.
 *
 * ⚠️ `dateTime`, not `timestamp`: MySQL's TIMESTAMP stops in 2038.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('sms-cloud.buffer.connection');
    }

    public function up(): void
    {
        Schema::create(config('sms-cloud.buffer.tables.events', 'sms_cloud_events'), function (Blueprint $table) {
            $table->id();
            // attempt | settled | circuit | delivery
            $table->string('type', 16);
            $table->dateTime('occurred_at', 3);
            $table->text('payload');

            $table->index('occurred_at');
        });

        Schema::create(config('sms-cloud.buffer.tables.batches', 'sms_cloud_batches'), function (Blueprint $table) {
            // The id is the batch's sequence number: monotonic per installation.
            $table->id();
            // Generated once, reused on every retry: this is what makes a retry a
            // duplicate on the Cloud side rather than a second count.
            $table->uuid('batch_uuid')->unique();
            $table->longText('payload');
            $table->unsignedInteger('rows')->default(0);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->dateTime('next_attempt_at');
            // A short machine code (`timeout`, `http_503`), never a response body.
            $table->string('last_error', 32)->nullable();
            $table->dateTime('created_at');

            $table->index('next_attempt_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('sms-cloud.buffer.tables.batches', 'sms_cloud_batches'));
        Schema::dropIfExists(config('sms-cloud.buffer.tables.events', 'sms_cloud_events'));
    }
};
