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
		if (email && emailconfirm
			&& (email == emailconfirm)) {
			document.querySelector("#codisto-form .next BUTTON").classList.add("button-primary");
		} else {
			document.querySelector("#codisto-form .next BUTTON").classList.remove("button-primary");
		}

	};

	document.addEventListener("DOMContentLoaded", function() {

		const codistoForm = document.querySelector("#codisto-form");

		if(codistoForm) {

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

	});

})();
