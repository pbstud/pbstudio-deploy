import './styles/backend.scss';

const $ = require('jquery');
global.$ = global.jQuery = $;

import 'bootstrap';
import 'fastclick';
import 'icheck';
import 'remodal/dist/remodal.css';
import 'remodal/dist/remodal-default-theme.css';
import './styles/backend/remodal-overrides.scss';
import 'remodal';
import 'jQuery-Smart-Wizard/js/jquery.smartWizard';
import 'select2';
import 'summernote';
import 'parsleyjs';
import 'parsleyjs/src/i18n/es';
import 'bootstrap-tagsinput';
import 'blockui';

import './js/backend/custom';

$(function () {
	const $modal = $('[data-remodal-id="modal"]');
	if (!$modal.length) {
		return;
	}

	const instRemodal = $modal.remodal();
	const remodalOriginalClasses = $modal.prop('class');

	$(document).on('closed', '.remodal', function () {
		$(this).empty();
		$(this).prop('class', remodalOriginalClasses);
	});

	$('body').on('click', '[data-remodal]', function (e) {
		e.preventDefault();

		const url = $(this).data('url');
		const extraClass = $(this).data('remodal');
		if (!url) {
			return;
		}

		if (extraClass) {
			$modal.addClass(extraClass);
		}

		$modal.load(url, function () {
			if ('closed' === instRemodal.getState()) {
				instRemodal.open();
			}
		});
	});
});
