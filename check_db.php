<?php
require_once 'lgu_staff/includes/config.php';
$result = $conn->query('DESCRIBE users');
while($row = $result->fetch_assoc()) {
    echo $row['Field'] . ' - ' . $row['Type'] . PHP_EOL;
}
?>
