<?php
/* sense modular v1 route module extracted from the current public/index.php master. */

if ($route==='forgot-password') {
    if(user()) redirect('dashboard');
    if($_SERVER['REQUEST_METHOD']==='POST'){
        check_csrf();
        $email=strtolower(trim((string)($_POST['email']??'')));
        if(filter_var($email,FILTER_VALIDATE_EMAIL)){
            try{
                $st=db()->prepare('SELECT id,name,email,company_id FROM users WHERE email=? AND status="active" LIMIT 1');
                $st->execute([$email]);
                $usr=$st->fetch();
                if($usr){
                    $token=sense_password_reset_token_create((int)$usr['id']);
                    $link=sense_password_reset_url($token);
                    $subject='Reset your sense password';
                    $body="Hello ".($usr['name']??'').",\n\nWe received a request to reset your sense password.\n\nOpen this link within 30 minutes to set a new password:\n".$link."\n\nIf you did not request this, you can safely ignore this email.\n\n— sense";
                    sense_send_mail((string)$usr['email'],$subject,$body);
                }
            }catch(Throwable $e){
                error_log('v240 forgot-password: '.$e->getMessage());
            }
        }
        flash('success','If an active account exists for that email, a password reset link has been sent.');
        redirect('forgot-password');
    }
    ?>
    <!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Forgot Password · sense</title><link rel="stylesheet" href="<?=e(url('assets/app.css'))?>"></head>
    <body class="auth"><div class="auth-card">
      <div class="auth-brand"><span class="brandmark">SA</span><span>sense</span></div>
      <h1>Forgot password?</h1><p>Enter your account email and we will send you a reset link.</p>
      <?php foreach(flashes() as $f):?><div class="alert <?=$f[0]?>"><?=e($f[1])?></div><?php endforeach;?>
      <form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>">
        <div class="form-group"><label>Email</label><input type="email" name="email" autocomplete="email" required></div>
        <button class="btn primary" style="width:100%;justify-content:center">Send Reset Link</button>
      </form>
      <a class="small-link" href="<?=e(url('login'))?>">Back to Login</a>
    </div></body></html><?php exit;
}

if ($route==='reset-password') {
    if(user()) redirect('dashboard');
    $token=trim((string)($_GET['token']??''));
    $valid=false; $resetId=0; $userId=0; $resetEmail='';
    if($token!==''){
        try{
            $hash=hash('sha256',$token);
            $st=db()->prepare('SELECT pr.id,pr.user_id,u.email FROM password_reset_tokens pr JOIN users u ON u.id=pr.user_id WHERE pr.token_hash=? AND pr.used_at IS NULL AND pr.expires_at>NOW() AND u.status="active" LIMIT 1');
            $st->execute([$hash]);
            if($row=$st->fetch()){
                $valid=true;$resetId=(int)$row['id'];$userId=(int)$row['user_id'];$resetEmail=(string)$row['email'];
            }
        }catch(Throwable $e){ error_log('v240 reset-password lookup: '.$e->getMessage()); }
    }
    if($_SERVER['REQUEST_METHOD']==='POST'){
        check_csrf();
        $token=trim((string)($_POST['token']??''));
        $pass=(string)($_POST['password']??'');
        $confirm=(string)($_POST['password_confirm']??'');
        $valid=false;
        try{
            $hash=hash('sha256',$token);
            $st=db()->prepare('SELECT pr.id,pr.user_id,u.email FROM password_reset_tokens pr JOIN users u ON u.id=pr.user_id WHERE pr.token_hash=? AND pr.used_at IS NULL AND pr.expires_at>NOW() AND u.status="active" LIMIT 1');
            $st->execute([$hash]);
            if($row=$st->fetch()){$resetId=(int)$row['id'];$userId=(int)$row['user_id'];$resetEmail=(string)$row['email'];$valid=true;}
        }catch(Throwable $e){}
        if(!$valid){flash('error','This reset link is invalid or has expired.');redirect('forgot-password');}
        if(strlen($pass)<8){flash('error','Password must be at least 8 characters.');redirect('reset-password?token='.rawurlencode($token));}
        if($pass!==$confirm){flash('error','Passwords do not match.');redirect('reset-password?token='.rawurlencode($token));}
        try{
            $hashPassword=password_hash($pass,PASSWORD_DEFAULT);
            $pdo=db();
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([$hashPassword,$userId]);
            $pdo->prepare('UPDATE password_reset_tokens SET used_at=NOW() WHERE id=?')->execute([$resetId]);
            $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id=? AND id<>?')->execute([$userId,$resetId]);
            $pdo->commit();
            flash('success','Your password has been reset. You can now sign in.');
            redirect('login');
        }catch(Throwable $e){
            if(db()->inTransaction())db()->rollBack();
            error_log('v240 reset-password save: '.$e->getMessage());
            flash('error','Unable to reset the password right now. Please try again.');
            redirect('reset-password?token='.rawurlencode($token));
        }
    }
    if(!$valid){
        flash('error','This reset link is invalid or has expired.');
        redirect('forgot-password');
    }
    ?>
    <!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Reset Password · sense</title><link rel="stylesheet" href="<?=e(url('assets/app.css'))?>"></head>
    <body class="auth"><div class="auth-card">
      <div class="auth-brand"><span class="brandmark">SA</span><span>sense</span></div>
      <h1>Reset password</h1><p>Set a new password for <?=e($resetEmail)?>.</p>
      <?php foreach(flashes() as $f):?><div class="alert <?=$f[0]?>"><?=e($f[1])?></div><?php endforeach;?>
      <form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="token" value="<?=e($token)?>">
        <div class="form-group"><label>New Password</label><input type="password" name="password" autocomplete="new-password" minlength="8" required></div>
        <div class="form-group"><label>Confirm New Password</label><input type="password" name="password_confirm" autocomplete="new-password" minlength="8" required></div>
        <button class="btn primary" style="width:100%;justify-content:center">Reset Password</button>
      </form>
    </div></body></html><?php exit;
}

