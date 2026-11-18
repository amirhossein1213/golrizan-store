<?php
declare(strict_types=1);require __DIR__.'/app/bootstrap.php';if(!is_post())redirect(app_url());verify_csrf();customer_logout();redirect(app_url());
