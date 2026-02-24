# Database scan summary – LGU Road Monitoring

**Scan date:** 2026-02-23

## Single database (already merged)

- The system uses **one database only**: `lg_road_monitoring`.
- **Single config:** `road_and_infra_dept/lgu_staff/includes/config.php` defines `DB_NAME = 'lg_road_monitoring'` and creates one `$conn` used by all pages.
- All SQL scripts in `road_and_infra_dept/database/` use `USE lg_road_monitoring`; no other database name appears in the project.

**Conclusion:** There is nothing to merge; everything already runs against one database. No change was made to “merge databases into 1” because it is already one.

---

## Tables in `lg_road_monitoring`

| Table | Defined in | Used by |
|-------|------------|---------|
| `users` | lg_road_monitoring_complete.sql | login, sidebar, functions |
| `audit_trails` | lg_road_monitoring_complete.sql | dashboard charts |
| `audit_logs` | lg_road_monitoring_complete.sql | functions (audit logging) |
| `audit_attachments` | lg_road_monitoring_complete.sql | audit trails |
| `road_transportation_reports` | lg_road_monitoring_complete.sql | dashboard, verification_monitoring, road_transportation_monitoring |
| `road_maintenance_reports` | lg_road_monitoring_complete.sql | dashboard, verification_monitoring |
| `public_documents` | lg_road_monitoring_complete.sql | public_transparency (stats) |
| `document_downloads` | lg_road_monitoring_complete.sql | public_transparency |
| `published_completed_projects` | lg_road_monitoring_complete.sql | public_transparency, public_transparency_view, verification_monitoring (publish) |

---

## Optional tables (not in main schema)

The Public Transparency page references these with `@` and fallback values, so the app works even if they do not exist:

- `document_views` – stats (views total)
- `documents` – transparency score (is_public)
- `budget_allocation` – budget data
- `infrastructure_projects` – project list
- `publications` – legacy publications list

**No merge or schema change was done for these** so existing logic and fallbacks are preserved. If you add these tables later, they should live in `lg_road_monitoring` as well.

---

## SQL files in this folder

| File | Purpose |
|------|---------|
| **lg_road_monitoring_complete.sql** | **Single schema** – creates database and all tables. Use for a fresh install. |
| **clear_data.sql** | **Single cleanup script** – Section 1: clear published_completed_projects. Section 2: clear all road_transportation_reports and road_maintenance_reports. Section 3 (commented): optional “remove only dummy/sample reports” if you want to keep real data. Run the whole file for a full data reset. |

Removed as duplicates or merged:

- ~~create_published_completed_projects_table.sql~~ – table is in lg_road_monitoring_complete.sql.
- ~~add_lat_lng_to_road_reports.sql~~ – columns are in the complete schema.
- ~~clear_all_reports.sql~~, ~~clear_published_projects.sql~~, ~~remove_dummy_reports.sql~~ – merged into **clear_data.sql**.
