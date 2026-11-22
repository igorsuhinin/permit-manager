const switcher = document.querySelector('.language-switcher');
document.addEventListener('click', (event) => {
    if (!switcher.contains(event.target)) switcher.open = false;
});
switcher.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && switcher.open) {
        switcher.open = false;
        switcher.querySelector('summary').focus();
    }
});
