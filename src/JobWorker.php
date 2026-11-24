<?php
declare(strict_types=1);
namespace IntelGpuFirmware;

final class Worker {
    public static function run(Engine $engine,string $id): int {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$id))return 2;
        $lock=fopen($engine->runtime->dir.'/work.lock','c');
        if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)) {
            $job=$engine->runtime->get('job');
            if(($job['id']??'')===$id && ($job['state']??'')==='queued') {
                $job['state']='failed';$job['message']='Another operation or plugin installation holds the device lock. Nothing was retried.';
                unset($job['args']);$engine->runtime->put('job',$job);
            }
            if($lock)fclose($lock);
            return 3;
        }
        try {
            $job=$engine->runtime->get('job');
            if(($job['id']??'')!==$id||($job['state']??'')!=='queued')return 4;
            $job['state']='running';$job['pid']=getmypid();$job['started_at']=time();
            $engine->runtime->put('job',$job);
            self::captureHistory($engine);
            try {
                switch($job['action']) {
                    case 'scan': $engine->scan();break;
                    case 'stage': $engine->assertNoUnfinishedWrite();$engine->stage();break;
                    case 'check': $engine->assertNoUnfinishedWrite();$engine->check($job['args']['bdf']);break;
                    case 'dry_run': $engine->dryRun($job['args']['bdf']);break;
                    case 'flash': $engine->flash($job['args']['plan_id'],$job['args']['confirmation']);break;
                    case 'verify': $engine->verify();break;
                    case 'releases': IntelRelease::check($engine);break;
                    case 'workloads': (new Maintenance($engine))->preview($job['args']['bdf']);break;
                    case 'prepare': (new Maintenance($engine))->prepare($job['args']['preview_id']);break;
                    case 'rehearse': (new Maintenance($engine))->rehearse($job['args']['preview_id']);break;
                    case 'restore': (new Maintenance($engine))->restore();break;
                    default: throw new Blocked('Unknown operation.');
                }
                $job['state']='complete';$job['message']='Finished.';
            } catch(\Throwable $e) {
                $job['state']='failed';$job['message']=$e->getMessage();
            }
            self::captureHistory($engine);
            $job['finished_at']=time();
            unset($job['args']);
            $engine->runtime->put('job',$job);
            return $job['state']==='complete'?0:1;
        } finally {flock($lock,LOCK_UN);fclose($lock);}
    }
    private static function captureHistory(Engine $engine): void {
        try {
            History::capture($engine);
            if($engine->runtime->get('history-warning'))$engine->runtime->put('history-warning',[]);
        } catch(\Throwable $e) {
            // History failure must never interrupt a flash or replace its authoritative outcome.
            try {$engine->runtime->put('history-warning',['message'=>'Firmware history could not be saved. The separate update journal remains authoritative.']);}
            catch(\Throwable $ignored) {}
        }
    }
}
