<?php
// clear_debug.php - Clear debug log file
if (file_exists('debug_upload.log')) {
    unlink('debug_upload.log');
}
echo '{"success": true, "message": "Debug log cleared"}';
?>
