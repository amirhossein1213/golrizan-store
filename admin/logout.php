<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
admin_required();
if (!is_post()) {
    http_response_code(405);
    header('Allow: POST');
    exit('روش درخواست معتبر نیست.');
}
verify_csrf();
admin_logout();
redirect(app_url('admin/login.php'));
