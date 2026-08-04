/* Common Progress Updates Export Functions for Report Management and Road Monitoring */

function exportUpdatesToExcel() {
    // Get all timeline entries
    const timelineEntries = document.querySelectorAll('.timeline-entry');
    if (timelineEntries.length === 0) {
        showNotification('No updates to export', 'error');
        return;
    }

    showNotification('Preparing document...', 'info');

    // Process images first
    processImagesAndExport(timelineEntries);
}

function processImagesAndExport(timelineEntries) {
    const updates = [];
    let firstDate = null;
    let lastDate = null;
    let imageLoadPromises = [];
    
    timelineEntries.forEach(function(entry) {
        const dateText = entry.querySelector('.time')?.textContent.trim() || '';
        const title = entry.querySelector('.timeline-title')?.textContent.trim() || '';
        const description = entry.querySelector('.timeline-desc')?.textContent.trim() || '';
        const author = entry.querySelector('.admin-badge')?.textContent.trim() || '';
        
        // Extract images
        const images = [];
        const mediaItems = entry.querySelectorAll('.timeline-media-item');
        mediaItems.forEach(function(media) {
            const img = media.querySelector('img');
            if (img && img.src) {
                // Create promise to load and resize image
                const imagePromise = resizeImage(img.src, 200);
                imageLoadPromises.push(imagePromise);
                images.push(imagePromise);
            }
        });
        
        updates.push({
            date: dateText,
            title: title,
            description: description,
            author: author,
            images: images
        });
        
        // Track dates for summary
        if (!firstDate) firstDate = dateText;
        lastDate = dateText;
    });

    // Wait for all images to be resized
    Promise.all(imageLoadPromises)
        .then(function(resizedImages) {
            // Replace image promises with resized base64 strings
            let imageIndex = 0;
            updates.forEach(function(update) {
                for (let i = 0; i < update.images.length; i++) {
                    update.images[i] = resizedImages[imageIndex++];
                }
            });
            
            // Now generate the document
            generateDocument(updates, firstDate, lastDate);
        })
        .catch(function(error) {
            console.error('Image processing error:', error);
            // Fall back to document without images
            generateDocument(updates, firstDate, lastDate);
        });
}

function resizeImage(imageSrc, maxWidth) {
    return new Promise(function(resolve, reject) {
        const img = new Image();
        img.crossOrigin = 'Anonymous';
        img.onload = function() {
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            
            // Calculate new dimensions
            const ratio = maxWidth / img.width;
            const newHeight = img.height * ratio;
            
            canvas.width = maxWidth;
            canvas.height = newHeight;
            
            // Draw resized image
            ctx.drawImage(img, 0, 0, maxWidth, newHeight);
            
            // Convert to base64
            resolve(canvas.toDataURL('image/jpeg', 0.8));
        };
        img.onerror = function() {
            resolve(null); // Return null if image fails to load
        };
        img.src = imageSrc;
    });
}

function generateDocument(updates, firstDate, lastDate) {
    try {
        // Calculate summary
        const totalUpdates = updates.length;
        const timeTaken = firstDate && lastDate ? calculateDaysBetween(firstDate, lastDate) : 0;

        // Create HTML document content
        let htmlContent = `
        <html xmlns:o='urn:schemas-microsoft-com:office:office' xmlns:w='urn:schemas-microsoft-com:office:word'>
        <head>
        <meta charset="utf-8">
        <title>Progress Updates Report</title>
        <style>
            body { font-family: 'Calibri', Arial, sans-serif; font-size: 11pt; line-height: 1.5; }
            h1 { color: #2E74B5; font-size: 18pt; text-align: center; margin-bottom: 20px; }
            h2 { color: #2E74B5; font-size: 14pt; margin-top: 20px; margin-bottom: 10px; border-bottom: 2px solid #2E74B5; padding-bottom: 5px; }
            .report-info { text-align: center; color: #666; margin-bottom: 30px; }
            .summary-table { border-collapse: collapse; width: 100%; margin-bottom: 20px; }
            .summary-table td { border: 1px solid #ddd; padding: 8px 12px; }
            .summary-table td:first-child { background-color: #f8f9fa; font-weight: bold; width: 150px; }
            .update-entry { margin-bottom: 25px; padding: 15px; background-color: #f8f9fa; border-left: 4px solid #2E74B5; }
            .update-header { color: #2E74B5; font-weight: bold; font-size: 12pt; margin-bottom: 5px; }
            .update-author { color: #666; font-style: italic; font-size: 10pt; margin-bottom: 10px; }
            .update-description { margin-bottom: 10px; }
            .update-images { margin-top: 10px; }
            .update-images img { width: 200px; height: auto; margin: 5px; border: 1px solid #ddd; }
            .image-count { color: #666; font-style: italic; font-size: 10pt; }
        </style>
        </head>
        <body>
            <h1>Progress Updates Report</h1>
            <p class="report-info">Report #${currentUpdatesReportId}</p>
            
            <h2>Project Summary</h2>
            <table class="summary-table">
                <tr><td>Start Date</td><td>${firstDate || 'N/A'}</td></tr>
                <tr><td>End Date</td><td>${lastDate || 'N/A'}</td></tr>
                <tr><td>Total Updates</td><td>${totalUpdates}</td></tr>
                <tr><td>Duration</td><td>${timeTaken} days</td></tr>
            </table>
            
            <h2>Progress Timeline</h2>
        `;

        // Add each update
        updates.forEach(function(update) {
            htmlContent += `
            <div class="update-entry">
                <div class="update-header">${update.date} - ${update.title || 'Update'}</div>
                <div class="update-author">By: ${update.author}</div>
                <div class="update-description">${update.description || 'No description'}</div>
                <div class="update-images">
            `;
            
            if (update.images.length > 0) {
                update.images.forEach(function(imgData) {
                    if (imgData) {
                        htmlContent += `<img src="${imgData}" alt="Update image" />`;
                    }
                });
            } else {
                htmlContent += `<div class="image-count">No images attached</div>`;
            }
            
            htmlContent += `
                </div>
            </div>
            `;
        });

        htmlContent += `
        </body>
        </html>
        `;

        // Create blob and download
        const blob = new Blob(['\ufeff', htmlContent], {
            type: 'application/msword'
        });
        
        const fileName = 'progress_updates_report_' + currentUpdatesReportId + '_' + new Date().toISOString().slice(0,10) + '.doc';
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = fileName;
        link.style.display = 'none';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        
        showNotification('Document exported successfully', 'success');

    } catch (error) {
        console.error('Export error:', error);
        showNotification('Failed to export document', 'error');
    }
}

function calculateDaysBetween(dateStr1, dateStr2) {
    try {
        const date1 = new Date(dateStr1);
        const date2 = new Date(dateStr2);
        const diffTime = Math.abs(date2 - date1);
        const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
        return diffDays;
    } catch (e) {
        return 0;
    }
}
