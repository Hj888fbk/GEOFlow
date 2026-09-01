<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class HengjiaSyncContentSourcesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hengjia:content-sync-sources {--root=* : Approved source root key} {--dry-run : Scan without database writes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Retired compatibility command; import sources through native GEOFlow knowledge bases.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->error('该兼容命令已停止写入平行 ContentSourceFile；请通过 GEOFlow 原生知识库导入和审核资料。');

        return self::FAILURE;
    }
}
