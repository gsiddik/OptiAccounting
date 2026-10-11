<?php

use Illuminate\Support\Facades\Route;

/*
| OA5 reporting routes. Included from routes/api.php inside the tenant `accounting` prefix group (auth + tenant context already applied).
| Every route declares access:<permission>,module=ACCOUNTING_REPORTING,feature=<feature>; the central resolver is the only authority.
*/
$rp = fn (string $permission, string $feature = 'FINANCIAL_STATEMENTS') => "access:{$permission},module=ACCOUNTING_REPORTING,feature={$feature}";
