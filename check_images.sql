-- Check what image filenames are stored in the database
SELECT report_id, images FROM damage_reports WHERE images IS NOT NULL AND images != 'null' AND images != '';
