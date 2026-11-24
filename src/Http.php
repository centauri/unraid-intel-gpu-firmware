<?php
declare(strict_types=1);
namespace IntelGpuFirmware;

final class Http {
    public static function rawFormRequest(string $method,string $raw,string $contentType,string $expectedCsrf): array {
        if(strlen($raw)>4096 || strtolower(trim(explode(';',$contentType)[0]))!=='application/x-www-form-urlencoded')
            throw new Blocked('Use a bounded Unraid form request.');
        parse_str($raw,$form);
        return self::formRequest($method,$form,$expectedCsrf);
    }
    public static function formRequest(string $method,array $form,string $expectedCsrf): array {
        if(array_diff(array_keys($form),['csrf_token','payload']) || !is_string($form['payload']??null)
            || !is_string($form['csrf_token']??null) || strlen($form['payload'])>4096)
            throw new Blocked('Use the expected Unraid form request.');
        $body=json_decode($form['payload'],true,8,JSON_THROW_ON_ERROR);
        if(!is_array($body) || ($body['csrf_token']??null)!==$form['csrf_token'])
            throw new Blocked('Request token does not match the form token.');
        return self::validate($method,$body,$expectedCsrf);
    }
    public static function jobActive(array $job,?int $now=null,?callable $alive=null): bool {
        $now??=time();
        if(($job['state']??'')==='queued')return $now-($job['created_at']??0)<30;
        if(($job['state']??'')!=='running')return false;
        $pid=(int)($job['pid']??0);
        if($pid<1)return false;
        if($alive)return $alive($pid,(string)($job['id']??''));
        $cmd=(string)@file_get_contents('/proc/'.$pid.'/cmdline');
        return str_contains($cmd,'/usr/local/libexec/intel-gpu-firmware/worker.php'."\0")
            && str_contains($cmd,"\0".($job['id']??'')."\0");
    }
    public static function validate(string $method,array $body,string $expectedCsrf): array {
        if($method!=='POST')throw new Blocked('Use POST for operations.');
        if(strlen($expectedCsrf)<16 || !is_string($body['csrf_token']??null)
            || !hash_equals($expectedCsrf,$body['csrf_token']))throw new Blocked('Invalid CSRF token. Reload the Unraid page.');
        $action=$body['action']??null;
        if(!is_string($action)||!in_array($action,['scan','stage','check','dry_run','flash','verify','releases','workloads','prepare','rehearse','restore'],true))throw new Blocked('Unknown action.');
        $allowed=['action','csrf_token'];$args=[];
        if(in_array($action,['check','dry_run','workloads'],true)) {
            $allowed[]='bdf';$bdf=$body['bdf']??'';
            if(!is_string($bdf)||!preg_match('/\A[0-9a-f]{4}:[0-9a-f]{2}:[0-9a-f]{2}\.[0-7]\z/D',$bdf))
                throw new Blocked('Invalid PCI address.');
            $args['bdf']=$bdf;
        }
        if(in_array($action,['prepare','rehearse'],true)) {
            array_push($allowed,'preview_id','acknowledged');
            if(!is_string($body['preview_id']??null)||!preg_match('/\A[a-f0-9]{32}\z/D',$body['preview_id'])||($body['acknowledged']??false)!==true)
                throw new Blocked('Review and acknowledge the exact GPU workload list first.');
            $args=['preview_id'=>$body['preview_id']];
        }
        if($action==='flash') {
            array_push($allowed,'plan_id','confirmation','acknowledged');
            if(!is_string($body['plan_id']??null)||!preg_match('/\A[a-f0-9]{32}\z/D',$body['plan_id']))
                throw new Blocked('Invalid update plan.');
            if(!is_string($body['confirmation']??null)||strlen($body['confirmation'])>64||($body['acknowledged']??false)!==true)
                throw new Blocked('Explicit acknowledgement is required.');
            $args=['plan_id'=>$body['plan_id'],'confirmation'=>$body['confirmation']];
        }
        if(array_diff(array_keys($body),$allowed))throw new Blocked('Unexpected request fields.');
        return ['action'=>$action,'args'=>$args];
    }
    public static function queue(Engine $engine,array $request,?callable $launcher=null): string {
        if($request['action']==='flash' && !WRITES_ENABLED)throw new Blocked(WRITE_BLOCK);
        $lock=fopen($engine->runtime->dir.'/queue.lock','c');
        if(!$lock||!flock($lock,LOCK_EX))throw new Blocked('Cannot lock operation queue.');
        try {
            $old=$engine->runtime->get('job');
            if(in_array($old['state']??'', ['queued','running'],true)) {
                if(self::jobActive($old))throw new Blocked('Another operation is already running.');
                // Do not replay a crashed worker. Firmware state is protected by the durable journal.
                $old['state']='failed';$old['message']='The previous worker stopped. Its operation was not retried.';
                $engine->runtime->put('job',$old);
            }
            if($request['action']==='flash') {
                Engine::assertPlan($engine->runtime->get('plan'),$request['args']['plan_id'],
                    $request['args']['confirmation'],$engine->hardware->bootId(),time());
                $engine->assertNoUnfinishedWrite();
                $engine->assertPlanUnconsumed($request['args']['plan_id']);
            }
            $id=bin2hex(random_bytes(16));
            $engine->runtime->put('job',['id'=>$id,'state'=>'queued','created_at'=>time(),...$request]);
            // Every word except the random hex job ID is a fixed installation path.
            $command='/usr/bin/nohup /usr/bin/php /usr/local/libexec/intel-gpu-firmware/worker.php '
                .escapeshellarg($id).' >/dev/null 2>&1 &';
            if($launcher) $code=$launcher($id);
            else exec($command,$unused,$code);
            if($code!==0)throw new Blocked('Could not start the background worker.');
            return $id;
        } finally {flock($lock,LOCK_UN);fclose($lock);}
    }
}
