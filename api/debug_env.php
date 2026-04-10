<?php
header('Content-Type: text/plain');
echo "VERCEL_ENV: " . (getenv('VERCEL_ENV') ?: 'Not set') . "\n";
echo "VERCEL_GIT_COMMIT_REF: " . (getenv('VERCEL_GIT_COMMIT_REF') ?: 'Not set') . "\n";
echo "PGDATABASE: " . (getenv('PGDATABASE') ?: 'Not set') . "\n";
?>
