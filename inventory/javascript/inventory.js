function invLightbox(full) {
     if (document.getElementById("inventory_blur")) {
         document.getElementById("inventory_blur").style.display = "block";
         document.getElementById("inventory_lightwrap").style.display = "block";
         document.getElementById("inventory_lightimg").src = full;
     }
}

function invHideLightbox() {
	if (document.getElementById("inventory_blur")) {
         document.getElementById("inventory_blur").style.display = "none";
         document.getElementById("inventory_lightwrap").style.display = "none";
		 document.getElementById("inventory_lightimg").src = "";
     }
}
