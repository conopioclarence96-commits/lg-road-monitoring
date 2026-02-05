# GIS Mapping Database Setup

## Overview

This document explains the database structure and setup for the GIS mapping functionality in the citizen module. The GIS system provides interactive mapping of road conditions, construction zones, and infrastructure projects.

## Database Tables

### 1. gis_map_markers

Stores point locations for various map features like damage reports, issues, and infrastructure points.

**Columns:**
- `id` - Primary key
- `marker_id` - Unique identifier (e.g., GIS-MARK-001)
- `latitude` - Decimal coordinate (10,8)
- `longitude` - Decimal coordinate (11,8)
- `marker_type` - ENUM: 'damage', 'issue', 'construction', 'project', 'completed', 'infrastructure'
- `title` - Display name for the marker
- `description` - Detailed description
- `severity` - ENUM: 'low', 'medium', 'high', 'critical'
- `status` - ENUM: 'active', 'inactive', 'resolved', 'completed'
- `address` - Physical address
- `barangay` - Local administrative area
- `related_report_id` - Links to damage_reports table
- `images` - JSON array of image filenames
- `properties` - JSON for additional data
- `created_by` - User ID who created the marker
- `created_at` - Timestamp
- `updated_at` - Last update timestamp

### 2. gis_road_segments

Stores road segment information for detailed road condition mapping.

**Columns:**
- `id` - Primary key
- `segment_id` - Unique identifier (e.g., ROAD-SEG-001)
- `road_name` - Name of the road
- `start_lat`, `start_lng` - Starting coordinates
- `end_lat`, `end_lng` - Ending coordinates
- `condition_status` - ENUM: 'excellent', 'good', 'fair', 'poor', 'critical'
- `surface_type` - ENUM: 'asphalt', 'concrete', 'gravel', 'dirt'
- `lanes` - Number of lanes
- `length_km` - Road segment length
- `last_inspection` - Date of last inspection
- `next_maintenance` - Scheduled maintenance date
- `traffic_volume` - ENUM: 'low', 'medium', 'high'
- `properties` - JSON for additional data
- `created_by` - User ID
- `created_at`, `updated_at` - Timestamps

### 3. gis_construction_zones

Stores construction and maintenance zone information.

**Columns:**
- `id` - Primary key
- `zone_id` - Unique identifier (e.g., ZONE-001)
- `zone_name` - Display name for the zone
- `zone_type` - ENUM: 'road_repair', 'bridge_work', 'drainage', 'expansion', 'maintenance'
- `latitude`, `longitude` - Center coordinates
- `radius_meters` - Zone radius in meters
- `start_date`, `end_date` - Project timeline
- `status` - ENUM: 'planned', 'active', 'completed', 'delayed'
- `contractor` - Company or team responsible
- `project_cost` - Estimated or actual cost
- `description` - Project details
- `traffic_impact` - ENUM: 'none', 'minor', 'moderate', 'severe'
- `alternate_route` - Detour information
- `contact_info` - Contact details
- `properties` - JSON for additional data
- `created_by` - User ID
- `created_at`, `updated_at` - Timestamps

## API Endpoints

### 1. GET api/get_gis_data.php

Fetches GIS data for map display.

**Parameters:**
- `filter` (optional): 'all', 'issues', 'projects', 'completed'
- `bounds` (optional): JSON object with map bounds for performance optimization

**Response:**
```json
{
    "success": true,
    "message": "GIS data retrieved successfully",
    "data": {
        "type": "FeatureCollection",
        "features": [
            {
                "type": "Feature",
                "geometry": {
                    "type": "Point",
                    "coordinates": [longitude, latitude]
                },
                "properties": {
                    "id": "GIS-MARK-001",
                    "data_type": "marker",
                    "type": "damage",
                    "title": "Main Road Pothole",
                    "description": "Large pothole...",
                    "severity": "high",
                    "status": "active",
                    "address": "Main Street",
                    "barangay": "Poblacion",
                    "images": ["image1.jpg"],
                    "properties": {},
                    "created_at": "2025-01-15 10:30:00"
                }
            }
        ],
        "statistics": {
            "total_markers": 156,
            "active_issues": 24,
            "active_projects": 8,
            "critical_issues": 3,
            "construction_zones": 5
        }
    }
}
```

