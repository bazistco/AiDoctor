jQuery(document).ready(function($) {
    $('nav > a').click(function(event) {
        $('nav > ul').slideToggle();
    });
    if($('.cr-sidebar-section aside .cr-menu nav ul li a').length) {
        let CR_Dropdown = $('.cr-sidebar-section aside .cr-menu nav ul li a');
        CR_Dropdown.click(function (event) {
            const dropdown = this.parentNode.getElementsByTagName('ul').item(0);
            if (dropdown != null) {
                event.preventDefault();
                $(dropdown).slideToggle();
            }
        });
    }

    $('select').multipleSelect();
    $('.cr-nav-section .cr-responsive').click(function() {
        toggleSidebar();
    });

    $('.cr-sidebar-section .cr-close, .cr-overlay').click(function() {
        closeSidebar();
    });

    $('.cr-sidebar-section .cr-sidebar').click(function() {
        toggleSidebar();
    });

    function toggleSidebar() {
        $('.cr-sidebar-section').toggleClass('cr-active');
        $('.cr-overlay').toggleClass('cr-active');
    }

    function closeSidebar() {
        $('.cr-sidebar-section').removeClass('cr-active');
        $('.cr-overlay').removeClass('cr-active');
    }

    $('.cr-nav-section .cr-actions .cr-user').click(function() {
        $('.cr-dropmenu').toggleClass('cr-active');
    });
    if (document.querySelector('.cr-offcanvas')) {
        new PerfectScrollbar('.cr-offcanvas .offcanvas-body');
    }
    if (document.querySelector('.cr-sidebar-section')) {
        new PerfectScrollbar('.cr-sidebar-section');
    }
    if (document.querySelector('.table-responsive')) {
        new PerfectScrollbar('.table-responsive');
    }
    const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]')
    const tooltipList = [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));

});
