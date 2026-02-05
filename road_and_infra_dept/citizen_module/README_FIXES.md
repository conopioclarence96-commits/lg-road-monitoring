# Citizen Module Bug Fixes

## Issues Fixed

### 1. Database Column Mismatch in handle_report.php ✅ FIXED

**Problem**: The INSERT query in `api/handle_report.php` was missing several required columns and had incorrect column names.

**Issues Found**:
- Missing `barangay` column (required in database)
- Missing `damage_type` column (required in database) 
- Missing `estimated_size` column
- Missing `anonymous_report` column
- Used `reported_at` instead of `created_at`
- Wrong number of bind parameters

**Fix Applied**:
```sql
-- OLD (Broken):
INSERT INTO damage_reports (report_id, reporter_id, location, description, severity, traffic_impact, contact_number, images, reported_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)

-- NEW (Fixed):
INSERT INTO damage_reports (report_id, reporter_id, location, barangay, damage_type, description, severity, estimated_size, traffic_impact, contact_number, anonymous_report, images, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
```

### 2. Database Schema Issues in get_transparency_data.php ✅ FIXED

**Problem**: The transparency API was referencing columns that don't exist in the database.

**Issues Found**:
- Referenced non-existent `publication_status` column
- Used `reported_at` instead of `created_at`
- Wrong status enum values

**Fix Applied**:
- Removed `WHERE publication_status = 'published'` clauses
- Changed `reported_at` to `created_at`
- Updated status enum values to match actual database schema

## Database Schema Requirements

The citizen module requires the `damage_reports` table to have these columns:

```sql
CREATE TABLE IF NOT EXISTS damage_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_id VARCHAR(20) UNIQUE NOT NULL,
    reporter_id INT NULL,
    location VARCHAR(255) NOT NULL,
    barangay VARCHAR(100) NOT NULL,
    damage_type VARCHAR(50) NOT NULL,
    severity ENUM('low', 'medium', 'high', 'urgent') NOT NULL DEFAULT 'medium',
    description TEXT NOT NULL,
    estimated_size VARCHAR(100) NULL,
    traffic_impact ENUM('none', 'minor', 'moderate', 'severe', 'blocked') DEFAULT 'moderate',
    contact_number VARCHAR(20) NULL,
    anonymous_report TINYINT(1) DEFAULT 0,
    images JSON,
    status ENUM('pending', 'under_review', 'approved', 'in_progress', 'completed', 'rejected') DEFAULT 'pending',
    assigned_to INT NULL,
    lgu_notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
);
```

## Required Actions

### Step 1: Update Your Database Schema

If you're still getting column errors, run the provided SQL script:

1. Open phpMyAdmin
2. Select your database
3. Go to "SQL" tab
4. Copy and paste the contents of `fix_database_schema.sql`
5. Click "Go"

This will add any missing columns to your existing `damage_reports` table.

### Step 2: Test the Report Form

1. Navigate to the citizen module
2. Go to the "Report Damage" page
3. Fill out all required fields:
   - Location / Landmark
   - Barangay
   - Damage Type
   - Description
4. Submit the form

The form should now work without database errors.

## Files Modified

1. `api/handle_report.php` - Fixed INSERT query
2. `public_transparency/api/get_transparency_data.php` - Fixed SELECT queries
3. `fix_database_schema.sql` - New file to update database schema
4. `README_FIXES.md` - This documentation file

## Troubleshooting

If you still encounter errors:

1. **Check Database Connection**: Ensure `config/database.php` has correct database credentials
2. **Verify Table Structure**: Run `DESCRIBE damage_reports;` in phpMyAdmin to confirm all columns exist
3. **Check File Permissions**: Ensure the web server can write to the uploads directory
4. **Error Logs**: Check PHP error logs for detailed error messages

## Support

If issues persist after applying these fixes:

1. Check the exact error message
2. Verify your database table structure matches the schema above
3. Ensure all configuration files are properly set up
4. Test with a fresh database if needed

The report form should now be fully functional for citizens to submit road damage reports.
