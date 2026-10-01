<?php
function ensure_bundle_schema(): void {
    static $done=false; if($done) return; $done=true;
    $pdo=db();
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS item_bundles (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id INT UNSIGNED NOT NULL,
            parent_item_id INT UNSIGNED NOT NULL,
            component_item_id INT UNSIGNED NOT NULL,
            quantity DECIMAL(18,6) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            UNIQUE KEY uq_item_bundle_component(company_id,parent_item_id,component_item_id),
            KEY idx_item_bundle_parent(company_id,parent_item_id,sort_order),
            KEY idx_item_bundle_component(company_id,component_item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch(Throwable $e) { error_log('bundle schema item_bundles: '.$e->getMessage()); }

    // Backward-compatible migration: older Bundle builds created item_bundles
    // without sort_order. CREATE TABLE IF NOT EXISTS does not alter an existing
    // table, so add the missing column explicitly before any INSERT/SELECT uses it.
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM item_bundles')->fetchAll(PDO::FETCH_COLUMN,0);
        if(!in_array('sort_order',$cols,true)){
            $pdo->exec('ALTER TABLE item_bundles ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER quantity');
        }
        try { $pdo->exec('CREATE INDEX idx_item_bundle_parent ON item_bundles(company_id,parent_item_id,sort_order)'); } catch(Throwable $e) {}
    } catch(Throwable $e) { error_log('bundle migration item_bundles sort_order: '.$e->getMessage()); }

    try {
        $cols=$pdo->query('SHOW COLUMNS FROM transaction_items')->fetchAll(PDO::FETCH_COLUMN,0);
        if(!in_array('bundle_parent_transaction_item_id',$cols,true)){
            $pdo->exec('ALTER TABLE transaction_items ADD COLUMN bundle_parent_transaction_item_id BIGINT UNSIGNED NULL AFTER item_id');
            try{$pdo->exec('CREATE INDEX idx_ti_bundle_parent ON transaction_items(bundle_parent_transaction_item_id)');}catch(Throwable $e){}
        }
    } catch(Throwable $e) { error_log('bundle schema transaction_items: '.$e->getMessage()); }
}
function bundle_components_for_parent(PDO $pdo,int $companyId,int $parentItemId): array {
    try {
        $st=$pdo->prepare('SELECT ib.id bundle_id,ib.component_item_id item_id,ib.quantity,ib.sort_order,
                                  i.name,i.code,i.item_type,i.serial_tracked,i.sale_price,i.purchase_price,i.unit_id,
                                  COALESCE(u.symbol,"") unit_symbol
                           FROM item_bundles ib
                           JOIN items i ON i.id=ib.component_item_id AND i.company_id=ib.company_id AND i.active=1
                           LEFT JOIN units u ON u.id=i.unit_id
                           WHERE ib.company_id=? AND ib.parent_item_id=?
                           ORDER BY ib.sort_order,ib.id');
        $st->execute([$companyId,$parentItemId]);
        $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    } catch(Throwable $e) {
        error_log('bundle lookup failed: '.$e->getMessage());
        return [];
    }
    foreach($rows as &$r){
        $r['bundle_id']=(int)$r['bundle_id']; $r['item_id']=(int)$r['item_id']; $r['quantity']=(float)$r['quantity'];
        $r['sort_order']=(int)$r['sort_order']; $r['serial_tracked']=(int)$r['serial_tracked']; $r['unit_id']=(int)$r['unit_id'];
    }
    unset($r); return $rows;
}
function bundle_map_for_company(PDO $pdo,int $companyId): array {
    try {
        $st=$pdo->prepare('SELECT ib.parent_item_id,ib.component_item_id,ib.quantity,ib.sort_order,
                                  i.name,i.code,i.item_type,i.serial_tracked,i.sale_price,i.purchase_price,i.unit_id,
                                  COALESCE(u.symbol,"") unit_symbol
                           FROM item_bundles ib
                           JOIN items i ON i.id=ib.component_item_id AND i.company_id=ib.company_id AND i.active=1
                           LEFT JOIN units u ON u.id=i.unit_id
                           WHERE ib.company_id=? ORDER BY ib.parent_item_id,ib.sort_order,ib.id');
        $st->execute([$companyId]); $map=[];
    } catch(Throwable $e) {
        error_log('bundle map failed: '.$e->getMessage());
        return [];
    }
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r){
        $pid=(int)$r['parent_item_id'];
        $r['component_item_id']=(int)$r['component_item_id'];
        // Normalize the bundle component shape for both server-rendered selects
        // and the live item-search AJAX response.  Older code paths use
        // component_item_id while the entry-row renderer expects item_id.
        $r['item_id']=$r['component_item_id'];
        $r['quantity']=(float)$r['quantity'];
        $r['serial_tracked']=(int)$r['serial_tracked']; $r['unit_id']=(int)$r['unit_id'];
        $map[$pid]??=[]; $map[$pid][]=$r;
    }
    return $map;
}
function bundle_component_lookup(PDO $pdo,int $companyId,int $parentItemId,int $componentItemId): ?array {
    foreach(bundle_components_for_parent($pdo,$companyId,$parentItemId) as $r){
        if((int)$r['item_id']===$componentItemId) return $r;
    }
    return null;
}
function bundle_option_json(array $rows): string {
    $out=[];
    foreach($rows as $r){
        $out[]=['item_id'=>(int)$r['item_id'],'name'=>(string)$r['name'],'quantity'=>(float)$r['quantity'],
                'unit_symbol'=>(string)($r['unit_symbol']??''),'serial_tracked'=>(int)($r['serial_tracked']??0)];
    }
    return json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
ensure_bundle_schema();
