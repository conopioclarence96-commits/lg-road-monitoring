<?php
// Script to execute transparency portal tables SQL
require_once __DIR__ . '/../lgu_staff/includes/config.php';

echo "<h2>Setting up Transparency Portal Tables...</h2>";

try {
    // Read the SQL file
    $sql_file = __DIR__ . '/transparency_portal_tables.sql';
    if (!file_exists($sql_file)) {
        throw new Exception("SQL file not found: " . $sql_file);
    }
    
    $sql_content = file_get_contents($sql_file);
    
    // Split SQL into individual statements
    $statements = array_filter(array_map('trim', explode(';', $sql_content)));
    
    $success_count = 0;
    $error_count = 0;
    $errors = [];
    
    echo "<h3>Executing SQL Statements...</h3>";
    echo "<ul>";
    
    foreach ($statements as $statement) {
        if (empty($statement) || preg_match('/^--/', $statement)) {
            continue; // Skip comments and empty statements
        }
        
        try {
            $result = $conn->query($statement);
            if ($result) {
                echo "<li style='color: green;'>✓ " . substr($statement, 0, 50) . "...</li>";
                $success_count++;
            } else {
                $error = $conn->error;
                echo "<li style='color: red;'>✗ " . substr($statement, 0, 50) . "...<br>Error: " . htmlspecialchars($error) . "</li>";
                $error_count++;
                $errors[] = $error;
            }
        } catch (Exception $e) {
            echo "<li style='color: red;'>✗ " . substr($statement, 0, 50) . "...<br>Error: " . htmlspecialchars($e->getMessage()) . "</li>";
            $error_count++;
            $errors[] = $e->getMessage();
        }
    }
    
    echo "</ul>";
    
    echo "<h3>Summary:</h3>";
    echo "<p><strong>Successful statements:</strong> $success_count</p>";
    echo "<p><strong>Failed statements:</strong> $error_count</p>";
    
    if ($error_count > 0) {
        echo "<h3>Errors encountered:</h3>";
        echo "<pre>" . htmlspecialchars(implode("\n", array_unique($errors))) . "</pre>";
    }
    
    // Verify tables were created
    echo "<h3>Verifying Tables...</h3>";
    $tables = ['publications', 'performance_metrics', 'citizen_feedback', 'transparency_scores', 'public_contacts', 'transparency_audit_logs'];
    
    echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
    echo "<tr><th>Table Name</th><th>Status</th><th>Record Count</th></tr>";
    
    foreach ($tables as $table) {
        $result = $conn->query("SHOW TABLES LIKE '$table'");
        if ($result && $result->num_rows > 0) {
            $count_result = $conn->query("SELECT COUNT(*) as count FROM `$table`");
            $count = $count_result ? $count_result->fetch_assoc()['count'] : 0;
            echo "<tr><td>$table</td><td style='color: green;'>✓ Created</td><td>$count records</td></tr>";
        } else {
            echo "<tr><td>$table</td><td style='color: red;'>✗ Not found</td><td>-</td></tr>";
        }
    }
    
    echo "</table>";
    
    if ($error_count === 0) {
        echo "<h3 style='color: green;'>✓ Setup completed successfully!</h3>";
        echo "<p>All transparency portal tables have been created and populated with sample data.</p>";
    } else {
        echo "<h3 style='color: orange;'>⚠ Setup completed with some errors</h3>";
        echo "<p>Please review the errors above and manually fix any issues.</p>";
    }
    
} catch (Exception $e) {
    echo "<h3 style='color: red;'>Setup Failed</h3>";
    echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<p><a href='../lgu_staff/pages/transparency/public_transparency.php'>Go to Transparency Portal</a></p>";
?>
