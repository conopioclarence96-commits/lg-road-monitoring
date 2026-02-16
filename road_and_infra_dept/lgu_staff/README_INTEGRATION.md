# LGU Staff Database Integration Guide

This guide explains how to integrate the LGU Staff HTML pages with the MySQL database we've created.

## 📁 File Structure

```
lgu_staff/
├── config/
│   └── database.php              # Database connection and helper functions
├── api/
│   ├── auth.php                  # Authentication endpoints
│   ├── dashboard.php             # Dashboard data API
│   ├── road_monitoring.php       # Road monitoring API
│   ├── verification.php          # Verification system API
│   └── transparency.php          # Public transparency API
├── js/
│   └── database-integration.js  # Frontend JavaScript integration
├── database/
│   ├── schema.sql               # Complete database schema
│   ├── sample_data.sql           # Sample data for testing
│   └── database_setup.sql       # Automated setup script
└── pages/
    ├── lgu_staff_dashboard.html
    ├── road_transportation_monitoring.html
    ├── verification_monitoring.html
    └── public_transparency.html
```

## 🚀 Quick Setup

### 1. Database Setup

```bash
# Create and populate the database
mysql -u root -p < database/database_setup.sql

# Load sample data (optional)
mysql -u root -p lgu_road_infrastructure < database/sample_data.sql
```

### 2. Configure Database Connection

Edit `config/database.php` and update the database credentials:

```php
private $host = 'localhost';
private $db_name = 'lgu_road_infrastructure';
private $username = 'lgu_app';
private $password = 'SecurePassword123!';
```

### 3. Web Server Configuration

Ensure your web server supports PHP and has the following extensions:
- PHP 8.0+
- MySQLi/PDO
- JSON
- mbstring

### 4. File Permissions

Create necessary directories and set permissions:

```bash
mkdir -p uploads/incidents uploads/documents logs
chmod 755 uploads logs
chmod 644 config/database.php
```

## 🔌 API Endpoints

### Authentication API (`api/auth.php`)

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `auth.php?action=login` | User login |
| POST | `auth.php?action=logout` | User logout |
| GET | `auth.php?action=validate_session` | Validate session |
| GET | `auth.php?action=user_profile` | Get user profile |
| POST | `auth.php?action=register` | Register new user (admin) |

### Dashboard API (`api/dashboard.php`)

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `dashboard.php?action=stats` | Dashboard statistics |
| GET | `dashboard.php?action=incidents` | Recent incidents |
| GET | `dashboard.php?action=activity` | Recent activity |
| GET | `dashboard.php?action=tasks` | Priority tasks |
| GET | `dashboard.php?action=charts` | Chart data |

### Road Monitoring API (`api/road_monitoring.php`)

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `road_monitoring.php?action=roads` | List all roads |
| GET | `road_monitoring.php?action=incidents` | Get incidents with filters |
| GET | `road_monitoring.php?action=map_data` | Map incident data |
| GET | `road_monitoring.php?action=stats` | Monitoring statistics |
| POST | `road_monitoring.php?action=create_incident` | Create new incident |
| POST | `road_monitoring.php?action=upload_photo` | Upload incident photo |

### Verification API (`api/verification.php`)

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `verification.php?action=requests` | Verification requests |
| GET | `verification.php?action=timeline` | Request timeline |
| GET | `verification.php?action=workload` | Staff workload |
| PUT | `verification.php?action=approve` | Approve request |
| PUT | `verification.php?action=reject` | Reject request |

### Transparency API (`api/transparency.php`)

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `transparency.php?action=stats` | Transparency statistics |
| GET | `transparency.php?action=documents` | Public documents |
| GET | `transparency.php?action=budget` | Budget data |
| GET | `transparency.php?action=projects` | Project information |
| POST | `transparency.php?action=upload_document` | Upload document |

## 🎯 Frontend Integration

### 1. Include Database Integration

Add this script to each HTML page before the closing `</body>` tag:

```html
<script src="js/database-integration.js"></script>
```

### 2. Automatic Integration

The `database-integration.js` script automatically detects the current page and loads appropriate data:

- **Dashboard**: Loads stats, incidents, activity, and charts
- **Road Monitoring**: Loads roads, incidents, map data, and alerts
- **Verification**: Loads requests, timeline, and workload
- **Transparency**: Loads documents, budget, projects, and metrics

### 3. Manual API Calls

You can also make manual API calls:

```javascript
// Get dashboard stats
const stats = await databaseIntegration.apiCall('dashboard.php?action=stats');

// Create new incident
const result = await databaseIntegration.apiCall('road_monitoring.php', {
    method: 'POST',
    body: JSON.stringify({
        action: 'create_incident',
        road_id: 1,
        incident_type: 'pothole',
        severity_level: 'high',
        title: 'Large pothole on Main Street',
        description: 'Dangerous pothole needs immediate repair',
        reported_by: 'Citizen Report',
        reporter_contact: '09123456789'
    })
});
```

## 🔐 Authentication Flow

### 1. Login Process

