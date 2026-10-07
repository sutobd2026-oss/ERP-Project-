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
            // This Month: show the complete calendar month on the chart.
            // Future dates remain 0 until sales are actually recorded.
            $start=date('Y-m-01');
            $end=date('Y-m-t');
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