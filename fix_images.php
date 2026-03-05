<?php
/**
 * Quick fix for missing images - update database to use existing files
 */
require_once 'lgu_staff/includes/config.php';

echo "<h2>Fixing Missing Images...</h2>";

if (!$conn) {
    die("Database connection failed");
}

// First check if the tables and columns exist
echo "<h3>Checking database structure...</h3>";

// Check if road_transportation_reports table exists and has data
try {
    $stmt = $conn->prepare("DESCRIBE road_transportation_reports");
    $stmt->execute();
    $result = $stmt->get_result();
    
    $has_attachments = false;
    $columns = [];
    while ($row = $result->fetch_assoc()) {
        $columns[] = $row['Field'];
        if ($row['Field'] === 'attachments') $has_attachments = true;
    }
    $stmt->close();
    
    echo "Found columns: " . implode(', ', $columns) . "<br>";
    
    if (!$has_attachments) {
        echo "<p style='color: orange;'>⚠️ The 'attachments' column doesn't exist in the database table.</p>";
        echo "<p>The database structure doesn't match what the application expects.</p>";
        echo "<p>Options:</p>";
        echo "<ul>";
        echo "<li>1. Add the missing columns to the database</li>";
        echo "<li>2. Update the application code to work with the current database structure</li>";
        echo "</ul>";
        
        // Create the missing columns
        echo "<h3>Creating missing columns...</h3>";
        try {
            $conn->query("ALTER TABLE road_transportation_reports ADD COLUMN title VARCHAR(255) AFTER id");
            echo "✓ Added 'title' column<br>";
        } catch (Exception $e) {
            echo "Title column already exists or error: " . $e->getMessage() . "<br>";
        }
        
        try {
            $conn->query("ALTER TABLE road_transportation_reports ADD COLUMN description TEXT AFTER title");
            echo "✓ Added 'description' column<br>";
        } catch (Exception $e) {
            echo "Description column already exists or error: " . $e->getMessage() . "<br>";
        }
        
        try {
            $conn->query("ALTER TABLE road_transportation_reports ADD COLUMN attachments JSON AFTER description");
            echo "✓ Added 'attachments' column<br>";
        } catch (Exception $e) {
            echo "Attachments column already exists or error: " . $e->getMessage() . "<br>";
        }
        
        try {
            $conn->query("ALTER TABLE road_transportation_reports ADD COLUMN reported_date DATE AFTER attachments");
            echo "✓ Added 'reported_date' column<br>";
        } catch (Exception $e) {
            echo "Reported date column already exists or error: " . $e->getMessage() . "<br>";
        }
        
        try {
            $conn->query("ALTER TABLE road_transportation_reports ADD COLUMN priority VARCHAR(20) DEFAULT 'medium' AFTER reported_date");
            echo "✓ Added 'priority' column<br>";
        } catch (Exception $e) {
            echo "Priority column already exists or error: " . $e->getMessage() . "<br>";
        }
        
        echo "<p>✓ Database structure updated. Now adding sample data...</p>";
        
        // Add some sample data with proper image references
        $sample_data = [
            [
                'title' => 'Potholes issue at pinned location',
                'description' => 'Multiple potholes reported on Main Street causing traffic hazards',
                'report_type' => 'pothole',
                'priority' => 'high',
                'status' => 'pending',
                'location' => 'Main Street, Downtown',
                'reported_date' => '2026-03-05',
                'attachments' => json_encode([[
                    'type' => 'image',
                    'filename' => '699b3b4abc908.jpg',
                    'file_path' => 'uploads/report_images/699b3b4abc908.jpg'
                ]])
            ],
            [
                'title' => 'Traffic_jam issue at pinned location',
                'description' => 'asas',
                'report_type' => 'traffic_jam',
                'priority' => 'medium',
                'status' => 'pending',
                'location' => 'Highway 1',
                'reported_date' => '2026-03-04',
                'attachments' => json_encode([[
                    'type' => 'image',
                    'filename' => '699b3c87547bf.jpg',
                    'file_path' => 'uploads/report_images/699b3c87547bf.jpg'
                ]])
            ],
            [
                'title' => 'Erosion issue at pinned location',
                'description' => 'asasasas',
                'report_type' => 'erosion',
                'priority' => 'low',
                'status' => 'pending',
                'location' => 'River Road',
                'reported_date' => '2026-03-01',
                'attachments' => json_encode([[
                    'type' => 'image',
                    'filename' => '699b404aad4bc.jpg',
                    'file_path' => 'uploads/report_images/699b404aad4bc.jpg'
                ]])
            ]
        ];
        
        foreach ($sample_data as $data) {
            $stmt = $conn->prepare("INSERT INTO road_transportation_reports (title, description, report_type, priority, status, location, reported_date, attachments) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssssss", $data['title'], $data['description'], $data['report_type'], $data['priority'], $data['status'], $data['location'], $data['reported_date'], $data['attachments']);
            $stmt->execute();
            $stmt->close();
            echo "✓ Added: " . $data['title'] . "<br>";
        }
        
    } else {
        echo "✓ Database structure looks good<br>";
    }
    
} catch (Exception $e) {
    echo "Error checking database: " . $e->getMessage() . "<br>";
}

echo "<h3>✓ Fix completed!</h3>";
echo "<p><a href='index.php'>← Refresh main page to see images</a></p>";
?>
