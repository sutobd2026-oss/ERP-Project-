<?php
/* sense modular v1 route module extracted from the current public/index.php master. */
if($route==='logout'){try{if(session_id()!=='')db()->prepare('DELETE FROM platform_sessions WHERE session_id=?')->execute([session_id()]);}catch(Throwable $e){} session_unset();session_destroy();redirect('login');}


if($route==='support'){
    require __DIR__.'/../support_v190.php'; exit;
}
if($route==='platform-control'){
    require __DIR__.'/../platform_control_v125.php'; exit;
}

if($route==='serial-search-api'){
    $u=require_login();$cid=(int)$u['company_id'];$itemId=(int)($_GET['item_id']??0);$q=trim((string)($_GET['q']??''));
    if($itemId<=0){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Invalid item.']);exit;}
    $st=db()->prepare('SELECT id,serial_tracked FROM items WHERE id=? AND company_id=? AND active=1 LIMIT 1');$st->execute([$itemId,$cid]);$it=$st->fetch();
    if(!$it||!(int)$it['serial_tracked']){echo json_encode(['ok'=>true,'items'=>[]],JSON_UNESCAPED_UNICODE);exit;}
    $sql='SELECT id,serial_number FROM item_serials WHERE company_id=? AND item_id=? AND status="available"';$params=[$cid,$itemId];
    if($q!==''){$sql.=' AND serial_number LIKE ?';$params[]='%'.$q.'%';}$sql.=' ORDER BY id DESC LIMIT 50';
    $st=db()->prepare($sql);$st->execute($params);echo json_encode(['ok'=>true,'items'=>$st->fetchAll()],JSON_UNESCAPED_UNICODE);exit;
}

if($route==='transactions'){
    page_start('Transactions');
    $cid=(int)$u['company_id'];
    $q=trim((string)($_GET['q']??''));
    $where='company_id=? AND deleted_at IS NULL'; $params=[$cid];
    if($q!==''){
        $like='%'.$q.'%';
        $where.=' AND (document_no LIKE ? OR txn_type LIKE ? OR CAST(total AS CHAR) LIKE ?)';
        array_push($params,$like,$like,$like);
    }
    $st=db()->prepare("SELECT txn_date,created_at,document_no,txn_type,total,paid,due FROM transactions WHERE $where ORDER BY txn_date DESC,id DESC LIMIT 250");
    $st->execute($params); $rows=$st->fetchAll();
    ?><div class="page-title"><div><h1>Transactions</h1><p>All transaction records for <?=e($u['company_name'])?></p></div></div>
    <div class="panel">
      <form method="get" class="table-search"><input name="q" value="<?=e($q)?>" placeholder="Search transaction"></form>
      <div class="table-wrap"><table><thead><tr><th>Date</th><th>Document</th><th>Type</th><th>Total</th><th>Paid</th><th>Due</th></tr></thead><tbody>
      <?php foreach($rows as $r): ?><?php $txBase=!empty($r['txn_date'])?strtotime((string)$r['txn_date']):false; $txCreated=!empty($r['created_at'])?strtotime((string)$r['created_at']):false; $txLabel=$txCreated!==false?date('d/m/Y h:i A',$txCreated):($txBase!==false?date('d/m/Y',$txBase):'—'); ?><tr><td><?=e($txLabel)?></td><td><?=e((string)$r['document_no'])?></td><td><?=e(ucwords(str_replace('_',' ',(string)$r['txn_type'])))?></td><td><?=money((float)$r['total'])?></td><td><?=money((float)$r['paid'])?></td><td><?=money((float)$r['due'])?></td></tr><?php endforeach; if(!$rows): ?><tr><td colspan="6" class="subtle">No transactions found.</td></tr><?php endif; ?>
      </tbody></table></div>
    </div><?php page_end(); exit;
}

