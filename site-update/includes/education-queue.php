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
function eq_batch_revision(array $articles): string { if(function_exists('education_english_translation'))foreach($articles as &$a)$a['en']=education_english_translation($a);unset($a);return hash('sha256',json_encode($articles,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)); }
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
function eq_decode_feed(string $body): array {
    if(strlen($body)>25000000) throw new RuntimeException('حجم بسته از حد مجاز بیشتر است.');
    $feed=json_decode($body,true,512,JSON_THROW_ON_ERROR);
    if(!is_array($feed)) throw new RuntimeException('ساختار بسته معتبر نیست.');
    return $feed;
}
function eq_fetch_feed(string $url): array {
    $body='';$tooLarge=false;$ch=curl_init($url);
    if($ch===false) return [null,'آماده‌سازی اتصال ممکن نشد'];
    curl_setopt_array($ch,[
        CURLOPT_TIMEOUT=>12,CURLOPT_CONNECTTIMEOUT=>4,
        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_ENCODING=>'',CURLOPT_USERAGENT=>'DrPedramAmiri-WeeklyInbox/7.4.10',
        CURLOPT_HTTPHEADER=>['Accept: application/vnd.github.raw+json'],
        CURLOPT_WRITEFUNCTION=>static function($ch,$chunk)use(&$body,&$tooLarge){
            if(strlen($body)+strlen($chunk)>25000000){$tooLarge=true;return 0;}
            $body.=$chunk;return strlen($chunk);
        }
    ]);
    $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    $errno=curl_errno($ch);$detail=curl_error($ch);curl_close($ch);
    if($tooLarge) return [null,'حجم پاسخ بیش از ۲۵ مگابایت است'];
    if($ok===false || $status!==200){
        error_log('Weekly inbox download failed: '.$url.' HTTP '.$status.' cURL '.$errno.' '.$detail);
        if($errno===28) $reason='مهلت اتصال تمام شد';
        elseif($errno===6) $reason='نام سرور پیدا نشد';
        elseif(in_array($errno,[35,51,60,77],true)) $reason='خطای اتصال امن هاست';
        elseif($errno) $reason='خطای شبکه '.$errno;
        else $reason='پاسخ HTTP '.$status;
        return [null,$reason];
    }
    try{return [eq_decode_feed($body),null];}
    catch(Throwable $e){error_log('Weekly inbox response invalid: '.$e->getMessage());return [null,'پاسخ دریافتی JSON معتبر نیست'];}
}
function eq_sync(): string {
    // An optional uploaded snapshot uses exactly the same validation/draft rules.
    // Imported batch IDs remain immutable; local edits and approvals are retained.
    $local=__DIR__.'/education-inbox.json';$localError='';
    if(is_file($local)){
        try{
            $size=filesize($local);
            if($size===false || $size>25000000) throw new RuntimeException('حجم فایل بسته مجاز نیست.');
            $body=file_get_contents($local);
            if($body===false) throw new RuntimeException('فایل بسته قابل خواندن نیست.');
            $n=eq_import(eq_decode_feed($body));
            if($n) return $n.' بسته جدید از فایل روی هاست برای بررسی دریافت شد؛ انتشار نیاز به تأیید دارد.';
        }catch(Throwable $e){$localError=' فایل روی هاست: '.$e->getMessage();}
    }
    if(!function_exists('curl_init')) return 'افزونه cURL روی هاست فعال نیست؛ برای دریافت آفلاین فایل education-inbox.json را در پوشه includes قرار بده.'.$localError;
    $errors=[];
    foreach([
        'https://raw.githubusercontent.com/Ipedrama/pedram-dental-education/main/inbox.json',
        'https://api.github.com/repos/Ipedrama/pedram-dental-education/contents/inbox.json?ref=main'
    ] as $url){
        [$feed,$error]=eq_fetch_feed($url);
        if($feed===null){$errors[]=$error;continue;}
        try{
            $n=eq_import($feed);
            return $n ? 'بسته جدید برای تأیید دریافت شد.' : 'بسته تازه‌ای برای دریافت نیست.';
        }catch(Throwable $e){return 'بسته دریافت نشد: '.$e->getMessage();}
    }
    return 'اتصال هاست به گیت‌هاب انجام نشد: '.implode('؛ ',array_unique($errors)).'. مطالب قبلی محفوظ هستند.'.$localError;
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
        foreach($records as $a)if(empty($a['deleted_at']) && ($a['status']??'published')==='published')$days[substr($a['publish_at']??$a['date'],0,10)]=true;
        $edits=eq_read('overrides');
        foreach($articles as $i=>$a){
            if(!empty($a['deleted_at']))throw new RuntimeException('یکی از مطالب بسته در سطل زباله است؛ ابتدا آن را بازیابی کن.');
            if(($a['status']??'draft')!=='draft')throw new RuntimeException('این بسته قبلاً زمان‌بندی شده یا بعضی مطالب آن منتشر شده‌اند.');
            $when=$date->modify('+'.$i.' days');
            if(isset($days[$when->format('Y-m-d')]))throw new RuntimeException('در یکی از روزهای انتخابی مطلب دیگری برنامه‌ریزی شده؛ روز شروع را تغییر بده.');
            $a['status']='published';$a['publish_at']=$when->format('Y-m-d\TH:i');$a['date']=$when->format('Y-m-d');
            $a['display_date']=pa_persian_date($when->format('Y-m-d'));
            if(class_exists('IntlDateFormatter')){$formatter=new IntlDateFormatter('fa_IR@calendar=persian',IntlDateFormatter::LONG,IntlDateFormatter::NONE,'Asia/Tehran',IntlDateFormatter::TRADITIONAL,'d MMMM yyyy');$a['display_date']=$formatter->format($when);}
            $a['approved_at']=date(DATE_ATOM);$edits[$a['slug']]=$a;
        }
        eq_write('overrides',$edits);
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}

function eq_publish_one(string $slug,string $revision,string $mode,string $value): void {
    $lock=fopen(dirname(__DIR__).'/admin/education-overrides.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('صف در دسترس نیست.');
    try{$records=eq_records();$a=$records[$slug]??null;
        if(!$a || !empty($a['deleted_at']) || ($a['status']??'published')!=='draft')throw new RuntimeException('فقط پیش‌نویس فعال قابل تأیید است.');
        if(!hash_equals(eq_batch_revision([$a]),$revision))throw new RuntimeException('مطلب تغییر کرده؛ دوباره آن را بررسی کن.');
        $tz=new DateTimeZone('Asia/Tehran');$now=new DateTimeImmutable('now',$tz);
        if($mode==='now')$date=$now;elseif($mode==='schedule'){$iso=pa_parse_persian_datetime($value);$date=new DateTimeImmutable($iso,$tz);if($date<=$now)throw new RuntimeException('زمان انتخابی باید در آینده باشد.');}else throw new RuntimeException('روش انتشار نامعتبر است.');
        $a['status']='published';$a['publish_at']=$date->format('Y-m-d\TH:i');$a['date']=$date->format('Y-m-d');$a['display_date']=pa_persian_date($a['date']);$a['approved_at']=$now->format(DATE_ATOM);
        $edits=eq_read('overrides');$edits[$slug]=$a;eq_write('overrides',$edits);
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
