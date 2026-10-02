<?php
if($route==='items'){
    $u=require_login();
    $cid=(int)$u['company_id'];
    $pdo=db();
    try{
        $pdo->exec('CREATE TABLE IF NOT EXISTS item_categories (
          item_id INT UNSIGNED NOT NULL,
          category_id INT UNSIGNED NOT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (item_id,category_id),
          KEY idx_item_categories_category (category_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $pdo->exec("INSERT IGNORE INTO item_categories(item_id,category_id)
                    SELECT id,category_id FROM items
                    WHERE category_id IS NOT NULL AND category_id>0");
    }catch(Throwable $e){ error_log('multi-category schema: '.$e->getMessage()); }

    /* v235: separate item metadata fields. The previous Item Note update reused
       description; migrate that content into item_note once, then keep the four
       fields independent going forward. */
    static $itemMetaReady = false;
    if(!$itemMetaReady){
        $itemMetaReady = true;
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM items")->fetchAll(PDO::FETCH_COLUMN,0);
            foreach ([
                'item_note' => 'TEXT NULL',
                'warranty'  => 'VARCHAR(255) NULL',
                'location'  => 'VARCHAR(255) NULL',
            ] as $col=>$def) {
                if(!in_array($col,$cols,true)) {
                    $pdo->exec("ALTER TABLE items ADD COLUMN `$col` $def");
                }
            }
            // The previous note feature stored notes in description. Preserve
            // them by moving them to item_note without overwriting a real note.
            $pdo->exec("UPDATE items SET item_note=description, description=NULL
                         WHERE (item_note IS NULL OR item_note='')
                           AND description IS NOT NULL AND TRIM(description)<>''");
        } catch(Throwable $e) {
            error_log('v235 item metadata schema: '.$e->getMessage());
        }
    }
    $tab=$_GET['tab']??'products';
    if(!in_array($tab,['products','services','categories','units'],true))$tab='products';

    if($_SERVER['REQUEST_METHOD']==='POST' && in_array($_POST['item_tx_action']??'', ['delete_stock','duplicate_stock'], true)){
        check_csrf();
        $stockId=(int)($_POST['stock_id']??0);
        $itemId=(int)($_POST['item_id']??0);
        try{
            $st=$pdo->prepare('SELECT sm.*,i.name item_name FROM stock_movements sm JOIN items i ON i.id=sm.item_id WHERE sm.id=? AND sm.item_id=? AND sm.company_id=? LIMIT 1');
            $st->execute([$stockId,$itemId,$cid]); $sm=$st->fetch();
            if(!$sm) throw new RuntimeException('Stock transaction not found.');
            $act=$_POST['item_tx_action'];
            if($act==='delete_stock'){
                $pdo->beginTransaction();
                $pdo->prepare('DELETE FROM stock_movements WHERE id=? AND item_id=? AND company_id=?')->execute([$stockId,$itemId,$cid]);
                $pdo->commit(); audit('delete','stock_movement',$stockId,['item_id'=>$itemId,'movement_type'=>$sm['movement_type'],'quantity'=>$sm['quantity']]); flash('success','Stock transaction deleted.');
            }else{
                $qty=(float)$sm['quantity'];
                $pdo->beginTransaction();
                $copyQty=$qty;
                $note='Duplicate of STK-'.$stockId;
                $pdo->prepare('INSERT INTO stock_movements(company_id,item_id,transaction_id,movement_date,quantity,unit_price,movement_type,note) VALUES(?,?,?,?,?,?,?,?)')->execute([$cid,$itemId,$sm['transaction_id'],$sm['movement_date'],$copyQty,$sm['unit_price'],$sm['movement_type'],$note]);
                $newId=(int)$pdo->lastInsertId();
                $pdo->commit(); audit('duplicate','stock_movement',$newId,['source_stock_movement'=>$stockId,'item_id'=>$itemId]); flash('success','Stock transaction duplicated as STK-'.$newId.'.');
            }
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();flash('error',$e->getMessage());}
        redirect('items?tab='.$tab.'&view='.$itemId);
    }
page_start('Items');
    $cid=(int)$u['company_id'];
    $pdo=db();
    $tab=$_GET['tab']??'products';
    if(!in_array($tab,['products','services','categories','units'],true))$tab='products';
    $editId=(int)($_GET['edit']??0);
    $selected=null;
    if(isset($_GET['view']) && !isset($_GET['edit'])){
        $vid=(int)$_GET['view'];
        $st=$pdo->prepare('SELECT i.*,c.name category_name,u.name unit_name,u.symbol unit_symbol,COALESCE((SELECT SUM(sm.quantity) FROM stock_movements sm WHERE sm.company_id=i.company_id AND sm.item_id=i.id
                     AND (sm.transaction_id IS NULL OR EXISTS (
                         SELECT 1 FROM transactions st
                         WHERE st.id=sm.transaction_id
                           AND st.company_id=sm.company_id
                           AND st.deleted_at IS NULL
                     ))),0) current_stock FROM items i LEFT JOIN categories c ON c.id=i.category_id LEFT JOIN units u ON u.id=i.unit_id WHERE i.id=? AND i.company_id=? LIMIT 1');
        $st->execute([$vid,$cid]);$selected=$st->fetch()?:null;
        if($selected && $selected['item_type']==='product') $selected['bundle_components']=bundle_components_for_parent($pdo,$cid,(int)$selected['id']);
    }
    if($_SERVER['REQUEST_METHOD']==='POST'){
        check_csrf();
        $action=$_POST['action']??'';
        try{
            if($action==='save_bundle'){
                $parentId=(int)($_POST['parent_item_id']??0);
                if($parentId<=0) throw new RuntimeException('Bundle product not found.');
                $st=$pdo->prepare('SELECT id,name,item_type,active FROM items WHERE id=? AND company_id=? LIMIT 1');
                $st->execute([$parentId,$cid]); $parent=$st->fetch();
                if(!$parent || (int)$parent['active']!==1) throw new RuntimeException('Bundle product not found.');
                if($parent['item_type']!=='product') throw new RuntimeException('Only products can have included free items.');
                $ids=(array)($_POST['component_item_id']??[]); $qtys=(array)($_POST['component_qty']??[]); $clean=[]; $seen=[]; $sort=0;
                foreach($ids as $i=>$raw){
                    $componentId=(int)$raw; $qty=(float)($qtys[$i]??0); if($componentId<=0||$qty<=0)continue;
                    if($componentId===$parentId)throw new RuntimeException('A product cannot include itself.');
                    if(isset($seen[$componentId]))throw new RuntimeException('The same included item cannot be added twice.');
                    $st=$pdo->prepare('SELECT id,item_type,active FROM items WHERE id=? AND company_id=? LIMIT 1');$st->execute([$componentId,$cid]);$component=$st->fetch();
                    if(!$component || (int)$component['active']!==1)throw new RuntimeException('Included item not found.');
                    if($component['item_type']!=='product')throw new RuntimeException('Only products can be included as free bundle items.');
                    $seen[$componentId]=true;$clean[]=[$componentId,$qty,$sort++];
                }
                $pdo->beginTransaction();
                try{$pdo->prepare('DELETE FROM item_bundles WHERE company_id=? AND parent_item_id=?')->execute([$cid,$parentId]);$ins=$pdo->prepare('INSERT INTO item_bundles(company_id,parent_item_id,component_item_id,quantity,sort_order) VALUES(?,?,?,?,?)');foreach($clean as $r)$ins->execute([$cid,$parentId,$r[0],$r[1],$r[2]]);$pdo->commit();}
                catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
                audit('update','item_bundle',$parentId,['component_count'=>count($clean)]);flash('success',count($clean)?'Bundle items saved successfully.':'Bundle items cleared.');redirect('items?tab='.$tab.'&view='.$parentId);
            }
            if($action==='save_item'){
                $type=($_POST['item_type']??'product')==='service'?'service':'product';
                $name=trim($_POST['name']??'');
                $code=trim($_POST['code']??'');
                // Barcode is managed separately from Add/Edit Item. Preserve an existing barcode on edit.
                $barcode='';
                if($editId){
                    $keep=$pdo->prepare('SELECT barcode FROM items WHERE id=? AND company_id=? LIMIT 1');
                    $keep->execute([$editId,$cid]);
                    $keepRow=$keep->fetch();
                    $barcode=(string)($keepRow['barcode']??'');
                }
                if($name==='')throw new RuntimeException('Item name is required.');
                $categoryIds=array_values(array_unique(array_map('intval',(array)($_POST['category_ids']??[]))));
                if(!$categoryIds && !empty($_POST['category_id'])) $categoryIds=[(int)$_POST['category_id']];
                if($categoryIds){
                    $ph=implode(',',array_fill(0,count($categoryIds),'?'));
                    $cs=$pdo->prepare("SELECT id,type FROM categories WHERE company_id=? AND id IN ($ph)");
                    $cs->execute(array_merge([$cid],$categoryIds));
                    $validCats=$cs->fetchAll(PDO::FETCH_KEY_PAIR);
                    $categoryIds=array_values(array_filter($categoryIds,fn($v)=>isset($validCats[$v]) && $validCats[$v]===$type));
                }
                $categoryId=$categoryIds[0]??null;
                $unitId=(int)($_POST['unit_id']??0)?:null;
                $sale=(float)($_POST['sale_price']??0);$wh=(float)($_POST['wholesale_price']??0);$minWh=(float)($_POST['min_wholesale_qty']??0);$buy=(float)($_POST['purchase_price']??0);
                $opening=$type==='product'?(float)($_POST['opening_stock']??0):0;$low=$type==='product'?(float)($_POST['low_stock_limit']??0):0;
                if($editId){
                    $st=$pdo->prepare('SELECT * FROM items WHERE id=? AND company_id=? LIMIT 1');$st->execute([$editId,$cid]);$old=$st->fetch();if(!$old)throw new RuntimeException('Item not found.');
                    $pdo->prepare('UPDATE items SET item_type=?,name=?,code=?,barcode=?,serial_tracked=?,category_id=?,unit_id=?,sale_price=?,wholesale_price=?,min_wholesale_qty=?,purchase_price=?,low_stock_limit=?,description=?,item_note=?,warranty=?,location=? WHERE id=? AND company_id=?')->execute([$type,$name,$code?:null,$barcode?:null,($type==='product' && !empty($_POST['serial_tracked']))?1:0,$categoryId,$unitId,$sale,$wh,$minWh,$buy,$low,trim($_POST['description']??'')?:null,trim($_POST['item_note']??'')?:null,trim($_POST['warranty']??'')?:null,trim($_POST['location']??'')?:null,$editId,$cid]);
                    $id=$editId;
                    $pdo->prepare('DELETE FROM item_categories WHERE item_id=?')->execute([$id]);
                    if($categoryIds){
                        $insCat=$pdo->prepare('INSERT IGNORE INTO item_categories(item_id,category_id) VALUES(?,?)');
                        foreach($categoryIds as $catId)$insCat->execute([$id,$catId]);
                    }
                    if($type==='product' && abs($opening-(float)$old['opening_stock'])>0.0001){
                        $delta=$opening-(float)$old['opening_stock'];
                        $pdo->prepare('UPDATE items SET opening_stock=? WHERE id=? AND company_id=?')->execute([$opening,$id,$cid]);
                        if(abs($delta)>0.0001){
                            $pdo->prepare('INSERT INTO stock_movements(company_id,item_id,movement_date,quantity,unit_price,movement_type,note) VALUES(?,?,?,?,?,?,?)')
                               ->execute([$cid,$id,date('Y-m-d'),$delta,$buy>0?$buy:(float)$old['purchase_price'],'opening_adjustment','Opening stock edited']);
                        }
                    }
                    audit('update','item',$id,['name'=>$name,'type'=>$type]);flash('success','Item updated successfully.');
                }else{
                    $pdo->beginTransaction();
                    try {
                        $pdo->prepare('INSERT INTO items(company_id,item_type,name,code,barcode,serial_tracked,category_id,unit_id,sale_price,wholesale_price,min_wholesale_qty,purchase_price,opening_stock,low_stock_limit,description,item_note,warranty,location) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$cid,$type,$name,$code?:null,$barcode?:null,($type==='product' && !empty($_POST['serial_tracked']))?1:0,$categoryId,$unitId,$sale,$wh,$minWh,$buy,$opening,$low,trim($_POST['description']??'')?:null,trim($_POST['item_note']??'')?:null,trim($_POST['warranty']??'')?:null,trim($_POST['location']??'')?:null]);
                        $id=(int)$pdo->lastInsertId();
                        if($categoryIds){
                            $insCat=$pdo->prepare('INSERT IGNORE INTO item_categories(item_id,category_id) VALUES(?,?)');
                            foreach($categoryIds as $catId)$insCat->execute([$id,$catId]);
                        }
                        if($type==='product' && abs($opening)>0.0001){
                            $pdo->prepare('INSERT INTO stock_movements(company_id,item_id,movement_date,quantity,unit_price,movement_type,note) VALUES(?,?,?,?,?,?,?)')
                                ->execute([$cid,$id,date('Y-m-d'),$opening,$buy,'opening_stock','Opening Stock']);
                        }
                        $pdo->commit();
                    } catch(Throwable $txe) {
                        if($pdo->inTransaction())$pdo->rollBack();
                        throw $txe;
                    }
                    audit('create','item',$id,['name'=>$name,'type'=>$type,'opening_stock'=>$opening]);flash('success','Item added successfully.');
                }
                redirect('items?tab='.($type==='service'?'services':'products'));
            }
            if($action==='save_item_note'){
                $iid=(int)($_POST['item_id']??0);
                $note=trim((string)($_POST['item_note']??''));
                if($iid<=0) throw new RuntimeException('Item not found.');
                $st=$pdo->prepare('SELECT id,name FROM items WHERE id=? AND company_id=? AND active=1 LIMIT 1');
                $st->execute([$iid,$cid]); $it=$st->fetch();
                if(!$it) throw new RuntimeException('Item not found.');
                $pdo->prepare('UPDATE items SET item_note=? WHERE id=? AND company_id=?')->execute([$note!==''?$note:null,$iid,$cid]);
                audit('update','item_note',$iid,['item_id'=>$iid,'note'=>$note]);
                flash('success',$note!==''?'Item note saved successfully.':'Item note removed successfully.');
                redirect('items?tab='.$tab.'&view='.$iid);
            }
            if($action==='save_category'){
                $name=trim($_POST['name']??'');$type=$_POST['type']==='service'?'service':'product';if($name==='')throw new RuntimeException('Category name is required.');
                $pdo->prepare('INSERT INTO categories(company_id,name,type) VALUES(?,?,?)')->execute([$cid,$name,$type]);flash('success','Category added.');redirect('items?tab=categories');
            }
            if($action==='save_unit'){
                $name=trim($_POST['name']??'');$symbol=trim($_POST['symbol']??'');if($name==='')throw new RuntimeException('Unit name is required.');
                $pdo->prepare('INSERT INTO units(company_id,name,symbol) VALUES(?,?,?)')->execute([$cid,$name,$symbol?:null]);flash('success','Unit added.');redirect('items?tab=units');
            }
            if($action==='edit_category'){
                $id=(int)($_POST['id']??0);
                $name=trim($_POST['name']??'');
                $type=$_POST['type']==='service'?'service':'product';
                if($id<=0||$name==='') throw new RuntimeException('Category name is required.');
                $st=$pdo->prepare('SELECT id FROM categories WHERE id=? AND company_id=? LIMIT 1');
                $st->execute([$id,$cid]); if(!$st->fetch()) throw new RuntimeException('Category not found.');
                $pdo->prepare('UPDATE categories SET name=?, type=? WHERE id=? AND company_id=?')->execute([$name,$type,$id,$cid]);
                audit('update','category',$id,['name'=>$name,'type'=>$type]);
                flash('success','Category updated.');
                redirect('items?tab=categories');
            }
            if($action==='delete_category'){
                $id=(int)($_POST['id']??0);
                $st=$pdo->prepare('SELECT id,name FROM categories WHERE id=? AND company_id=? LIMIT 1');
                $st->execute([$id,$cid]); $cat=$st->fetch();
                if(!$cat) throw new RuntimeException('Category not found.');
                $st=$pdo->prepare('SELECT COUNT(DISTINCT item_id) FROM item_categories ic JOIN items ii ON ii.id=ic.item_id AND ii.company_id=? AND ii.active=1 WHERE ic.category_id=?');
                $st->execute([$cid,$id]); $count=(int)$st->fetchColumn();
                if($count>0) throw new RuntimeException('This category cannot be deleted because '.$count.' active item(s) use it. Reassign those items first.');
                $pdo->prepare('DELETE FROM categories WHERE id=? AND company_id=?')->execute([$id,$cid]);
                audit('delete','category',$id,['name'=>$cat['name']]);
                flash('success','Category deleted.');
                redirect('items?tab=categories');
            }
            if($action==='edit_unit'){
                $id=(int)($_POST['id']??0);
                $name=trim($_POST['name']??'');
                $symbol=trim($_POST['symbol']??'');
                if($id<=0||$name==='') throw new RuntimeException('Unit name is required.');
                $st=$pdo->prepare('SELECT id FROM units WHERE id=? AND company_id=? LIMIT 1');
                $st->execute([$id,$cid]); if(!$st->fetch()) throw new RuntimeException('Unit not found.');
                $pdo->prepare('UPDATE units SET name=?, symbol=? WHERE id=? AND company_id=?')->execute([$name,$symbol?:null,$id,$cid]);
                audit('update','unit',$id,['name'=>$name,'symbol'=>$symbol]);
                flash('success','Unit updated.');
                redirect('items?tab=units');
            }
            if($action==='delete_unit'){
                $id=(int)($_POST['id']??0);
                $st=$pdo->prepare('SELECT id,name FROM units WHERE id=? AND company_id=? LIMIT 1');
                $st->execute([$id,$cid]); $unit=$st->fetch();
                if(!$unit) throw new RuntimeException('Unit not found.');
                $st=$pdo->prepare('SELECT COUNT(*) FROM items WHERE unit_id=? AND company_id=? AND active=1');
                $st->execute([$id,$cid]); $count=(int)$st->fetchColumn();
                if($count>0) throw new RuntimeException('This unit cannot be deleted because '.$count.' active item(s) use it. Reassign those items first.');
                $pdo->prepare('DELETE FROM units WHERE id=? AND company_id=?')->execute([$id,$cid]);
                audit('delete','unit',$id,['name'=>$unit['name']]);
                flash('success','Unit deleted.');
                redirect('items?tab=units');
            }
            if($action==='adjust_stock'){
                $iid=(int)($_POST['item_id']??0);$qty=(float)($_POST['quantity']??0);$mode=$_POST['mode']==='reduce'?'reduce':'add';$note=trim($_POST['note']??'');$adjustmentDate=$_POST['adjustment_date']??date('Y-m-d');$atPrice=(float)($_POST['at_price']??0);
                if($iid<=0||$qty<=0)throw new RuntimeException('Enter a quantity greater than zero.');
                if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$adjustmentDate))$adjustmentDate=date('Y-m-d');
                $st=$pdo->prepare('SELECT id,item_type,name,purchase_price FROM items WHERE id=? AND company_id=? AND active=1');$st->execute([$iid,$cid]);$it=$st->fetch();if(!$it||$it['item_type']!=='product')throw new RuntimeException('Only product stock can be adjusted.');
                $movementQty=$mode==='reduce' ? -$qty : $qty;
                $movementType=$mode==='reduce' ? 'manual_reduce' : 'manual_add';
                $detail=$note?:($mode==='reduce'?'Manual stock reduction':'Manual stock addition');
                $pdo->prepare('INSERT INTO stock_movements(company_id,item_id,movement_date,quantity,unit_price,movement_type,note) VALUES(?,?,?,?,?,?,?)')->execute([$cid,$iid,$adjustmentDate,$movementQty,$atPrice>0?$atPrice:(float)$it['purchase_price'],$movementType,$detail]);
                audit('adjust','item',$iid,['mode'=>$mode,'quantity'=>$qty,'signed_quantity'=>$movementQty,'at_price'=>$atPrice,'adjustment_date'=>$adjustmentDate,'note'=>$note]);flash('success',$mode==='reduce'?'Stock reduced successfully.':'Stock added successfully.');redirect('items?tab='.$tab.'&view='.$iid);
            }
            if($action==='generate_barcode'){
                $iid=(int)($_POST['item_id']??0);
                if($iid<=0) throw new RuntimeException('Item not found.');
                $st=$pdo->prepare('SELECT id,name,barcode FROM items WHERE id=? AND company_id=? AND active=1 LIMIT 1');
                $st->execute([$iid,$cid]); $it=$st->fetch();
                if(!$it) throw new RuntimeException('Item not found.');
                if(trim((string)($it['barcode']??''))!==''){
                    flash('success','This item already has a barcode.');
                    redirect('items?tab='.($it['item_type']==='service'?'services':'products').'&view='.$iid);
                }
                do {
                    $barcode='20'.str_pad((string)$cid,4,'0',STR_PAD_LEFT).date('ymdHis').random_int(0,9);
                    $chk=$pdo->prepare('SELECT COUNT(*) FROM items WHERE barcode=? AND company_id=?');
                    $chk->execute([$barcode,$cid]);
                } while((int)$chk->fetchColumn()>0);
                $pdo->prepare('UPDATE items SET barcode=? WHERE id=? AND company_id=?')->execute([$barcode,$iid,$cid]);
                audit('generate_barcode','item',$iid,['barcode'=>$barcode]);
                flash('success','Barcode assigned: '.$barcode);
                redirect('items?tab='.($it['item_type']==='service'?'services':'products').'&view='.$iid);
            }
            if($action==='delete_item'){
                $iid=(int)($_POST['item_id']??0);
                $st=$pdo->prepare('SELECT id,name,item_type,active FROM items WHERE id=? AND company_id=? LIMIT 1');
                $st->execute([$iid,$cid]);$item=$st->fetch();
                if(!$item) throw new RuntimeException('Item not found.');
                $txc=$pdo->prepare('SELECT COUNT(*) FROM transaction_items ti JOIN transactions t ON t.id=ti.transaction_id WHERE ti.item_id=? AND t.company_id=?');
                $txc->execute([$iid,$cid]);$transactionCount=(int)$txc->fetchColumn();
                if($transactionCount>0) throw new RuntimeException('This item cannot be deleted because it has '.$transactionCount.' transaction(s). Delete all transactions linked to this item first.');
                $pdo->prepare('UPDATE items SET active=0 WHERE id=? AND company_id=?')->execute([$iid,$cid]);audit('delete','item',$iid);flash('success','Item moved to Recycle Bin.');redirect('items');
            }
        }catch(Throwable $e){
            flash('error',$e->getCode()==='23000'?'This name already exists.':$e->getMessage());
            redirect('items'.($editId?'?edit='.$editId:''));
        }
    }
    $items=get_items($cid);
    if (!$selected && in_array($tab,['products','services'],true)) {
        foreach ($items as $r0) {
            if (($tab==='products' && $r0['item_type']==='product') || ($tab==='services' && $r0['item_type']==='service')) {
                $selected=$r0;
                break;
            }
        }
    }
    $cats=$pdo->prepare('SELECT id,name,type FROM categories WHERE company_id=? ORDER BY type,name');$cats->execute([$cid]);$catRows=$cats->fetchAll();
    $units=$pdo->prepare('SELECT id,name,symbol FROM units WHERE company_id=? ORDER BY name');$units->execute([$cid]);$unitRows=$units->fetchAll();
    $edit=null;
    $editCategoryIds=[];
    if($editId){
        try{
            $cs=$pdo->prepare('SELECT category_id FROM item_categories WHERE item_id=? ORDER BY category_id');$cs->execute([$editId]);
            $editCategoryIds=array_map('intval',$cs->fetchAll(PDO::FETCH_COLUMN));
        }catch(Throwable $e){}
    }
    if($editId){$st=$pdo->prepare('SELECT * FROM items WHERE id=? AND company_id=? AND active=1');$st->execute([$editId,$cid]);$edit=$st->fetch()?:null;
        if(!$editCategoryIds && $edit && !empty($edit['category_id'])) $editCategoryIds=[(int)$edit['category_id']];
    }
    /** v233: Items workspace UI restore. Keep all four tabs visible and use a wider left master column. */
    echo '<style id="sense-items-ui-v233">

      .items-shell .items-workspace{grid-template-columns:360px minmax(0,1fr)!important;}
      .items-shell .item-master{min-width:0!important;}
      .items-shell .item-master-toolbar{gap:10px;}
      .items-shell .item-master-head{grid-template-columns:minmax(0,1fr) 100px;}
      .items-shell .item-master-row{grid-template-columns:minmax(0,1fr) 70px 34px!important;}
      .items-shell .item-master-name{font-size:13px;}
      .items-shell .item-master-meta{font-size:10.5px;}
      .items-shell .item-detail-area{min-width:0;}
      .items-shell .master-search-v233{width:100%;box-sizing:border-box;border:1px solid #d8dee7;border-radius:7px;padding:9px 10px;background:#fff;outline:0;}
      .items-shell .master-search-v233:focus{border-color:#1987f0;box-shadow:0 0 0 2px rgba(25,135,240,.08);}
      .items-shell .category-list-head-v233,.items-shell .unit-list-head-v233{display:grid;grid-template-columns:minmax(0,1fr) 72px;align-items:center;height:39px;padding:0 12px;border-top:1px solid #e4e8ed;border-bottom:1px solid #e4e8ed;background:#fff;color:#6b7581;font-size:12px;font-weight:600;}
      .items-shell .master-list-v233{max-height:calc(100vh - 235px);overflow-y:auto;background:#fff;}
      .items-shell .master-row-v233{display:grid;grid-template-columns:minmax(0,1fr) 78px;gap:8px;align-items:center;padding:11px 10px;border-bottom:1px solid #eef1f4;background:#fff;text-decoration:none;color:inherit;}
      .items-shell .master-row-v233:hover,.items-shell .master-row-v233.selected{background:#cfe7f5;}
      .items-shell .master-row-v233 strong{font-size:13px;color:#4e5966;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
      .items-shell .master-row-v233 small{font-size:10px;color:#9aa3ad;margin-top:2px;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
      .items-shell .master-type-v233{text-align:right;font-size:11px;color:#738297;font-weight:600;}
      .items-shell .master-symbol-v233{text-align:right;font-size:11px;color:#738297;font-weight:600;}
      .items-shell .master-empty-v233{padding:24px 14px;color:#718096;font-size:13px;text-align:center;}
      .items-shell .selected-row-v233{background:#f4f8fc;}
      @media(max-width:900px){
        .items-shell .items-workspace{grid-template-columns:1fr!important;}
        .items-shell .item-master{min-width:0!important;}
        .items-shell .master-list-v233,.items-shell .item-master-list{max-height:350px;}
      }
    </style>';
    if($tab==='categories'){
        $selectedCatId=(int)($_GET['cat_view']??0);
        ?><div class="items-shell">
          <div class="items-tabsbar">
            <a href="<?=e(url('items?tab=products'))?>">PRODUCTS</a>
            <a href="<?=e(url('items?tab=services'))?>">SERVICES</a>
            <a class="active" href="<?=e(url('items?tab=categories'))?>">CATEGORY</a>
            <a href="<?=e(url('items?tab=units'))?>">UNITS</a>
          </div>
          <div class="items-workspace">
            <aside class="item-master panel">
              <div class="item-master-toolbar">
                <button type="button" class="icon-circle" aria-label="Search category">⌕</button>
                <button type="button" class="btn item-add-btn" onclick="openModal('categoryModal')">＋ Add Category</button>
                <button type="button" class="icon-more" aria-label="More">⋮</button>
              </div>
              <div class="category-list-head-v233"><span>CATEGORY</span><span>TYPE</span></div>
              <div class="item-search-wrap"><input id="categorySearchV233" class="master-search-v233" placeholder="Search categories" oninput="filterMasterV233('categorySearchV233','categoryListV233')"></div>
              <div id="categoryListV233" class="master-list-v233">
                <?php if($catRows): foreach($catRows as $c): ?>
                  <a class="master-row-v233 <?=($selectedCatId===(int)$c['id'])?'selected':''?>" data-master-search="<?=e(strtolower($c['name'].' '.$c['type']))?>" href="<?=e(url('items?tab=categories&cat_view='.(int)$c['id']))?>">
                    <span><strong><?=e($c['name'])?></strong><small><?=e(ucfirst($c['type']))?> category</small></span>
                    <span class="master-type-v233"><?=e(ucfirst($c['type']))?></span>
                  </a>
                <?php endforeach; else: ?><div class="master-empty-v233">No categories yet.</div><?php endif; ?>
              </div>
            </aside>
            <section class="item-detail-area">
              <?php
                $selectedCategory=null; $categoryItems=[];
                if($selectedCatId>0){
                    $cs=$pdo->prepare('SELECT id,name,type FROM categories WHERE id=? AND company_id=? LIMIT 1');
                    $cs->execute([$selectedCatId,$cid]); $selectedCategory=$cs->fetch()?:null;
                    if($selectedCategory){
                        $isql='SELECT i.id,i.name,i.code,i.item_type,i.sale_price,i.purchase_price,u.symbol unit_symbol,
                               COALESCE((SELECT SUM(sm.quantity) FROM stock_movements sm
                                         WHERE sm.company_id=i.company_id AND sm.item_id=i.id
                                           AND (sm.transaction_id IS NULL OR EXISTS(
                                               SELECT 1 FROM transactions st
                                               WHERE st.id=sm.transaction_id AND st.company_id=sm.company_id AND st.deleted_at IS NULL
                                           ))),0) current_stock
                               FROM item_categories ic
                               JOIN items i ON i.id=ic.item_id AND i.company_id=? AND i.active=1
                               LEFT JOIN units u ON u.id=i.unit_id
                               WHERE ic.category_id=?
                               ORDER BY i.name ASC';
                        $ist=$pdo->prepare($isql); $ist->execute([$cid,$selectedCatId]); $categoryItems=$ist->fetchAll();
                    }
                }
              ?>
              <div class="item-detail-card panel">
                <div class="item-detail-top">
                  <div>
                    <h2><?= $selectedCategory ? e($selectedCategory['name']) : 'CATEGORIES' ?></h2>
                    <div class="item-subline">
                      <?= $selectedCategory ? 'Items in this category' : 'Manage product and service categories' ?>
                    </div>
                  </div>
                  <button class="btn primary" onclick="openModal('categoryModal')">⊕ Add Category</button>
                </div>
              </div>

              <?php if($selectedCategory): ?>
                <div class="panel category-items-panel-v204" style="margin-top:8px">
                  <div class="panel-head">
                    <h2>Items in <?=e($selectedCategory['name'])?></h2>
                    <span class="subtle"><?=count($categoryItems)?> item<?=count($categoryItems)===1?'':'s'?></span>
                  </div>
                  <div class="table-wrap">
                    <table>
                      <thead>
                        <tr><th>ITEM</th><th>TYPE</th><th>STOCK</th><th>SALE PRICE</th><th>UNIT</th></tr>
                      </thead>
                      <tbody>
                        <?php if($categoryItems): foreach($categoryItems as $ci): ?>
                          <tr>
                            <td>
                              <a class="category-item-link-v204" href="<?=e(url('items?tab='.($ci['item_type']==='service'?'services':'products').'&view='.(int)$ci['id']))?>">
                                <strong><?=e($ci['name'])?></strong>
                                <?php if(!empty($ci['code'])): ?><small><?=e($ci['code'])?></small><?php endif; ?>
                              </a>
                            </td>
                            <td><?=e(ucfirst($ci['item_type']))?></td>
                            <td class="<?=((float)$ci['current_stock']<0)?'category-stock-negative-v204':''?>"><?= $ci['item_type']==='service'?'—':e(qty((float)$ci['current_stock'])) ?></td>
                            <td><?=e(money((float)$ci['sale_price']))?></td>
                            <td><?=e($ci['unit_symbol']??'—')?></td>
                          </tr>
                        <?php endforeach; else: ?>
                          <tr><td colspan="5" class="subtle" style="padding:24px">No items are assigned to this category yet.</td></tr>
                        <?php endif; ?>
                      </tbody>
                    </table>
                  </div>
                </div>
              <?php else: ?>
                <div class="panel" style="margin-top:8px">
                  <div class="panel-head"><h2>Category List</h2><span class="subtle"><?=count($catRows)?> total</span></div>
                  <div class="table-wrap"><table><thead><tr><th>NAME</th><th>TYPE</th><th>ACTION</th></tr></thead><tbody>
                    <?php foreach($catRows as $c): ?>
                      <tr><td><strong><?=e($c['name'])?></strong></td><td><?=e(ucfirst($c['type']))?></td><td><button type="button" class="btn small" onclick="openCategoryEdit(<?= (int)$c['id']?>,<?=json_encode($c['name'])?>,<?=json_encode($c['type'])?>)">Edit</button> <form method="post" style="display:inline" onsubmit="return confirm('Delete this category?')"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="delete_category"><input type="hidden" name="id" value="<?=$c['id']?>"><button class="btn small danger" type="submit">Delete</button></form></td></tr>
                    <?php endforeach; if(!$catRows): ?><tr><td colspan="3" class="subtle">No categories yet.</td></tr><?php endif; ?>
                  </tbody></table></div>
                </div>
              <?php endif; ?>
            </section>          </div>
        </div>
        <div class="modal-backdrop" id="categoryModal" onclick="if(event.target===this)closeModal('categoryModal')"><div class="modal"><div class="modal-head"><h2>Add Category</h2><button class="close" type="button" onclick="closeModal('categoryModal')">×</button></div><form method="post"><div class="form-body"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="save_category"><div class="grid2"><div class="form-group"><label>Category Name*</label><input name="name" required></div><div class="form-group"><label>Type</label><select name="type"><option value="product">Product</option><option value="service">Service</option></select></div></div></div><div class="form-footer"><button type="button" class="btn" onclick="closeModal('categoryModal')">Cancel</button><button class="btn primary">Save</button></div></form></div></div>
        <div class="modal-backdrop" id="categoryEditModal" onclick="if(event.target===this)closeModal('categoryEditModal')"><div class="modal"><div class="modal-head"><h2>Edit Category</h2><button class="close" type="button" onclick="closeModal('categoryEditModal')">×</button></div><form method="post"><div class="form-body"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="edit_category"><input type="hidden" name="id" id="categoryEditId"><div class="grid2"><div class="form-group"><label>Category Name*</label><input name="name" id="categoryEditName" required></div><div class="form-group"><label>Type</label><select name="type" id="categoryEditType"><option value="product">Product</option><option value="service">Service</option></select></div></div></div><div class="form-footer"><button type="button" class="btn" onclick="closeModal('categoryEditModal')">Cancel</button><button class="btn primary">Update</button></div></form></div></div>
        <script>function openCategoryEdit(id,name,type){document.getElementById('categoryEditId').value=id;document.getElementById('categoryEditName').value=name;document.getElementById('categoryEditType').value=type;openModal('categoryEditModal')}function filterMasterV233(inputId,listId){var q=(document.getElementById(inputId)?.value||'').toLowerCase().trim();document.querySelectorAll('#'+listId+' [data-master-search]').forEach(function(r){r.style.display=(!q||r.dataset.masterSearch.indexOf(q)!==-1)?'':'none';});}</script></div><?php page_end();exit;
    }
    if($tab==='units'){
        $selectedUnitId=(int)($_GET['unit_view']??0);
        ?><div class="items-shell">
          <div class="items-tabsbar">
            <a href="<?=e(url('items?tab=products'))?>">PRODUCTS</a>
            <a href="<?=e(url('items?tab=services'))?>">SERVICES</a>
            <a href="<?=e(url('items?tab=categories'))?>">CATEGORY</a>
            <a class="active" href="<?=e(url('items?tab=units'))?>">UNITS</a>
          </div>
          <div class="items-workspace">
            <aside class="item-master panel">
              <div class="item-master-toolbar">
                <button type="button" class="icon-circle" aria-label="Search unit">⌕</button>
                <button type="button" class="btn item-add-btn" onclick="openModal('unitModal')">＋ Add Unit</button>
                <button type="button" class="icon-more" aria-label="More">⋮</button>
              </div>
              <div class="unit-list-head-v233"><span>UNIT</span><span>SYMBOL</span></div>
              <div class="item-search-wrap"><input id="unitSearchV233" class="master-search-v233" placeholder="Search units" oninput="filterMasterV233('unitSearchV233','unitListV233')"></div>
              <div id="unitListV233" class="master-list-v233">
                <?php if($unitRows): foreach($unitRows as $x): ?>
                  <a class="master-row-v233 <?=($selectedUnitId===(int)$x['id'])?'selected':''?>" data-master-search="<?=e(strtolower($x['name'].' '.($x['symbol']??'')))?>" href="<?=e(url('items?tab=units&unit_view='.(int)$x['id']))?>">
                    <span><strong><?=e($x['name'])?></strong><small><?=e($x['symbol']??'')?><?=($x['symbol']??'')!==''?' · Measurement unit':''?></small></span>
                    <span class="master-symbol-v233"><?=e($x['symbol']??'—')?></span>
                  </a>
                <?php endforeach; else: ?><div class="master-empty-v233">No units yet.</div><?php endif; ?>
              </div>
            </aside>
            <section class="item-detail-area">
              <div class="item-detail-card panel">
                <div class="item-detail-top"><div><h2>UNITS</h2><div class="item-subline">Measurement units for products and services</div></div><button class="btn primary" onclick="openModal('unitModal')">⊕ Add Unit</button></div>
              </div>
              <div class="panel" style="margin-top:8px"><div class="panel-head"><h2>Unit List</h2><span class="subtle"><?=count($unitRows)?> total</span></div><div class="table-wrap"><table><thead><tr><th>NAME</th><th>SYMBOL</th><th>ACTION</th></tr></thead><tbody>
                <?php foreach($unitRows as $x): ?>
                  <tr class="<?=($selectedUnitId===(int)$x['id'])?'selected-row-v233':''?>"><td><strong><?=e($x['name'])?></strong></td><td><?=e($x['symbol']??'')?></td><td><button type="button" class="btn small" onclick="openUnitEdit(<?= (int)$x['id']?>,<?=json_encode($x['name'])?>,<?=json_encode($x['symbol']??'')?>)">Edit</button> <form method="post" style="display:inline" onsubmit="return confirm('Delete this unit?')"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="delete_unit"><input type="hidden" name="id" value="<?=$x['id']?>"><button class="btn small danger" type="submit">Delete</button></form></td></tr>
                <?php endforeach; if(!$unitRows): ?><tr><td colspan="3" class="subtle">No units yet.</td></tr><?php endif; ?>
              </tbody></table></div></div>
            </section>
          </div>
        </div>
        <div class="modal-backdrop" id="unitModal" onclick="if(event.target===this)closeModal('unitModal')"><div class="modal"><div class="modal-head"><h2>Add Unit</h2><button class="close" type="button" onclick="closeModal('unitModal')">×</button></div><form method="post"><div class="form-body"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="save_unit"><div class="grid2"><div class="form-group"><label>Unit Name*</label><input name="name" required></div><div class="form-group"><label>Symbol</label><input name="symbol" placeholder="pcs"></div></div></div><div class="form-footer"><button type="button" class="btn" onclick="closeModal('unitModal')">Cancel</button><button class="btn primary">Save</button></div></form></div></div>
        <div class="modal-backdrop" id="unitEditModal" onclick="if(event.target===this)closeModal('unitEditModal')"><div class="modal"><div class="modal-head"><h2>Edit Unit</h2><button class="close" type="button" onclick="closeModal('unitEditModal')">×</button></div><form method="post"><div class="form-body"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="edit_unit"><input type="hidden" name="id" id="unitEditId"><div class="grid2"><div class="form-group"><label>Unit Name*</label><input name="name" id="unitEditName" required></div><div class="form-group"><label>Symbol</label><input name="symbol" id="unitEditSymbol"></div></div></div><div class="form-footer"><button type="button" class="btn" onclick="closeModal('unitEditModal')">Cancel</button><button class="btn primary">Update</button></div></form></div></div>
        <script>function openUnitEdit(id,name,symbol){document.getElementById('unitEditId').value=id;document.getElementById('unitEditName').value=name;document.getElementById('unitEditSymbol').value=symbol;openModal('unitEditModal')}function filterMasterV233(inputId,listId){var q=(document.getElementById(inputId)?.value||'').toLowerCase().trim();document.querySelectorAll('#'+listId+' [data-master-search]').forEach(function(r){r.style.display=(!q||r.dataset.masterSearch.indexOf(q)!==-1)?'':'none';});}</script></div><?php page_end();exit;
    }
    ?><div class="items-shell">
      <div class="items-tabsbar">
        <a class="<?= $tab==='products'?'active':'' ?>" href="<?=e(url('items?tab=products'))?>">PRODUCTS</a>
        <a class="<?= $tab==='services'?'active':'' ?>" href="<?=e(url('items?tab=services'))?>">SERVICES</a>
        <a class="<?= $tab==='categories'?'active':'' ?>" href="<?=e(url('items?tab=categories'))?>">CATEGORY</a>
        <a class="<?= $tab==='units'?'active':'' ?>" href="<?=e(url('items?tab=units'))?>">UNITS</a>
      </div>
      <div class="items-workspace">
        <aside class="item-master panel">
          <div class="item-master-toolbar">
            <button type="button" class="icon-circle" aria-label="Search">⌕</button>
            <div class="add-item-split"><button type="button" class="btn item-add-btn" onclick="openModal('itemModal')">＋ Add Item</button><button type="button" class="btn item-add-caret" onclick="openModal('itemModal')">⌄</button></div>
            <button type="button" class="icon-more" aria-label="More">⋮</button>
          </div>
          <div class="item-master-head"><span>ITEM</span><span>QUANTITY</span></div>
          <div class="item-search-wrap"><input id="itemSearch" placeholder="Search items" oninput="senseItemSearchNow(this)" onkeydown="if(event.key==='Enter')event.preventDefault()"></div>
          <script>
          window.senseItemSearchNow=function(input){
            const list=document.getElementById('itemListBody');
            if(!list)return;
            const q=String(input.value||'').toLowerCase().trim();
            list._senseAllRows=list._senseAllRows||[...list.querySelectorAll('.item-master-row')];
            const all=list._senseAllRows;
            function render(ids){
              const allowed=ids?new Set(ids.map(String)):null;
              all.forEach(function(row){
                const hay=String(row.dataset.name||row.textContent||'').toLowerCase();
                const show=!q || (allowed ? allowed.has(String(row.dataset.itemId||'')) : hay.indexOf(q)!==-1);
                row._senseShow=show;
              });
              while(list.firstChild)list.removeChild(list.firstChild);
              all.forEach(function(row){if(row._senseShow)list.appendChild(row);});
            }
            clearTimeout(window.__senseItemSearchTimer);
            render(null);
            if(!q)return;
            window.__senseItemSearchTimer=setTimeout(async function(){
              try{
                const u=new URL('<?=e(url('item-search-api'))?>',location.origin);
                u.searchParams.set('q',q);
                const res=await fetch(u.toString(),{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
                const data=await res.json();
                if(!data?.ok||!Array.isArray(data.items))return;
                render(data.items.map(function(x){return x.id;}));
              }catch(_){}
            },80);
          };          </script>

          <script>
          window.itemLiveFilter=window.itemLiveFilter||function(input){
            clearTimeout(input._itemTimer);
            input._itemTimer=setTimeout(async function(){
              const list=document.getElementById('itemListBody');
              if(!list)return;
              const q=(input.value||'').toLowerCase().trim();
              const rows=[...list.querySelectorAll('.item-master-row')];
              if(!q){rows.forEach(r=>r.style.display='');return;}
              rows.forEach(function(row){
                const txt=(row.dataset.name||row.textContent||'').toLowerCase();
                row.style.display=txt.indexOf(q)!==-1?'':'none';
              });
              try{
                const u=new URL('<?=e(url('item-search-api'))?>',location.origin);
                u.searchParams.set('q',q);
                const res=await fetch(u.toString(),{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
                const data=await res.json();
                if(!data?.ok||!Array.isArray(data.items))return;
                const ids=new Set(data.items.map(function(x){return String(x.id);}));
                [...list.querySelectorAll('.item-master-row')].forEach(function(row){
                  row.style.display=ids.has(String(row.dataset.itemId||''))?'':'none';
                });
              }catch(e){}
            },60);
          };
          document.addEventListener('DOMContentLoaded',function(){
            const input=document.getElementById('itemSearch');
            const list=document.getElementById('itemListBody');
            if(!input||!list)return;
            let timer=0,seq=0;
            const rows=()=>Array.from(list.querySelectorAll('.item-master-row'));
            function localFilter(q,ids){
              const qq=(q||'').toLowerCase().trim();
              rows().forEach(function(row){
                const text=(row.dataset.name||'').toLowerCase();
                const id=String(row.dataset.itemId||'');
                const match=!qq || (ids ? ids.has(id) : text.indexOf(qq)!==-1);
                row.style.display=match?'':'none';
              });
            }
            async function search(){
              const q=input.value.trim();
              if(!q){localFilter('');return;}
              const my=++seq;
              localFilter(q,null);
              try{
                const u=new URL('<?=e(url('item-search-api'))?>',location.origin);
                u.searchParams.set('q',q);
                const res=await fetch(u.toString(),{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
                const data=await res.json();
                if(my!==seq)return;
                const ids=new Set(Array.isArray(data?.items)?data.items.map(x=>String(x.id)):[]);
                localFilter(q,ids);
              }catch(_){}
            }
            input.removeAttribute('oninput');
            input.addEventListener('input',function(){clearTimeout(timer);timer=setTimeout(search,80);});
            input.addEventListener('keydown',function(e){if(e.key==='Enter')e.preventDefault();});
          });
          </script>
          <div id="itemListBody" class="item-master-list">
            <?php $visibleCount=0; foreach($items as $r): if(($tab==='products'&&$r['item_type']!=='product')||($tab==='services'&&$r['item_type']!=='service')||$r['active']!=1)continue; $visibleCount++; ?>
              <div class="item-master-row <?=($selected&&$selected['id']==$r['id'])?'selected':''?>" data-item-id="<?=e((string)$r['id'])?>" data-name="<?=e(strtolower($r['name'].' '.$r['code'].' '.$r['barcode']))?>">
                <?php $itemTxCountSt=$pdo->prepare('SELECT COUNT(*) FROM transaction_items ti JOIN transactions t ON t.id=ti.transaction_id WHERE ti.item_id=? AND t.company_id=?');$itemTxCountSt->execute([(int)$r['id'],$cid]);$itemTxCount=(int)$itemTxCountSt->fetchColumn(); ?>
                <a class="item-master-main" href="<?=e(url('items?tab='.$tab.'&view='.$r['id']))?>">
                  <span class="item-master-name"><?=e($r['name'])?></span>
                  <?php if($r['code']||$r['barcode']): ?><span class="item-master-meta"><?=e($r['code']?:$r['barcode'])?></span><?php endif; ?>
                </a>
                <span class="item-master-qty <?=((float)$r['current_stock']<0)?'neg':'positive'?> <?=((float)$r['current_stock']>0)?'pos':''?>"><?= $r['item_type']==='service' ? '—' : qty((float)$r['current_stock']) ?></span>
                <div class="item-master-actions">
                  <button type="button" class="item-menu-trigger-v52" aria-label="Item actions" aria-expanded="false" onclick="SutoItemMenu.open(this,event)">⋮</button>
                  <div class="item-menu-panel-v52" hidden>
                    <a href="<?=e(url('items?tab='.$tab.'&edit='.$r['id']))?>">View/Edit</a>
                    <?php if($itemTxCount>0): ?>
                      <div class="item-delete-blocked" title="Delete all linked transactions first">Delete unavailable · <?=$itemTxCount?> transaction<?=($itemTxCount===1?'':'s')?> </div>
                    <?php else: ?>
                      <form method="post" onsubmit="return confirm('Move this item to Recycle Bin?')">
                        <input type="hidden" name="_csrf" value="<?=csrf_token()?>">
                        <input type="hidden" name="action" value="delete_item">
                        <input type="hidden" name="item_id" value="<?=$r['id']?>">
                        <button type="submit">Delete</button>
                      </form>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endforeach; if(!$visibleCount):?><div class="item-empty">No <?= $tab==='services'?'services':'products' ?> yet.</div><?php endif; ?>
          </div>
        </aside>
        <section class="item-detail-area">
        <?php if($selected):
          $tx=$pdo->prepare('(SELECT t.id source_transaction_id,NULL stock_movement_id,t.txn_date,t.txn_type,t.document_no,ti.qty,ti.unit_price,t.status,p.name party_name FROM transaction_items ti JOIN transactions t ON t.id=ti.transaction_id LEFT JOIN parties p ON p.id=t.party_id WHERE ti.item_id=? AND t.company_id=? AND t.deleted_at IS NULL) UNION ALL (SELECT NULL source_transaction_id,sm.id stock_movement_id,sm.movement_date txn_date,sm.movement_type txn_type,CONCAT("STK-",sm.id) document_no,sm.quantity qty,COALESCE(sm.unit_price,i.purchase_price) unit_price,"Final" status,COALESCE(NULLIF(sm.note,""),CASE sm.movement_type WHEN "manual_add" THEN "Stock Adjustment (Add)" WHEN "manual_reduce" THEN "Stock Adjustment (Reduce)" WHEN "opening_adjustment" THEN "Opening Stock Adjustment" ELSE REPLACE(sm.movement_type,"_"," ") END) party_name FROM stock_movements sm JOIN items i ON i.id=sm.item_id WHERE sm.item_id=? AND sm.company_id=? AND sm.movement_type NOT IN ("sale","purchase","sale_return","purchase_return")) ORDER BY txn_date DESC LIMIT 100');$tx->execute([$selected['id'],$cid,$selected['id'],$cid]);$txRows=$tx->fetchAll();
        ?><div class="item-detail-card panel">
            <div class="item-detail-top">
              <div><h2><?=e($selected['name'])?> <span class="item-share">↗</span></h2><div class="item-subline"><?=e($selected['code']?:($selected['barcode']?:''))?></div></div>
              <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
              <?php if(empty($selected['barcode'])): ?><form method="post" style="display:inline"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="generate_barcode"><input type="hidden" name="item_id" value="<?=$selected['id']?>"><button class="btn" type="submit">▦ GENERATE BARCODE</button></form><?php else: ?><span class="subtle">Barcode: <b><?=e($selected['barcode'])?></b></span><?php endif; ?>
              <a class="btn" href="<?=e(url('item-ledger?item='.(int)$selected['id']))?>">ITEM STOCK LEDGER</a>
              <button type="button" class="btn primary adjust-btn" onclick="openModal('adjustModal')">☷ ADJUST ITEM</button>
            </div>
            </div>
            <div class="item-price-grid">
              <div><span>SALE PRICE:</span> <b><?=money((float)$selected['sale_price'])?></b></div>
              <div class="stock-right"><span>STOCK QUANTITY:</span> <b class="<?=((float)$selected['current_stock']<0)?'negative-value':'positive-value'?>"><?= $selected['item_type']==='service' ? '—' : qty((float)$selected['current_stock']) ?></b></div>
              <div><span>PURCHASE PRICE:</span> <b><?=money((float)$selected['purchase_price'])?></b></div>
              <div class="stock-right"><span>STOCK VALUE:</span> <b><?=money($selected['item_type']==='service'?0:(float)$selected['purchase_price']*(float)$selected['current_stock'])?></b></div>
            </div>
          </div>
          <?php if($selected && $selected['item_type']==='product'): ?>
          <div class="panel item-bundle-card" style="margin-top:10px"><div class="panel-head"><div><h2>BUNDLE / INCLUDED FREE ITEMS</h2><span class="subtle">Included items are free on the sale invoice and reduce their own stock when supplied.</span></div><button type="button" class="btn primary small-btn" onclick="openModal('itemBundleModal')">Manage Bundle</button></div>
            <?php if(!empty($selected['bundle_components'])): ?><div class="table-wrap"><table><thead><tr><th>FREE ITEM</th><th style="width:120px">QTY</th></tr></thead><tbody><?php foreach($selected['bundle_components'] as $bc): ?><tr><td>└─ <?=e($bc['name'])?></td><td><?=e(qty((float)$bc['quantity']).' '.($bc['unit_symbol']??''))?></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><div class="subtle" style="padding:12px 0">No included free items configured.</div><?php endif; ?>
          </div>
          <?php endif; ?>
          <div class="item-note-card panel">
            <div class="panel-head"><div><h2>ITEM NOTE</h2><span class="subtle">Internal note for this item</span></div><button type="button" class="btn small-btn" onclick="openModal('itemNoteModal')"><?=trim((string)($selected['item_note']??''))!==''?'Edit Note':'+ Add Note'?></button></div>
            <?php if(trim((string)($selected['item_note']??''))!==''): ?>
              <div class="item-note-body-v234"><?=nl2br(e((string)$selected['item_note']))?></div>
            <?php else: ?>
              <div class="subtle item-note-empty-v234">No note added for this item.</div>
            <?php endif; ?>
          </div>
          <div class="item-meta-card panel">
            <div class="panel-head"><div><h2>ITEM DETAILS</h2><span class="subtle">Description, warranty and location</span></div></div>
            <div class="item-meta-grid-v235">
              <div><span>DESCRIPTION</span><p><?=trim((string)($selected['description']??''))!==''?nl2br(e((string)$selected['description'])):'—'?></p></div>
              <div><span>WARRANTY</span><p><?=e(trim((string)($selected['warranty']??''))!==''?(string)$selected['warranty']:'—')?></p></div>
              <div><span>LOCATION</span><p><?=e(trim((string)($selected['location']??''))!==''?(string)$selected['location']:'—')?></p></div>
            </div>
          </div>
          <div class="item-transactions panel">
            <div class="panel-head"><h2>TRANSACTIONS</h2><div class="tx-tools"><input class="input" id="itemTxSearch" placeholder="⌕ Search"><span class="export-icon">▣</span></div></div>
            <div class="table-wrap"><table><thead><tr><th></th><th>TYPE</th><th>NO</th><th>NAME</th><th>DATE</th><th>QUANTITY</th><th>PRICE/UNIT</th><th>STATUS</th><th></th></tr></thead><tbody><?php foreach($txRows as $r): $tt=$r['txn_type']; $typeLabel=['sale'=>'Sale','purchase'=>'Purchase','payment_in'=>'Payment In','payment_out'=>'Payment Out','manual_add'=>'Stock Adjustment','manual_reduce'=>'Stock Adjustment','opening_adjustment'=>'Opening Stock Adjustment','opening_stock'=>'Opening Stock'][$tt]??ucwords(str_replace('_',' ',$tt)); $doc=(string)($r['document_no']??''); $docLink=''; if($tt==='sale' && !empty($r['source_transaction_id'])) $docLink=url('sales?view='.(int)$r['source_transaction_id']); elseif($tt==='purchase' && !empty($r['source_transaction_id'])) $docLink=url('purchase?view='.(int)$r['source_transaction_id']); $displayNo=$doc!==''?$doc:'—'; $displayName=(string)($r['party_name']??''); if($displayName==='') $displayName=$typeLabel; ?><tr><td><span class="tx-dot"></span></td><td><?=e($typeLabel)?></td><td><?php if($docLink):?><a class="tx-doc-link" href="<?=e($docLink)?>"><?=e($displayNo)?></a><?php else:?><?=e($displayNo)?><?php endif;?></td><td><?=e($displayName)?></td><td><?=e(!empty($r['txn_date'])?date('d/m/Y',strtotime($r['txn_date'])):'—')?></td><td><?=qty((float)$r['qty'])?> <?=e($selected['unit_symbol']??'')?></td><td><?=money((float)$r['unit_price'])?></td><td><span class="tx-status"><?=e(ucfirst($r['status']))?></span></td><td class="tx-more" style="width:52px;min-width:52px;text-align:center;position:relative;overflow:visible!important;">
<?php $isDocTx=in_array($tt,['sale','purchase'],true)&&!empty($r['source_transaction_id']); $sourceRoute=$tt==='sale'?'sales':'purchase'; ?>
<button type="button" aria-label="Transaction actions" aria-expanded="false" onclick="return SutoTxV72.toggle(this,event)"
style="width:32px;height:32px;padding:0;border:1px solid #cfd8e3;background:#fff!important;color:#334155;border-radius:7px;cursor:pointer;font-size:18px;line-height:30px;display:inline-flex;align-items:center;justify-content:center;box-shadow:0 1px 3px rgba(0,0,0,.08);">⋮</button>
<div class="tx-action-menu-v72" hidden style="position:fixed;display:none;min-width:165px;padding:6px;background:#fff!important;color:#1f2937!important;border:1px solid #dbe3ee;border-radius:9px;box-shadow:0 14px 36px rgba(15,23,42,.18);z-index:2147483647;">
<?php if($isDocTx): ?>
<a href="<?=e(url($sourceRoute.'?view='.(int)$r['source_transaction_id']))?>" style="display:block;width:100%;box-sizing:border-box;padding:9px 11px;border-radius:6px;background:#fff!important;color:#1f2937!important;text-decoration:none;">View/Edit</a>
<form method="post" action="<?=e(url($sourceRoute))?>" style="margin:0" onsubmit="return confirm('Delete this transaction? It will move to Recycle Bin.');"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="transaction_id" value="<?=$r['source_transaction_id']?>"><button type="submit" style="display:block;width:100%;box-sizing:border-box;padding:9px 11px;border:0;border-radius:6px;background:#fff!important;color:#1f2937!important;text-align:left;cursor:pointer;">Delete</button></form>
<form method="post" action="<?=e(url($sourceRoute))?>" style="margin:0"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="duplicate"><input type="hidden" name="transaction_id" value="<?=$r['source_transaction_id']?>"><button type="submit" style="display:block;width:100%;box-sizing:border-box;padding:9px 11px;border:0;border-radius:6px;background:#fff!important;color:#1f2937!important;text-align:left;cursor:pointer;">Duplicate</button></form>
<?php else: ?>
<a href="<?=e(url('items?tab='.$tab.'&view='.$selected['id'].'&stock_view='.(int)($r['stock_movement_id']??0)))?>" style="display:block;width:100%;box-sizing:border-box;padding:9px 11px;border-radius:6px;background:#fff!important;color:#1f2937!important;text-decoration:none;">View/Edit</a>
<form method="post" action="<?=e(url('items?tab='.$tab.'&view='.$selected['id']))?>" style="margin:0" onsubmit="return confirm('Delete this stock transaction?');"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="item_tx_action" value="delete_stock"><input type="hidden" name="item_id" value="<?=$selected['id']?>"><input type="hidden" name="stock_id" value="<?=$r['stock_movement_id']?>"><button type="submit" style="display:block;width:100%;box-sizing:border-box;padding:9px 11px;border:0;border-radius:6px;background:#fff!important;color:#1f2937!important;text-align:left;cursor:pointer;">Delete</button></form>
<form method="post" action="<?=e(url('items?tab='.$tab.'&view='.$selected['id']))?>" style="margin:0"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="item_tx_action" value="duplicate_stock"><input type="hidden" name="item_id" value="<?=$selected['id']?>"><input type="hidden" name="stock_id" value="<?=$r['stock_movement_id']?>"><button type="submit" style="display:block;width:100%;box-sizing:border-box;padding:9px 11px;border:0;border-radius:6px;background:#fff!important;color:#1f2937!important;text-align:left;cursor:pointer;">Duplicate</button></form>
<?php endif; ?>
</div>
</td></tr><?php endforeach;if(!$txRows):?><tr><td colspan="9" class="subtle">No transactions for this item yet.</td></tr><?php endif;?></tbody></table></div>
          </div>
          <div class="modal-backdrop" id="adjustModal" onclick="if(event.target===this)closeModal('adjustModal')"><div class="stock-adjust-dialog"><div class="stock-adjust-head"><h2>Stock Adjustment</h2><button class="close" type="button" onclick="closeModal('adjustModal')">×</button></div><form method="post" id="stockAdjustForm"><div class="stock-adjust-body"><div class="stock-adjust-top"><div><div class="subtle stock-item-label">Item Name</div><strong><?=e($selected['name'])?></strong></div><div class="form-group adjustment-date-wrap"><label>Adjustment Date</label><input type="date" name="adjustment_date" value="<?=date('Y-m-d')?>"></div></div><div class="stock-mode-switch" role="group" aria-label="Stock adjustment mode"><button type="button" class="stock-mode active" data-mode="add" onclick="setStockMode('add')">Add Stock</button><span class="switch-knob" aria-hidden="true"></span><button type="button" class="stock-mode" data-mode="reduce" onclick="setStockMode('reduce')">Reduce Stock</button></div><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="adjust_stock"><input type="hidden" name="item_id" value="<?=$selected['id']?>"><input type="hidden" name="mode" id="stockMode" value="add"><div class="stock-adjust-grid"><div class="form-group"><label>Total Qty</label><input class="stock-qty-input" type="number" step="0.01" min="0.01" name="quantity" required placeholder="Enter quantity"></div><div class="form-group"><label>Unit</label><input value="<?=e($selected['unit_symbol']??'')?>" disabled></div><div class="form-group"><label>At Price</label><input type="number" step="0.01" min="0" name="at_price" value="<?=e($selected['purchase_price'])?>" placeholder="0.00"></div><div class="form-group details-field"><label>Details</label><input name="note" placeholder="Details"></div></div><div class="current-stock-strip"><span>Current Stock</span><strong><?=qty((float)$selected['current_stock'])?> <?=e($selected['unit_symbol']??'')?></strong></div></div><div class="stock-adjust-footer"><button type="button" class="btn" onclick="closeModal('adjustModal')">Cancel</button><button type="submit" class="btn primary">Save</button></div></form></div></div><?php
        else:
        ?><div class="panel empty-detail"><div class="empty-icon">▣</div><h2>Select an item</h2><p class="subtle">Choose a product or service from the list to see pricing, stock and transaction history.</p></div><?php endif; ?></section>
      </div>
    </div>
    <div class="modal-backdrop" id="itemNoteModal" onclick="if(event.target===this)closeModal('itemNoteModal')"><div class="modal"><div class="modal-head"><h2><?=trim((string)($selected['item_note']??''))!==''?'Edit Item Note':'Add Item Note'?></h2><button class="close" type="button" onclick="closeModal('itemNoteModal')">×</button></div><form method="post"><div class="form-body"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="save_item_note"><input type="hidden" name="item_id" value="<?= (int)$selected['id']?>"><div class="form-group"><label>Note</label><textarea name="item_note" rows="6" placeholder="Write a note about this item..."><?=e((string)($selected['item_note']??''))?></textarea></div></div><div class="form-footer"><button type="button" class="btn" onclick="closeModal('itemNoteModal')">Cancel</button><button class="btn primary">Save Note</button></div></form></div></div>
    <?php if($selected && $selected['item_type']==='product'): ?>
    <div class="modal-backdrop" id="itemBundleModal" onclick="if(event.target===this)closeModal('itemBundleModal')"><div class="modal" style="width:min(820px,calc(100vw - 28px));max-height:calc(100vh - 28px);overflow:auto"><div class="modal-head"><h2>Bundle / Included Free Items</h2><button class="close" type="button" onclick="closeModal('itemBundleModal')">×</button></div><form method="post"><div class="form-body"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="save_bundle"><input type="hidden" name="parent_item_id" value="<?=((int)$selected['id'])?>"><div class="subtle" style="margin-bottom:10px"><strong><?=e($selected['name'])?></strong> remains the billed product. Every included item below is always free on a sale invoice.</div><div class="entry-table"><table><thead><tr><th>FREE ITEM</th><th style="width:150px">QTY</th><th style="width:70px"></th></tr></thead><tbody id="itemBundleRows">
<?php if(!empty($selected['bundle_components'])): foreach($selected['bundle_components'] as $bc): ?><tr class="bundle-config-row"><td><select name="component_item_id[]" required><option value="">Select product</option><?php foreach($items as $opt): if($opt['item_type']!=='product'||(int)$opt['id']===(int)$selected['id']||!(int)$opt['active'])continue; ?><option value="<?=$opt['id']?>" <?=((int)$opt['id']===(int)$bc['item_id'])?'selected':''?>><?=e($opt['name'])?></option><?php endforeach; ?></select></td><td><input type="number" name="component_qty[]" min="0.001" step="0.001" value="<?=e((string)$bc['quantity'])?>" required></td><td><button type="button" class="btn small-btn" onclick="this.closest('tr').remove()">×</button></td></tr><?php endforeach; else: ?><tr class="bundle-config-row"><td><select name="component_item_id[]"><option value="">Select product</option><?php foreach($items as $opt): if($opt['item_type']!=='product'||(int)$opt['id']===(int)$selected['id']||!(int)$opt['active'])continue; ?><option value="<?=$opt['id']?>"><?=e($opt['name'])?></option><?php endforeach; ?></select></td><td><input type="number" name="component_qty[]" min="0.001" step="0.001" value="1"></td><td><button type="button" class="btn small-btn" onclick="this.closest('tr').remove()">×</button></td></tr><?php endif; ?>
</tbody></table></div><div style="margin-top:10px"><button type="button" class="btn" onclick="addBundleConfigRow()">+ Add Free Item</button></div></div><div class="form-footer"><button type="button" class="btn" onclick="closeModal('itemBundleModal')">Cancel</button><button class="btn primary">Save Bundle</button></div></form></div></div>
    <?php endif; ?>
    <div class="modal-backdrop" id="itemModal"><div class="modal"><div class="modal-head"><h2><?= $edit?'Edit Item':'Add Item' ?></h2><button class="close" onclick="closeModal('itemModal')">×</button></div><form method="post"><div class="form-body"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="save_item"><div class="item-form-top"><div class="form-group"><label>Item Name*</label><input name="name" required value="<?=e($edit['name']??'')?>"></div><div class="form-group">
<label>Category</label>
<details class="item-category-dropdown" id="itemCategoryDropdown">
  <summary class="item-category-trigger">
    <span class="item-category-trigger-text" id="itemCategoryTriggerText">Select Category</span><span class="item-category-chevron">⌄</span>
  </summary>
  <div class="item-category-menu">
    <div class="item-category-options">
      <?php foreach($catRows as $c): if($edit && ($edit['item_type']??'product')!==$c['type']) continue; $checked=in_array((int)$c['id'],$editCategoryIds,true); ?>
        <label class="item-category-option" data-category-type="<?=e($c['type'])?>">
          <input type="checkbox" name="category_ids[]" value="<?=$c['id']?>" <?=$checked?'checked':''?> onchange="updateItemCategoryTrigger()">
          <span><?=e($c['name'])?></span>
        </label>
      <?php endforeach; ?>
    </div>
  </div>
</details>
</div><div class="form-group"><label>Select Unit</label><select name="unit_id"><option value="">Select Unit</option><?php foreach($unitRows as $x):?><option value="<?=$x['id']?>" <?=($edit&&$edit['unit_id']==$x['id'])?'selected':''?>><?=e($x['name'].' '.($x['symbol']?'('.$x['symbol'].')':''))?></option><?php endforeach;?></select></div></div><div class="item-type-toggle"><label><input type="radio" name="item_type" value="product" <?=(!$edit||$edit['item_type']==='product')?'checked':''?> onchange="toggleStock();filterItemCategoryChoices()"> Product</label><label><input type="radio" name="item_type" value="service" <?=($edit&&$edit['item_type']==='service')?'checked':''?> onchange="toggleStock();filterItemCategoryChoices()"> Service</label></div><div class="serial-tracking-toggle"><label><input type="checkbox" name="serial_tracked" value="1" <?=($edit&&((int)($edit['serial_tracked']??0)===1))?'checked':''?>> Enable Serial Number Tracking</label><span class="subtle"> Purchase each unit with a unique serial; sale can auto-pick or use specific serials.</span></div><div class="grid2"><div class="form-group span2"><label>Item Code</label><input name="code" value="<?=e($edit['code']??'')?>"></div></div><div class="tabs"><button type="button" class="active">PRICING</button><button type="button">STOCK</button></div><div class="pricing-section"><div class="grid3"><div class="form-group"><label>Sale Price</label><input type="number" step="1" name="sale_price" value="<?=e($edit['sale_price']??'0')?>"></div><div class="form-group"><label>Wholesale Price</label><input type="number" step="1" name="wholesale_price" value="<?=e($edit['wholesale_price']??'0')?>"></div><div class="form-group"><label>Minimum Wholesale Qty</label><input type="number" step="1" name="min_wholesale_qty" value="<?=e($edit['min_wholesale_qty']??'0')?>"></div><div class="form-group"><label>Purchase Price</label><input type="number" step="1" name="purchase_price" value="<?=e($edit['purchase_price']??'0')?>"></div></div></div><div class="stock-section"><div class="grid2"><div class="form-group stock-field"><label>Opening Stock</label><input type="number" step="1" name="opening_stock" value="<?=e($edit['opening_stock']??'0')?>"></div><div class="form-group stock-field"><label>Low Stock Limit</label><input type="number" step="1" name="low_stock_limit" value="<?=e($edit['low_stock_limit']??'0')?>"></div><div class="form-group"><label>Location</label><input name="location" value="<?=e($edit['location']??'')?>" placeholder="e.g. Main Warehouse / Rack A-03"></div><div class="form-group"><label>Warranty</label><input name="warranty" value="<?=e($edit['warranty']??'')?>" placeholder="e.g. 12 Months"></div></div><div class="grid2" style="margin-top:14px"><div class="form-group"><label>Description</label><textarea name="description" rows="4" placeholder="General product/service description"><?=e($edit['description']??'')?></textarea></div><div class="form-group"><label>Item Note</label><textarea name="item_note" rows="4" placeholder="Internal note for this item"><?=e($edit['item_note']??'')?></textarea></div></div></div></div><div class="form-footer"><button type="button" class="btn" onclick="closeModal('itemModal')">Cancel</button><button class="btn primary"><?= $edit?'Update':'Save' ?></button></div></form></div></div>
    <style>
      .item-note-card{margin-top:10px;}
      .item-note-body-v234{padding:12px 14px;border:1px solid #e3e9f0;border-radius:8px;background:#fbfdff;color:#334155;line-height:1.55;font-size:13px;white-space:normal;}
      .item-note-empty-v234{padding:12px 14px;border:1px dashed #d9e2ec;border-radius:8px;background:#fafcff;}
      .item-meta-card{margin-top:10px;}
      .item-meta-grid-v235{display:grid;grid-template-columns:2fr 1fr 1fr;gap:12px;padding:12px 14px;}
      .item-meta-grid-v235>div{border:1px solid #e3e9f0;border-radius:8px;background:#fbfdff;padding:11px 12px;min-height:62px;}
      .item-meta-grid-v235 span{display:block;font-size:10px;font-weight:700;color:#718096;letter-spacing:.04em;margin-bottom:6px;}
      .item-meta-grid-v235 p{margin:0;color:#334155;font-size:13px;line-height:1.5;}
      @media(max-width:900px){.item-meta-grid-v235{grid-template-columns:1fr;}}
    </style>
    <script>
    function addBundleConfigRow(){var b=document.getElementById('itemBundleRows');if(!b)return;var f=b.querySelector('.bundle-config-row');if(!f)return;var c=f.cloneNode(true);c.querySelectorAll('select').forEach(function(x){x.selectedIndex=0});c.querySelectorAll('input').forEach(function(x){x.value='1'});b.appendChild(c);}
    </script>
    <script>
    function setStockMode(mode){
      var hidden=document.getElementById('stockMode');
      if(hidden) hidden.value=(mode==='reduce'?'reduce':'add');
      document.querySelectorAll('.stock-mode').forEach(function(btn){btn.classList.toggle('active',btn.getAttribute('data-mode')===(mode==='reduce'?'reduce':'add'));});
      var dlg=document.querySelector('#adjustModal .stock-adjust-dialog'); if(dlg) dlg.classList.toggle('reduce-mode',mode==='reduce');
    }
        
window.SutoTxMenu=(function(){
  var active=null;
  function restore(){
    if(!active)return;
    var p=active.p, parent=active.parent, next=active.next, b=active.b;
    p.hidden=true; p.removeAttribute('style');
    if(parent){
      if(next && next.parentNode===parent) parent.insertBefore(p,next); else parent.appendChild(p);
    }
    b.setAttribute('aria-expanded','false');
    active=null;
  }
  function place(b,p){
    var r=b.getBoundingClientRect();
    var w=Math.max(154, Math.min(220, p.scrollWidth||180));
    var h=p.offsetHeight||90;
    var left=r.right+8, top=r.top;
    if(left+w>window.innerWidth-8) left=Math.max(8,r.left-w-8);
    if(top+h>window.innerHeight-8) top=Math.max(8,window.innerHeight-h-8);
    p.style.position='fixed';
    p.style.left=Math.round(left)+'px';
    p.style.top=Math.round(top)+'px';
    p.style.width=w+'px';
    p.style.zIndex='2147483647';
    p.style.display='block';
  }
  function open(b,e){
    if(e){e.preventDefault();e.stopPropagation();e.stopImmediatePropagation();}
    var wrap=b.closest('.tx-more');
    var p=wrap&&wrap.querySelector('.tx-action-menu-v66');
    if(!p)return false;
    if(active && active.b===b){restore();return false;}
    restore();
    var parent=p.parentNode, next=p.nextSibling;
    document.body.appendChild(p);
    p.hidden=false;
    b.setAttribute('aria-expanded','true');
    active={b:b,p:p,parent:parent,next:next};
    place(b,p);
    requestAnimationFrame(function(){if(active&&active.p===p)place(b,p);});
    return false;
  }
  document.addEventListener('click',function(e){
    var b=e.target.closest('.tx-action-trigger-v66');
    if(b){open(b,e);return;}
    if(e.target.closest('.tx-action-menu-v66')) return;
    restore();
  },true);
  document.addEventListener('keydown',function(e){if(e.key==='Escape')restore();});
  window.addEventListener('resize',restore);
  window.addEventListener('scroll',function(){if(active)place(active.b,active.p);},true);
  return {open:open,close:restore};
})();

window.SutoItemMenu=(function(){
      var active=null, originalParent=new WeakMap(), originalNext=new WeakMap();
      function close(){
        if(!active)return; var b=active.b,p=active.p,parent=originalParent.get(p),next=originalNext.get(p);
        if(p){p.hidden=true;p.removeAttribute('style'); if(parent){ if(next&&next.parentNode===parent) parent.insertBefore(p,next); else parent.appendChild(p); }}
        if(b)b.setAttribute('aria-expanded','false'); active=null;
      }
      function place(b,p){
        var r=b.getBoundingClientRect(), w=150, h=p.offsetHeight||82, gap=8;
        var left=r.right+gap;
        var top=r.top;
        if(left+w>window.innerWidth-8) left=Math.max(8,r.left-w-gap);
        if(top+h>window.innerHeight-8) top=Math.max(8,window.innerHeight-h-8);
        p.style.position='fixed';p.style.left=Math.round(left)+'px';p.style.top=Math.round(top)+'px';p.style.width=w+'px';p.style.zIndex='2147483647';p.style.display='block';
      }
      function open(b,e){
        if(e){e.preventDefault();e.stopPropagation();e.stopImmediatePropagation();}
        if(active&&active.b===b){close();return false;}
        close(); var wrap=b.closest('.item-master-actions'); var p=wrap&&wrap.querySelector('.item-menu-panel-v52'); if(!p)return false;
        originalParent.set(p,p.parentNode); originalNext.set(p,p.nextSibling); document.body.appendChild(p); p.hidden=false; b.setAttribute('aria-expanded','true'); active={b:b,p:p};
        place(b,p); requestAnimationFrame(function(){if(active&&active.p===p)place(b,p);}); return false;
      }
      document.addEventListener('click',function(e){
        if(e.target.closest('.item-menu-panel-v52')) return;
        if(!e.target.closest('.item-menu-trigger-v52')) close();
      },true);
      window.addEventListener('resize',function(){if(active)place(active.b,active.p);});
      window.addEventListener('scroll',function(){if(active)place(active.b,active.p);},true);
      return {open:open,close:close};
    })();
    </script>
    <script>
window.SutoTxV72=(function(){
  let active=null;
  function close(){
    if(!active)return;
    active.panel.hidden=true;
    active.panel.style.display='none';
    active.button.setAttribute('aria-expanded','false');
    active=null;
  }
  function position(){
    if(!active)return;
    const r=active.button.getBoundingClientRect(), p=active.panel, w=165, gap=8;
    let left=r.right+gap, top=r.top;
    if(left+w>window.innerWidth-8) left=Math.max(8,r.left-w-gap);
    const h=p.offsetHeight||110;
    if(top+h>window.innerHeight-8) top=Math.max(8,window.innerHeight-h-8);
    p.style.left=Math.round(left)+'px';
    p.style.top=Math.round(top)+'px';
    p.style.width=w+'px';
    p.style.display='block';
  }
  function toggle(button,e){
    if(e){e.preventDefault();e.stopPropagation();}
    if(active&&active.button===button){close();return false;}
    close();
    const panel=button.parentElement.querySelector('.tx-action-menu-v72');
    if(!panel)return false;
    panel.hidden=false;
    document.body.appendChild(panel);
    active={button:button,panel:panel};
    button.setAttribute('aria-expanded','true');
    position();
    requestAnimationFrame(position);
    return false;
  }
  document.addEventListener('click',function(e){
    if(e.target.closest('.tx-action-menu-v72')||e.target.closest('[aria-label="Transaction actions"]')) return;
    close();
  },true);
  document.addEventListener('keydown',function(e){if(e.key==='Escape')close();});
  window.addEventListener('resize',position);
  window.addEventListener('scroll',position,true);
  return {toggle:toggle,close:close};
})();
</script>
<?php if($edit):?><script>document.addEventListener('DOMContentLoaded',()=>openModal('itemModal'))</script><?php endif; page_end();exit;
}

if(in_array($route,['sale-new','purchase-new'],true)){
    $u=require_login();
    page_start($route==='sale-new'?'Sale':'Purchase');
    $isSale=$route==='sale-new'; $cid=(int)$u['company_id']; $pdo=db();
    $type=$isSale?'sale':'purchase'; $prefix=$isSale?'SI-':'PB-';
    $editId=(int)($_GET['edit']??0);
    $editTx=null; $editLines=[]; $editPayments=[]; $serialMap=[];
    if($editId>0){
        $st=db()->prepare('SELECT t.*,p.name party_name,p.phone party_phone FROM transactions t LEFT JOIN parties p ON p.id=t.party_id WHERE t.id=? AND t.company_id=? AND t.txn_type=? AND t.deleted_at IS NULL LIMIT 1');
        $st->execute([$editId,$cid,$type]); $editTx=$st->fetch();
        if(!$editTx){http_response_code(404);echo '<div class="panel"><h1>'.e($isSale?'Sale transaction not found':'Purchase transaction not found').'</h1></div>';page_end();exit;}
        $st=db()->prepare('SELECT ti.*,i.item_type,i.serial_tracked,u.symbol unit_symbol FROM transaction_items ti JOIN items i ON i.id=ti.item_id LEFT JOIN units u ON u.id=i.unit_id WHERE ti.transaction_id=? ORDER BY ti.id');
        $st->execute([$editId]); $editLines=$st->fetchAll();
        $serialMap=[]; $ss=$pdo->prepare('SELECT tis.transaction_item_id,isx.serial_number FROM transaction_item_serials tis JOIN item_serials isx ON isx.id=tis.serial_id WHERE tis.transaction_id=? AND tis.company_id=? ORDER BY tis.id');$ss->execute([$editId,$cid]);foreach($ss->fetchAll() as $sr){$serialMap[(int)$sr['transaction_item_id']][]=(string)$sr['serial_number'];}
        $st=db()->prepare('SELECT * FROM payment_lines WHERE transaction_id=? ORDER BY id');
        $st->execute([$editId]); $editPayments=$st->fetchAll();
    }
    $parties=db()->prepare('SELECT id,name,phone,party_type FROM parties WHERE company_id=? AND EXISTS(SELECT 1 FROM party_roles pr WHERE pr.party_id=parties.id AND pr.role IN (?,?,?)) ORDER BY name');
    $parties->execute([$cid,'customer','both','supplier']); $partyRows=$parties->fetchAll();
    $items=get_items($cid);
    $banks=db()->prepare('SELECT id,name,bank_name,account_number FROM bank_accounts WHERE company_id=? AND active=1 ORDER BY name'); $banks->execute([$cid]); $bankRows=$banks->fetchAll();
    $pageHeading=$editTx?($isSale?'Edit Sale Invoice':'Edit Purchase Bill'):($isSale?'Sale':'Purchase');
    $pageSub=$editTx?('Edit '.($isSale?'Sales Invoice':'Purchase Bill').' '.$editTx['document_no']):($isSale?'Create Sales Invoice':'Create Purchase Bill');
    $rows=$editLines ?: [null];
    $payRows=$editPayments ?: [null];
?>
<div class="page-title"><div><h1><?=e($pageHeading)?></h1><p><?=e($pageSub)?></p></div><a class="btn" href="<?=e(url($isSale?'sales':'purchase'))?>">Back to List</a></div>
<form id="txnForm" class="transaction-form txn-compact <?= $isSale ? '' : 'purchase-entry-form' ?>" method="post" action="<?=e(url('transaction-save'))?>" onsubmit="return validateTransactionForm()">
<input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="txn_type" value="<?=$type?>"><?php if($editTx):?><input type="hidden" name="transaction_id" value="<?=$editTx['id']?>"><?php endif; ?>
<div class="panel">
  <div class="entry-top <?= $isSale?'sale-entry-top':'purchase-entry-top' ?>">
    <div>
      <?php party_search_field($isSale?'Customer':'Supplier',$isSale?'customer':'supplier',(int)($editTx['party_id']??0),$editTx['party_name']??'',(($editTx['party_phone']??'')),$isSale?false:true); ?>
      <div class="subtle" style="margin-top:6px;display:flex;gap:8px;align-items:center"><button type="button" class="btn small-btn" onclick="senseOpenInlinePartyModal('<?=$isSale?'customer':'supplier'?>')">+ Add Party</button><?= $isSale?' <span>(Customer optional for Cash Sale)</span>':'' ?></div>
    </div>
    <div class="entry-right <?= $isSale?'sale-invoice-meta':'' ?>">
      <div class="form-group"><label><?=$isSale?'Invoice':'Bill'?> Number</label><input name="document_no" value="<?=e($editTx['document_no']??'')?>" placeholder="Auto: <?=$prefix?>01"></div>
      <div class="form-group"><label><?=$isSale?'Invoice':'Bill'?> Date*</label><?php $editDateTime=(!empty($editTx['txn_date']))?date('Y-m-d\TH:i',strtotime((string)$editTx['txn_date'])):date('Y-m-d\TH:i'); ?><input type="<?= $isSale?'datetime-local':'date' ?>" name="txn_date" value="<?=e($isSale?$editDateTime:($editTx['txn_date']??date('Y-m-d')))?>" required></div>
      <?php if(!$isSale): ?><div class="subtle sale-purchase-note" style="margin-top:8px">BDT · Negative stock allowed</div><?php endif; ?>
    </div>
  </div>
  <div class="entry-table">
    <table><thead><tr><th>#</th><th>ITEM</th><th>QTY</th><th>UNIT</th><th>PRICE/UNIT</th><th>AMOUNT</th><th></th></tr></thead>
    <tbody id="entryRows">
<?php foreach($rows as $ri=>$r): $rid=(int)($r['item_id']??0); $rv=(float)($r['qty']??1); $rp=(float)($r['unit_price']??0); $rd=(float)($r['discount']??0); ?>
      <tr class="sale-row"><td><?=($ri+1)?></td><td><div class="item-picker-cell"><?php item_search_field($rid,'','',$isSale?'sale':'purchase'); ?><button type="button" class="serial-trigger-btn" hidden title="Enter serial numbers">Serial</button><select class="item-select item-source-select" name="item_id[]" onchange="updatePrice(this)"><option value="">Select item</option><?php foreach($items as $it):?><option value="<?=$it['id']?>" data-sale="<?=$it['sale_price']?>" data-buy="<?=$it['purchase_price']?>" data-unit="<?=e($unitSymbols[(int)($it['unit_id']??0)]??'')?>" data-type="<?=$it['item_type']?>" data-serial-tracked="<?=((int)($it['serial_tracked']??0))?>" <?=($rid===(int)$it['id'])?'selected':''?>><?=e($it['name'])?></option><?php endforeach;?></select><div class="serial-entry-box" hidden data-mode="<?=$type?>"><textarea name="serial_numbers[]" class="serial-number-input" tabindex="-1" aria-hidden="true"><?=e(implode("\n",$serialMap[$r['id']]??[]))?></textarea></div></div></td><td><input class="qty" type="number" min="0.01" step="0.01" name="qty[]" value="<?=e((string)$rv)?>"></td><td><span class="unit-label subtle"><?=e($r['unit_symbol']??'—')?></span></td><td><input class="price" type="number" step="0.01" min="0" name="price[]" value="<?=e((string)$rp)?>"></td><td><input class="line-disc" type="number" step="0.01" min="0" name="discount[]" value="<?=e((string)$rd)?>"></td><td class="amount"><?=money(max(0,$rv*$rp-$rd))?></td><td><button type="button" class="row-remove-btn" onclick="removeRow(this)" aria-label="Remove item row" title="Remove row">×</button></td></tr>
<?php endforeach; ?>
    </tbody></table>
  </div>
  <div class="entry-actions"><div style="display:flex;gap:8px"><button type="button" class="btn" onclick="addRow('<?=$type?>')">+ Add Row</button><button type="button" class="btn" onclick="senseOpenInlineProductModal('#entryRows','<?=$type?>')">+ Add Product</button></div><span><b>Items Total</b <strong id="subTotal"><?=money((float)($editTx['subtotal']??0))?></strong></span></div>
  <?php if($isSale): ?>
  <div class="sale-adjustments">
    <div class="form-group"><label>Tax / VAT <span class="subtle">(optional)</span></label><input id="tax" type="number" step="0.01" min="0" name="tax" value="<?=e((string)($editTx['tax']??0))?>"></div>
    <div class="form-group"><label>Direct Expense</label><input id="directExpense" type="number" step="0.01" min="0" name="direct_expense" value="<?=e((string)($editTx['direct_expense']??0))?>"></div>
    <div class="form-group"><label>Invoice Discount</label><input id="invoiceDiscount" type="number" step="0.01" min="0" name="invoice_discount" value="<?=e((string)($editTx['invoice_discount']??0))?>"></div>
  </div>
  <?php else: ?>
  <div class="grid3">
    <div class="form-group"><label>Invoice Discount</label><input id="invoiceDiscount" type="number" step="0.01" min="0" name="invoice_discount" value="<?=e((string)($editTx['invoice_discount']??0))?>"></div>
    <div class="form-group"><label>Tax / VAT <span class="subtle">(optional)</span></label><input id="tax" type="number" step="0.01" min="0" name="tax" value="<?=e((string)($editTx['tax']??0))?>"></div>
    <div class="form-group"><label>Direct Expense</label><input id="directExpense" type="number" step="0.01" min="0" name="direct_expense" value="<?=e((string)($editTx['direct_expense']??0))?>"></div>
  </div>
  <?php endif; ?>
  <div class="grid2" style="margin-top:12px">
    <div class="form-group"><label>Description / Note</label><textarea name="notes" rows="3" placeholder="Add description"><?=e($editTx['notes']??'')?></textarea></div>
    <div class="payment-box"><div class="panel-head payment-panel-head"><div class="payment-title-wrap"><h2>Payment</h2><?php if($isSale): ?><label class="received-toggle"><input type="checkbox" id="receivedToggle" name="received" value="1" <?=($editTx && (float)$editTx['due']<=0.009 && (float)$editTx['total']>0)?'checked':''?>> <span>Received</span></label><?php endif; ?></div><span class="subtle">Multiple methods allowed</span></div>
      <div id="paymentRows">
<?php foreach($payRows as $pi=>$pr): $pm=(string)($pr['method']??'cash'); $pa=(float)($pr['amount']??0); ?>
        <div class="payment-line" data-payment-row><select name="pay_method[]" onchange="togglePaymentFields(this)"><?=payment_select_options($bankRows,$pm,(string)($pr['account_name']??''))?></select><input type="date" name="pay_cheque_date[]" class="pay-cheque-date" style="display:none" value="<?=e($pr['cheque_date']??'')?>"><input type="number" min="0" step="0.01" name="pay_amount[]" value="<?=e((string)$pa)?>" placeholder="Amount"><input name="pay_ref[]" value="<?=e($pr['reference_no']??'')?>" placeholder="Reference / Cheque No."><button type="button" class="payment-remove" onclick="removePayment(this)" aria-label="Remove payment">×</button></div>
<?php endforeach; ?>
      </div>
      <button type="button" class="btn" onclick="addPayment()">+ Add Payment</button>
    </div>
  </div>
  <div class="grand"><span>Total</span><b id="grandTotal"><?=money((float)($editTx['total']??0))?></b></div>
  <div class="payment-summary"><span>Paid <b id="paidPreview"><?=money((float)($editTx['paid']??0))?></b></span><span>Due <b id="duePreview"><?=money((float)($editTx['due']??0))?></b></span></div>
  <div class="form-footer" style="margin:0 -16px -16px"><?php if($isSale): ?><button type="submit" name="save_and_print" value="1" class="btn">Save and Print</button><?php endif; ?><a class="btn" href="<?=e(url($isSale?'sales':'purchase'))?>">Cancel</a><button type="submit" class="btn primary"><?= $editTx?'Update':'Save' ?></button></div>
</div>
<div id="serialEntryModal" class="serial-entry-modal" data-mode="<?=$type?>" hidden aria-hidden="true">
  <div class="serial-entry-backdrop"></div>
  <div class="serial-entry-dialog" role="dialog" aria-modal="true" aria-labelledby="serialEntryTitle">
    <button type="button" class="serial-entry-x" id="serialModalClose" aria-label="Close">×</button>
    <div class="serial-entry-title" id="serialEntryTitle">Sale Item - Serial No.</div>
    <div class="serial-entry-item" id="serialEntryItemName"></div>
    <div class="serial-entry-sep"></div>
    <div class="serial-entry-label-row"><span>Enter Serial No.:</span><strong id="serialEntryCount">0/0 Entered</strong></div>
    <div class="serial-input-line" id="serialManualInputLine">
      <input type="text" id="serialEntryInput" class="serial-modal-input" placeholder="Enter/Scan" autocomplete="off">
      <button type="button" class="serial-add-btn" id="serialEntryAdd" aria-label="Add serial">✓</button>
    </div>
    <div class="serial-available-wrap" id="serialAvailableWrap">
      <input type="text" id="serialAvailableSearch" class="serial-available-search" placeholder="Search available serial..." autocomplete="off">
      <div class="serial-available-list" id="serialAvailableList"></div>
    </div>
    <div class="serial-entered-list" id="serialEnteredList"></div>
    <div class="serial-entry-help" id="serialEntryHelp"></div>
    <div class="serial-modal-footer"><button type="button" class="btn" id="serialModalCancel">Close</button><button type="button" class="btn primary" id="serialModalSave">Save</button></div>
  </div>
</div>
</form>
<script>
function renumberTxnRows(){document.querySelectorAll('#entryRows .sale-row').forEach(function(r,i){if(r.children[0])r.children[0].textContent=i+1;});}
function rowHasItem(row){return !!row?.querySelector('.item-select')?.value;}
function removeRow(btn){
  const row=btn?.closest('.sale-row'); const body=document.getElementById('entryRows'); if(!row||!body)return;
  row.remove(); renumberTxnRows();
  if(typeof recalc==='function') recalc();
}
function addRow(type){
  const body=document.getElementById('entryRows'); const first=body?.querySelector('.sale-row'); if(!body||!first)return;
  const clone=first.cloneNode(true);
  clone.querySelectorAll('input').forEach(function(i){
    if(i.classList.contains('qty')) i.value='1';
    else if(i.classList.contains('price')||i.classList.contains('line-disc')) i.value='0';
    else if(i.name==='serial_numbers[]') i.value='';
  });
  clone.querySelectorAll('select').forEach(function(s){s.selectedIndex=0;});
  const itemSearch=clone.querySelector('.item-search-input'); if(itemSearch)itemSearch.value='';
  const clear=clone.querySelector('.item-search-clear'); if(clear)clear.style.display='none';
  const results=clone.querySelector('.item-search-results'); if(results){results.hidden=true;results.innerHTML='';}
  const unit=clone.querySelector('.unit-label'); if(unit)unit.textContent='—';
  const amt=clone.querySelector('.amount'); if(amt)amt.textContent='৳0.00';
  body.appendChild(clone); renumberTxnRows();
  if(typeof bindEntryRow==='function') bindEntryRow(clone);
  if(typeof window.SutoInitItemSearch==='function') window.SutoInitItemSearch(clone);
  if(typeof recalc==='function') recalc();
}
function validateTransactionForm(){
  const rows=[...document.querySelectorAll('#entryRows .sale-row')];
  let validCount=0;
  for(const row of rows){
    const item=row.querySelector('.item-select')?.value||'';
    const qty=parseFloat(row.querySelector('.qty')?.value||0)||0;
    const price=parseFloat(row.querySelector('.price')?.value||0)||0;
    if(!item){ row.remove(); continue; }
    if(qty<=0){ row.querySelector('.qty')?.focus(); alert('Please enter a valid quantity.'); return false; }
    if(price<0){ row.querySelector('.price')?.focus(); alert('Please enter a valid price.'); return false; }
    validCount++;
  }
  renumberTxnRows();
  if(validCount<1){ alert('Add at least one item.'); return false; }
  try{
    if(typeof window._validateTransactionFormOriginal==='function') return window._validateTransactionFormOriginal();
  }catch(e){ alert(e.message||String(e)); return false; }
  return true;
}
document.addEventListener('DOMContentLoaded',function(){
  document.querySelectorAll('.sale-row').forEach(function(r){bindEntryRow(r)});
  document.querySelectorAll('.payment-line').forEach(function(r){bindPaymentLine(r);var m=r.querySelector('[name="pay_method[]"]');if(m)togglePaymentFields(m);});
  recalc();
});
</script>
<script>
window.updateItemCategoryTrigger=function(){
  const root=document.getElementById('itemCategoryDropdown');
  const out=document.getElementById('itemCategoryTriggerText');
  if(!root||!out)return;
  const names=[...root.querySelectorAll('input[name="category_ids[]"]:checked')].map(x=>x.nextElementSibling?.textContent.trim()||'').filter(Boolean);
  out.textContent=names.length?names.join(', '):'Select Category';
};
window.filterItemCategoryChoices=function(){
  const type=document.querySelector('#itemModal input[name="item_type"]:checked')?.value||'product';
  document.querySelectorAll('#itemModal .item-category-option').forEach(function(el){
    const show=el.dataset.categoryType===type;
    el.style.display=show?'flex':'none';
    if(!show){const cb=el.querySelector('input');if(cb)cb.checked=false;}
  });
  window.updateItemCategoryTrigger();
};
document.addEventListener('click',function(e){
  const dd=document.getElementById('itemCategoryDropdown');
  if(dd && dd.hasAttribute('open') && !dd.contains(e.target)) dd.removeAttribute('open');
});
document.addEventListener('DOMContentLoaded',function(){
  window.filterItemCategoryChoices();
  window.updateItemCategoryTrigger();
});
</script>
<?php render_inline_creation_modals(); page_end(); exit; }

