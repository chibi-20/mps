<?php if (($layoutVariant ?? 'app') === 'auth'): ?>
</main>
<?php else: ?>
        </div>
    </div>
</main>
</div>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.11.0/browser/overlayscrollbars.browser.es6.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@4.10.0/dist/js/adminlte.min.js"></script>
<?php if (!empty($layoutFooterScripts)) echo $layoutFooterScripts; ?>
</body>
</html>
