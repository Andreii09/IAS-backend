<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AuditChainTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_and_verifies_a_valid_hash_chain(): void
    {
        AuditLogger::record('test.first', null, [], ['step' => 1]);
        AuditLogger::record('test.second', 'story', [], ['step' => 2]);

        $this->assertSame(2, AuditLog::query()->count());

        $first = AuditLog::query()->orderBy('id')->firstOrFail();
        $second = AuditLog::query()->orderByDesc('id')->firstOrFail();

        $this->assertSame(AuditLogger::ZERO_HASH, $first->prev_hash);
        $this->assertSame($first->hash, $second->prev_hash);
        $this->assertTrue(AuditLogger::verify()['valid']);
    }

    public function test_it_detects_a_tampered_audit_row(): void
    {
        AuditLogger::record('test.original', null, [], ['step' => 1]);
        AuditLogger::record('test.following', null, [], ['step' => 2]);

        $first = AuditLog::query()->orderBy('id')->firstOrFail();

        // Use the query builder so the model's append-only guard is bypassed,
        // just as a database-level attacker would bypass application code.
        DB::table('audit_logs')->where('id', $first->id)->update(['action' => 'test.tampered']);

        $result = AuditLogger::verify();

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('hash mismatch', $result['errors'][0]);
    }
}
