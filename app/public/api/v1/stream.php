<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/auth.php';
require_once dirname(__DIR__,2).'/lib/mobile_hls.php';
try{sf_mobile_hls_serve(false);}catch(Throwable $e){error_log('Mobile HLS '.get_class($e));sf_mobile_hls_error(500);}
