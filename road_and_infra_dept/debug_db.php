<?php
$host = 'localhost';
$user = 'root';
$pass = '';

echo "Testing connection with User: $user, Pass: [HIDDEN]\n";

$conn = @new mysqli($host, $user, $pass);

if ($conn->connect_error) {
    echo "Connection failed: " . $conn->connect_error . "\n";
    
    echo "Trying with User: root, Pass: root123\n";
    $conn = @new mysqli($host, 'root', 'root123');
    if ($conn->connect_error) {
        echo "Connection failed again: " . $conn->connect_error . "\n";
    } else {
        echo "Connected successfully with root/root123!\n";
        list_dbs($conn);
    }
} else {
    echo "Connected successfully with root/[EMPTY]!\n";
    list_dbs($conn);
}

function list_dbs($conn) {
    $result = $conn->query("SHOW DATABASES");
    echo "Databases found:\n";
    while ($row = $result->fetch_assoc()) {
        echo "- " . $row['Database'] . "\n";
    }
}
