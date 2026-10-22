<?php
declare(strict_types=1);
if (!function_exists('admin_logged_in')) { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/education.php';
require_once dirname(__DIR__) . '/includes/education-queue.php';
function education_admin_records(): array { return eq_records(); }
function education_admin_revision(array $a): string { return hash('sha256', serialize($a)); }
function education_admin_save(string $action): void {
    if($action==='education_approve') {
        try { eq_approve((string)($_POST['batch_id']??''),(string)($_POST['batch_revision']??''),(string)($_POST['start_at']??'')); admin_flash('success','بسته تأیید شد؛ هر روز یک مطلب نمایش داده می‌شود.'); }
        catch(Throwable $e){admin_flash('error',$e->getMessage());} return;
    }
    if($action==='education_sync'){admin_flash('success',eq_sync());return;}

    $lock = fopen(__DIR__.'/education-overrides.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) { admin_flash('error','ذخیره ممکن نشد.'); return; }
    try {
        $records = education_admin_records();
        $slug = (string)($_POST['slug'] ?? '');
        if (!isset($records[$slug])) throw new RuntimeException('مطلب پیدا نشد.');
        $a = $records[$slug];
        if (!hash_equals(education_admin_revision($a), (string)($_POST['revision'] ?? ''))) throw new RuntimeException('این مطلب در صفحه دیگری تغییر کرده؛ صفحه را تازه کن و دوباره ویرایش کن.');
        foreach (['title','excerpt','category','takeaway','display_date'] as $field) $a[$field] = trim((string)($_POST[$field] ?? ''));
        $status = (string)($_POST['status'] ?? 'draft');
        if (!in_array($status, ['draft','published'],true)) throw new RuntimeException('وضعیت نامعتبر است.');
        $date = (string)($_POST['publish_at'] ?? '');
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$date,new DateTimeZone('Asia/Tehran'));
        if (!$parsed || $parsed->format('Y-m-d\TH:i') !== $date) throw new RuntimeException('زمان انتشار نامعتبر است.');
        $a['status']=$status; $a['publish_at']=$date; $a['date']=substr($date,0,10);
        foreach ($a['sections'] as $i=>&$section) {
            $section['heading']=trim((string)($_POST['heading'][$i] ?? ''));
            $section['paragraphs']=array_values(array_filter(array_map('trim',preg_split('/\R\s*\R/u',(string)($_POST['paragraphs'][$i] ?? ''))),static fn($v)=>$v!==''));
        }
        unset($section);
        $a['cover']['alt']=trim((string)($_POST['cover_alt'] ?? ''));
        $a['cover']['caption']=trim((string)($_POST['cover_caption'] ?? ''));
        $file=$_FILES['cover_photo'] ?? null;
        $created=null;
        if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE) {
            if ($file['error']!==UPLOAD_ERR_OK || $file['size']>8000000 || !is_uploaded_file($file['tmp_name'])) throw new RuntimeException('عکس باید سالم و کمتر از ۸ مگابایت باشد.');
            $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
            $types=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
            $dims=getimagesize($file['tmp_name']);
            if (!isset($types[$mime]) || !$dims || $dims[0]>4096 || $dims[1]>4096) throw new RuntimeException('عکس JPEG، PNG یا WebP با ابعاد حداکثر ۴۰۹۶ پیکسل انتخاب کن.');
            $src='assets/images/education-upload-'.bin2hex(random_bytes(12)).'.'.$types[$mime];
            $created=dirname(__DIR__).'/'.$src;
            if (!move_uploaded_file($file['tmp_name'],$created)) throw new RuntimeException('ذخیره عکس انجام نشد.');
            $a['cover']['src']=$src; $a['cover']['width']=$dims[0]; $a['cover']['height']=$dims[1];
            unset($a['cover']['credit']);
        }
        $a['manually_edited']=true;
        if ($a['title']==='' || !pa_education_valid(['articles'=>[$a]])) { if($created) @unlink($created); throw new RuntimeException('متن یا ساختار مطلب معتبر نیست.'); }
        $path=__DIR__.'/education-overrides.json';
        $raw=is_file($path)?file_get_contents($path):'{}';
        $edits=json_decode($raw,true);
        if (!is_array($edits)) throw new RuntimeException('فایل ویرایش‌ها قابل خواندن نیست؛ ذخیره متوقف شد.');
        $edits[$slug]=$a;
        $tmp=tempnam(__DIR__,'education-overrides.tmp');
        if ($tmp===false || file_put_contents($tmp,json_encode($edits,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),LOCK_EX)===false || !rename($tmp,$path)) throw new RuntimeException('ذخیره انجام نشد.');
        admin_flash('success','مطلب ذخیره شد. زمان انتشار به وقت تهران است.');
    } catch (Throwable $e) { admin_flash('error',$e instanceof RuntimeException ? $e->getMessage() : 'ذخیره انجام نشد.'); }
    finally { flock($lock,LOCK_UN); fclose($lock); }
}
function education_admin_render(): void {
    $syncMessage='';
    if(time()-(int)($_SESSION['education_sync_at']??0)>300){$syncMessage=eq_sync();$_SESSION['education_sync_at']=time();}
    $records=education_admin_records();
    education_queue_render($records,$syncMessage); ?>
<section class="card"><h2>مطالب آموزشی</h2><p>متن و عکس را ویرایش کن؛ با انتخاب «آماده انتشار»، مطلب از زمان تعیین‌شده روی سایت دیده می‌شود. ساعت‌ها به وقت تهران هستند.</p><p>مطالب بسته‌های هفتگی ابتدا پیش‌نویس هستند؛ فقط بعد از تأیید تو منتشر می‌شوند.</p>
<?php foreach($records as $a): ?>
<details class="education-editor"><summary><?= admin_h($a['title']) ?> — <?= ($a['status']??'published')==='draft'?'پیش‌نویس':'آماده انتشار' ?></summary>
<form method="post" enctype="multipart/form-data" class="form-grid">
<input type="hidden" name="csrf" value="<?= admin_h(admin_csrf()) ?>"><input type="hidden" name="action" value="education_save"><input type="hidden" name="slug" value="<?= admin_h($a['slug']) ?>"><input type="hidden" name="revision" value="<?= education_admin_revision($a) ?>">
<?php foreach(['title'=>'عنوان','excerpt'=>'خلاصه','category'=>'موضوع','takeaway'=>'نکته اصلی','display_date'=>'تاریخ نمایشی فارسی'] as $key=>$label): ?><label><?= $label ?><textarea name="<?= $key ?>" required><?= admin_h($a[$key]) ?></textarea></label><?php endforeach; ?>
<label>وضعیت<select name="status"><option value="draft" <?= ($a['status']??'published')==='draft'?'selected':'' ?>>پیش‌نویس</option><option value="published" <?= ($a['status']??'published')!=='draft'?'selected':'' ?>>آماده انتشار</option></select></label>
<label>زمان انتشار به وقت تهران<input type="datetime-local" name="publish_at" required value="<?= admin_h($a['publish_at']??$a['date'].'T09:00') ?>"></label>
<img class="education-admin-cover" src="<?= admin_h(pa_education_image($a['cover']['src'])) ?>" alt="<?= admin_h($a['cover']['alt']) ?>">
<label>تعویض عکس اصلی<input type="file" name="cover_photo" accept="image/jpeg,image/png,image/webp"></label><p>عکس واقعی با اجازه استفاده انتخاب کن. با تعویض عکس، انتساب عکس قبلی حذف می‌شود؛ منبع عکس جدید را در توضیح بنویس.</p>
<label>شرح عکس<input name="cover_alt" value="<?= admin_h($a['cover']['alt']) ?>"></label><label>توضیح و منبع عکس<textarea name="cover_caption"><?= admin_h($a['cover']['caption']) ?></textarea></label>
<?php foreach($a['sections'] as $i=>$section): ?><label>عنوان بخش <?= $i+1 ?><input name="heading[<?= $i ?>]" value="<?= admin_h($section['heading']) ?>"></label><label>متن بخش <?= $i+1 ?> — بین پاراگراف‌ها یک خط خالی بگذار<textarea rows="7" name="paragraphs[<?= $i ?>]"><?= admin_h(implode("\n\n",$section['paragraphs'])) ?></textarea></label><?php endforeach; ?>
<button class="primary" type="submit">ذخیره تغییرات</button><a href="<?= admin_h(pa_education_url($a)) ?>" target="_blank" rel="noopener">مشاهده صفحه عمومی مطلب</a>
</form></details><?php endforeach; ?></section>
<?php }

