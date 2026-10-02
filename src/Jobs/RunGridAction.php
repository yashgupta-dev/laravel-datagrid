<?php

namespace CodeCorner\Datagrid\Jobs;

use CodeCorner\Datagrid\Core\ActionRunner;
use CodeCorner\Datagrid\Core\GridRegistry;
use CodeCorner\Datagrid\Support\JobProgress;
use CodeCorner\Datagrid\Support\ResultCache;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class RunGridAction implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  list<int|string>  $ids
     */
    public function __construct(
        public string $gridKey,
        public string $actionKey,
        public array $ids,
        public mixed $actor,
        public string $jobId,
    ) {}

    public function handle(GridRegistry $registry, ActionRunner $runner): void
    {
        $total = count($this->ids);
        JobProgress::update($this->jobId, ['status' => 'running', 'done' => 0, 'total' => $total]);

        try {
            $grid = $registry->find($this->gridKey);
            $action = $grid?->findAction($this->actionKey);
            if ($grid === null || $action === null) {
                throw new \RuntimeException('This action is no longer available.');
            }

            $result = $runner->run($grid, $action, $this->actor, $this->ids);
            JobProgress::update($this->jobId, [
                'status' => 'done',
                'done' => $total,
                'total' => $total,
                'message' => $result['message'],
            ]);
            ResultCache::bump($this->gridKey);
        } catch (Throwable $e) {
            JobProgress::update($this->jobId, [
                'status' => 'failed',
                'done' => 0,
                'total' => $total,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
