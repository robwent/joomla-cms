/**
 * @copyright	Copyright (C) 2005 - 2013 Open Source Matters, Inc. All rights reserved.
 * @license		GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * JavaScript behavior to allow selected tab to be remained after save or page reload
 * keeping state in localstorage
 */

jQuery(function() {

    var $ = jQuery.noConflict();

    $('.nav-tabs').find('a').on('click', function(e) {
        // Store the selected tab href in localstorage
        window.localStorage.setItem('tab-href', $(e.target).attr('href'));
    });

    var activateTab = function(href) {
        var $el = $('.nav-tabs').find('a[href*=' + href + ']');
        $el.tab('show');
    }
    if (localStorage.getItem('tab-href')) {
        // Clean default tabs
        $('li').find('.active').removeClass('active');
        var tabhref = localStorage.getItem('tab-href');
        // Add active attribute for selected tab indicated by url
        activateTab(tabhref);
        // Check whether internal tab is selected (in format <tabname>-<id>)
        var seperatorIndex = tabhref.indexOf('-');
        if (seperatorIndex !== -1) {
            var singular = tabhref.substring(0, seperatorIndex);
            var plural = singular + "s";
            activateTab(plural);
        }
    }

}); 