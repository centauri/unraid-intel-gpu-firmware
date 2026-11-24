<?php
declare(strict_types=1);
namespace IntelGpuFirmware;

/** Discovery is informational and cannot change the trusted firmware catalog. */
final class IntelRelease {
    public const SOURCE='https://www.intel.com/content/www/us/en/download/785597/intel-arc-graphics-windows.html';
    public static function parse(string $html): array {
        if(strlen($html)>4194304)throw new Blocked('Intel release page exceeded the size limit.');
        preg_match_all('~<span\b[^>]*class="cmp-teaser__required-info"[^>]*>(.*?)</span>~si',$html,$fields);
        $versions=[];
        foreach($fields[1] as $field) {
            $text=trim(html_entity_decode(strip_tags($field),ENT_QUOTES|ENT_HTML5,'UTF-8'));
            if(preg_match('/\AVersion\s+([0-9]{1,6}(?:\.[0-9]{1,6}){3})(?:\s|$)/',$text,$m))$versions[]=$m[1];
        }
        preg_match_all('~\bhref="(https://downloadmirror\.intel\.com/[0-9]+/gfx_win_[0-9]+\.[0-9]+\.exe)"~',$html,$links);
        $links=array_values(array_unique($links[1]));$versions=array_values(array_unique($versions));
        $text=html_entity_decode(strip_tags(str_replace('<',' <',$html)),ENT_QUOTES|ENT_HTML5,'UTF-8');
        preg_match_all('/\bSHA512:\s*([a-fA-F0-9]{128})\b/',$text,$hashes);
        $hashes=array_values(array_unique(array_map('strtolower',$hashes[1])));
        if(count($versions)!==1 || count($links)!==1 || count($hashes)!==1)
            throw new Blocked('Intel changed its release page or returned incomplete information. Latest firmware status is unknown.');
        $parts=explode('.',$versions[0]);
        if(!str_ends_with($links[0],'/gfx_win_'.$parts[2].'.'.$parts[3].'.exe'))
            throw new Blocked('Intel package version and download name disagree. Latest firmware status is unknown.');
        return ['version'=>$versions[0],'url'=>$links[0],'sha512'=>$hashes[0]];
    }
    public static function classify(array $latest,array $trusted): string {
        $cmp=version_compare($latest['version'],$trusted['version']);
        if($cmp>0)return 'new_package';
        if($cmp<0)return 'unexpected_older';
        return hash_equals($trusted['sha512']??'',$latest['sha512']) && ($trusted['url']??'')===$latest['url']
            ?'catalog_current':'package_changed';
    }
    public static function check(Engine $engine): void {
        $old=$engine->persistent->get('intel-release');
        $result=$old;$result['attempted_at']=gmdate('c');$result['source']=self::SOURCE;
        $temp=tempnam($engine->runtime->dir,'intel-release-');
        try {
            if($temp===false)throw new Blocked('Could not create temporary storage for the Intel release check.');
            // Fixed HTTPS URL, no redirects, no card identifiers and no package/image downloads.
            $r=$engine->exec->run(['/usr/bin/curl','--fail','--silent','--show-error','--proto','=https',
                '--connect-timeout','10','--max-time','25','--max-filesize','4194304',
                '--output',$temp,'--write-out','%{http_code}',self::SOURCE],30);
            if($r['code']!==0 || trim($r['out'])!=='200')
                throw new Blocked('Intel release information could not be retrieved. Last successful results are retained; latest firmware status is unknown.');
            $latest=self::parse((string)file_get_contents($temp));
            $result['status']=self::classify($latest,$engine->catalog->package());
            $result['latest']=$latest;$result['checked_at']=$result['attempted_at'];
            $result['catalog_version']=$engine->catalog->package()['version'];unset($result['error']);
            $seen=$result['releases']??[];$known=false;
            foreach($seen as $item)if($item['package']===$latest)$known=true;
            if(!$known)$seen[]=['first_seen_at'=>$result['checked_at'],'package'=>$latest];
            $result['releases']=array_slice($seen,-20);
            $engine->persistent->put('intel-release',$result);
        } catch(\Throwable $e) {
            $result['status']='unknown';$result['error']=$e->getMessage();
            $engine->persistent->put('intel-release',$result);throw $e;
        } finally {if($temp!==false && is_file($temp))unlink($temp);}
    }
}
