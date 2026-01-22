<?php
require_once 'road_and_infra_dept/config/database.php';

$db = new Database();
$ref = new ReflectionClass($db);
$props = $ref->getProperties(ReflectionProperty::IS_PRIVATE);

foreach ($props as $prop) {
    if ($prop->getName() === 'conn') continue;
    $prop->setAccessible(true);
    echo $prop->getName() . ": [" . $prop->getValue($db) . "]\n";
}
?>
