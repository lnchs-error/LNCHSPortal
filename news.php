<?php
define('NO_SESSION', true);
require __DIR__ . '/config.php';

$per = 10;
$page = max(1, (int)($_GET['p'] ?? 1));
$total = (int)$conn->query("SELECT COUNT(*) c FROM articles")->fetch_assoc()['c'];
$pages = max(1, (int)ceil($total / $per));
$page = min($page, $pages);
$off = ($page - 1) * $per;
$s = $conn->prepare("SELECT category, title, content, author_name, created_at FROM articles ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?");
$s->bind_param("ii", $per, $off); $s->execute();
$articles = $s->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>LNCHS News and Articles</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
<style>
.hdr{background:linear-gradient(rgba(15,23,42,.85),rgba(15,23,42,.85)),url('bg.png') center/cover;color:#fff;padding:34px 20px;text-align:center}
.hdr img{width:56px;height:56px;border-radius:12px;object-fit:cover;margin-bottom:8px}
.hdr h1{font-size:28px;margin-bottom:6px}.hdr p{font-size:13px;color:#94A3B8}
.art-cat{font-size:11px;font-weight:700;color:var(--primary);margin-bottom:6px}
.art-title{font-size:20px;font-weight:700;margin-bottom:8px}
.art-meta{font-size:12px;color:var(--muted);margin-bottom:14px}
.art-body{font-size:14px;line-height:1.7;color:#334155;white-space:pre-line}
.pager{display:flex;justify-content:space-between;font-size:13px;font-weight:600}
.pager a{color:var(--primary);text-decoration:none}
</style>
</head>
<body>
<div class="hdr"><img src="<?php echo e(LOGO_URL); ?>" alt="LNCHS logo"><h1>LNCHS campus news and articles</h1><p>Official updates from Lopez National Comprehensive High School</p></div>
<div class="page">
    <a href="index.html" style="display:inline-block;margin-bottom:20px;color:var(--primary);text-decoration:none;font-weight:600;font-size:13px">&larr; Back to the main website</a>
    <?php if (!$articles): ?>
        <div class="plain muted" style="text-align:center">No articles yet. Check back soon.</div>
    <?php else: foreach ($articles as $a): ?>
        <div class="plain">
            <div class="art-cat"><?php echo e($a['category']); ?></div>
            <div class="art-title"><?php echo e($a['title']); ?></div>
            <div class="art-meta">By <strong><?php echo e($a['author_name']); ?></strong> on <?php echo e(date('M d, Y', strtotime($a['created_at']))); ?></div>
            <div class="art-body"><?php echo e($a['content']); ?></div>
        </div>
    <?php endforeach; endif; ?>
    <?php if ($pages > 1): ?>
        <div class="pager">
            <span><?php if ($page > 1): ?><a href="news.php?p=<?php echo $page - 1; ?>">&larr; Newer</a><?php endif; ?></span>
            <span class="muted">Page <?php echo $page; ?> of <?php echo $pages; ?></span>
            <span><?php if ($page < $pages): ?><a href="news.php?p=<?php echo $page + 1; ?>">Older &rarr;</a><?php endif; ?></span>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