```javascript
// Login
const loginData = {
    username: 'admin',
    password: 'admin123'
};

const response = await databaseIntegration.apiCall('auth.php', {
    method: 'POST',
    body: JSON.stringify({
        action: 'login',
        ...loginData
    })
});

// Store session
localStorage.setItem('session_id', response.session_id);
```

### 2. Session Validation

The system automatically validates sessions on page load and redirects to login if needed.

### 3. Role-Based Access

Different user roles have different access levels:
- **Admin**: Full access to all features
- **Supervisor**: Can verify requests and manage staff
- **Staff**: Can manage incidents and view reports
- **Technician**: Can update incident status and upload photos

## 📊 Real-Time Features

### 1. Live Updates

The system supports real-time updates through:
- **Polling**: Regular API calls to check for new data
- **WebSocket**: Future enhancement for true real-time updates

### 2. Notifications

Users receive notifications for:
- New incident assignments
- Verification requests
- System alerts
- Status changes

### 3. Activity Feeds

Real-time activity feeds show:
- Recent incidents
- Verification actions
- System changes
- User activities

## 🗂️ File Uploads

### 1. Incident Photos

Upload incident photos with automatic resizing and validation:

```javascript
const formData = new FormData();
formData.append('photo', fileInput.files[0]);
formData.append('incident_id', incidentId);
formData.append('uploaded_by', userId);
formData.append('description', 'Photo of the damage');

const response = await fetch('api/road_monitoring.php?action=upload_photo', {
    method: 'POST',
    body: formData
});
```

### 2. Document Uploads

Upload public transparency documents:

```javascript
const formData = new FormData();
formData.append('document', fileInput.files[0]);
formData.append('title', 'Annual Report 2024');
formData.append('document_type', 'annual_report');
formData.append('description', 'Comprehensive annual report');
formData.append('uploaded_by', userId);

const response = await fetch('api/transparency.php?action=upload_document', {
    method: 'POST',
    body: formData
});
```

## 📈 Charts and Analytics

### 1. Dashboard Charts

The dashboard uses Chart.js for data visualization:

```javascript
// Chart data is automatically loaded
const chartData = await databaseIntegration.apiCall('dashboard.php?action=charts&chart=weekly');

// Chart is automatically updated
window.reportsChart = new Chart(ctx, {
    type: 'line',
    data: chartData,
    options: { /* chart options */ }
});
```

### 2. Performance Metrics

Performance metrics are tracked and displayed:
- Response times
- Resolution rates
- Budget utilization
- Service delivery scores

## 🛠️ Customization

### 1. Adding New API Endpoints

1. Create the PHP function in the appropriate API file
2. Add the action to the switch statement
3. Update the JavaScript integration if needed

### 2. Modifying Database Schema

1. Update the `database/schema.sql` file
2. Create migration scripts for existing databases
3. Update the DBHelper class methods
4. Update API endpoints to use new fields

### 3. Adding New Pages

1. Create the HTML page in the `pages/` directory
2. Add page detection logic to `database-integration.js`
3. Create corresponding API endpoints if needed
4. Add navigation links to the sidebar

## 🔧 Troubleshooting

### Common Issues

1. **Database Connection Failed**
   - Check database credentials in `config/database.php`
   - Ensure MySQL server is running
   - Verify database exists and user has permissions

2. **API Calls Returning 404**
   - Check file paths and permissions
   - Ensure web server is configured for PHP
   - Check .htaccess rules if using Apache

3. **Authentication Issues**
   - Clear browser localStorage
   - Check session timeout settings
   - Verify user account is active

4. **File Upload Issues**
   - Check upload directory permissions
   - Verify file size limits
   - Ensure PHP file upload settings are correct

### Debug Mode

Enable debug mode by adding this to `config/database.php`:

```php
// Add at the top of the file
define('DEBUG_MODE', true);

// Modify error handling
if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}
```

## 📱 Mobile Support

The system is responsive and works on mobile devices:
- Touch-friendly interface
- Responsive charts
- Mobile-optimized forms
- Adaptive navigation

## 🔒 Security Considerations

1. **Password Security**: Uses bcrypt for password hashing
2. **Session Management**: Secure session tokens with expiration
3. **Input Validation**: All inputs are validated and sanitized
4. **SQL Injection**: Uses prepared statements
5. **File Upload**: Validates file types and sizes
6. **CORS**: Properly configured CORS headers

## 🚀 Performance Optimization

1. **Database Indexing**: Optimized indexes for common queries
2. **Caching**: Browser caching for static assets
3. **Lazy Loading**: Data loaded as needed
4. **Compression**: Gzip compression for API responses
5. **Connection Pooling**: Efficient database connections

## 📞 Support

For technical support:
- Check the error logs in `logs/error.log`
- Review browser console for JavaScript errors
- Verify database connection and permissions
- Test API endpoints directly

## 🔄 Updates and Maintenance

1. **Regular Backups**: Schedule database backups
2. **Log Rotation**: Rotate log files regularly
3. **Security Updates**: Keep PHP and MySQL updated
4. **Performance Monitoring**: Monitor system performance
5. **User Training**: Train staff on new features

---

**Note**: This integration provides a complete database-backed system for the LGU Road & Infrastructure Department. All features are functional and ready for production use with proper setup and configuration.
