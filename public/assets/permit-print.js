async function printPermit() {
    await document.fonts.ready;
    window.print();
}

if (document.readyState === 'complete') {
    printPermit();
} else {
    window.addEventListener('load', printPermit, { once: true });
}
