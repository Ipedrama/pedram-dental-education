<?php
declare(strict_types=1);
function eq_read(string $name): array {
    $path=dirname(__DIR__).'/admin/education-'.$name.'.json';
    if(!is_file($path)) return [];
    $data=json_decode((string)file_get_contents($path),true);
    if(!is_array($data)) throw new RuntimeException('فایل صف قابل خواندن نیست.');
    return $data;
}
function eq_write(string $name,array $data): void {
    $path=dirname(__DIR__).'/admin/education-'.$name.'.json';
    $tmp=tempnam(dirname($path),'education-queue-tmp-');
    try {
        if(!$tmp || file_put_contents($tmp,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),LOCK_EX)===false || !rename($tmp,$path)) throw new RuntimeException('ذخیره صف ممکن نشد.');
    } finally { if($tmp && is_file($tmp)) unlink($tmp); }
}
function eq_records(): array {
    $base=json_decode((string)file_get_contents(dirname(__DIR__).'/data/education.json'),true);
    $records=[];
    foreach(($base['articles']??[]) as $a) $records[$a['slug']]=$a;
    foreach(eq_read('queue') as $batch) foreach($batch['articles'] as $a) if(!isset($records[$a['slug']])) $records[$a['slug']]=$a;
    foreach(eq_read('overrides') as $slug=>$a) if(isset($records[$slug]) && pa_education_valid(['articles'=>[$a]])) $records[$slug]=$a;
    return $records;
}
function eq_batch_revision(array $articles): string { return hash('sha256',json_encode($articles,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)); }
function eq_import(array $feed): int {
    if(($feed['schema_version']??null)!==1 || !isset($feed['batches']) || !is_array($feed['batches']) || count($feed['batches'])>52) throw new RuntimeException('ساختار بسته معتبر نیست.');
    $lock=fopen(dirname(__DIR__).'/admin/education-overrides.lock','c');
    if(!$lock || !flock($lock,LOCK_EX)) throw new RuntimeException('صف در دسترس نیست.');
    try {
        $queue=eq_read('queue'); $records=eq_records(); $added=0;
        foreach($feed['batches'] as $batch) {
            $id=$batch['id']??'';
            if(!is_string($id) || !preg_match('/^week-\d{4}-\d{2}-\d{2}$/D',$id)) throw new RuntimeException('شناسه بسته نامعتبر است.');
            if(isset($queue[$id])) continue; // Immutable once imported; manual edits remain authoritative.
            $articles=$batch['articles']??[];
            if(!is_array($articles) || !in_array(count($articles),[7,8],true) || !pa_education_valid(['articles'=>$articles])) throw new RuntimeException('هر بسته باید ۷ یا ۸ مطلب کامل و معتبر داشته باشد.');
            foreach($articles as &$a) {
                if(isset($records[$a['slug']])) throw new RuntimeException('شناسه تکراری در بسته وجود دارد.');
                $figures=[$a['cover']]; foreach($a['sections'] as $section) if(isset($section['figure'])) $figures[]=$section['figure'];
                foreach($figures as $figure) {
                    if(!str_starts_with($figure['src'],'data:image/') || empty($figure['credit']) || !pa_education_credit_valid($figure)) throw new RuntimeException('عکس واقعی با منبع و مجوز باید داخل بسته باشد.');
                    $bytes=base64_decode(explode(',',$figure['src'],2)[1],true);
                    if($bytes===false || !@getimagesizefromstring($bytes)) throw new RuntimeException('تصویر بسته قابل خواندن نیست.');
                }
                $a['status']='draft'; unset($a['publish_at']); $a['batch_id']=$id; $records[$a['slug']]=$a;
            } unset($a);
            $queue[$id]=['id'=>$id,'articles'=>$articles,'imported_at'=>date(DATE_ATOM)];$added++;
        }
        if($added) eq_write('queue',$queue);
        return $added;
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}
function eq_sync(): string {
    if(!function_exists('curl_init')) return 'افزونه cURL روی هاست فعال نیست.';
    $body='';$ch=curl_init('https://raw.githubusercontent.com/Ipedrama/pedram-dental-education/main/inbox.json');
    curl_setopt_array($ch,[CURLOPT_TIMEOUT=>15,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_WRITEFUNCTION=>static function($ch,$chunk)use(&$body){if(strlen($body)+strlen($chunk)>25000000)return 0;$body.=$chunk;return strlen($chunk);}]);
    $ok=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    if(!$ok || $status!==200) return 'دریافت بسته از گیت‌هاب انجام نشد؛ مطالب قبلی محفوظ هستند.';
    try { $feed=json_decode($body,true,512,JSON_THROW_ON_ERROR);$n=eq_import($feed);return $n ? 'بسته جدید برای تأیید دریافت شد.' : 'بسته تازه‌ای برای دریافت نیست.'; }
    catch(Throwable $e){return 'بسته دریافت نشد: '.$e->getMessage();}
}
function eq_approve(string $id,string $revision,string $start): void {
    $lock=fopen(dirname(__DIR__).'/admin/education-overrides.lock','c');
    if(!$lock || !flock($lock,LOCK_EX)) throw new RuntimeException('صف در دسترس نیست.');
    try {
        $queue=eq_read('queue');if(!isset($queue[$id]))throw new RuntimeException('بسته پیدا نشد.');
        $records=eq_records();$articles=[];
        foreach($queue[$id]['articles'] as $a)$articles[]=$records[$a['slug']];
        if(!hash_equals(eq_batch_revision($articles),$revision))throw new RuntimeException('بسته تغییر کرده؛ دوباره آن را بررسی کن.');
        $tz=new DateTimeZone('Asia/Tehran');$date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$start,$tz);
        if(!$date || $date->format('Y-m-d\TH:i')!==$start || $date<new DateTimeImmutable('now',$tz))throw new RuntimeException('شروع انتشار باید در آینده باشد.');
        $days=[];
        foreach($records as $a)if(($a['status']??'published')==='published')$days[substr($a['publish_at']??$a['date'],0,10)]=true;
        $edits=eq_read('overrides');
        foreach($articles as $i=>$a){
            if(($a['status']??'draft')!=='draft')throw new RuntimeException('این بسته قبلاً زمان‌بندی شده یا بعضی مطالب آن منتشر شده‌اند.');
            $when=$date->modify('+'.$i.' days');
            if(isset($days[$when->format('Y-m-d')]))throw new RuntimeException('در یکی از روزهای انتخابی مطلب دیگری برنامه‌ریزی شده؛ روز شروع را تغییر بده.');
            $a['status']='published';$a['publish_at']=$when->format('Y-m-d\TH:i');$a['date']=$when->format('Y-m-d');
            $a['display_date']=$when->format('Y/m/d');
            if(class_exists('IntlDateFormatter')){$formatter=new IntlDateFormatter('fa_IR@calendar=persian',IntlDateFormatter::LONG,IntlDateFormatter::NONE,'Asia/Tehran',IntlDateFormatter::TRADITIONAL,'d MMMM yyyy');$a['display_date']=$formatter->format($when);}
            $a['approved_at']=date(DATE_ATOM);$edits[$a['slug']]=$a;
        }
        eq_write('overrides',$edits);
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}
