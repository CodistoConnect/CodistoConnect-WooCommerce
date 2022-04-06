(function() {

	document.addEventListener("DOMContentLoaded", function() {

		const templateBody = document.querySelectorAll("BODY.codisto-templates");
		if(templateBody.length) {

			document.querySelectorAll(".new-template").forEach(function(el) {

				el.addEventListener("click", function(e) {

					document.location.search = "page=codisto-templates&file=_new";

				});

			});

			document.querySelectorAll("#filename").forEach(function(el) {

				el.focus();

			});

		}

	});

})();

(function() {

	const checkButton = function() {

		const email = document.querySelector("#codisto-form input[name=email]").value;
		const emailconfirm = document.querySelector("#codisto-form input[name=emailconfirm]").value;
		const phone = document.querySelector("#codisto-form input[name=phone]").value;
		let invalid = true;
		if (email && !/^(([^<>()[\]\\.,;:\s@\"]+(\.[^<>()[\]\\.,;:\s@\"]+)*)|(\".+\"))@((\[[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\])|(([a-zA-Z\-0-9]+\.)+[a-zA-Z]{2,}))$/.test(email))
		{
			document.querySelector(".email-help-text").innerHTML = document.querySelector(".email-help-text").dataset.invalidmessage;
		} else if(!email) {
			document.querySelector(".email-help-text").innerHTML = document.querySelector(".email-help-text").dataset.defaultmessage;
		} else {
			invalid = invalid && false;
			document.querySelector(".email-help-text").innerHTML = "";
		}
		if (emailconfirm && !/^(([^<>()[\]\\.,;:\s@\"]+(\.[^<>()[\]\\.,;:\s@\"]+)*)|(\".+\"))@((\[[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\])|(([a-zA-Z\-0-9]+\.)+[a-zA-Z]{2,}))$/.test(emailconfirm))
		{
			document.querySelector(".emailconfirm-help-text").innerHTML = document.querySelector(".emailconfirm-help-text").dataset.invalidmessage;
		} else if(!emailconfirm) {
			document.querySelector(".emailconfirm-help-text").innerHTML = document.querySelector(".emailconfirm-help-text").dataset.defaultmessage;
		} else {
			invalid = invalid && false;
			document.querySelector(".emailconfirm-help-text").innerHTML = "";
		}
		if (phone && !/(\+?)\d{10,14}$/.test(phone))
		{
			document.querySelector(".phone-help-text").innerHTML = document.querySelector(".phone-help-text").dataset.invalidmessage;
		} else if(!phone) {
			document.querySelector(".phone-help-text").innerHTML = document.querySelector(".phone-help-text").dataset.defaultmessage;
		} else {
			invalid = invalid && false;
			document.querySelector(".phone-help-text").innerHTML = "";
		}
		if (!invalid && email && emailconfirm
			&& (email == emailconfirm)) {
			document.querySelector(".error-message").style.display = "none";
			document.querySelector("#codisto-form .next BUTTON").classList.add("button-primary");
		} else {
			document.querySelector("#codisto-form .next BUTTON").classList.remove("button-primary");
		}

	};

	document.addEventListener("DOMContentLoaded", function() {

		const codistoForm = document.querySelector("#codisto-form");

		if(codistoForm) {

			document.querySelector("#create-account-modal .selection").style.opacity = 0.1;

			function jsonp(url, callback) {
				var callbackName = 'jsonp_callback_' + Math.round(100000 * Math.random());
				var script = document.createElement('script');

				script.src = url + (url.indexOf('?') >= 0 ? '&' : '?') + 'callback=' + callbackName;
				document.body.appendChild(script);

				window[callbackName] = function(data) {
					delete window[callbackName];
					document.body.removeChild(script);
					callback(data);
				};
			}

			jsonp("https://ui.codisto.com/getcountrylist", function(data) {
				document.querySelector(".select-html-wrapper").innerHTML = data;
				document.querySelector("#create-account-modal .selection").style.opacity = 1;
			});

			codistoForm.addEventListener("change", checkButton);
			codistoForm.addEventListener("keyup", checkButton);
			codistoForm.addEventListener("submit", function(e) {

				const email = codistoForm.querySelector("INPUT[name=email]").value;
				const emailConfirm = codistoForm.querySelector("INPUT[name=emailconfirm]").value;
				if (email != emailConfirm) {
					e.stopPropagation();
					e.preventDefault();
					document.querySelector(".error-message").style.display = "block";
				} else {
					document.querySelector(".error-message").style.display = "none";
				}

			});

		}

		function setFrameLeft() {

			const adminMenu = document.querySelector("#adminmenuwrap");
			if(adminMenu) {
				const adminMenuWidth = parseInt(adminMenu.clientWidth, 10);
				if(adminMenuWidth) {
					document.querySelector(".codisto #wpbody").style.setProperty("left", adminMenuWidth + "px", "important");
				}
			}

		}

		setFrameLeft();

		document.getElementById("collapse-menu").addEventListener("click", function(e) {
			setFrameLeft();
		});

	});

})();
