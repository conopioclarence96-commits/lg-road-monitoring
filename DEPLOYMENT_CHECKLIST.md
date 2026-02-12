# LGU Road Monitoring Deployment Checklist

## 🚀 Pre-Deployment

### Local Testing
- [ ] PHP syntax check: `php -l index.php`
- [ ] Database connection test locally
- [ ] All required files present

### Configuration Files
- [ ] `database.local.php` configured with server credentials
- [ ] `.htaccess` ready for upload
- [ ] File permissions checked locally

## 📤 Deployment Steps

### 1. Upload Files
Using FTP/SFTP or rsync:
```bash
# Core files to upload:
- index.php
- road_and_infra_dept/ (entire directory)
- .htaccess
- SMART_BARANGAY_DETECTION_GUIDE.md
```

### 2. Set Permissions
```bash
# Directory permissions: 755
# File permissions: 644
chmod 755 /path/to/your/website/
chmod 644 /path/to/your/website/*.php
chmod -R 755 /path/to/your/website/road_and_infra_dept/
```

### 3. Database Setup
- [ ] Database `rgmap_road_infra` exists on server
- [ ] User `rgmap_root` has proper permissions
- [ ] Test connection: `mysql -u rgmap_root -p rgmap_road_infra`

## ✅ Post-Deployment Testing

### Basic Functionality
- [ ] Visit `http://rgmap.infragovservices.com/` - should load login page
- [ ] No 404 errors
- [ ] No PHP fatal errors

### Database Testing
- [ ] Login page loads without database errors
- [ ] Test with valid credentials
- [ ] Check error logs if issues

### Security Check
- [ ] `database.local.php` not accessible via browser
- [ ] Error handling works properly
- [ ] Sensitive directories blocked

## 🔧 Troubleshooting

### 404 Errors
1. Check file locations: `index.php` must be in document root
2. Verify `.htaccess` is uploaded and readable
3. Check Apache/Nginx configuration
4. Ensure `DirectoryIndex index.php` is set

### Database Connection Issues
1. Verify database exists: `SHOW DATABASES;`
2. Test credentials manually
3. Check MySQL service status
4. Review error logs: `/var/log/mysql/error.log`

### Permission Issues
1. Check file ownership: `ls -la`
2. Verify web server user (www-data, apache, etc.)
3. Reset permissions if needed

### PHP Errors
1. Enable error reporting temporarily:
   ```php
   ini_set('display_errors', 1);
   error_reporting(E_ALL);
   ```
2. Check PHP error log
3. Verify PHP version compatibility

## 📞 Support Contacts

- **Web Host**: Your hosting provider support
- **Database Admin**: Database administrator
- **Domain**: Domain registrar (if DNS issues)

## 🔄 Regular Maintenance

- [ ] Backup database weekly
- [ ] Update dependencies monthly
- [ ] Monitor error logs
- [ ] Security audit quarterly

---

**Last Updated**: $(date)
**Version**: 1.0
