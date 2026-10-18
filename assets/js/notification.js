/* Notification bell: auto-refresh unread count every 30s */
document.addEventListener('DOMContentLoaded', () => {
    const bell = document.querySelector('.bell');
    if (!bell) return; // Only run if the bell exists on the page

    const badge = bell.querySelector('.count');
    
    async function refreshNotifications() {
        try {
            // The BASE_URL variable is defined in dashboard-footer.php
            const response = await fetch(BASE_URL + 'api/notifications.php?action=count', { credentials: 'same-origin' });
            if (!response.ok) return;
            
            const data = await response.json();
            
            if (data.count > 0) {
                if (badge) {
                    badge.textContent = data.count;
                    badge.style.display = 'inline-block';
                } else {
                    // Create badge if it doesn't exist
                    const newBadge = document.createElement('span');
                    newBadge.className = 'count';
                    newBadge.textContent = data.count;
                    bell.appendChild(newBadge);
                }
            } else {
                if (badge) badge.style.display = 'none';
            }
        } catch (e) {
            // Silent fail
        }
    }

    // Poll every 15 seconds
    setInterval(refreshNotifications, 15000);
});
