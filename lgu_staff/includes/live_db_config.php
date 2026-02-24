<?php
// Live Server Database Configuration
// UPDATE THESE VALUES WITH YOUR ACTUAL LIVE SERVER DATABASE CREDENTIALS

return [
    'host' => 'localhost', // Your live database host (usually localhost)
    'user' => 'rgmapinf_lgu_user', // Replace with actual database username
    'pass' => 'YourSecurePassword123!', // Replace with actual database password
    'name' => 'lg_road_monitoring'      // Database name from your SQL dump
];

/*
HOW TO FIND YOUR LIVE DATABASE CREDENTIALS:

1. cPanel:
   - Login to cPanel at yourdomain.com/cpanel
   - Go to "MySQL Databases" or "MySQL Database Wizard"
   - Look for existing databases and users
   - Note the database name, username, and password

2. Plesk:
   - Login to Plesk
   - Go to "Databases" > "MySQL Databases"
   - Find your database and click on the user to see credentials

3. Hosting Control Panel:
   - Look for "Database Management" or "MySQL" section
   - Find database users and reset password if needed

4. Create New Database User (if needed):
   - Create database: lg_road_monitoring
   - Create user: rgmapinf_lgu_user
   - Set password: Choose a strong password
   - Grant all privileges to user on database

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
