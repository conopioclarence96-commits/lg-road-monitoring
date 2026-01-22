<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = $_POST['host'] ?? 'localhost';
    $user = $_POST['user'] ?? 'root';
    $pass = $_POST['pass'] ?? '';
    $db   = $_POST['db']   ?? 'rgmap_lgu_road_infra';
    
    echo "<h3>Testing Connection...</h3>";
    $c = @new mysqli($host, $user, $pass, $db);
    if ($c->connect_error) {
        echo "<p style='color:red'>FAIL: " . $c->connect_error . "</p>";
    } else {
        echo "<p style='color:green'>SUCCESS! Connected to $db</p>";
        echo "<p>Use these settings in database.local.php</p>";
        $c->close();
    }
}
?>
<form method="POST">
    Host: <input name="host" value="localhost"><br>
    User: <input name="user" value="root"><br>
    Pass: <input name="pass" type="password"><br>
    DB: <input name="db" value="rgmap_lgu_road_infra"><br>
    <button type="submit">Test Connection</button>
</form>
