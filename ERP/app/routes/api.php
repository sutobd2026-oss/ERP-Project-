<?php
/* sense modular v1 route module extracted from the current public/index.php master. */
if($route==='party-search-api'){
    $u=require_login(); $cid=(int)$u['company_id'];
    header('Content-Type: application/json; charset=utf-8');
    $q=trim((string)($_GET['q']??''));
    $role=trim((string)($_GET['role']??'customer'));
    if($q===''){echo json_encode(['ok'=>true,'items'=>[]],JSON_UNESCAPED_UNICODE);exit;}
    $rolesMap=[
      'customer'=>['customer'],
      'supplier'=>['supplier'],
      'both'=>['customer','supplier'],
      'all'=>['customer','supplier','investor','lender','other'],
      'customer_all'=>['customer','investor','lender','other'],
      'supplier_all'=>['supplier']
    ];
    $wanted=$rolesMap[$role]??['customer'];
    $ph=implode(',',array_fill(0,count($wanted),'?'));
    $sql='SELECT p.id,p.name,p.phone,
      COALESCE((SELECT GROUP_CONCAT(pr.role ORDER BY pr.role SEPARATOR ",") FROM party_roles pr WHERE pr.party_id=p.id),"") roles,
      COALESCE((SELECT SUM(CASE WHEN t.txn_type="sale" THEN t.due WHEN t.txn_type="payment_in" THEN -t.total WHEN t.txn_type="purchase" THEN -t.due WHEN t.txn_type="payment_out" THEN t.total ELSE 0 END) FROM transactions t WHERE t.company_id=p.company_id AND t.party_id=p.id AND t.deleted_at IS NULL),0) outstanding
      FROM parties p WHERE p.company_id=? AND p.deleted_at IS NULL
      AND EXISTS(SELECT 1 FROM party_roles xr WHERE xr.party_id=p.id AND xr.role IN ('.$ph.'))
      AND (p.name LIKE ? OR p.phone LIKE ? OR p.email LIKE ?)
      ORDER BY p.name LIMIT 20';
    $params=[$cid,...$wanted,'%'.$q.'%','%'.$q.'%','%'.$q.'%'];
    $st=db()->prepare($sql);$st->execute($params);$items=$st->fetchAll();
    echo json_encode(['ok'=>true,'items'=>$items],JSON_UNESCAPED_UNICODE);exit;
}

if($route==='item-search-api'){
    $u=require_login(); $cid=(int)$u['company_id'];
    // v236: item metadata must always be available to the AJAX search.
    try {
        $cols=db()->query('SHOW COLUMNS FROM items')->fetchAll(PDO::FETCH_COLUMN,0);
        foreach(['item_note'=>'TEXT NULL','warranty'=>'VARCHAR(255) NULL','location'=>'VARCHAR(255) NULL'] as $c=>$def){
            if(!in_array($c,$cols,true)) db()->exec('ALTER TABLE items ADD COLUMN `'.$c.'` '.$def);
        }
    } catch(Throwable $e) { error_log('item search metadata schema: '.$e->getMessage()); }
    header('Content-Type: application/json; charset=utf-8');
    $q=trim((string)($_GET['q']??''));
    if($q===''){echo json_encode(['ok'=>true,'items'=>[]],JSON_UNESCAPED_UNICODE);exit;}
    $like='%'.$q.'%';
    $sql='SELECT i.id,i.name,i.code,i.barcode,i.item_type,i.serial_tracked,i.sale_price,i.wholesale_price,i.purchase_price,i.unit_id,
      COALESCE(i.location,"") location,COALESCE(i.item_note,"") item_note,COALESCE(i.description,"") description,COALESCE(i.warranty,"") warranty,
      COALESCE((SELECT SUM(sm.quantity) FROM stock_movements sm WHERE sm.company_id=i.company_id AND sm.item_id=i.id
                     AND (sm.transaction_id IS NULL OR EXISTS (
                         SELECT 1 FROM transactions st
                         WHERE st.id=sm.transaction_id
                           AND st.company_id=sm.company_id
                           AND st.deleted_at IS NULL
                     ))),0) current_stock,
      COALESCE(u.symbol,"") unit_symbol
      FROM items i LEFT JOIN units u ON u.id=i.unit_id
      WHERE i.company_id=? AND i.active=1
      AND (i.name LIKE ? OR i.code LIKE ? OR i.barcode LIKE ?)
      ORDER BY CASE WHEN i.name LIKE ? THEN 0 WHEN i.code LIKE ? THEN 1 WHEN i.barcode LIKE ? THEN 2 ELSE 3 END, i.name
      LIMIT 20';
    $params=[$cid,$like,$like,$like,$q.'%',$q.'%',$q.'%'];
    try{
      $st=db()->prepare($sql);$st->execute($params);$rows=$st->fetchAll(PDO::FETCH_ASSOC);
      $bundleMap=bundle_map_for_company(db(),$cid);
      foreach($rows as &$r){
        $r['current_stock']=(float)$r['current_stock'];
        $r['sale_price']=(float)$r['sale_price'];
        $r['wholesale_price']=(float)$r['wholesale_price'];
        $r['purchase_price']=(float)$r['purchase_price'];
        $r['bundle_components']=array_map(function($c){
          $c['item_id']=(int)($c['item_id']??$c['component_item_id']??0);
          $c['quantity']=(float)($c['quantity']??1);
          return $c;
        },$bundleMap[(int)$r['id']]??[]);
      }
      unset($r);
      echo json_encode(['ok'=>true,'items'=>$rows],JSON_UNESCAPED_UNICODE);exit;
    }catch(Throwable $e){http_response_code(500);echo json_encode(['ok'=>false,'error'=>'Item search failed.'],JSON_UNESCAPED_UNICODE);exit;}
}


