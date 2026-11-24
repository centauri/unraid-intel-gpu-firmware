<?php
declare(strict_types=1);
namespace IntelGpuFirmware;

final class AuthServer {
    public static function serve(string $path,string $pidFile,callable $loadState,callable $inspect): never {
        umask(0077);
        $lock=fopen($path.'.lock','c');
        if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit(0);
        if(is_link($path))exit(3);if(file_exists($path))unlink($path);
        $server=stream_socket_server('unix://'.$path,$errno,$error);
        if(!$server)exit(4);chmod($path,0600);
        file_put_contents($pidFile,(string)getmypid());
        pcntl_async_signals(true);pcntl_signal(SIGCHLD,function(){while(pcntl_waitpid(-1,$status,WNOHANG)>0){}});
        while(true) {
            $client=@stream_socket_accept($server,-1);
            // SIGCHLD interrupts accept after a request worker exits; this is not shutdown.
            if($client===false)continue;
            $pid=pcntl_fork();
            if($pid===-1){fclose($client);continue;}
            if($pid>0){fclose($client);continue;}
            fclose($server);stream_set_timeout($client,10);
            $reply=['Allow'=>false,'Msg'=>'IGF_GPU_MAINTENANCE: request could not be verified.'];
            try {
                $line=fgets($client,8192);if(!preg_match('~^POST (/[^ ]+) HTTP/1\.[01]\r?\n$~',(string)$line,$m))throw new Blocked('Invalid request.');
                // HTTP requests without Content-Length/Transfer-Encoding have no body.
                // Docker's empty Plugin.Activate request uses this form.
                $route=$m[1];$length=0;$headerBytes=0;
                while(($line=fgets($client,8192))!==false && $line!=="\r\n") {
                    $headerBytes+=strlen($line);if($headerBytes>32768)throw new Blocked('Headers too large.');
                    if(preg_match('/^Content-Length:\s*([0-9]+)\s*$/i',trim($line),$m))$length=(int)$m[1];
                    if(stripos($line,'Transfer-Encoding:')===0)throw new Blocked('Chunked authorization request unsupported.');
                }
                if($length>16777216)throw new Blocked('Request too large.');
                $body='';while(strlen($body)<$length){$part=fread($client,min(65536,$length-strlen($body)));if($part===false||$part==='')throw new Blocked('Incomplete request.');$body.=$part;}
                if($route==='/Plugin.Activate')$reply=['Implements'=>['authz']];
                elseif($route==='/AuthZPlugin.AuthZRes')$reply=['Allow'=>true];
                elseif($route==='/AuthZPlugin.AuthZReq') {
                    $s=$loadState();
                    $allow=StartPolicy::docker(json_decode($body,true,64,JSON_THROW_ON_ERROR),$s,$inspect);
                    $reply=['Allow'=>$allow,'Msg'=>$allow?'':'IGF_GPU_MAINTENANCE: selected GPU is reserved; restore GPU workloads after maintenance.'];
                }
            } catch(\Throwable $e) {
                // Diagnostics identify the check only; never log request bodies or credentials.
                fwrite(STDERR,'Guard request rejected at '.basename($e->getFile()).':'.$e->getLine()."\n");
            }
            $json=json_encode($reply);$response="HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nConnection: close\r\nContent-Length: ".strlen($json)."\r\n\r\n".$json;
            fwrite($client,$response);fclose($client);exit(0);
        }
    }
}