if ($route==='login') { if(user()) redirect('dashboard'); if($_SERVER['REQUEST_METHOD']==='POST'){check_csrf();$email=trim($_POST['email']??'');$pass=$_POST['password']??'';$st=db()->prepare('SELECT * FROM users WHERE email=? AND status="active" LIMIT 1');$st->execute([$email]);$x=$st->fetch();if($x&&password_verify($pass,$x['password_hash'])){try{$cs=db()->prepare('SELECT account_status FROM companies WHERE id=? LIMIT 1');$cs->execute([(int)$x['company_id']]);if((string)($cs->fetchColumn()??'active')==='suspended'){flash('error','This company account is suspended. Please contact sense support.');redirect('login');}}catch(Throwable $e){}session_regenerate_id(true);$_SESSION['uid']=$x['id'];db()->prepare('UPDATE users SET last_login_at=NOW() WHERE id=?')->execute([$x['id']]);platform_record_login((int)$x['id']);redirect('dashboard');}flash('error','Invalid email or password.');redirect('login');} ?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Login · sense</title><link rel="stylesheet" href="<?=e(url('assets/app.css'))?>"></head><body class="auth"><div class="auth-card"><div class="auth-brand"><span class="brandmark">SA</span><span>sense</span></div><h1>Welcome back</h1><p>Sign in to your company account.</p><?php foreach(flashes() as $f):?><div class="alert <?=$f[0]?>"><?=e($f[1])?></div><?php endforeach;?><form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><div class="form-group"><label>Email</label><input type="email" name="email" required></div><div class="form-group"><label>Password</label><input type="password" name="password" autocomplete="current-password" required></div><div style="display:flex;justify-content:flex-end;margin:-2px 0 10px"><a class="small-link" href="<?=e(url('forgot-password'))?>">Forgot Password?</a></div><button class="btn primary" style="width:100%;justify-content:center">Login</button></form><a class="small-link" href="<?=e(url('register'))?>">Create company account</a></div></body></html><?php exit; }
if ($route==='platform-login') {
    if(platform_admin()) redirect('platform-control');
    if($_SERVER['REQUEST_METHOD']==='POST'){
        check_csrf();
        $identity=trim((string)($_POST['identity']??''));
        $pass=(string)($_POST['password']??'');
        $st=db()->prepare('SELECT * FROM platform_admins WHERE status="active" AND (username=? OR email=?) LIMIT 1');
        $st->execute([$identity,$identity]);
        $a=$st->fetch();
        if($a && password_verify($pass,(string)$a['password_hash'])){
            session_regenerate_id(true);
            $_SESSION['platform_admin_id']=(int)$a['id'];
            db()->prepare('UPDATE platform_admins SET last_login_at=NOW() WHERE id=?')->execute([(int)$a['id']]);
            redirect('platform-control');
        }
        flash('error','Invalid Platform Control username/email or password.');
        redirect('platform-login');
    }
    ?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Platform Control Login · sense</title><link rel="stylesheet" href="<?=e(url('assets/app.css'))?>?v=128"></head><body class="auth"><div class="auth-card"><div class="auth-brand"><span class="brandmark">SA</span><span>sense</span></div><h1>Platform Control</h1><p>Separate administrator access for managing all company accounts and SaaS operations.</p><?php foreach(flashes() as $f):?><div class="alert <?=$f[0]?>"><?=e($f[1])?></div><?php endforeach;?><form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><div class="form-group"><label>Username or Email</label><input name="identity" autocomplete="username" required></div><div class="form-group"><label>Password</label><input type="password" name="password" autocomplete="current-password" required></div><button class="btn primary" style="width:100%;justify-content:center">Sign in to Platform Control</button></form><div class="pc-note" style="margin-top:14px">This login is separate from company users. A company Super Admin cannot access Platform Control unless explicitly created as a Platform Admin.</div></div></body></html><?php exit;
}
if ($route==='platform-logout') { unset($_SESSION['platform_admin_id']); redirect('platform-login'); }
if ($route==='platform-audit') { require __DIR__.'/../platform_audit_v189.php'; exit; }