function education_queue_render(array $records,string $message): void { ?>
<section class="card"><h2>بسته هفتگی آموزش‌ها</h2><p><?= admin_h($message) ?></p>
<form method="post"><input type="hidden" name="csrf" value="<?= admin_h(admin_csrf()) ?>"><input type="hidden" name="action" value="education_sync"><button type="submit">دریافت بسته‌های جدید</button></form>
<?php $batches=eq_read('queue'); if(!$batches): ?><p>هنوز بسته‌ای دریافت نشده است. جمعه‌ها مطالب جدید آماده می‌شوند.</p><?php endif; ?>
<?php foreach(array_reverse($batches) as $batch): $articles=[];$allDraft=true;foreach($batch['articles'] as $a){$a=$records[$a['slug']];$articles[]=$a;if(($a['status']??'draft')!=='draft')$allDraft=false;} ?>
<details class="education-editor"><summary><?= admin_h($batch['id']) ?> — <?= $allDraft?pa_education_number(count($articles)).' مطلب در انتظار تأیید':'زمان‌بندی شده / وضعیت ترکیبی' ?></summary>
<?php foreach($articles as $i=>$a): ?><article><h3><?= $i+1 ?>. <?= admin_h($a['title']) ?></h3><p><?= admin_h($a['excerpt']) ?></p><img class="education-admin-cover" src="<?= admin_h(pa_education_image($a['cover']['src'])) ?>" alt="<?= admin_h($a['cover']['alt']) ?>"><p><?php pa_education_caption($a['cover']); ?></p>
<?php foreach($a['sections'] as $section): ?><h4><?= admin_h($section['heading']) ?></h4><?php foreach($section['paragraphs'] as $paragraph): ?><p><?= admin_h($paragraph) ?></p><?php endforeach; ?><?php if(isset($section['figure'])):$f=$section['figure']; ?><img class="education-admin-cover" src="<?= admin_h(pa_education_image($f['src'])) ?>" alt="<?= admin_h($f['alt']) ?>"><p><?php pa_education_caption($f); ?></p><?php endif; ?><?php endforeach; ?>
<p><?= admin_h($a['takeaway']) ?></p><ul><?php foreach($a['sources'] as $source): ?><li><a href="<?= admin_h($source['url']) ?>" target="_blank" rel="noopener"><?= admin_h($source['title']) ?></a></li><?php endforeach; ?></ul><p>زمان انتشار: <?= admin_h($a['publish_at']??'پس از تأیید') ?></p></article><?php endforeach; ?>
<?php if($allDraft): $start=(new DateTimeImmutable('tomorrow 09:00',new DateTimeZone('Asia/Tehran')))->format('Y-m-d\TH:i'); ?>
<form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?= admin_h(admin_csrf()) ?>"><input type="hidden" name="action" value="education_approve"><input type="hidden" name="batch_id" value="<?= admin_h($batch['id']) ?>"><input type="hidden" name="batch_revision" value="<?= eq_batch_revision($articles) ?>"><label>شروع انتشار به وقت تهران<input type="datetime-local" name="start_at" value="<?= $start ?>" required></label><p>با تأیید، این <?= pa_education_number(count($articles)) ?> مطلب به ترتیب در <?= pa_education_number(count($articles)) ?> روز متوالی منتشر می‌شوند. ویرایش هر مطلب از بخش پایین صفحه در دسترس است.</p><button class="primary" type="submit">تأیید و انتشار طی <?= pa_education_number(count($articles)) ?> روز</button></form>
<?php endif; ?></details><?php endforeach; ?></section>
<?php }
