<?php
/**
 * Public Transparency view – no login. Same data as staff page, blurred cityhall background.
 */
require_once __DIR__ . '/lgu_staff/includes/config.php';

$published_projects = [];
if ($conn) {
    @$conn->query("CREATE TABLE IF NOT EXISTS published_completed_projects (
        id int(11) unsigned NOT NULL AUTO_INCREMENT,
        title varchar(255) NOT NULL,
        description text,
        location varchar(255) DEFAULT NULL,
        completed_date date DEFAULT NULL,
        cost decimal(12,2) DEFAULT NULL,
        completed_by varchar(255) DEFAULT NULL,
        photo varchar(500) DEFAULT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $res = @$conn->query("SELECT id, title, description, location, completed_date, cost, completed_by, photo, created_at FROM published_completed_projects ORDER BY created_at DESC");
    if ($res && $res->num_rows > 0) {
        while ($row = $res->fetch_assoc()) {
            $published_projects[] = [
                'title' => $row['title'],
                'description' => $row['description'] ?? '',
                'location' => $row['location'] ?? '',
                'completed_date' => $row['completed_date'] ? date('Y-m-d', strtotime($row['completed_date'])) : '',
                'cost' => (float) ($row['cost'] ?? 0),
                'completed_by' => $row['completed_by'] ?? '',
                'photo' => !empty($row['photo']) ? $row['photo'] : null,
            ];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Public Transparency | LGU</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="lgu_staff/css/public_transparency.css">
    <style>
        /* Public view: no sidebar, full-width content */
        body { margin: 0; }
        .main-content { margin-left: 0 !important; padding: 24px 20px 48px !important; max-width: 100%; }
        .public-view-header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(15px);
            padding: 20px 24px;
            border-radius: 16px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }
        .public-view-header h1 {
            font-size: 24px;
            font-weight: 700;
            color: #1e3c72;
            margin: 0;
        }
        .public-view-header a {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: linear-gradient(135deg, #3762c8, #1e3c72);
            color: #fff;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 500;
            font-size: 14px;
        }
        .public-view-header a:hover { opacity: 0.95; color: #fff; }
    </style>
</head>
<body>
    <div class="main-content">
        <div class="public-view-header">
            <h1><i class="fas fa-university"></i> Public Transparency</h1>
            <a href="index.php"><i class="fas fa-arrow-left"></i> Back to Home</a>
        </div>

        <div class="publications-section publications-feed-section">
            <div class="section-header">
                <h3 class="section-title">
                    <i class="fas fa-newspaper"></i>
                    Recent Publications
                </h3>
            </div>

            <div class="publication-feed-list" role="feed" aria-label="Published projects">
                <?php if (empty($published_projects)): ?>
                <div class="publication-feed-empty">
                    <p>No publications yet. Completed projects published by the LGU will appear here.</p>
                </div>
                <?php else: ?>
                <?php foreach ($published_projects as $proj): ?>
                <article class="publication-feed-card">
                    <div class="publication-feed-card__image">
                        <?php if (!empty($proj['photo'])): ?>
                            <img src="../<?php echo htmlspecialchars(ltrim(str_replace(['../', '..\\'], '', $proj['photo']), '/\\')); ?>" alt="<?php echo htmlspecialchars($proj['title']); ?>">
                        <?php else: ?>
                            <div class="publication-feed-card__placeholder">
                                <i class="fas fa-road"></i>
                                <span>Project photo</span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="publication-feed-card__body">
                        <h4 class="publication-feed-card__title"><?php echo htmlspecialchars($proj['title']); ?></h4>
                        <div class="publication-feed-card__meta">
                            <span><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($proj['location'] ?: '—'); ?></span>
                            <span><i class="fas fa-calendar"></i> <?php echo !empty($proj['completed_date']) ? date('Y-m-d', strtotime($proj['completed_date'])) : '—'; ?></span>
                        </div>
                        <p class="publication-feed-card__desc"><?php echo nl2br(htmlspecialchars($proj['description'])); ?></p>
                        <div class="publication-feed-card__footer">
                            <div class="publication-feed-card__cost"><strong>Cost:</strong> ₱<?php echo number_format($proj['cost'], 0); ?></div>
                            <div class="publication-feed-card__by"><strong>Completed by:</strong> <?php echo htmlspecialchars($proj['completed_by'] ?: '—'); ?></div>
                        </div>
                    </div>
                </article>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
