<?php
$users = ['root', 'rgmap', 'infragov', 'admin'];
$passes = ['', 'root', 'root123', 'admin', 'admin123', 'rgmap123', 'mysql'];

foreach ($users as $u) {
    foreach ($passes as $p) {
        echo "Testing $u with pass '$p': ";
        $c = @new mysqli('localhost', $u, $p);
        if ($c->connect_error) {
            echo "FAIL (" . $c->connect_errno . ")\n";
        } else {
            echo "SUCCESS!\n";
            $c->close();
        }
    }
}
?>