### 2. POST api/add_gis_marker.php

Adds new GIS markers (admin/authorized users only).

**Required Fields:**
- `latitude` - Decimal coordinate
- `longitude` - Decimal coordinate
- `marker_type` - Valid marker type
- `title` - Display name

**Optional Fields:**
- `description` - Detailed description
- `severity` - Severity level
- `address` - Physical address
- `barangay` - Administrative area
- `related_report_id` - Link to damage report
- `images` - File uploads

## Installation Steps

### 1. Setup Database

Run the SQL script in phpMyAdmin:

1. Open phpMyAdmin
2. Select your database
3. Go to "SQL" tab
4. Copy and paste contents of `setup_gis_database.sql`
5. Click "Go"

This will create:
- All required tables
- Sample data for testing
- Proper indexes for performance
- A combined view for easy data retrieval

### 2. Verify Installation

Check that tables were created correctly:

```sql
SHOW TABLES LIKE 'gis_%';
DESCRIBE gis_map_markers;
DESCRIBE gis_road_segments;
DESCRIBE gis_construction_zones;
```

### 3. Test the System

1. Navigate to the GIS mapping page
2. The map should load with sample data
3. Test filters (All, Issues, Projects, Completed)
4. Click on markers to see popup information
5. Statistics cards should show real counts

## Data Management

### Adding Markers Programmatically

You can add markers using the API or directly via SQL:

```sql
INSERT INTO gis_map_markers (
    marker_id, latitude, longitude, marker_type, 
    title, description, severity, created_by
) VALUES (
    'GIS-MARK-006', 14.6000, 120.9850, 'damage',
    'New Pothole', 'Medium sized pothole found', 'medium', 1
);
```

### Linking to Damage Reports

When citizens submit damage reports with coordinates, you can automatically create GIS markers:

```sql
INSERT INTO gis_map_markers (
    marker_id, latitude, longitude, marker_type,
    title, description, severity, related_report_id, created_by
) 
SELECT 
    CONCAT('GIS-MARK-', id),
    latitude, 
    longitude, 
    'damage',
    CONCAT('Damage Report: ', report_id),
    description,
    severity,
    id,
    reporter_id
FROM damage_reports 
WHERE latitude IS NOT NULL AND longitude IS NOT NULL;
```

## Performance Considerations

1. **Indexes**: All tables have proper indexes on coordinates and frequently queried columns
2. **Bounds Filtering**: API supports geographic bounds to limit data transfer
3. **View**: Combined view `gis_map_data` simplifies complex queries
4. **Limits**: API returns maximum 1000 features per request

## Integration with Existing System

The GIS system integrates with:

- **damage_reports** table via `related_report_id`
- **users** table via `created_by` foreign keys
- **notifications** system for activity logging
- **file uploads** system for marker images

## Troubleshooting

### Common Issues

1. **Map not loading data**: Check API endpoint is accessible
2. **No markers showing**: Verify database tables have data
3. **Filter not working**: Check API response structure
4. **Coordinates wrong**: Ensure decimal precision (10,8) and (11,8)

### Debug Queries

```sql
-- Check if markers exist
SELECT COUNT(*) FROM gis_map_markers WHERE status = 'active';

-- Check sample data
SELECT * FROM gis_map_markers LIMIT 5;

-- Test API query manually
SELECT * FROM gis_map_data WHERE data_type = 'marker' LIMIT 10;
```

## Future Enhancements

1. **Real-time Updates**: WebSocket integration for live data
2. **Advanced Filtering**: Date ranges, severity levels, barangay filters
3. **Export Features**: PDF reports, CSV exports
4. **Mobile Integration**: GPS coordinates from mobile devices
5. **Analytics**: Heat maps, trend analysis, predictive maintenance

## Security Notes

- All write operations require authentication
- Role-based access control for marker creation
- Input validation for coordinates and data types
- File upload restrictions for images
- SQL injection prevention with prepared statements
