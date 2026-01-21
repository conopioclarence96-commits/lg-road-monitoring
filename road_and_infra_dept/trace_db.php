<?php
require_once __DIR__ . '/config/database.php';

try {
    echo "Attempting to initialize Database class...\n";
    $db = new Database();
    echo "Database instance created.\n";
    
    $conn = $db->getConnection();
    if ($conn) {
        echo "Connection object obtained.\n";
        if ($conn->connect_error) {
            echo "Connection Error: " . $conn->connect_error . "\n";
        } else {
            echo "Connection successful!\n";
            echo "Current DB: " . $db->query("SELECT DATABASE()")->fetch_row()[0] . "\n";
        }
    } else {
        echo "Connection object is null.\n";
    }
} catch (Exception $e) {
    echo "Caught Exception: " . $e->getMessage() . "\n";
    echo "Stack trace: \n" . $e->getTraceAsString() . "\n";
}
