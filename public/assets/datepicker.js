'use strict';

const term = document.querySelector('#term');
const dateField = jQuery(term);
dateField.datepicker(JSON.parse(term.dataset.datepickerOptions));
// Keep the nonmodal calendar inside the page's main landmark.
dateField.datepicker('widget')
    .appendTo(document.querySelector('main'))
    .attr({ role: 'group', 'aria-label': term.dataset.datepickerLabel });
