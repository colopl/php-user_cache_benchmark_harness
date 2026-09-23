<?php
require __DIR__ . '/bench.php';
while (frankenphp_handle_request(benchmark(...))) {}
