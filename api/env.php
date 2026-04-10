<?php
require_once __DIR__ . '/_db.php';
fp_cors();
fp_success(['env' => fp_env_info()]);
