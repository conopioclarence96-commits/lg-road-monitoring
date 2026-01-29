<?php
/**
 * Local Database Configuration
 * 
 * Replace the values below with your live server database credentials.
 * This file is loaded by database.php and will override any defaults.
 * 
 * COMMON MYSQL CONFIGURATIONS:
 * 1. XAMPP: username='root', password='', database='your_db_name'
 * 2. WAMP: username='root', password='', database='your_db_name'  
 * 3. MAMP: username='root', password='root', database='your_db_name'
 * 4. CyberPanel/Domain: username='root', password='root123456', database='road_infra'
 * 5. Custom: username='your_username', password='your_password', database='your_db_name'
 */
return [
    'host' => '127.0.0.1',        // Use IP for domain environment
    'username' => 'rgmap_root',         // your database username
    'password' => 'root123',   // your database password - DOMAIN PASSWORD
    'database' => 'rgmap_road_infra'    // your database name - DOMAIN DATABASE
];
