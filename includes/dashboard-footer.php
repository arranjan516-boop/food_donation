<?php // includes/dashboard-footer.php ?>
        </div><!-- .dash-content -->
    </div><!-- .dash-main -->
</div><!-- .dash -->

<script>
    // Define BASE_URL for JavaScript
    const BASE_URL = "<?= BASE_URL ?>";
</script>
<script src="<?= BASE_URL ?>assets/js/script.js"></script>

<script>
    // ==========================================
    // LIVE NOTIFICATION POLLING
    // ==========================================
    document.addEventListener('DOMContentLoaded', () => {
        const bell = document.querySelector('.bell');
        if (!bell) return;

        // Function to fetch the latest count from the database
        async function updateBellCount() {
            try {
                const response = await fetch(BASE_URL + 'api/check-notifications.php');
                if (!response.ok) return;
                
                const data = await response.json();
                let badge = bell.querySelector('.count');

                if (data.count > 0) {
                    if (!badge) {
                        badge = document.createElement('span');
                        badge.className = 'count';
                        bell.appendChild(badge);
                    }
                    // Only animate if the number increased
                    if (badge.textContent != data.count) {
                        badge.textContent = data.count;
                        badge.style.animation = 'none';
                        badge.offsetHeight; /* trigger reflow */
                        badge.style.animation = 'pop 0.3s ease-out';
                    }
                } else {
                    if (badge) badge.remove();
                }
            } catch (error) {
                // Silently fail if network is down
            }
        }

        // Check immediately on page load
        updateBellCount();

        // Then check every 10 seconds automatically
        setInterval(updateBellCount, 10000);
    });
</script>

<style>
    /* Animation for the bell popping */
    @keyframes pop {
        0% { transform: scale(1); }
        50% { transform: scale(1.4); }
        100% { transform: scale(1); }
    }
</style>

<?php 
// Only load DataTables on Admin pages
if (strpos($_SERVER['PHP_SELF'], '/admin/') !== false): 
?>
<script>
$(document).ready(function() {
    if ($.fn.DataTable) {
        $('table.table').each(function() {
            if ($.fn.DataTable.isDataTable(this)) return;
            $(this).DataTable({
                pageLength: 10,
                lengthMenu: [10, 25, 50, 100],
                order: [],
                language: {
                    search: "🔍 Search:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries",
                    paginate: { previous: "←", next: "→" }
                }
            });
        });
    }
});
</script>
<?php endif; ?>

<?php if (!empty($extraJs)) foreach ((array)$extraJs as $j): ?>
    <script src="<?= BASE_URL ?>assets/js/<?= sanitize($j) ?>"></script>
<?php endforeach; ?>
</body>
</html>
