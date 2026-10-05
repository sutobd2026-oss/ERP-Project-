<?php
/* sense modular v1 route module extracted from the current public/index.php master. */
if($route==='notifications'){
    $u=require_login(); $cid=(int)$u['company_id'];
    if(isset($_GET['read'])){ $nid=(int)$_GET['read']; db()->prepare('UPDATE notifications SET read_at=NOW() WHERE id=? AND user_id=?')->execute([$nid,$u['id']]); redirect('notifications'); }
    if(isset($_GET['read_all'])){ db()->prepare('UPDATE notifications SET read_at=NOW() WHERE user_id=? AND company_id=?')->execute([$u['id'],$cid]); redirect('notifications'); }
    $q=db()->prepare('SELECT * FROM notifications WHERE user_id=? AND company_id=? ORDER BY id DESC LIMIT 100'); $q->execute([$u['id'],$cid]); $rows=$q->fetchAll();
    page_start('Notifications'); ?><div class="page-title"><div><h1>Notifications</h1><p>System alerts and user activity notifications</p></div><a class="btn" href="<?=e(url('notifications?read_all=1'))?>">Mark all as read</a></div><div class="panel table-wrap"><table><thead><tr><th>DATE</th><th>TYPE</th><th>TITLE</th><th>MESSAGE</th><th></th></tr></thead><tbody><?php foreach($rows as $r):?><tr class="<?=empty($r['read_at'])?'notice-unread':''?>"><td><?=e($r['created_at'])?></td><td><?=e($r['type'])?></td><td><b><?=e($r['title'])?></b></td><td><?=nl2br(e($r['body']??''))?></td><td><?php if(!$r['read_at']):?><a class="btn small-btn" href="<?=e(url('notifications?read='.(int)$r['id']))?>">Mark read</a><?php else:?><span class="subtle">Read</span><?php endif;?></td></tr><?php endforeach;if(!$rows):?><tr><td colspan="5" class="subtle">No notifications.</td></tr><?php endif;?></tbody></table></div><?php page_end();exit;
}


