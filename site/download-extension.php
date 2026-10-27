<?php
$f = __DIR__ . '/crm-xigma-v1.4.0.zip';
if (!is_file($f)) { http_response_code(404); exit('missing'); }
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="crm-xigma-v1.4.0.zip"');
header('Content-Length: ' . filesize($f));
header('Cache-Control: no-store');
readfile($f);
