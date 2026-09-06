// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 Bijstaan
/*
 * Swap the preview without a round trip.
 *
 * Progressive: the select sits in a GET form with a Show button, so the page
 * works with this file blocked, missing or erroring. All this does is make the
 * button unnecessary.
 */
document.addEventListener('DOMContentLoaded', function () {
    var pick  = document.getElementById('glpimail-preview-pick');
    var frame = document.getElementById('glpimail-preview');

    if (!pick || !frame) {
        return;
    }

    pick.addEventListener('change', function () {
        var src = frame.getAttribute('src') || '';
        var base = src.split('?')[0];

        frame.setAttribute('src', base + '?name=' + encodeURIComponent(pick.value));
    });
});
