-- GIS Mapping Database Setup for Citizen Module
-- This script creates the necessary tables for the GIS mapping functionality

-- Drop existing tables if they exist (for clean setup)
DROP TABLE IF EXISTS gis_map_markers;
DROP TABLE IF EXISTS gis_road_segments;
DROP TABLE IF EXISTS gis_construction_zones;

-- GIS Map Markers Table (for points of interest like damage reports, issues, etc.)
CREATE TABLE IF NOT EXISTS gis_map_markers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    marker_id VARCHAR(20) UNIQUE NOT NULL,
    latitude DECIMAL(10,8) NOT NULL,
    longitude DECIMAL(11,8) NOT NULL,
    marker_type ENUM('damage', 'issue', 'construction', 'project', 'completed', 'infrastructure') NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    severity ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
    status ENUM('active', 'inactive', 'resolved', 'completed') DEFAULT 'active',
    address VARCHAR(255),
    barangay VARCHAR(100),
    related_report_id INT NULL,
    images JSON,
    properties JSON,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (related_report_id) REFERENCES damage_reports(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    
    INDEX idx_marker_type (marker_type),
    INDEX idx_severity (severity),
    INDEX idx_status (status),
    INDEX idx_location (latitude, longitude),
    INDEX idx_created_at (created_at)
);

-- GIS Road Segments Table (for road condition mapping)
CREATE TABLE IF NOT EXISTS gis_road_segments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    segment_id VARCHAR(20) UNIQUE NOT NULL,
    road_name VARCHAR(255) NOT NULL,
    start_lat DECIMAL(10,8) NOT NULL,
    start_lng DECIMAL(11,8) NOT NULL,
    end_lat DECIMAL(10,8) NOT NULL,
    end_lng DECIMAL(11,8) NOT NULL,
    condition_status ENUM('excellent', 'good', 'fair', 'poor', 'critical') NOT NULL DEFAULT 'good',
    surface_type ENUM('asphalt', 'concrete', 'gravel', 'dirt') DEFAULT 'asphalt',
    lanes INT DEFAULT 2,
    length_km DECIMAL(8,3),
    last_inspection DATE,
    next_maintenance DATE,
    traffic_volume ENUM('low', 'medium', 'high') DEFAULT 'medium',
    properties JSON,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    
    INDEX idx_road_name (road_name),
    INDEX idx_condition_status (condition_status),
    INDEX idx_last_inspection (last_inspection),
    INDEX idx_next_maintenance (next_maintenance)
);

-- GIS Construction Zones Table
CREATE TABLE IF NOT EXISTS gis_construction_zones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    zone_id VARCHAR(20) UNIQUE NOT NULL,
    zone_name VARCHAR(255) NOT NULL,
    zone_type ENUM('road_repair', 'bridge_work', 'drainage', 'expansion', 'maintenance') NOT NULL,
    latitude DECIMAL(10,8) NOT NULL,
    longitude DECIMAL(11,8) NOT NULL,
    radius_meters INT DEFAULT 100,
    start_date DATE NOT NULL,
    end_date DATE,
    status ENUM('planned', 'active', 'completed', 'delayed') DEFAULT 'planned',
    contractor VARCHAR(255),
    project_cost DECIMAL(12,2),
    description TEXT,
    traffic_impact ENUM('none', 'minor', 'moderate', 'severe') DEFAULT 'moderate',
    alternate_route TEXT,
    contact_info VARCHAR(255),
    properties JSON,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    
    INDEX idx_zone_type (zone_type),
    INDEX idx_status (status),
    INDEX idx_dates (start_date, end_date),
    INDEX idx_location (latitude, longitude)
);

-- Insert sample data for testing
INSERT INTO gis_map_markers (marker_id, latitude, longitude, marker_type, title, description, severity, status, address, barangay) VALUES
('GIS-MARK-001', 14.5995, 120.9842, 'damage', 'Main Road Pothole', 'Large pothole causing traffic issues near the market area', 'high', 'active', 'Main Street, Poblacion', 'Poblacion'),
('GIS-MARK-002', 14.6055, 120.9902, 'issue', 'Market Street Crack', 'Surface cracks needing immediate repair', 'medium', 'active', 'Market Street, Barangay 1', 'Barangay 1'),
('GIS-MARK-003', 14.5955, 120.9782, 'infrastructure', 'High Street', 'Good road condition, recently resurfaced', 'low', 'active', 'High Street, San Miguel', 'San Miguel'),
('GIS-MARK-004', 14.6025, 120.9872, 'construction', 'Bridge Repair', 'Ongoing bridge maintenance work', 'medium', 'active', 'Bridge Area, San Jose', 'San Jose'),
('GIS-MARK-005', 14.5985, 120.9912, 'project', 'New Road Project', 'Planned road expansion project', 'low', 'active', 'Quezon Avenue Extension', 'San Miguel');

INSERT INTO gis_road_segments (segment_id, road_name, start_lat, start_lng, end_lat, end_lng, condition_status, surface_type, lanes, length_km, last_inspection, next_maintenance) VALUES
('ROAD-SEG-001', 'Quezon Avenue', 14.5995, 120.9842, 14.6055, 120.9902, 'good', 'asphalt', 4, 1.2, '2025-01-15', '2025-06-15'),
('ROAD-SEG-002', 'Market Street', 14.6055, 120.9902, 14.5955, 120.9782, 'fair', 'concrete', 2, 0.8, '2025-01-10', '2025-04-10'),
('ROAD-SEG-003', 'High Street', 14.5955, 120.9782, 14.6025, 120.9872, 'excellent', 'asphalt', 2, 0.6, '2025-01-20', '2025-08-20');

INSERT INTO gis_construction_zones (zone_id, zone_name, zone_type, latitude, longitude, radius_meters, start_date, end_date, status, contractor, project_cost, description, traffic_impact) VALUES
('ZONE-001', 'Bridge Repair Project', 'bridge_work', 14.6025, 120.9872, 150, '2025-01-01', '2025-03-31', 'active', 'ABC Construction Co.', 250000.00, 'Major bridge rehabilitation and structural repairs', 'severe'),
('ZONE-002', 'Road Expansion', 'expansion', 14.5985, 120.9912, 200, '2025-02-15', '2025-08-15', 'planned', 'Metro Builders', 1500000.00, 'Two-lane road expansion with sidewalk addition', 'moderate'),
('ZONE-003', 'Drainage System Upgrade', 'drainage', 14.6055, 120.9902, 100, '2025-01-10', '2025-02-28', 'active', 'Drainage Solutions Inc.', 75000.00, 'Installation of new drainage system', 'minor');

-- Create view for easy data retrieval
CREATE OR REPLACE VIEW gis_map_data AS
SELECT 
    'marker' as data_type,
    marker_id as feature_id,
    latitude,
    longitude,
    marker_type as type,
    title,
    description,
    severity,
    status,
    address,
    barangay,
    images,
    properties,
    created_at
FROM gis_map_markers
WHERE status = 'active'

UNION ALL

SELECT 
    'construction' as data_type,
    zone_id as feature_id,
    latitude,
    longitude,
    zone_type as type,
    zone_name as title,
    description,
    CASE 
        WHEN traffic_impact = 'severe' THEN 'high'
        WHEN traffic_impact = 'moderate' THEN 'medium'
        ELSE 'low'
    END as severity,
    status,
    NULL as address,
    NULL as barangay,
    NULL as images,
    properties,
    created_at
FROM gis_construction_zones
WHERE status IN ('active', 'planned');

-- Show final table structures
DESCRIBE gis_map_markers;
DESCRIBE gis_road_segments;
DESCRIBE gis_construction_zones;
