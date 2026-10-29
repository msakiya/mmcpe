<?php
$f = __DIR__ . '/crm-cpe-v1.5.1.zip';
if (!is_file($f)) { http_response_code(404); exit('missing'); }
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="crm-cpe-v1.5.1.zip"');
header('Content-Length: ' . filesize($f));
header('Cache-Control: no-store');
readfile($f);
