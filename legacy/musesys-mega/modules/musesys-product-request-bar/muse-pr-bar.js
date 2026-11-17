document.addEventListener('DOMContentLoaded', function() {
    const toast = document.getElementById('muse-pr-bar-toast');
    const params = new URLSearchParams(window.location.search);

    if (params.get('request_sent') === '1') {
        toast.classList.add('show');
        setTimeout(() => toast.classList.remove('show'), 4000);
    }
});
