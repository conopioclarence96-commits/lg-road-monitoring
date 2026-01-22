<?php
echo "DB_HOST: " . getenv('DB_HOST') . "\n";
echo "DB_USER: " . getenv('DB_USER') . "\n";
echo "DB_PASS: " . (getenv('DB_PASS') ? 'SET' : 'NOT SET') . "\n";
echo "DB_NAME: " . getenv('DB_NAME') . "\n";
echo "--- All Env ---\n";
print_r($_ENV);
echo "--- Server Env ---\n";
print_r($_SERVER);
?>
