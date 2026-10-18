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
</body>
</html>
