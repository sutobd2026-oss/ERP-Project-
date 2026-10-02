<?php
/* sense modular v1 route module extracted from the current public/index.php master. */
if($route==='dashboard'){
    page_start('Home');
    $cid=(int)$u['company_id'];
    $currency=$u['currency_code']==='BDT'?'৳':$u['currency_code'];
    echo '<style>
      .dashboard-cash-link.cash-negative-warning{border-color:#fca5a5!important;background:#fff7f7!important}
      .dashboard-cash-link .cash-negative-value{color:#ef4444!important}
      .dashboard-cash-link.cash-negative-warning .title:after{content:" ⚠";color:#ef4444;font-size:12px;margin-left:4px}
    </style>';
    $dashboardMoney=function(float $amount)use($currency):string{
        $formatted=number_format($amount,2,'.',',');
        $parts=explode('.',$formatted);
        $whole=$parts[0];
        $dec=$parts[1]??'00';
        return '<span class="dash-money"><span class="dash-money-main">'.e($currency.$whole).'</span><span class="dash-money-dec">.'.e($dec).'</span></span>';
    };
    $rangeOptions=[
        'this_month'=>'This Month',
        'last_month'=>'Last Month',
        'last_30_days'=>'Last 30 Days',
        'this_quarter'=>'This Quarter',
        'this_year'=>'This Year',
    ];
    $normalizeRange=function(string $key)use($rangeOptions):string{return isset($rangeOptions[$key])?$key:'this_month';};
    $saleRange=$normalizeRange((string)($_GET['sale_range']??'this_month'));
    $expenseRange=$normalizeRange((string)($_GET['expense_range']??'this_month'));
    $rangeDates=function(string $key):array{
        $today=date('Y-m-d');
        if($key==='last_month'){
            $start=date('Y-m-01',strtotime('first day of last month'));
            $end=date('Y-m-t',strtotime('first day of last month'));
        }elseif($key==='last_30_days'){
            $start=date('Y-m-d',strtotime('-29 days')); $end=$today;
        }elseif($key==='this_quarter'){
            $m=(int)date('n'); $qStart=$m-((($m-1)%3));
            $start=date('Y-m-d',strtotime(date('Y').'-'.str_pad((string)$qStart,2,'0',STR_PAD_LEFT).'-01')); $end=$today;
        }elseif($key==='this_year'){
            $start=date('Y-01-01'); $end=$today;
        }else{
            $start=date('Y-m-01'); $end=$today;
        }
        return [$start,$end];
    };
    $buildSeries=function(string $type,string $rangeKey)use($cid,$rangeDates):array{
        [$start,$end]=$rangeDates($rangeKey);
        $startTs=strtotime($start); $endTs=strtotime($end);
        $days=(int)floor(($endTs-$startTs)/86400)+1;
        $labels=[]; $values=[];
        if($rangeKey==='this_year'){
            $first=strtotime(date('Y-01-01', $startTs));
            $last=strtotime(date('Y-m-01', $endTs));
            for($ts=$first;$ts<=$last;$ts=strtotime('+1 month', $ts)){$labels[]=date('M',$ts);$values[]=0.0;}
            $st=db()->prepare('SELECT DATE_FORMAT(txn_date,"%Y-%m") period,COALESCE(SUM(total),0) total FROM transactions WHERE company_id=? AND txn_type=? AND txn_date>=? AND txn_date<? AND deleted_at IS NULL GROUP BY DATE_FORMAT(txn_date,"%Y-%m") ORDER BY period');
            $endExclusive=date('Y-m-d',strtotime($end.' +1 day')); $st->execute([$cid,$type,$start,$endExclusive]);
            $map=[];foreach($st as $r)$map[(string)$r['period']]=(float)$r['total'];
            foreach($labels as $i=>$lbl){$key=date('Y-m',strtotime(date('Y').'-01-01 +'.$i.' months'));$values[$i]=$map[$key]??0.0;}
        }else{
            for($ts=$startTs;$ts<=$endTs;$ts+=86400){$labels[]=date('d M',$ts);$values[]=0.0;}
            $st=db()->prepare('SELECT DATE(txn_date) period,COALESCE(SUM(total),0) total FROM transactions WHERE company_id=? AND txn_type=? AND txn_date>=? AND txn_date<? AND deleted_at IS NULL GROUP BY DATE(txn_date) ORDER BY period');
            $endExclusive=date('Y-m-d',strtotime($end.' +1 day')); $st->execute([$cid,$type,$start,$endExclusive]);
            $idx=[];foreach($labels as $i=>$lbl){$idx[date('Y-m-d',$startTs+($i*86400))]=$i;}
            foreach($st as $r){$k=(string)$r['period'];if(isset($idx[$k]))$values[$idx[$k]]=(float)$r['total'];}
        }
        return ['start'=>$start,'end'=>$end,'labels'=>$labels,'values'=>$values,'total'=>array_sum($values)];
    };
    $saleData=$buildSeries('sale',$saleRange); $expenseData=$buildSeries('expense',$expenseRange);
    $saleGrowth=0.0;
    $periodDays=max(1,(int)floor((strtotime($saleData['end'])-strtotime($saleData['start']))/86400)+1);
    $prevEnd=date('Y-m-d',strtotime($saleData['start'].' -1 day')); $prevStart=date('Y-m-d',strtotime($prevEnd.' -'.($periodDays-1).' days'));
    $st=db()->prepare('SELECT COALESCE(SUM(total),0) FROM transactions WHERE company_id=? AND txn_type="sale" AND txn_date>=? AND txn_date<? AND deleted_at IS NULL');
    $st->execute([$cid,$prevStart,date('Y-m-d',strtotime($prevEnd.' +1 day'))]); $prevSale=(float)$st->fetchColumn();
    $saleGrowth=$prevSale>0?(($saleData['total']-$prevSale)/$prevSale*100):($saleData['total']>0?100:0);
    $sumType=function(string $type)use($cid){$st=db()->prepare('SELECT COALESCE(SUM(total),0) FROM transactions WHERE company_id=? AND txn_type=? AND deleted_at IS NULL');$st->execute([$cid,$type]);return(float)$st->fetchColumn();};
    $purchase=$sumType('purchase');
    $partyBalanceRows=[]; $receive=0.0; $pay=0.0;
    try {
        // Keep the dashboard resilient: calculate transaction balance first and
        // add opening balance in PHP where possible. This avoids taking the
        // entire dashboard down when an older tenant schema is missing one
        // optional party column.
        $pst=db()->prepare('SELECT p.id,p.name,COALESCE(p.opening_balance,0) opening_balance,
              (SELECT COALESCE(SUM(CASE
                WHEN t.txn_type="sale" THEN t.due
                WHEN t.txn_type="payment_in" THEN -t.total
                WHEN t.txn_type="purchase" THEN -t.due
                WHEN t.txn_type="payment_out" THEN t.total
                WHEN t.txn_type="sale_return" THEN -t.total
                WHEN t.txn_type="purchase_return" THEN t.total
                ELSE 0 END),0)
               FROM transactions t
               WHERE t.company_id=p.company_id AND t.party_id=p.id AND t.deleted_at IS NULL) AS txn_balance
            FROM parties p
            WHERE p.company_id=? AND p.deleted_at IS NULL
            ORDER BY p.name ASC');
        $pst->execute([$cid]);
        $rows=$pst->fetchAll();
        foreach($rows as $pr){
            $bal=(float)($pr['txn_balance']??0);
            // opening_balance is kept as a positive amount by the existing party form.
            // Prefer the configured opening type when the column exists.
            try {
                $typ=db()->prepare('SELECT opening_balance_type FROM parties WHERE id=? AND company_id=? LIMIT 1');
                $typ->execute([(int)$pr['id'],$cid]);
                $ot=(string)($typ->fetchColumn()??'receivable');
            } catch(Throwable $ignore) {
                $ot='receivable';
            }
            $ob=(float)($pr['opening_balance']??0);
            if(in_array($ot,['payable','loan_taken'],true)) $bal-=$ob;
            elseif(in_array($ot,['receivable','loan_given'],true)) $bal+=$ob;
            if(abs($bal)<=0.009) continue;
            $partyBalanceRows[]=['id'=>(int)$pr['id'],'name'=>(string)$pr['name'],'balance'=>$bal];
            if($bal>0)$receive+=$bal; else $pay+=abs($bal);
        }
    } catch(Throwable $e) {
        // Never let the dashboard become blank because of optional party data.
        // Fall back to transaction-only balances and keep the rest of the dashboard usable.
        $partyBalanceRows=[]; $receive=0.0; $pay=0.0;
        try {
            $fst=db()->prepare('SELECT p.id,p.name,COALESCE(SUM(CASE
                WHEN t.txn_type="sale" THEN t.due
                WHEN t.txn_type="payment_in" THEN -t.total
                WHEN t.txn_type="purchase" THEN -t.due
                WHEN t.txn_type="payment_out" THEN t.total
                WHEN t.txn_type="sale_return" THEN -t.total
                WHEN t.txn_type="purchase_return" THEN t.total
                ELSE 0 END),0) balance
              FROM parties p LEFT JOIN transactions t
                ON t.company_id=p.company_id AND t.party_id=p.id AND t.deleted_at IS NULL
              WHERE p.company_id=? AND p.deleted_at IS NULL
              GROUP BY p.id,p.name
              HAVING ABS(balance)>0.009
              ORDER BY ABS(balance) DESC,p.name ASC');
            $fst->execute([$cid]);
            foreach($fst as $pr){
                $bal=(float)$pr['balance'];
                $partyBalanceRows[]=['id'=>(int)$pr['id'],'name'=>(string)$pr['name'],'balance'=>$bal];
                if($bal>0)$receive+=$bal; else $pay+=abs($bal);
            }
        } catch(Throwable $ignore) {}
    }
    $cash=company_cash_balance($cid);
    $st=db()->prepare('SELECT i.name,COALESCE(sm.qty,0) stock,i.low_stock_limit FROM items i LEFT JOIN (SELECT sm2.item_id,SUM(sm2.quantity) qty
             FROM stock_movements sm2
             WHERE sm2.company_id=?
               AND (sm2.transaction_id IS NULL OR EXISTS (
                   SELECT 1 FROM transactions st2
                   WHERE st2.id=sm2.transaction_id
                     AND st2.company_id=sm2.company_id
                     AND st2.deleted_at IS NULL
               ))
             GROUP BY sm2.item_id) sm ON sm.item_id=i.id WHERE i.company_id=? AND i.item_type="product" AND i.active=1 HAVING stock<=i.low_stock_limit ORDER BY stock LIMIT 5');$st->execute([$cid,$cid]);$low=$st->fetchAll();
    $st=db()->prepare('SELECT COALESCE(SUM(COALESCE(sm.qty,0)*COALESCE(NULLIF(i.purchase_price,0),(SELECT ti.unit_price FROM transaction_items ti JOIN transactions pt ON pt.id=ti.transaction_id WHERE ti.item_id=i.id AND pt.company_id=i.company_id AND pt.txn_type="purchase" AND pt.deleted_at IS NULL ORDER BY pt.txn_date DESC,pt.id DESC,ti.id DESC LIMIT 1),0)),0) FROM items i LEFT JOIN (SELECT sm2.item_id,SUM(sm2.quantity) qty
             FROM stock_movements sm2
             WHERE sm2.company_id=?
               AND (sm2.transaction_id IS NULL OR EXISTS (
                   SELECT 1 FROM transactions st2
                   WHERE st2.id=sm2.transaction_id
                     AND st2.company_id=sm2.company_id
                     AND st2.deleted_at IS NULL
               ))
             GROUP BY sm2.item_id) sm ON sm.item_id=i.id WHERE i.company_id=? AND i.item_type="product" AND i.active=1');$st->execute([$cid,$cid]);$stockValue=(float)$st->fetchColumn();
    $chartSvg=function(array $vals,int $w,int $h,string $stroke):string{
        $n=count($vals);if($n<2)return '';$max=max($vals);$min=min($vals);if(abs($max-$min)<0.000001){$min=0;$max=max(1,$max);} $range=$max-$min;
        $pad=6;$pw=$w-12;$ph=$h-24;$pts=[];foreach($vals as $i=>$v){$x=$pad+($i/max(1,$n-1))*$pw;$y=10+($ph-(($v-$min)/$range)*$ph);$pts[]=round($x,1).','.round($y,1);} $poly=implode(' ',$pts);$baseY=10+$ph;$area=$pad.','.$baseY.' '.$poly.' '.($w-$pad).','.$baseY;$gid='c'.substr(md5($poly.$stroke.$w.$h),0,10);
        return '<svg class="dashboard-chart-svg" viewBox="0 0 '.$w.' '.$h.'" preserveAspectRatio="none" aria-hidden="true"><defs><linearGradient id="'.$gid.'" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="'.$stroke.'" stop-opacity="0.18"/><stop offset="100%" stop-color="'.$stroke.'" stop-opacity="0.02"/></linearGradient></defs><polygon points="'.$area.'" fill="url(#'.$gid.')"></polygon><polyline points="'.$poly.'" fill="none" stroke="'.$stroke.'" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"></polyline></svg>';
    };
    $report=function(array $d):string{return date('d M',strtotime($d['start'])).' to '.date('d M',strtotime($d['end']));};
    $rangeForm=function(string $name,string $current,string $otherName,string $other)use($rangeOptions):string{
        $html='<form method="get" class="dashboard-range-form"><input type="hidden" name="'.e($otherName).'" value="'.e($other).'">';
        $html.='<select class="range" name="'.e($name).'" onchange="this.form.submit()">';
        foreach($rangeOptions as $k=>$label)$html.='<option value="'.e($k).'"'.($k===$current?' selected':'').'>'.e($label).'</option>';
        $html.='</select></form>';return $html;
    };
    $saleRangeLabel=$rangeOptions[$saleRange]; $expenseRangeLabel=$rangeOptions[$expenseRange];
?><div class="dashboard-page" id="dashboardPage">
  <?php $platformNotices=function_exists('platform_active_announcements')?platform_active_announcements($cid):[]; if($platformNotices): ?>
    <div class="platform-notices">
      <?php foreach($platformNotices as $pn): ?>
        <div class="platform-notice platform-notice-<?=e((string)$pn['type'])?>">
          <div class="platform-notice-icon">●</div>
          <div class="platform-notice-body"><div class="platform-notice-title"><?=e($pn['title'])?></div><div class="platform-notice-text"><?=nl2br(e($pn['body']))?></div></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <div class="dashboard-grid">
    <section class="dashboard-blur-target">
      <div class="cards-top">
        <div class="sales-card">
          <div class="card-head"><h3>▱ Sale</h3><?=$rangeForm('sale_range',$saleRange,'expense_range',$expenseRange)?></div>
          <div class="big-money"><?=$dashboardMoney((float)$saleData['total'])?></div>
          <div class="subtle">Total Sale (<?=e($saleRangeLabel)?>)</div>
          <div class="growth <?= $saleGrowth<0?'negative':'' ?>"><?=($saleGrowth>=0?'↑ ':'↓ ').number_format(abs($saleGrowth),2)?> % <span class="subtle">Growth vs previous period</span></div>
          <div class="chart dashboard-chart-wrap"><?= $chartSvg($saleData['values'],620,170,'#10b981') ?><div class="chart-baseline"></div></div>
          <div class="subtle dashboard-report">Report: From <?=e($report($saleData))?></div>
        </div>
        <div class="expense-card">
          <div class="card-head"><h3>▤ Expenses</h3><?=$rangeForm('expense_range',$expenseRange,'sale_range',$saleRange)?></div>
          <div class="big-money"><?=$dashboardMoney((float)$expenseData['total'])?></div>
          <div class="subtle">Total Expenses (<?=e($expenseRangeLabel)?>)</div>
          <div class="chart dashboard-chart-wrap expense-chart"><?= $chartSvg($expenseData['values'],460,150,'#10b981') ?><div class="chart-baseline"></div></div>
          <div class="subtle dashboard-report">Report: From <?=e($report($expenseData))?></div>
        </div>
      </div>
      <div class="mid-cards">
        <div class="metric-card receivable-card">
          <div class="label" style="color:#16a34a">↓ You'll Receive</div>
          <div class="value"><?=$dashboardMoney((float)$receive)?></div>
          <div class="party-balance-list">
            <?php $receivableRows=array_values(array_filter($partyBalanceRows,static fn($r)=>(float)$r['balance']>0)); $rShown=0; foreach($receivableRows as $pr): if($rShown>=4) break; $rShown++; ?>
              <a class="party-balance-row" href="<?=e(url('parties?id='.(int)$pr['id']))?>"><span><?=e($pr['name'])?></span><strong><?=$dashboardMoney((float)$pr['balance'])?></strong></a>
            <?php endforeach; ?>
            <?php $rMore=max(0,count($receivableRows)-$rShown); if($rMore>0): ?><div class="party-more">+ <?=$rMore?> More</div><?php elseif(!$rShown): ?><div class="empty">You don't have any pending amount to be received</div><?php endif; ?>
          </div>
        </div>
        <div class="metric-card payable-card">
          <div class="label" style="color:#ef4444">↑ You'll Pay</div>
          <div class="value"><?=$dashboardMoney((float)$pay)?></div>
          <div class="party-balance-list">
            <?php $payableRows=array_values(array_filter($partyBalanceRows,static fn($r)=>(float)$r['balance']<0)); $pShown=0; foreach($payableRows as $pr): if($pShown>=4) break; $pShown++; ?>
              <a class="party-balance-row" href="<?=e(url('parties?id='.(int)$pr['id']))?>"><span><?=e($pr['name'])?></span><strong class="payable-amount"><?=$dashboardMoney(abs((float)$pr['balance']))?></strong></a>
            <?php endforeach; ?>
            <?php $pMore=max(0,count($payableRows)-$pShown); if($pMore>0): ?><div class="party-more">+ <?=$pMore?> More</div><?php elseif(!$pShown): ?><div class="empty">You don't have any pending amount to be paid</div><?php endif; ?>
          </div>
        </div>
        <div class="metric-card"><div class="label">🛒 Purchase <span class="subtle">This Month</span></div><div class="value"><?=$dashboardMoney((float)$purchase)?></div><div class="empty"><?= $purchase>0?'Purchase transactions entered this month.':'You have no purchased items entered for selected time.' ?></div></div>
      </div>
      <div class="panel" style="margin-top:14px"><div class="panel-head"><h2>Recent Transactions</h2><a href="<?=e(url('transactions'))?>">View all</a></div><div class="table-wrap"><table><thead><tr><th>Date</th><th>Document</th><th>Type</th><th>Total</th><th>Due</th></tr></thead><tbody><?php $st=db()->prepare('SELECT txn_date,document_no,txn_type,total,due FROM transactions WHERE company_id=? AND deleted_at IS NULL ORDER BY id DESC LIMIT 8');$st->execute([$cid]);foreach($st as $r):?><tr><td><?=e(!empty($r['txn_date'])?date('d/m/Y',strtotime($r['txn_date'])):'—')?></td><td><?=e($r['document_no'])?></td><td><?=e(ucwords(str_replace('_',' ',$r['txn_type'])))?></td><td><?=$dashboardMoney((float)$r['total'])?></td><td><?=$dashboardMoney((float)$r['due'])?></td></tr><?php endforeach;if(!$st->rowCount()):?><tr><td colspan="5" class="subtle">No recent transactions.</td></tr><?php endif;?></tbody></table></div></div>
      <?php
      $dashUpdates=[];
      try{
          $du=$pdo->prepare("SELECT cu.id,cu.update_type,cu.title,cu.body,cu.created_at,c.name company_name FROM company_updates cu JOIN companies c ON c.id=cu.company_id WHERE cu.status='published' AND (cu.company_id=? OR EXISTS(SELECT 1 FROM company_subscriptions cs WHERE cs.target_company_id=cu.company_id AND cs.subscriber_company_id=? AND cs.status='approved')) ORDER BY cu.id DESC LIMIT 5");
          $du->execute([$cid,$cid]);
          $dashUpdates=$du->fetchAll();
      }catch(Throwable $e){}
      ?>
      <div class="panel" style="margin-top:14px"><div class="panel-head"><h2>Company Updates</h2><a href="<?=e(url('company-network'))?>">View all</a></div>
        <?php if($dashUpdates): foreach($dashUpdates as $du): ?>
          <div style="padding:11px 0;border-bottom:1px solid var(--line);display:flex;gap:12px;align-items:flex-start">
            <div style="width:30px;height:30px;border-radius:50%;background:#eaf5ff;color:#1688f7;display:flex;align-items:center;justify-content:center;font-weight:700;flex:0 0 auto">◎</div>
            <div style="min-width:0"><div style="font-size:12px;color:#738194"><?=e($du['company_name'])?> · <?=e(ucfirst((string)$du['update_type']))?> · <?=e(date('d/m/Y h:i A',strtotime((string)$du['created_at'])))?></div><div style="font-weight:700;margin-top:3px"><?=e($du['title'])?></div><div class="subtle" style="margin-top:3px"><?=e(mb_strimwidth((string)($du['body']??''),0,140,'…'))?></div></div>
          </div>
        <?php endforeach; else: ?>
          <div class="empty">No company updates yet.</div>
        <?php endif; ?>
      </div>
    </section>
    <aside class="right-stack">
      <div class="privacy"><span>Privacy</span><button type="button" class="privacy-toggle" id="privacyToggle" aria-pressed="false"><span class="privacy-dot"></span><span class="privacy-state">Off</span></button></div>
      <div class="dashboard-sensitive-right dashboard-blur-target">
        <div class="right-head">Pinned cards</div><div class="right-card"><span class="pin-star">★</span><div class="title">Stock Value</div><div class="value"><?=$dashboardMoney((float)$stockValue)?></div></div>
        <a href="<?=e(url('cash'))?>" class="right-card dashboard-cash-link <?=((float)$cash<0)?'cash-negative-warning':''?>"><span class="pin-star">★</span><div class="title">Cash In hand</div><div class="value cash-in-hand-value <?=((float)$cash<0)?'cash-negative-value':''?>"><?=$dashboardMoney((float)$cash)?></div></a>
        <div class="right-head">Stock Inventory</div><div class="right-card low"><div class="title">Low Stocks</div><?php if($low):foreach($low as $l):?><div style="display:flex;justify-content:space-between;margin-top:10px;font-size:13px"><span><?=e($l['name'])?></span><span style="color:#ef4444"><?=number_format((float)$l['stock'],0)?></span></div><?php endforeach;else:?><div class="subtle" style="margin-top:10px">No low stock items.</div><?php endif;?></div>
        <div class="right-head">Cash & Bank</div><div class="right-card"><div class="title">Bank Accounts</div><div class="value"><?=(int)db()->query('SELECT COUNT(*) FROM bank_accounts WHERE company_id='.(int)$cid)->fetchColumn()?></div></div>
        <div class="right-card"><div class="title">Loan Accounts</div><div class="value">0</div></div>
        <div class="right-card"><div class="title">Sale</div><div class="value"><?=$dashboardMoney((float)$saleData['total'])?></div></div>
        <div class="right-card"><div class="title">Sale Orders</div><div class="value"><?php $st=db()->prepare('SELECT COUNT(*) FROM transactions WHERE company_id=? AND txn_type="sale_order" AND deleted_at IS NULL');$st->execute([$cid]);echo (int)$st->fetchColumn();?></div></div>
        <div class="right-card"><div class="title">Delivery Challan</div><div class="value"><?php $st=db()->prepare('SELECT COUNT(*) FROM transactions WHERE company_id=? AND txn_type="delivery_challan" AND deleted_at IS NULL');$st->execute([$cid]);echo (int)$st->fetchColumn();?></div></div>
        <div class="right-card"><div class="title">Purchase</div><div class="value"><?=$dashboardMoney((float)$purchase)?></div></div>
      </div>
    </aside>
  </div>
</div>
<script>
(function(){
  const page=document.getElementById('dashboardPage');
  const btn=document.getElementById('privacyToggle');
  if(!page||!btn)return;
  const key='suto_dashboard_privacy';
  const apply=(on)=>{page.classList.toggle('privacy-on',on);btn.setAttribute('aria-pressed',on?'true':'false');const state=btn.querySelector('.privacy-state');if(state)state.textContent=on?'On':'Off';localStorage.setItem(key,on?'1':'0');};
  let on=false;try{on=localStorage.getItem(key)==='1';}catch(e){}
  apply(on);btn.addEventListener('click',()=>apply(!page.classList.contains('privacy-on')));
})();
</script><?php page_end();exit;}

/* v205 party contact actions + reliable View/Edit + hide empty PRIVATE NOTES */
