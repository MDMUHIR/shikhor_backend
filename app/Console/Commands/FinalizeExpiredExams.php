<?php

namespace App\Console\Commands;

use App\Services\ExamService;
use Illuminate\Console\Command;

class FinalizeExpiredExams extends Command
{
    protected $signature = 'exams:finalize-expired';

    protected $description = 'Auto-submit and mark exam attempts whose time has run out';

    public function handle(ExamService $exams): int
    {
        $this->info('Finalized '.$exams->finalizeExpired().' expired attempt(s).');

        return self::SUCCESS;
    }
}