/** v231: ensure company roles/permissions exist and existing users are linked to role_id. */
function ensure_team_permission_defaults_v231(int $cid): void {
    static $done=[]; if(isset($done[$cid])) return; $done[$cid]=true;
    $pdo=db();
    // Defensive foundation: some modular installs may not have the RBAC tables
    // from the monolithic master. Create only when missing; existing data is kept.
    try{$pdo->exec("CREATE TABLE IF NOT EXISTS roles (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        company_id INT UNSIGNED NOT NULL,
        name VARCHAR(100) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY(id),
        UNIQUE KEY uq_roles_company_name(company_id,name),
        KEY idx_roles_company(company_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");}catch(Throwable $e){error_log('v231 roles table: '.$e->getMessage());}
    try{$pdo->exec("CREATE TABLE IF NOT EXISTS permissions (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        code VARCHAR(50) NOT NULL,
        label VARCHAR(100) NOT NULL,
        PRIMARY KEY(id),
        UNIQUE KEY uq_permissions_code(code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");}catch(Throwable $e){error_log('v231 permissions table: '.$e->getMessage());}
    try{$pdo->exec("CREATE TABLE IF NOT EXISTS role_permissions (
        role_id INT UNSIGNED NOT NULL,
        permission_id INT UNSIGNED NOT NULL,
        PRIMARY KEY(role_id,permission_id),
        KEY idx_rp_permission(permission_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");}catch(Throwable $e){error_log('v231 role_permissions table: '.$e->getMessage());}
    $roles=[
        'Super Admin'=>'super_admin',
        'Admin'=>'admin',
        'Manager'=>'manager',
        'Accountant'=>'accountant',
        'Sales'=>'sales',
        'Purchase'=>'purchase',
        'Viewer'=>'viewer',
        'Custom'=>'custom',
    ];
    foreach(array_keys($roles) as $roleName){
        try{
            $q=$pdo->prepare('SELECT id FROM roles WHERE company_id=? AND name=? LIMIT 1');
            $q->execute([$cid,$roleName]);
            if(!$q->fetchColumn()){
                $pdo->prepare('INSERT INTO roles(company_id,name) VALUES(?,?)')->execute([$cid,$roleName]);
            }
        }catch(Throwable $e){ error_log('v231 role seed '.$roleName.': '.$e->getMessage()); }
    }
    $permissions=[['view','View'],['add','Add'],['edit','Edit'],['delete','Delete'],['print','Print'],['export','Export'],['approve','Approve']];
    foreach($permissions as [$code,$label]){
        try{
            $pdo->prepare('INSERT INTO permissions(code,label) VALUES(?,?) ON DUPLICATE KEY UPDATE label=VALUES(label)')->execute([$code,$label]);
        }catch(Throwable $e){ error_log('v231 permission seed '.$code.': '.$e->getMessage()); }
    }
    $roleLookup=[];
    try{
        $q=$pdo->prepare('SELECT id,name FROM roles WHERE company_id=?'); $q->execute([$cid]);
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r) $roleLookup[(string)$r['name']] = (int)$r['id'];
    }catch(Throwable $e){}
    $map=$roles;
    try{
        $users=$pdo->prepare('SELECT id,role,role_id FROM users WHERE company_id=?'); $users->execute([$cid]);
        foreach($users->fetchAll(PDO::FETCH_ASSOC) as $usr){
            $roleCode=strtolower((string)($usr['role']??'viewer'));
            $roleName='Viewer';
            foreach($map as $name=>$code){ if($code===$roleCode){$roleName=$name;break;} }
            $rid=$roleLookup[$roleName]??0;
            if($rid>0 && (int)($usr['role_id']??0)!==$rid){
                $pdo->prepare('UPDATE users SET role_id=? WHERE id=? AND company_id=?')->execute([$rid,(int)$usr['id'],$cid]);
            }
        }
    }catch(Throwable $e){ error_log('v231 user role sync: '.$e->getMessage()); }
}

if($route==='team'){
    $u=require_super_admin(); $cid=(int)$u['company_id']; $pdo=db();
    ensure_team_permission_defaults_v231($cid);
    if($_SERVER['REQUEST_METHOD']==='POST'){
        check_csrf(); $action=$_POST['team_action']??'';
        if($action==='invite'){
            $name=trim($_POST['name']??''); $email=strtolower(trim($_POST['email']??'')); $role=$_POST['role']??'viewer';
            $allowed=['admin','manager','accountant','sales','purchase','viewer','custom'];
            if(!$name || !filter_var($email,FILTER_VALIDATE_EMAIL) || !in_array($role,$allowed,true)){flash('error','Enter a valid name, email and role.');redirect('team');}
            $chk=db()->prepare('SELECT id FROM users WHERE company_id=? AND email=? LIMIT 1');$chk->execute([$cid,$email]);
            if($chk->fetchColumn()){flash('error','This email is already a user in the company.');redirect('team');}
            $chk=db()->prepare('SELECT id FROM user_invites WHERE company_id=? AND email=? AND accepted_at IS NULL AND expires_at>NOW() ORDER BY id DESC LIMIT 1');$chk->execute([$cid,$email]);
            if($chk->fetchColumn()){flash('error','An active invitation already exists for this email.');redirect('team');}
            $token=bin2hex(random_bytes(32)); $expires=date('Y-m-d H:i:s',time()+7*86400);
            db()->prepare('INSERT INTO user_invites(company_id,invited_by,email,name,role,token,expires_at) VALUES(?,?,?,?,?,?,?)')->execute([$cid,$u['id'],$email,$name,$role,$token,$expires]);
            $link=url('accept-invite?token='.$token); $subject='Invitation to '.$u['company_name'].' · sense'; $msg="You have been invited to join {$u['company_name']} on sense.\n\nOpen this link to accept: https://".$_SERVER['HTTP_HOST'].$link."\n\nThis invitation expires in 7 days."; $sent=false; if(function_exists('mail')){$headers='From: sense <no-reply@'.preg_replace('/[^a-z0-9.-]/i','',$_SERVER['HTTP_HOST']).'>\r\nContent-Type: text/plain; charset=UTF-8'; $sent=@mail($email,$subject,$msg,$headers);}
            audit('invite','user_invite',(int)db()->lastInsertId(),['email'=>$email,'role'=>$role]); flash('success',$sent?'Invitation email sent.':'Invitation created. Copy the invitation link from the pending invitations table.'); redirect('team');
        }
        if($action==='send_password_reset'){
            $targetId=(int)($_POST['user_id']??0);
            $st=db()->prepare('SELECT id,name,email,status FROM users WHERE id=? AND company_id=? LIMIT 1');
            $st->execute([$targetId,$cid]);
            $target=$st->fetch();
            if(!$target || (string)$target['status']!=='active'){flash('error','Active user not found.');redirect('team');}
            try{
                $token=sense_password_reset_token_create((int)$target['id']);
                $link=sense_password_reset_url($token);
                $subject='Reset your sense password';
                $body="Hello ".($target['name']??'').",\n\nAn administrator of ".($u['company_name']??'your company')." requested a password reset link for your sense account.\n\nOpen this link within 30 minutes to set a new password:\n".$link."\n\nIf you did not request this, you can safely ignore this email.\n\n— sense";
                if(sense_send_mail((string)$target['email'],$subject,$body)){
                    audit('password_reset','user',(int)$target['id'],['method'=>'admin_email','email'=>$target['email']]);
                    flash('success','Password reset link sent to '.($target['email']??'the user').'.');
                }else{
                    audit('password_reset_failed','user',(int)$target['id'],['method'=>'admin_email','email'=>$target['email']]);
                    flash('error','The reset token was created, but the email could not be sent. Check the server email configuration.');
                }
            }catch(Throwable $e){
                error_log('v240 admin password reset: '.$e->getMessage());
                flash('error','Unable to create the reset link right now.');
            }
            redirect('team');
        }
        if($action==='role_permissions'){
            $roleId=(int)($_POST['role_id']??0); $permIds=array_map('intval',$_POST['permissions']??[]);
            $st=db()->prepare('SELECT id,name FROM roles WHERE id=? AND company_id=?');$st->execute([$roleId,$cid]);$roleRow=$st->fetch();
            if(!$roleRow){flash('error','Invalid role.');redirect('team');}
            db()->prepare('DELETE FROM role_permissions WHERE role_id=?')->execute([$roleId]); $ins=db()->prepare('INSERT INTO role_permissions(role_id,permission_id) VALUES(?,?)'); foreach($permIds as $pid)$ins->execute([$roleId,$pid]); audit('update','role',$roleId,['permissions'=>$permIds]); flash('success','Role permissions updated.'); redirect('team');
        }
    }
    $rolesStmt=db()->prepare("SELECT * FROM roles WHERE company_id=? ORDER BY FIELD(name,'Super Admin','Admin','Manager','Accountant','Sales','Purchase','Viewer','Custom'), name");
    $rolesStmt->execute([$cid]);
    $roles=$rolesStmt->fetchAll();
    // Last-resort hydration so a transient schema issue never leaves the section empty.
    if(!$roles){
        $fallbackNames=['Super Admin','Admin','Manager','Accountant','Sales','Purchase','Viewer','Custom'];
        foreach($fallbackNames as $roleName){
            try{$ins=$pdo->prepare('INSERT INTO roles(company_id,name) VALUES(?,?)');$ins->execute([$cid,$roleName]);}catch(Throwable $e){}
        }
        $rolesStmt->execute([$cid]);
        $roles=$rolesStmt->fetchAll();
    }
    try{$perms=db()->query('SELECT * FROM permissions ORDER BY id')->fetchAll();}catch(Throwable $e){$perms=[];}
    if(!$perms){
        foreach([['view','View'],['add','Add'],['edit','Edit'],['delete','Delete'],['print','Print'],['export','Export'],['approve','Approve']] as [$code,$label]){
            try{$pdo->prepare('INSERT INTO permissions(code,label) VALUES(?,?)')->execute([$code,$label]);}catch(Throwable $e){}
        }
        $perms=$pdo->query('SELECT * FROM permissions ORDER BY id')->fetchAll();
    }
    $us=db()->prepare('SELECT u.id,u.name,u.email,u.role,u.status,u.last_login_at,r.name role_name FROM users u LEFT JOIN roles r ON r.id=u.role_id WHERE u.company_id=? ORDER BY u.id');$us->execute([$cid]);$us=$us->fetchAll();
    $inv=db()->prepare('SELECT i.*,u.name inviter_name FROM user_invites i JOIN users u ON u.id=i.invited_by WHERE i.company_id=? AND i.accepted_at IS NULL AND i.expires_at>NOW() ORDER BY i.id DESC');$inv->execute([$cid]);$inv=$inv->fetchAll();
    page_start('Team & Permissions');
    ?><div class="page-title"><div><h1>Team & Permissions</h1><p>Invite users and control role-based access</p></div><span class="status paid"><?=count($us)?> users</span></div>
    <div class="grid2"><div class="panel"><div class="panel-head"><h2>Invite User</h2></div><form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="team_action" value="invite"><div class="grid2"><div class="form-group"><label>Name*</label><input name="name" required></div><div class="form-group"><label>Email*</label><input name="email" type="email" required></div><div class="form-group"><label>Role</label><select name="role"><option value="admin">Admin</option><option value="manager">Manager</option><option value="accountant">Accountant</option><option value="sales">Sales</option><option value="purchase">Purchase</option><option value="viewer" selected>Viewer</option><option value="custom">Custom</option></select></div></div><button class="btn primary">Send Invite</button></form></div>
    <div class="panel"><div class="panel-head"><h2>Pending Invitations</h2></div><div class="table-wrap"><table><thead><tr><th>NAME</th><th>EMAIL</th><th>ROLE</th><th>EXPIRES</th><th>LINK</th></tr></thead><tbody><?php foreach($inv as $i):$link=url('accept-invite?token='.$i['token']);?><tr><td><?=e($i['name']??'-')?></td><td><?=e($i['email'])?></td><td><?=e(ucfirst($i['role']))?></td><td><?=e($i['expires_at'])?></td><td><input class="copy-link" readonly value="https://<?=e($_SERVER['HTTP_HOST'].$link)?>"></td></tr><?php endforeach;if(!$inv):?><tr><td colspan="5" class="subtle">No pending invitations.</td></tr><?php endif;?></tbody></table></div></div></div>
    <div class="panel" style="margin-top:14px"><div class="panel-head"><h2>Users</h2></div><div class="table-wrap"><table><thead><tr><th>NAME</th><th>EMAIL</th><th>ROLE</th><th>STATUS</th><th>LAST LOGIN</th><th>ACTION</th></tr></thead><tbody><?php foreach($us as $x):?><tr><td><?=e($x['name'])?></td><td><?=e($x['email'])?></td><td><?=e(ucwords(str_replace('_',' ',($x['role_name']?:$x['role']))))?></td><td><?=e(ucfirst($x['status']))?></td><td><?=e($x['last_login_at']?:'-')?></td><td><form method="post" style="margin:0" onsubmit="return confirm('Send a password reset link to this user?')"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="team_action" value="send_password_reset"><input type="hidden" name="user_id" value="<?=((int)$x['id'])?>"><button class="btn small-btn" type="submit">Send Reset Link</button></form></td></tr><?php endforeach;?></tbody></table></div></div>
    <style>
      .team-perm-panel{margin-top:14px}.team-role-card{border:1px solid #e4e9f0;border-radius:10px;background:#fff;margin:10px 0;overflow:hidden}.team-role-head{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:12px 14px;border-bottom:1px solid #edf1f5;background:#fafbfd}.team-role-name{font-weight:700;color:#18324f}.team-role-note{font-size:12px;color:#7b8794;margin-left:8px}.team-perm-grid{display:grid;grid-template-columns:repeat(7,minmax(90px,1fr));gap:10px;padding:14px}.team-perm-grid label{display:flex;align-items:center;gap:7px;font-size:13px;color:#334155;min-height:28px}.team-perm-grid input{width:15px;height:15px}.team-unrestricted{padding:12px 14px;color:#0b7a55;background:#f0fdf7;border-top:1px solid #d1fae5;font-size:12px;font-weight:600}.team-role-actions{display:flex;align-items:center;gap:8px}@media(max-width:1100px){.team-perm-grid{grid-template-columns:repeat(4,minmax(100px,1fr))}}@media(max-width:700px){.team-perm-grid{grid-template-columns:repeat(2,minmax(120px,1fr))}.team-role-head{align-items:flex-start;flex-direction:column}}
    </style>
    <div class="panel team-perm-panel"><div class="panel-head"><h2>Role Permissions</h2><span class="subtle">View · Add · Edit · Delete · Print · Export · Approve</span></div>
    <?php foreach($roles as $rr):
        $rp=$pdo->prepare('SELECT permission_id FROM role_permissions WHERE role_id=?');$rp->execute([(int)$rr['id']]);$chosen=array_map('intval',$rp->fetchAll(PDO::FETCH_COLUMN));
        $isSuper=strcasecmp((string)$rr['name'],'Super Admin')===0;
    ?>
      <form method="post" class="team-role-card">
        <input type="hidden" name="_csrf" value="<?=csrf_token()?>">
        <input type="hidden" name="team_action" value="role_permissions">
        <input type="hidden" name="role_id" value="<?=$rr['id']?>">
        <div class="team-role-head"><div><span class="team-role-name"><?=e($rr['name'])?></span><?php if($isSuper):?><span class="team-role-note">Unrestricted</span><?php endif;?></div><div class="team-role-actions"><?php if(!$isSuper):?><button class="btn small-btn" type="submit">Save Permissions</button><?php endif;?></div></div>
        <?php if($isSuper): ?>
          <div class="team-unrestricted">Super Admin always has full access. Permission changes are not required for this role.</div>
          <div class="team-perm-grid"><?php foreach($perms as $pp):?><label><input type="checkbox" checked disabled> <?=e($pp['label'])?></label><?php endforeach;?></div>
        <?php else: ?>
          <div class="team-perm-grid"><?php foreach($perms as $pp):?><label><input type="checkbox" name="permissions[]" value="<?=$pp['id']?>" <?=in_array((int)$pp['id'],$chosen,true)?'checked':''?>> <?=e($pp['label'])?></label><?php endforeach;?></div>
        <?php endif; ?>
      </form>
    <?php endforeach; ?>
    </div><?php page_end();exit;
}