if($route==='bundle-components-api'){
    $u=require_login(); $cid=(int)$u['company_id'];
    header('Content-Type: application/json; charset=utf-8');
    $itemId=(int)($_GET['item_id']??0);
    if($itemId<=0){echo json_encode(['ok'=>true,'items'=>[]],JSON_UNESCAPED_UNICODE);exit;}
    try{
        $map=bundle_map_for_company(db(),$cid);
        $items=[];
        foreach(($map[$itemId]??[]) as $r){
            $items[]=[
                'item_id'=>(int)($r['item_id']??$r['component_item_id']??0),
                'name'=>(string)($r['name']??''),
                'quantity'=>(float)($r['quantity']??1),
                'unit_symbol'=>(string)($r['unit_symbol']??''),
                'serial_tracked'=>(int)($r['serial_tracked']??0),
                'sale_price'=>(float)($r['sale_price']??0),
                'purchase_price'=>(float)($r['purchase_price']??0),
            ];
        }
        echo json_encode(['ok'=>true,'items'=>$items],JSON_UNESCAPED_UNICODE);exit;
    }catch(Throwable $e){
        error_log('bundle components api: '.$e->getMessage());
        http_response_code(500);echo json_encode(['ok'=>false,'error'=>'Bundle components lookup failed.'],JSON_UNESCAPED_UNICODE);exit;
    }
}

if($route==='inline-party-create'){
    $u=require_login();
    header('Content-Type: application/json; charset=utf-8');
    if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['ok'=>false,'error'=>'Method not allowed.']);exit;}
    try{
        check_csrf();
        $cid=(int)$u['company_id'];
        $name=trim((string)($_POST['name']??''));
        $phone=preg_replace('/\D+/','',(string)($_POST['phone']??''));
        $email=trim((string)($_POST['email']??''));
        $roles=array_values(array_unique(array_intersect(
            ['customer','supplier','investor','lender','borrower','employee','other'],
            (array)($_POST['party_roles']??[])
        )));
        $address=trim((string)($_POST['address']??''));
        $opening=(float)($_POST['opening_balance']??0);
        $openingType=(string)($_POST['opening_balance_type']??'receivable');
        $limit=(float)($_POST['credit_limit']??0);
        if($name==='')throw new RuntimeException('Party name is required.');
        if(!preg_match('/^(013|014|015|016|017|018|019)\d{8}$/',$phone))throw new RuntimeException('Phone must be 11 digits and start with 013–019.');
        if(!$roles)throw new RuntimeException('Select at least one party role.');
        if(!in_array($openingType,['receivable','payable','capital','loan_given','loan_taken'],true))$openingType='receivable';
        $ptype=in_array('customer',$roles,true)&&in_array('supplier',$roles,true)?'both':(in_array('supplier',$roles,true)?'supplier':'customer');
        $pdo=db();
        $st=$pdo->prepare('SELECT id FROM parties WHERE company_id=? AND phone=? AND deleted_at IS NULL LIMIT 1');
        $st->execute([$cid,$phone]);
        if($st->fetchColumn())throw new RuntimeException('This phone number already belongs to another party.');
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO parties(company_id,name,phone,email,party_type,address,opening_balance,opening_balance_type,credit_limit) VALUES(?,?,?,?,?,?,?,?,?)')
            ->execute([$cid,$name,$phone,$email,$ptype,$address,$opening,$openingType,$limit]);
        $id=(int)$pdo->lastInsertId();
        $ins=$pdo->prepare('INSERT INTO party_roles(party_id,role) VALUES(?,?)');
        foreach($roles as $r)$ins->execute([$id,$r]);
        $pdo->commit();
        audit('create','party',$id,['name'=>$name,'phone'=>$phone,'roles'=>$roles,'source'=>'inline_form']);
        echo json_encode(['ok'=>true,'party'=>['id'=>$id,'name'=>$name,'phone'=>$phone,'email'=>$email,'roles'=>$roles]],JSON_UNESCAPED_UNICODE);exit;
    }catch(Throwable $e){
        if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
        http_response_code(422); echo json_encode(['ok'=>false,'error'=>$e instanceof PDOException && $e->getCode()==='23000'?'This phone number already belongs to another party.':$e->getMessage()],JSON_UNESCAPED_UNICODE);exit;
    }
}

