jQuery(document).ready(function(){
    jQuery('#optiontabs a').click(function() {
    	jQuery('#optiontabs a').removeClass('current');
    	jQuery(this).addClass('current');
    	var id = jQuery(this).attr('id');
    	id = id.replace('tab_', '');
	if (id!='all') {
		jQuery('.inv_options').hide('easeOutBounce');
		jQuery('#' + id + '_options').show('easeOutBounce');
	} else {
		jQuery('.inv_options').show('easeOutBounce');
	}
    });
});