if ($route==='register') { if(user())redirect('dashboard'); if($_SERVER['REQUEST_METHOD']==='POST'){check_csrf();$name=trim($_POST['name']??'');$email=trim($_POST['email']??'');$pass=$_POST['password']??'';$company=trim($_POST['company_name']??'');$phone=trim($_POST['phone']??'');$address=trim($_POST['address']??'');$biz=trim($_POST['business_type']??'');$fy=$_POST['financial_year_mode']??'july_june';$currency=$_POST['currency_code']??'BDT';if(!$name||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($pass)<8||!$company){flash('error','Name, company, valid email and password (8+ chars) are required.');redirect('register');}$pdo=db();try{$pdo->beginTransaction();$pdo->prepare('INSERT INTO companies(name,email,phone,address,business_type,currency_code,financial_year_mode,financial_year_start) VALUES(?,?,?,?,?,?,?,?)')->execute([$company,$email,$phone,$address,$biz,$currency,$fy,$fy==='jan_dec'?1:7]);$cid=(int)$pdo->lastInsertId();$pdo->prepare('INSERT INTO users(company_id,name,email,password_hash,role,status) VALUES(?,?,?,?,"super_admin","active")')->execute([$cid,$name,$email,password_hash($pass,PASSWORD_DEFAULT)]);$uid=(int)$pdo->lastInsertId();$pdo->prepare('INSERT INTO roles(company_id,name) VALUES(? ,"Super Admin")')->execute([$cid]);foreach([['view','View'],['add','Add'],['edit','Edit'],['delete','Delete'],['print','Print'],['export','Export'],['approve','Approve']] as $p)$pdo->prepare('INSERT INTO permissions(code,label) VALUES(?,?) ON DUPLICATE KEY UPDATE label=VALUES(label)')->execute($p);$pdo->prepare('INSERT INTO branches(company_id,name,code,is_default) VALUES(? ,"Main Branch","MAIN",1)')->execute([$cid]);$pdo->prepare('INSERT INTO units(company_id,name,symbol) VALUES(? ,"Piece","pcs")')->execute([$cid]);$pdo->commit();session_regenerate_id(true);$_SESSION['uid']=$uid;redirect('dashboard');}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();flash('error',$e->getCode()==='23000'?'This email may already be registered.':'Registration failed.');redirect('register');}} ?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Create Company</title><link rel="stylesheet" href="<?=e(url('assets/app.css'))?>"></head><body class="auth"><div class="auth-card wide"><div class="auth-brand"><span class="brandmark">SA</span><span>sense</span></div><h1>Create your company</h1><p>The first account becomes the Super Admin / Company Owner.</p><?php foreach(flashes() as $f):?><div class="alert <?=$f[0]?>"><?=e($f[1])?></div><?php endforeach;?><form method="post" class="grid2"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><div class="form-group"><label>Admin Name*</label><input name="name" required></div><div class="form-group"><label>Admin Email*</label><input name="email" type="email" required></div><div class="form-group"><label>Password*</label><input name="password" type="password" minlength="8" required></div><div class="form-group"><label>Company Name*</label><input name="company_name" required></div><div class="form-group"><label>Company Phone</label><input name="phone"></div><div class="form-group"><label>Business Type</label><input name="business_type"></div><div class="form-group"><label>Currency</label><select name="currency_code"><option value="BDT">BDT — ৳</option><option value="USD">USD — $</option><option value="EUR">EUR — €</option></select></div><div class="form-group"><label>Financial Year</label><select name="financial_year_mode"><option value="july_june">1 July – 30 June</option><option value="jan_dec">1 January – 31 December</option></select></div><div class="form-group span2"><label>Company Address</label><textarea name="address"></textarea></div><div class="span2"><button class="btn primary" style="width:100%;justify-content:center">Create Company & Admin Account</button></div></form></div></body></html><?php exit; }

// Public entry points: login, company registration, and invitation acceptance.
// All other routes require an authenticated user before route-specific code accesses $u.
$u = null;
