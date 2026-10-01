<?php
/** v191: unified notification center helpers. */
function ensure_notifications_v191_schema(): void {
    static $done=false; if($done) return; $done=true; $pdo=db();
    try{
        $cols=$pdo->query('SHOW COLUMNS FROM notifications')->fetchAll(PDO::FETCH_COLUMN,0);
        if(!in_array('link',$cols,true)) $pdo->exec('ALTER TABLE notifications ADD COLUMN link VARCHAR(500) NULL AFTER body');
        if(!in_array('read_at',$cols,true)) $pdo->exec('ALTER TABLE notifications ADD COLUMN read_at DATETIME NULL');
        if(!in_array('created_at',$cols,true)) $pdo->exec('ALTER TABLE notifications ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
        if(!in_array('deleted_at',$cols,true)) $pdo->exec('ALTER TABLE notifications ADD COLUMN deleted_at DATETIME NULL');
        try{$pdo->exec('CREATE INDEX idx_notifications_user_state ON notifications(user_id,company_id,read_at,id)');}catch(Throwable $e){}
    }catch(Throwable $e){}
}
function notifications_v191_soft_delete(int $id,int $uid,int $cid): void {
    try{$st=db()->prepare('UPDATE notifications SET deleted_at=NOW() WHERE id=? AND user_id=? AND company_id=?');$st->execute([$id,$uid,$cid]);}catch(Throwable $e){}
}
function notifications_v191_delete_read(int $uid,int $cid): void {
    try{$st=db()->prepare('UPDATE notifications SET deleted_at=NOW() WHERE user_id=? AND company_id=? AND read_at IS NOT NULL AND deleted_at IS NULL');$st->execute([$uid,$cid]);}catch(Throwable $e){}
}
function notifications_v191_unread_count(int $uid,int $cid): int {
    try{$st=db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=? AND company_id=? AND read_at IS NULL AND deleted_at IS NULL');$st->execute([$uid,$cid]);return (int)$st->fetchColumn();}catch(Throwable $e){return 0;}
}
