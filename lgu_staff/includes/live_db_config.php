<?php
// Live Server Database Configuration
// UPDATE THESE VALUES WITH YOUR ACTUAL LIVE SERVER DATABASE CREDENTIALS

return [
    'host' => 'localhost', // Your live database host (usually localhost)
    'user' => 'rgmapinf_lgu_user', // This user exists but password needs to be updated
    'pass' => '', // Leave empty for now, or use the actual password
    'name' => 'lg_road_monitoring'      // Database name from your SQL dump
];

/*
CURRENT ISSUE:
- User 'rgmapinf_lgu_user' exists on the server
- But the password in config doesn't match the actual password
- Error changed from "using password: NO" to "using password: YES"

SOLUTIONS:

1. FIND THE ACTUAL PASSWORD:
   - Check your hosting control panel (cPanel/Plesk)
   - Look for MySQL Database section
   - Find the user 'rgmapinf_lgu_user' and view/reset password
   - Update the 'pass' value above with the correct password

2. RESET THE PASSWORD:
   - In cPanel: MySQL Databases > Current Users > Click on user > Change Password
   - In Plesk: Databases > Database Users > Click on user > Change Password
   - Set a new password and update it in the config above

3. CREATE NEW USER (if you can't reset password):
   - Create a new database user
   - Grant privileges to lg_road_monitoring database
   - Update the 'user' and 'pass' values above

4. USE ROOT ACCESS (temporary):
   - Temporarily change user to 'root' and pass to ''
   - This will connect but is not secure for production
   - Use only to create proper user credentials

TESTING:
After updating the password, test the connection by visiting your login page.
If it works, the error will disappear.

5. Import Your SQL Dump:
   - Use phpMyAdmin to import: lg_road_monitoring (5).sql
   - Or use command line: mysql -u username -p lg_road_monitoring < file.sql

TROUBLESHOOTING:
- If you get "Access denied", check username and password
- If you get "Unknown database", create the database first
- If you get "Can't connect", check the host (sometimes it's not localhost)
- Contact hosting support if you're unsure about credentials

COMMON HOSTING PROVIDERS:
- Bluehost: Host = localhost
- HostGator: Host = localhost  
- GoDaddy: Host = localhost or specific hostname
- SiteGround: Host = localhost
- Others: Check hosting documentation
*/
?>
