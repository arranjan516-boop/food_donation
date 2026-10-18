<?php // includes/dashboard-footer.php ?>
        </div><!-- .dash-content -->
    </div><!-- .dash-main -->
</div><!-- .dash -->

<script>
    // Define BASE_URL for JavaScript
    const BASE_URL = "<?= BASE_URL ?>";
</script>
<script src="<?= BASE_URL ?>assets/js/script.js"></script>
<script src="<?= BASE_URL ?>assets/js/notification.js"></script>
<?php if (!empty($extraJs)) foreach ((array)$extraJs as $j): ?>
    <script src="<?= BASE_URL ?>assets/js/<?= sanitize($j) ?>"></script>
<?php endforeach; ?>
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
</body>
</html>
