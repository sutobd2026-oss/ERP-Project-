<?php
declare(strict_types=1);

// sense modular v1: public entry point.
// The previous monolithic public/index.php is preserved under backup/index-original.php.
require __DIR__ . '/../app/core.php';

$publicRoutes = ['login','register','accept-invite','forgot-password','reset-password','platform-login','platform-logout','platform-control','platform-audit'];
if (!in_array($route, $publicRoutes, true)) {
    $u = require_login();
}

$routeFiles = [
    'login'=>'auth.php', 'platform-login'=>'auth.php', 'platform-logout'=>'auth.php', 'platform-audit'=>'auth.php', 'register'=>'auth.php', 'forgot-password'=>'auth.php', 'reset-password'=>'auth.php',
    'party-search-api'=>'api.php', 'item-search-api'=>'api.php', 'bundle-components-api'=>'api.php', 'inline-party-create'=>'api.php', 'inline-product-create'=>'api.php',
    'logout'=>'general.php', 'support'=>'general.php', 'platform-control'=>'general.php', 'serial-search-api'=>'general.php', 'transactions'=>'general.php',
    'dashboard'=>'dashboard.php',
    'parties'=>'parties-ledgers.php', 'party-ledger'=>'parties-ledgers.php', 'item-ledger'=>'parties-ledgers.php',
    'items'=>'items.php',
    'sale-new'=>'transaction-entry.php', 'purchase-new'=>'transaction-entry.php', 'transaction-save'=>'transaction-entry.php',
    'payment-in'=>'sales-purchase-reports.php', 'sales'=>'sales-purchase-reports.php', 'purchase'=>'sales-purchase-reports.php', 'reports'=>'sales-purchase-reports.php', 'payment-out'=>'sales-purchase-reports.php',
    'return-items'=>'returns-product-request.php', 'product-request-stock'=>'returns-product-request.php',
    'sale-return'=>'sales-purchase-reports.php', 'purchase-return'=>'sales-purchase-reports.php',
    'delivery-challans'=>'delivery-docs.php', 'delivery-challan-items'=>'delivery-docs.php', 'delivery-challan-new'=>'returns-product-request.php',
    'product-requests'=>'returns-product-request.php', 'product-request-new'=>'returns-product-request.php',
    'quotations'=>'returns-product-request.php', 'sale-order'=>'returns-product-request.php', 'sale-order-new'=>'returns-product-request.php', 'purchase-order'=>'returns-product-request.php', 'purchase_order'=>'returns-product-request.php',
    'expense-new'=>'expense.php', 'expense'=>'expense.php',
    'cash'=>'cash.php',
    'cheques'=>'banking.php', 'loans'=>'banking.php', 'bank-accounts'=>'banking.php',
    'messages'=>'messages.php',
    'company-network'=>'company-network.php',
    'notifications'=>'notifications-team.php', 'team'=>'notifications-team.php',
    'accept-invite'=>'admin-utilities.php', 'backup'=>'admin-utilities.php', 'recycle-bin'=>'admin-utilities.php', 'audit-log'=>'admin-utilities.php', 'financial-year'=>'admin-utilities.php',
    'export-items'=>'admin-utilities.php', 'import-items'=>'admin-utilities.php', 'import-parties'=>'admin-utilities.php', 'barcode'=>'admin-utilities.php', 'bulk-update'=>'admin-utilities.php', 'settings'=>'admin-utilities.php',
];

$file = $routeFiles[$route] ?? null;
if ($file !== null) {
    require __DIR__ . '/../app/routes/' . $file;

    // Some modules expose route handlers as functions so multiple routes can
    // share one file. Call the handler explicitly after loading the module.
    switch ($route) {
        case 'delivery-challan-new':
            delivery_challan_new();
            break;
        case 'product-requests':
            product_requests_list();
            break;
        case 'product-request-new':
            product_request_new();
            break;
        case 'sale-return':
            return_module('sale_return');
            break;
        case 'purchase-return':
            return_module('purchase_return');
            break;
        case 'quotations':
            document_module('quotation','Estimate / Quotation','QT-','Customer',['sale_order'],true,'quotations');
            break;
        case 'quotations-new':
            document_module('quotation','Estimate / Quotation','QT-','Customer',['sale_order'],false,'quotations');
            break;
        case 'sale-order':
            document_module('sale_order','Sale Order','SO-','Customer',['sale'],true,'sale-order');
            break;
        case 'sale-order-new':
            document_module('sale_order','Sale Order','SO-','Customer',['sale'],false,'sale-order');
            break;
        case 'purchase-order':
        case 'purchase_order':
            document_module('purchase_order','Purchase Order','PO-','Supplier',['purchase'],true,'purchase-order');
            break;
        case 'purchase-order-new':
            document_module('purchase_order','Purchase Order','PO-','Supplier',['purchase'],false,'purchase-order');
            break;
    }
    exit;
}

http_response_code(404);
page_start('Not Found');
echo '<div class="panel"><h1>Page not found</h1></div>';
page_end();
