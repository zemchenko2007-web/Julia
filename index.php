<?php
require_once __DIR__.'/../config.php';
$page = $_GET['page'] ?? 'catalog';
$u = current_user();
$notice = $_SESSION['notice'] ?? ''; unset($_SESSION['notice']);
function go(string $page): never { header('Location: ?page='.$page); exit; }
if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action==='register') {
        $name=trim($_POST['full_name']??''); $email=strtolower(trim($_POST['email']??'')); $pass=$_POST['password']??'';
        if ($name==='' || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($pass)<8) { $_SESSION['notice']='Проверьте поля: пароль должен быть не короче 8 символов.'; go('register'); }
        try {
            $st=db()->prepare('INSERT INTO users(full_name,email,password_hash) VALUES(?,?,?)');
            $st->execute([$name,$email,password_hash($pass,PASSWORD_DEFAULT)]);
            $_SESSION['user']=['id'=>(int)db()->lastInsertId(),'full_name'=>$name,'email'=>$email,'role'=>'reader'];
            session_regenerate_id(true); go('catalog');
        } catch (PDOException $e) { $_SESSION['notice']='Этот email уже зарегистрирован.'; go('register'); }
    }
    if ($action==='login') {
        $st=db()->prepare('SELECT * FROM users WHERE email=?'); $st->execute([strtolower(trim($_POST['email']??''))]); $user=$st->fetch();
        if ($user && password_verify($_POST['password']??'', $user['password_hash'])) {
            session_regenerate_id(true); $_SESSION['user']=['id'=>(int)$user['id'],'full_name'=>$user['full_name'],'email'=>$user['email'],'role'=>$user['role']]; go('catalog');
        }
        $_SESSION['notice']='Неверный email или пароль.'; go('login');
    }
    if ($action==='logout') { $_SESSION=[]; session_destroy(); header('Location: ?page=login'); exit; }
    if ($action==='request_book') {
        require_login(); $book=(int)($_POST['book_id']??0);
        $st=db()->prepare("SELECT id FROM books WHERE id=?"); $st->execute([$book]);
        if ($st->fetch()) {
            $st=db()->prepare("SELECT id FROM loans WHERE book_id=? AND returned_at IS NULL"); $st->execute([$book]);
            if (!$st->fetch()) {
                $st=db()->prepare("INSERT INTO requests(user_id,book_id) VALUES(?,?)"); $st->execute([$u['id'],$book]);
                $_SESSION['notice']='Заявка отправлена администратору.'; 
            } else $_SESSION['notice']='Эта книга уже выдана.';
        }
        go('catalog');
    }
    if ($action==='add_book') {
        require_admin();
        $st=db()->prepare('INSERT INTO books(title,author,genre,isbn,description) VALUES(?,?,?,?,?)');
        $st->execute([trim($_POST['title']),trim($_POST['author']),trim($_POST['genre']),trim($_POST['isbn'])?:null,trim($_POST['description'])]);
        $_SESSION['notice']='Книга добавлена.'; go('admin');
    }
    if ($action==='delete_book') {
        require_admin(); $id=(int)$_POST['book_id'];
        $st=db()->prepare('DELETE FROM books WHERE id=?'); 
        try {$st->execute([$id]); $_SESSION['notice']='Книга удалена.';} catch(PDOException $e) {$_SESSION['notice']='Нельзя удалить книгу с историей выдач.';}
        go('admin');
    }
    if ($action==='approve_request') {
        require_admin(); $rid=(int)$_POST['request_id'];
        $st=db()->prepare("SELECT r.*, b.id AS bid FROM requests r JOIN books b ON b.id=r.book_id WHERE r.id=? AND r.status='pending'"); $st->execute([$rid]); $r=$st->fetch();
        if ($r) {
            $check=db()->prepare('SELECT id FROM loans WHERE book_id=? AND returned_at IS NULL'); $check->execute([$r['bid']]);
            if (!$check->fetch()) {
                db()->beginTransaction();
                db()->prepare("UPDATE requests SET status='approved' WHERE id=?")->execute([$rid]);
                db()->prepare("INSERT INTO loans(user_id,book_id,request_id,issued_at,due_at) VALUES(?,?,?,?,DATE_ADD(CURDATE(), INTERVAL 14 DAY))")->execute([$r['user_id'],$r['bid'],$rid,date('Y-m-d')]);
                db()->commit(); $_SESSION['notice']='Книга выдана на 14 дней.';
            } else $_SESSION['notice']='Книга уже выдана другому читателю.';
        }
        go('admin');
    }
    if ($action==='return_loan') {
        require_admin(); $id=(int)$_POST['loan_id'];
        db()->prepare("UPDATE loans SET returned_at=CURDATE(),status='returned' WHERE id=? AND returned_at IS NULL")->execute([$id]);
        $_SESSION['notice']='Возврат зарегистрирован.'; go('admin');
    }
    if ($action==='extend_loan') {
        require_admin(); $id=(int)$_POST['loan_id']; $days=max(1,min(30,(int)($_POST['days']??14)));
        db()->prepare("UPDATE loans SET due_at=DATE_ADD(due_at, INTERVAL ? DAY),status='issued' WHERE id=? AND returned_at IS NULL")->execute([$days,$id]);
        $_SESSION['notice']='Срок продлён.'; go('admin');
    }
}
$allowed=['catalog','login','register','mybooks','admin']; if(!in_array($page,$allowed,true)) $page='catalog';
if($page==='admin') require_admin();
if($page==='mybooks') require_login();
$q=trim($_GET['q']??'');
if($page==='catalog') {
    $sql='SELECT b.*, CASE WHEN EXISTS(SELECT 1 FROM loans l WHERE l.book_id=b.id AND l.returned_at IS NULL) THEN 0 ELSE 1 END AS available FROM books b';
    $params=[];
    if($q!=='') { $sql.=' WHERE b.title LIKE ? OR b.author LIKE ? OR b.genre LIKE ?'; $like='%'.$q.'%'; $params=[$like,$like,$like]; }
    $sql.=' ORDER BY b.title'; $st=db()->prepare($sql); $st->execute($params); $books=$st->fetchAll();
}
if($page==='mybooks') { $st=db()->prepare('SELECT l.*,b.title,b.author FROM loans l JOIN books b ON b.id=l.book_id WHERE l.user_id=? ORDER BY l.returned_at IS NULL DESC,l.due_at'); $st->execute([$u['id']]); $mybooks=$st->fetchAll(); }
if($page==='admin') {
    $requests=db()->query("SELECT r.id,r.created_at,u.full_name,u.email,b.title,b.author FROM requests r JOIN users u ON u.id=r.user_id JOIN books b ON b.id=r.book_id WHERE r.status='pending' ORDER BY r.created_at")->fetchAll();
    $loans=db()->query("SELECT l.id,l.due_at,l.returned_at,l.status,u.full_name,b.title,b.author FROM loans l JOIN users u ON u.id=l.user_id JOIN books b ON b.id=l.book_id WHERE l.returned_at IS NULL ORDER BY l.due_at")->fetchAll();
    $counts=db()->query("SELECT (SELECT COUNT(*) FROM books) books,(SELECT COUNT(*) FROM loans WHERE returned_at IS NULL) issued,(SELECT COUNT(*) FROM loans WHERE returned_at IS NULL AND due_at<CURDATE()) overdue")->fetch();
}
?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Библиотека</title><link rel="stylesheet" href="assets/style.css"></head><body>
<header class="top"><a class="brand" href="?page=catalog"><span class="logo">▤</span><span><b>Библиотека</b><small>Система управления</small></span></a><nav><a href="?page=catalog">Каталог</a><a href="?page=catalog#search">Поиск</a><?php if($u):?><a href="?page=mybooks">Мои книги</a><?php if($u['role']==='admin'):?><a href="?page=admin">Управление</a><?php endif;?><form method="post" class="inline"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="logout"><button class="quiet">Выйти</button></form><?php else:?><a href="?page=login">Войти</a><a class="navbutton" href="?page=register">Регистрация</a><?php endif;?></nav></header>
<main class="wrap"><?php if($notice):?><div class="notice"><?=h($notice)?></div><?php endif;?>
<?php if($page==='login'||$page==='register'):?><section class="auth card"><span class="eyebrow">ИС «Библиотека»</span><h1><?=$page==='login'?'Вход в библиотеку':'Создание аккаунта'?></h1><p class="muted"><?=$page==='login'?'Войдите, чтобы управлять своими книгами.':'Зарегистрируйтесь как читатель.'?></p><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="<?=$page==='login'?'login':'register'?>"><?php if($page==='register'):?><label>ФИО<input name="full_name" required maxlength="150" autocomplete="name"></label><?php endif;?><label>Email<input type="email" name="email" required autocomplete="email"></label><label>Пароль<input type="password" name="password" required minlength="8" autocomplete="<?=$page==='login'?'current-password':'new-password'?>"></label><button class="primary full"><?=$page==='login'?'Войти':'Зарегистрироваться'?></button></form><p class="muted"><?=$page==='login'?'Нет аккаунта?':'Уже есть аккаунт?'?> <a href="?page=<?=$page==='login'?'register':'login'?>"><?=$page==='login'?'Зарегистрироваться':'Войти'?></a></p></section>
<?php elseif($page==='catalog'):?><section class="heading"><div><span class="eyebrow">КНИЖНЫЙ ФОНД</span><h1>Каталог книг</h1><p class="muted">Найдите следующую книгу для чтения</p></div></section><form id="search" class="search" method="get"><input type="hidden" name="page" value="catalog"><input name="q" value="<?=h($q)?>" placeholder="Название книги, автор или жанр…"><button class="primary">Найти книги</button></form><div class="grid"><?php foreach($books as $b):?><article class="book card"><div class="cover">▤</div><span class="pill <?=$b['available']?'good':'neutral'?>"><?=$b['available']?'Доступна':'Выдана'?></span><h2><?=h($b['title'])?></h2><p><?=h($b['author'])?></p><span class="genre"><?=h($b['genre'])?></span><div class="bookbottom"><?php if($b['available']&&$u):?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="request_book"><input type="hidden" name="book_id" value="<?=$b['id']?>"><button class="primary full">Запросить книгу</button></form><?php elseif(!$u):?><a class="primary full buttonlink" href="?page=login">Войти для выдачи</a><?php else:?><button class="quiet full" disabled>Недоступна</button><?php endif;?></div></article><?php endforeach;?></div><?php if(!$books):?><p class="empty">Ничего не найдено. Попробуйте другой запрос.</p><?php endif;?>
<?php elseif($page==='mybooks'):?><section class="heading"><div><span class="eyebrow">ЛИЧНЫЙ КАБИНЕТ</span><h1>Мои книги</h1><p class="muted">Текущие выдачи и сроки возврата</p></div></section><?php if(!$mybooks):?><div class="card empty">У вас пока нет выданных книг.</div><?php endif;?><?php foreach($mybooks as $b):?><article class="loan card"><div class="cover small">▤</div><div class="loaninfo"><h2><?=h($b['title'])?></h2><p><?=h($b['author'])?></p><small>Выдана: <?=h($b['issued_at'])?> · Вернуть до: <?=h($b['due_at'])?></small></div><span class="pill <?=$b['returned_at']?'neutral':($b['due_at']<date('Y-m-d')?'bad':'good')?>"><?=$b['returned_at']?'Возвращена':($b['due_at']<date('Y-m-d')?'Просрочено':'В срок')?></span></article><?php endforeach;?>
<?php elseif($page==='admin'):?><section class="heading"><div><span class="eyebrow">ПАНЕЛЬ АДМИНИСТРАТОРА</span><h1>Управление библиотекой</h1><p class="muted">Книги, заявки и текущие выдачи</p></div></section><div class="stats"><div class="card"><small>Всего книг</small><strong><?=$counts['books']?></strong></div><div class="card"><small>Выдано</small><strong><?=$counts['issued']?></strong></div><div class="card"><small>Просрочено</small><strong class="red"><?=$counts['overdue']?></strong></div></div><section class="card adminblock"><h2>Добавить книгу</h2><form method="post" class="addform"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="add_book"><input name="title" placeholder="Название" required><input name="author" placeholder="Автор" required><input name="genre" placeholder="Жанр"><input name="isbn" placeholder="ISBN"><input name="description" placeholder="Описание"><button class="primary">Добавить</button></form></section><section class="card adminblock"><h2>Заявки на выдачу</h2><?php if(!$requests):?><p class="muted">Новых заявок нет.</p><?php endif;?><?php foreach($requests as $r):?><div class="adminrow"><div><b><?=h($r['full_name'])?></b><p><?=h($r['title'])?> · <?=h($r['author'])?></p></div><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="approve_request"><input type="hidden" name="request_id" value="<?=$r['id']?>"><button class="primary">Выдать на 14 дней</button></form></div><?php endforeach;?></section><section class="card adminblock"><h2>Текущие выдачи</h2><?php foreach($loans as $l):?><div class="adminrow"><div><b><?=h($l['full_name'])?></b><p><?=h($l['title'])?> · до <?=h($l['due_at'])?></p></div><div class="actions"><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="extend_loan"><input type="hidden" name="loan_id" value="<?=$l['id']?>"><input type="number" name="days" min="1" max="30" value="14" aria-label="Дней"><button class="quiet">Продлить</button></form><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="return_loan"><input type="hidden" name="loan_id" value="<?=$l['id']?>"><button class="primary green">Принять</button></form></div></div><?php endforeach;?></section><section class="card adminblock"><h2>Удаление книги</h2><p class="muted">Удаление доступно только для книг без истории выдач.</p><div class="grid compact"><?php foreach(db()->query('SELECT id,title,author FROM books ORDER BY title')->fetchAll() as $b):?><div class="deleteitem"><span><?=h($b['title'])?></span><form method="post" onsubmit="return confirm('Удалить книгу?')"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="delete_book"><input type="hidden" name="book_id" value="<?=$b['id']?>"><button class="danger">Удалить</button></form></div><?php endforeach;?></div></section><?php endif;?></main><footer>© <?=date('Y')?> Библиотека · Учебный проект</footer></body></html>
