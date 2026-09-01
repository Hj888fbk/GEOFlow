<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class HengjiaPlanDailyContentCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hengjia:content-plan-daily {--date= : YYYY-MM-DD} {--limit= : 3-6}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Retired compatibility command; use the native GEOFlow Task workflow.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->error('该兼容命令已停止写入平行 ContentTask；请在 GEOFlow 原生任务页创建或排期 Task。');

        return self::FAILURE;
    }
}
