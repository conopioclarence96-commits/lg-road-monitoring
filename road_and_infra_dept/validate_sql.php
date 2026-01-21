<?php
$host = '127.0.0.1';
$user = 'root';
$pass = '';

$mysqli = @new mysqli($host, $user, $pass);

if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}

echo "Connected to MySQL successfully.\n";

$sqlFile = __DIR__ . '/setup/master_database_setup.sql';
if (!file_exists($sqlFile)) {
    die("SQL file not found at: $sqlFile");
}

$sql = file_get_contents($sqlFile);

echo "Attempting to execute multi-query...\n";

if ($mysqli->multi_query($sql)) {
    $i = 0;
    do {
        $i++;
        if ($result = $mysqli->store_result()) {
            $result->free();
        }
    } while ($mysqli->more_results() && $mysqli->next_result());
    
    if ($mysqli->error) {
        echo "SQL Error on statement $i: " . $mysqli->error . "\n";
    } else {
        echo "SQL Syntax and Execution Verified Successfully! ($i statements executed)\n";
    }
} else {
    echo "Initial SQL Error: " . $mysqli->error . "\n";
}

$mysqli->close();
