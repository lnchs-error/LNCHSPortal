<?php
require __DIR__ . '/config.php';
$me = require_staff($conn);
$uid = (int)$me['id'];
const CATS = ['General Announcement', 'Academic Updates', 'Sports & Competitions', 'SSLG & Leadership'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    if ($action === 'publish_article') {
        $title = trim($_POST['title'] ?? ''); $cat = trim($_POST['category'] ?? ''); $content = trim($_POST['content'] ?? '');
        if ($title === '' || $content === '') { flash('error', 'Title and content cannot be empty.'); }
        elseif (mb_strlen($title) > 255) { flash('error', 'Title is too long (255 characters max).'); }
        elseif (!in_array($cat, CATS, true)) { flash('error', 'Choose a category from the list.'); }
        elseif (mb_strlen($content) > 20000) { flash('error', 'Article is too long (20,000 characters max).'); }
        else {
            $s = $conn->prepare("INSERT INTO articles (title, category, content, author_name, author_id) VALUES (?,?,?,?,?)");
            $s->bind_param("ssssi", $title, $cat, $content, $me['full_name'], $uid);
            if ($s->execute()) flash('success', 'Article published.'); else flash('error', 'Could not publish the article.');
        }
    } elseif ($action === 'delete_article') {
        $id = (int)($_POST['id'] ?? 0); $admin = ($me['role'] === 'admin') ? 1 : 0;
        $s = $conn->prepare("DELETE FROM articles WHERE id = ? AND (author_id IS NULL OR author_id = ? OR ? = 1)");
        $s->bind_param("iii", $id, $uid, $admin); $s->execute();
        flash($s->affected_rows ? 'success' : 'error', $s->affected_rows ? 'Article deleted.' : 'You can only delete your own articles.');
    }
    redirect('manage_news.php');
}
$flash = flash();
$articles = $conn->query("SELECT id, title, category, created_at, author_name FROM articles ORDER BY created_at DESC, id DESC LIMIT 100")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Publish article | LNCHS staff</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="page">
    <div class="topbar"><h2>Publish a school article</h2><a href="inter.php" style="font-size:13px;text-decoration:none;color:var(--staff)">&larr; Back to portal</a></div>
    <?php if ($flash): ?><div class="alert <?php echo $flash[0] === 'error' ? 'alert-danger' : 'alert-info'; ?>"><?php echo e($flash[1]); ?></div><?php endif; ?>
    <div class="plain">
        <form method="POST" action="manage_news.php"><?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="publish_article">
            <div class="fg"><label>Article title</label><input type="text" name="title" class="fc" maxlength="255" placeholder="LNCHS wins regional academic competition" required></div>
            <div class="fg"><label>Category</label><select name="category" class="fc"><?php foreach (CATS as $c): ?><option><?php echo e($c); ?></option><?php endforeach; ?></select></div>
            <div class="fg"><label>Article content</label><textarea name="content" class="fc" rows="7" maxlength="20000" placeholder="Write the full article here" required></textarea></div>
            <button type="submit" class="btn btn-staff" style="width:auto;padding:10px 22px;margin-bottom:0">Publish article</button>
        </form>
    </div>
    <h3 style="margin-bottom:12px">Published articles</h3>
    <?php if (!$articles): ?><div class="plain muted">No articles yet.</div><?php endif; ?>
    <?php foreach ($articles as $a): ?>
        <div class="plain" style="display:flex;justify-content:space-between;align-items:center;padding:14px 18px;margin-bottom:12px">
            <div>
                <strong><?php echo e($a['title']); ?></strong>
                <div style="font-size:11px" class="muted"><?php echo e(date('M d, Y', strtotime($a['created_at']))); ?> | <?php echo e($a['category']); ?> | <?php echo e($a['author_name']); ?></div>
            </div>
            <form method="POST" action="manage_news.php" onsubmit="return confirm('Delete this article?')"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete_article"><input type="hidden" name="id" value="<?php echo (int)$a['id']; ?>"><button class="link-del">Delete</button></form>
        </div>
    <?php endforeach; ?>
</div>
</body>
</html>