if($route==='inline-product-create'){
    $u=require_login();
    header('Content-Type: application/json; charset=utf-8');
    if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['ok'=>false,'error'=>'Method not allowed.']);exit;}
    try{
        check_csrf();
        $cid=(int)$u['company_id']; $pdo=db();
        $name=trim((string)($_POST['name']??''));
        $code=trim((string)($_POST['code']??''));
        $categoryId=(int)($_POST['category_id']??0)?:null;
        $unitId=(int)($_POST['unit_id']??0)?:null;
        $sale=(float)($_POST['sale_price']??0); $wh=(float)($_POST['wholesale_price']??0); $minWh=(float)($_POST['min_wholesale_qty']??0); $buy=(float)($_POST['purchase_price']??0);
        $opening=max(0,(float)($_POST['opening_stock']??0)); $low=max(0,(float)($_POST['low_stock_limit']??0));
        $serial=!empty($_POST['serial_tracked'])?1:0; $desc=trim((string)($_POST['description']??''));
        if($name==='')throw new RuntimeException('Product name is required.');
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO items(company_id,item_type,name,code,barcode,serial_tracked,category_id,unit_id,sale_price,wholesale_price,min_wholesale_qty,purchase_price,opening_stock,low_stock_limit,description) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$cid,'product',$name,$code?:null,null,$serial,$categoryId,$unitId,$sale,$wh,$minWh,$buy,$opening,$low,$desc]);
        $id=(int)$pdo->lastInsertId();
        if($opening>0.0001){
            $pdo->prepare('INSERT INTO stock_movements(company_id,item_id,movement_date,quantity,unit_price,movement_type,note) VALUES(?,?,?,?,?,?,?)')
                ->execute([$cid,$id,date('Y-m-d'),$opening,$buy,'opening_stock','Opening Stock']);
        }
        $pdo->commit();
        $st=$pdo->prepare('SELECT i.id,i.name,i.code,i.barcode,i.sale_price,i.wholesale_price,i.purchase_price,i.unit_id,i.item_type,i.serial_tracked,COALESCE(u.symbol,"") unit_symbol FROM items i LEFT JOIN units u ON u.id=i.unit_id WHERE i.id=? AND i.company_id=? LIMIT 1');
        $st->execute([$id,$cid]);$item=$st->fetch(PDO::FETCH_ASSOC) ?: [];
        audit('create','item',$id,['name'=>$name,'type'=>'product','opening_stock'=>$opening,'source'=>'inline_form']);
        foreach(['sale_price','wholesale_price','purchase_price'] as $k)$item[$k]=(float)($item[$k]??0);
        $item['id']=(int)$item['id']; $item['serial_tracked']=(int)$item['serial_tracked'];
        echo json_encode(['ok'=>true,'item'=>$item],JSON_UNESCAPED_UNICODE);exit;
    }catch(Throwable $e){
        if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
        http_response_code(422); echo json_encode(['ok'=>false,'error'=>$e instanceof PDOException && $e->getCode()==='23000'?'A product with this value already exists.':$e->getMessage()],JSON_UNESCAPED_UNICODE);exit;
    }
}

