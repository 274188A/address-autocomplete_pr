<?php namespace Vanderbilt\AddressExternalModule;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;

class AddressExternalModule extends AbstractExternalModule
{
	function hook_survey_page($project_id, $record, $instrument, $event_id, $group_id) {
		$this->addAddressAutoCompletion($project_id, $record, $instrument, $event_id, $group_id);
	}

	function hook_data_entry_form($project_id, $record, $instrument, $event_id, $group_id) {
		$this->addAddressAutoCompletion($project_id, $record, $instrument, $event_id, $group_id);
	}

	function addAddressAutoCompletion($project_id, $record, $instrument, $event_id, $group_id) {
		$key = $this->getProjectSetting('google-api-key', $project_id);
		$import = $this->getProjectSetting('import-google-api', $project_id);
		
		// Gather all settings into a single array
		// This allows us to pass them to JS safely via json_encode
		$settings = [
			'trigger_field' => $this->getProjectSetting('autocomplete', $project_id),
			'fields' => [
				'street_number' => $this->getProjectSetting('street-number', $project_id),
				'route' => $this->getProjectSetting('street', $project_id),
				'locality' => $this->getProjectSetting('city', $project_id),
				'administrative_area_level_2' => $this->getProjectSetting('county', $project_id),
				'administrative_area_level_1' => $this->getProjectSetting('state', $project_id),
				'postal_code' => $this->getProjectSetting('zip', $project_id),
				'country' => $this->getProjectSetting('country', $project_id),
				'latitude' => $this->getProjectSetting('latitude', $project_id),
				'longitude' => $this->getProjectSetting('longitude', $project_id),
			]
		];

		// Only proceed if API key and the trigger field are set
		if ($key && $settings['trigger_field']) {
			?>
			<script>
				(function() {
					// Safely pass PHP configuration to JavaScript
					var config = <?php echo json_encode($settings); ?>;
					var autocompleteInstance;
					
					// Map Google Address Types to the keys in our config.fields object
					var componentMap = {
						street_number: 'street_number',
						route: 'route',
						locality: 'locality',
						administrative_area_level_2: 'administrative_area_level_2',
						administrative_area_level_1: 'administrative_area_level_1',
						country: 'country',
						postal_code: 'postal_code'
					};

					// Google API format preference
					var googleFormat = {
						street_number: 'short_name',
						route: 'long_name',
						locality: 'long_name',
						administrative_area_level_2: 'short_name',
						administrative_area_level_1: 'short_name',
						country: 'long_name',
						postal_code: 'short_name'
					};

					$(document).ready(function() {
						initModule();
					});

					function initModule() {
						var $triggerInput = $('[name="' + config.trigger_field + '"]');
						if ($triggerInput.length === 0) return;

						// Assign a unique ID to the trigger field for Google to attach to
						// This allows multiple instances if needed in the future
						var uniqueId = 'google_places_' + Math.floor(Math.random() * 1000000);
						$triggerInput.attr('id', uniqueId);
						$triggerInput.wrap('<div id="locationField_' + uniqueId + '"></div>');
						$triggerInput.attr('placeholder', 'Enter your address here');

						// Set target fields to readonly (instead of disabled) so data isn't blocked if API fails
						for (var key in config.fields) {
							if (config.fields[key]) {
								var $field = $('[name="' + config.fields[key] + '"]');
								$field.prop('readonly', true).addClass('google-autofilled');
							}
						}

						// Events
						$triggerInput.on('keydown', function(e) {
							// Prevent enter key from submitting the form
							if (e.which == 13) e.preventDefault();
							geolocate();
						});
						
						$triggerInput.on('focus', function() {
							geolocate();
						});

						// Initialize Google Autocomplete
						// We check if the library is loaded
						if (typeof google === 'object' && typeof google.maps === 'object') {
							setupAutocomplete(uniqueId);
						} else {
							// Poll briefly in case script is still loading
							var checkGoogle = setInterval(function() {
								if (typeof google === 'object' && typeof google.maps === 'object') {
									clearInterval(checkGoogle);
									setupAutocomplete(uniqueId);
								}
							}, 200);
						}
					}

					function setupAutocomplete(elementId) {
						var inputElement = document.getElementById(elementId);
						if(!inputElement) return;

						var defaultBounds = new google.maps.LatLngBounds(
							new google.maps.LatLng(-90, -180),
							new google.maps.LatLng(90, 180)
						);

						autocompleteInstance = new google.maps.places.Autocomplete(
							inputElement, {
								types: ['address'],
								bounds: defaultBounds
							}
						);

						autocompleteInstance.addListener('place_changed', fillInAddress);

						// If user clears the input, clear the fields
						inputElement.addEventListener('change', (event) => {
							if (inputElement.value === "") {
								clearFields();
							}
						});
					}

					function clearFields() {
						// Trigger change on main field
						var $trigger = $('[name="' + config.trigger_field + '"]');
						$trigger.change();

						// Clear address fields
						for (var key in config.fields) {
							if (config.fields[key]) {
								updateValue(config.fields[key], '');
							}
						}
						
						if (typeof doBranching === 'function') doBranching();
					}

					function fillInAddress() {
						// Trigger change for other modules (like Census Geocoder)
						var $trigger = $('[name="' + config.trigger_field + '"]');
						$trigger.change();

						var place = autocompleteInstance.getPlace();

						// Clear existing values first
						for (var key in config.fields) {
							if (config.fields[key]) updateValue(config.fields[key], '');
						}

						if (place && place.geometry) {
							// Fill Lat/Long
							if (config.fields.latitude) updateValue(config.fields.latitude, place.geometry.location.lat());
							if (config.fields.longitude) updateValue(config.fields.longitude, place.geometry.location.lng());

							// Fill Address Components
							for (var i = 0; i < place.address_components.length; i++) {
								var addressType = place.address_components[i].types[0];
								
								// Check if this google type maps to a configured REDCap field
								if (componentMap[addressType] && config.fields[componentMap[addressType]]) {
									var format = googleFormat[addressType];
									var val = place.address_components[i][format];

									// Specific clean up for US Counties
									if (addressType == 'administrative_area_level_2') {
										val = $.trim(val.replace('County', ''));
									}

									updateValue(config.fields[componentMap[addressType]], val);
								}
							}
						}
						
						if (typeof doBranching === 'function') doBranching();
					}

					function updateValue(fieldName, value) {
						if (!fieldName) return;
						
						var $element = $('[name="' + fieldName + '"]');
						if ($element.length === 0) return;

						var eleType = $element.prop('type');

						// Handle Radios
						if ($element.hasClass('hiddenradio')) { 
							$('input[name="' + fieldName + '___radio"][value="' + value + '"]').prop('checked', true);
						} 
						// Handle Selects/Dropdowns
						else if (eleType && eleType.indexOf("select") >= 0) { 
							var $option = $element.find('option[value="' + value + '"]');
							
							if ($option.length > 0) {
								$option.prop('selected', true);
							} else {
								// Try matching with underscores
								var valUnderscore = value.replace(/\s+/g, "_");
								var $optionUnderscore = $element.find('option[value="' + valUnderscore + '"]');
								
								if ($optionUnderscore.length > 0) {
									$optionUnderscore.prop('selected', true);
								} else {
									// Try "Other"
									var $optionOther = $element.find('option[value="Other"]');
									if ($optionOther.length > 0) {
										$optionOther.prop('selected', true);
									} else {
										// Fail silently (removed Alert for UX)
										console.warn("Address AutoComplete: Value '" + value + "' not found in dropdown for " + fieldName);
										$element.find('option[value=""]').prop('selected', true);
									}
								}
							}
						} 
						// Handle Text Inputs
						else {
							$element.val(value);
						}

						// Trigger change for REDCap internals
						$element.change();

						// Handle REDCap's internal autocomplete/combobox fields
						if ($element.hasClass('rc-autocomplete')) {
							var autocompleteField = $element.closest('td').find('.ui-autocomplete-input');
							autocompleteField.val($element.find('option:selected').text());
							autocompleteField.change();
						}
					}

					function geolocate() {
						if (navigator.geolocation) {
							navigator.geolocation.getCurrentPosition(function(position) {
								var geolocation = {
									lat: position.coords.latitude,
									lng: position.coords.longitude
								};
								var circle = new google.maps.Circle({
									center: geolocation,
									radius: position.coords.accuracy
								});
								if(autocompleteInstance) {
									autocompleteInstance.setBounds(circle.getBounds());
								}
							});
						}
					}
				})();
			</script>

			<?php
			if ($import) {
				// Only load the script if it hasn't been loaded by another module
				echo '<script>
					if(typeof google === "undefined" || typeof google.maps === "undefined") {
						var script = document.createElement("script");
						script.src = "https://maps.googleapis.com/maps/api/js?key=' . urlencode($key) . '&libraries=places";
						script.async = true;
						script.defer = true;
						document.head.appendChild(script);
					}
				</script>';
			}
		}
	}
}
