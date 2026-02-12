#!/bin/bash

# LGU Road Monitoring Deployment Script
# This script helps deploy the project to rgmap.infragovservices.com

echo "=== LGU Road Monitoring Deployment ==="
echo "Target: rgmap.infragovservices.com"
echo ""

# Configuration
SERVER_USER="rgmap_road_infra"  # Change to your SSH username
SERVER_HOST="rgmap.infragovservices.com"
REMOTE_PATH="/home/your_username/public_html"  # Change to your document root
LOCAL_PATH="$(pwd)"

echo "Local path: $LOCAL_PATH"
echo "Remote path: $REMOTE_PATH"
echo ""

# Create deployment checklist
echo "=== Pre-deployment Checklist ==="
echo "✓ PHP syntax check..."
php -l index.php
php -l road_and_infra_dept/config/database.php
php -l road_and_infra_dept/user_and_access_management_module/login.php

echo ""
echo "=== Files to deploy ==="
echo "Core files:"
echo "  - index.php"
echo "  - road_and_infra_dept/ (entire directory)"
echo "  - .vscode/ (if needed for debugging)"
echo "  - SMART_BARANGAY_DETECTION_GUIDE.md"
echo ""

echo "=== Database Configuration ==="
echo "Current database.local.php settings:"
if [ -f "road_and_infra_dept/config/database.local.php" ]; then
    grep -E "(host|username|password|database)" road_and_infra_dept/config/database.local.php | head -4
else
    echo "⚠ database.local.php not found - using default settings"
fi

echo ""
echo "=== Deployment Commands ==="
echo "Run these commands manually or use FTP/SFTP client:"
echo ""
echo "# 1. Upload files:"
echo "rsync -avz --exclude='.git' --exclude='deploy.sh' $LOCAL_PATH/ $SERVER_USER@$SERVER_HOST:$REMOTE_PATH/"
echo ""
echo "# 2. Set permissions:"
echo "ssh $SERVER_USER@$SERVER_HOST 'chmod 755 $REMOTE_PATH && chmod 644 $REMOTE_PATH/*.php && chmod -R 755 $REMOTE_PATH/road_and_infra_dept'"
echo ""
echo "# 3. Test deployment:"
echo "curl -I http://$SERVER_HOST/"
echo ""

echo "=== Post-deployment Testing ==="
echo "1. Visit: http://rgmap.infragovservices.com/"
echo "2. Check for PHP errors in: /var/log/apache2/error.log or similar"
echo "3. Test database connection via login page"
echo ""

echo "=== Troubleshooting ==="
echo "If 404 persists:"
echo "- Check .htaccess file exists and is correct"
echo "- Verify Apache/Nginx configuration"
echo "- Ensure index.php is in document root"
echo "- Check file ownership and permissions"
echo ""
echo "If database errors:"
echo "- Verify database exists on server"
echo "- Check database credentials in database.local.php"
echo "- Test MySQL connection manually"
