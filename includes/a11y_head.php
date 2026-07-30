<script>
(function(){
    try {
        var dm = localStorage.getItem('a11y_darkMode');
        if (dm === 'true' || dm === true) {
            document.documentElement.classList.add('dark-mode');
            document.body.classList.add('dark-mode');
        }
    } catch(e) {}
})();
</script>