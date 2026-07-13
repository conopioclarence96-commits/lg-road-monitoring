<?php
/**
 * Migration: Add before_photo column to published_completed_projects
 * Run this once to update the database schema
 */

$server_name = $_SERVER['SERVER_NAME'] ?? 'localhost';
$is_local = ($server_name === 'localhost' || $server_name === '127.0.0.1');

if ($is_local) {
    define('DB_HOST', 'localhost');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('DB_NAME', 'rgmap_lg_road_monitoring');
} else {
    $live_config = require __DIR__ . '/../lgu_staff/includes/live_db_config.php';
    define('DB_HOST', $live_config['host']);
    define('DB_USER', $live_config['user']);
    define('DB_PASS', $live_config['pass']);
    define('DB_NAME', $live_config['name']);
}

try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $conn->set_charset("utf8mb4");
    
    echo "Connected to database successfully.\n\n";
    
    // Check if column already exists
    $result = $conn->query("SHOW COLUMNS FROM published_completed_projects LIKE 'before_photo'");
    if ($result->num_rows > 0) {
        echo "Column 'before_photo' already exists. Skipping migration.\n";
    } else {
        // Add the before_photo column
        $conn->query("ALTER TABLE published_completed_projects ADD COLUMN before_photo VARCHAR(500) DEFAULT NULL AFTER photo");
        echo "Column 'before_photo' added successfully.\n";
    }
    
    // Show current table structure
    echo "\nCurrent table structure:\n";
    $result = $conn->query("DESCRIBE published_completed_projects");
    while ($row = $result->fetch_assoc()) {
        echo "  {$row['Field']} - {$row['Type']} " . ($row['Null'] === 'YES' ? 'NULL' : 'NOT NULL') . "\n";
    }
    
    // Show existing data
    echo "\nExisting projects:\n";
    $result = $conn->query("SELECT id, title, photo, before_photo FROM published_completed_projects");
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            echo "  ID: {$row['id']} | Title: {$row['title']} | Photo: " . ($row['photo'] ?: 'none') . " | Before: " . ($row['before_photo'] ?: 'none') . "\n";
        }
    } else {
        echo "  No projects found.\n";
    }
    
    $conn->close();
    echo "\nMigration completed successfully!\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
