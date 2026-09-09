<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // RFC 2822 headers, distinct from Gmail's own external_message_id — these are what
            // threading actually keys off (both in Gmail's own strict same-thread-send check and
            // in every other mail client). Captured on ingest for inbound messages and generated
            // ourselves for outbound ones, since Gmail's send response doesn't reliably echo them
            // back.
            $table->string('rfc_message_id')->nullable()->after('external_message_id');
            $table->text('rfc_references')->nullable()->after('rfc_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['rfc_message_id', 'rfc_references']);
        });
    }
};
