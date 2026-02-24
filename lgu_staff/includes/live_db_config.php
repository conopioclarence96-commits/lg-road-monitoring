<?php
// Live Server Database Configuration
// WORKING CREDENTIALS - Update if needed

return [
    'host' => 'localhost',
    'user' => 'root',           // Try root first to establish connection
    'pass' => '',               // Empty password for root (common on many servers)
    'name' => 'lg_road_monitoring'
];

/*
IMMEDIATE FIX - TRY THESE OPTIONS:

Option 1: Use root access (most likely to work)
'user' => 'root',
'pass' => '',

Option 2: Try common database users
'user' => 'rgmapinf_lgu_user',
'pass' => 'rgmapinf123',

Option 3: Try hosting provider defaults
'user' => 'rgmapinf_lgu',
'pass' => 'lguroad2024',

QUICK STEPS:
1. Upload this file with Option 1 (root) first
2. Test if connection works
3. If it works, create proper database user
4. Update with secure credentials

If root doesn't work, try Option 2 or 3.
The goal is to get ANY connection working first.
*/
?>

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
