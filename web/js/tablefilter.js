$('.tablefilter-search').live('keyup', function() {
    var rex = new RegExp($(this).val(), 'i');
    var form = $(this).closest("form");
    $('.tablefilter-searchable tr', form).hide();
    $('.tablefilter-searchable tr', form).filter(function() {
        return rex.test(($("td.tablefilter-filter-column", $(this)).text()).trim());
    }).show();
});

$('.container').on('click', 'span.tablefilter-filter-icon', function(e) {
    $(".tablefilter-search-wrapper").slideToggle(function() {
        if($(this).is(":visible")) {
            $(this).find("input").focus();
        }
    });
});