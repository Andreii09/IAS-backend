<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AuditLogger;
use Illuminate\Console\Command;

class AuditVerifyCommand extends Command
{
    protected $signature = 'audit:verify';

    protected $description = 'Verify every audit log hash and hash-chain link';

    public function handle(): int
    {
        $result = AuditLogger::verify();

        if ($result['valid']) {
            $this->info('Audit log is valid: all hashes and chain links match.');

            return self::SUCCESS;
        }

        foreach ($result['errors'] as $error) {
            $this->error($error);
        }

        $this->error(sprintf('Audit verification failed with %d error(s).', count($result['errors'])));

        return self::FAILURE;
    }
}
