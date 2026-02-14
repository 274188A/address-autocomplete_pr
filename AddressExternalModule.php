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
		$key = $this->getProjectSetting('google-api-key',$project_id);
		$autocomplete = $this->getProjectSetting('autocomplete',$project_id);
		$streetNumber = $this->getProjectSetting('street-number',$project_id);
		$street = $this->getProjectSetting('street',$project_id);
		$city = $this->getProjectSetting('city',$project_id);
		$county = $this->getProjectSetting('county',$project_id);
		$state = $this->getProjectSetting('state',$project_id);
		$zip = $this->getProjectSetting('zip',$project_id);
		$country = $this->getProjectSetting('country',$project_id);
		$latitude = $this->getProjectSetting('latitude',$project_id);
		$longitude = $this->getProjectSetting('longitude',$project_id);
		$import = $this->getProjectSetting('import-google-api',$project_id);

		if ($key && $autocomplete) {

			// FIX #3: Load Google Maps API asynchronously with loading=async
			if ($import) {
				echo '<script async src="https://maps.googleapis.com/maps/api/js?key='
					. htmlspecialchars($key, ENT_QUOTES, 'UTF-8')
					. '&libraries=places&loading=async"></script>';
			}

			?>
			<style>
				#locationField { position: relative; }
				#locationField gmp-place-autocomplete {
					width: 100%;
					font-size: 13px;
				}
			</style>

			<script>
			(function() {
				var autocompletePrefix = 'googleSearch_';
				var autocompleteFieldName = <?php echo json_encode($autocomplete); ?>;

				// Component mapping: Google address type -> format preference
				var componentForm = {
					<?php echo ($streetNumber ? "street_number: 'short_name'," : ""); ?>
					<?php echo ($street ? "route: 'long_name'," : ""); ?>
					<?php echo ($city ? "locality: 'long_name'," : ""); ?>
					<?php echo ($county ? "administrative_area_level_2: 'short_name'," : ""); ?>
					<?php echo ($state ? "administrative_area_level_1: 'short_name'," : ""); ?>
					<?php echo ($country ? "country: 'long_name'," : ""); ?>
					<?php echo ($zip ? "postal_code: 'short_name'," : ""); ?>
				};

				// Map legacy property names to the new Places API property names
				var formatMap = {
					'short_name': 'shortText',
					'long_name': 'longText'
				};

				$(document).ready(function() {
					// FIX #1: Guard — only proceed if the autocomplete target field
					// exists on this particular instrument / form page.
					var $autocompleteField = $('[name="' + autocompleteFieldName + '"]');
					if ($autocompleteField.length === 0) {
						return; // Field not on this form; do nothing.
					}

					// Set up component destination fields: assign IDs and disable them
					<?php if ($streetNumber): ?>
						$('[name="<?php echo $streetNumber; ?>"]').attr('id', autocompletePrefix + 'street_number').prop('disabled', true);
					<?php endif; ?>
					<?php if ($street): ?>
						$('[name="<?php echo $street; ?>"]').attr('id', autocompletePrefix + 'route').prop('disabled', true);
					<?php endif; ?>
					<?php if ($city): ?>
						$('[name="<?php echo $city; ?>"]').attr('id', autocompletePrefix + 'locality').prop('disabled', true);
					<?php endif; ?>
					<?php if ($county): ?>
						$('[name="<?php echo $county; ?>"]').attr('id', autocompletePrefix + 'administrative_area_level_2').prop('disabled', true);
					<?php endif; ?>
					<?php if ($state): ?>
						$('[name="<?php echo $state; ?>"]').attr('id', autocompletePrefix + 'administrative_area_level_1').prop('disabled', true);
					<?php endif; ?>
					<?php if ($zip): ?>
						$('[name="<?php echo $zip; ?>"]').attr('id', autocompletePrefix + 'postal_code').prop('disabled', true);
					<?php endif; ?>
					<?php if ($country): ?>
						$('[name="<?php echo $country; ?>"]').attr('id', autocompletePrefix + 'country').prop('disabled', true);
					<?php endif; ?>
					<?php if ($latitude): ?>
						$('[name="<?php echo $latitude; ?>"]').prop('disabled', true);
					<?php endif; ?>
					<?php if ($longitude): ?>
						$('[name="<?php echo $longitude; ?>"]').prop('disabled', true);
					<?php endif; ?>

					// Wrap original field and hide it; the PlaceAutocompleteElement will replace it visually
					$autocompleteField.wrap('<div id="locationField"></div>');
					$autocompleteField.hide();

					// Initialize the autocomplete once the Google Maps API is available
					initAutocomplete($autocompleteField);
				});

				/**
				 * Polls until the Google Maps core object is available (handles async loading).
				 */
				function waitForGoogleMaps() {
					return new Promise(function(resolve) {
						if (typeof google !== 'undefined' && google.maps) {
							resolve();
							return;
						}
						var poll = setInterval(function() {
							if (typeof google !== 'undefined' && google.maps) {
								clearInterval(poll);
								resolve();
							}
						}, 100);
					});
				}

				/**
				 * Load the Places library, handling both modern (importLibrary) and
				 * legacy (synchronous script tag) loading paths.
				 */
				function loadPlacesLibrary() {
					return waitForGoogleMaps().then(function() {
						// Modern async path: importLibrary exists when loaded with loading=async
						if (typeof google.maps.importLibrary === 'function') {
							return google.maps.importLibrary('places');
						}
						// Legacy path: the places library was already loaded via the script tag
						if (google.maps.places) {
							return Promise.resolve(google.maps.places);
						}
						return Promise.reject(new Error(
							'Google Maps Places library is not available. ' +
							'Ensure the script tag includes &libraries=places.'
						));
					});
				}

				/**
				 * Initialise autocomplete on the given field.
				 *
				 * Strategy (maximises compatibility):
				 *   1. If the legacy google.maps.places.Autocomplete class exists,
				 *      use it — it works for every Google Cloud project that already
				 *      has the "Places API" enabled (the vast majority of installs).
				 *   2. Only fall through to the new PlaceAutocompleteElement when
				 *      the legacy class is absent (brand-new post-Mar-2025 accounts
				 *      that only have "Places API (New)" enabled).
				 */
				function initAutocomplete($field) {
					loadPlacesLibrary()
						.then(function(placesLib) {
							if (typeof placesLib.Autocomplete === 'function') {
								// Legacy class available — preferred path
								initWithLegacyApi(placesLib, $field);
							} else if (typeof placesLib.PlaceAutocompleteElement === 'function') {
								// New-only account — use the modern element
								initWithNewApi(placesLib.PlaceAutocompleteElement, $field);
							} else {
								console.error(
									'Address Autocomplete: neither Autocomplete nor ' +
									'PlaceAutocompleteElement found in the Places library.'
								);
							}
						})
						.catch(function(err) {
							console.error('Address Autocomplete: failed to initialise.', err);
						});
				}

				/**
				 * Modern path: PlaceAutocompleteElement (New Places API).
				 */
				function initWithNewApi(PlaceAutocompleteElement, $field) {
					var placeAutocomplete = new PlaceAutocompleteElement({
						types: ['address']
					});
					placeAutocomplete.id = autocompletePrefix + 'autocomplete';
					placeAutocomplete.setAttribute('placeholder', 'Enter your address here');

					// Insert the new element into the wrapper, before the hidden original field
					$field.before(placeAutocomplete);

					// Apply geolocation bias to improve relevance
					applyGeolocationBias(placeAutocomplete);

					// Listen for place selection (new API event)
					placeAutocomplete.addEventListener('gmp-placeselect', async function(event) {
						var place = event.place;
						if (place) {
							try {
								await place.fetchFields({
									fields: ['addressComponents', 'location', 'formattedAddress']
								});
							} catch (e) {
								console.warn('Address Autocomplete: could not fetch place fields.', e);
								place = null;
							}
						}
						fillInAddress(place, $field);
					});
				}

				/**
				 * Legacy fallback: google.maps.places.Autocomplete (deprecated but
				 * still functional on pages that loaded the API the old way).
				 */
				function initWithLegacyApi(placesLib, $field) {
					// Show the original input again — the legacy class attaches to it
					$field.show();
					$field.attr('id', autocompletePrefix + 'autocomplete');
					$field.attr('placeholder', 'Enter your address here');

					var inputEl = $field[0];
					var autocompleteObj = new placesLib.Autocomplete(inputEl, {
						types: ['address']
					});

					// Geolocation bias
					if (navigator.geolocation) {
						navigator.geolocation.getCurrentPosition(function(position) {
							var circle = new google.maps.Circle({
								center: { lat: position.coords.latitude, lng: position.coords.longitude },
								radius: position.coords.accuracy
							});
							autocompleteObj.setBounds(circle.getBounds());
						});
					}

					autocompleteObj.addListener('place_changed', function() {
						var place = autocompleteObj.getPlace();
						fillInAddressLegacy(place, $field);
					});

					// If the user clears the field, wipe all components
					inputEl.addEventListener('change', function() {
						if (inputEl.value === '') { fillInAddressLegacy(undefined, $field); }
					});
				}

				/**
				 * Fill address from the legacy Autocomplete Place result.
				 * Uses address_components[].short_name / long_name (old property names).
				 */
				function fillInAddressLegacy(place, $field) {
					for (var component in componentForm) {
						updateValue(autocompletePrefix + component, '');
					}

					if (place && place.address_components) {
						$field.change();

						if (place.geometry && place.geometry.location) {
							<?php echo ($latitude  ? "updateValue('latitude',  place.geometry.location.lat());\n" : ""); ?>
							<?php echo ($longitude ? "updateValue('longitude', place.geometry.location.lng());\n" : ""); ?>
						}

						for (var i = 0; i < place.address_components.length; i++) {
							var addressType = place.address_components[i].types[0];
							if (componentForm[addressType] && document.getElementById(autocompletePrefix + addressType)) {
								var val = place.address_components[i][componentForm[addressType]];
								if (addressType === 'administrative_area_level_2') {
									val = $.trim(val.replace('County', ''));
								}
								updateValue(autocompletePrefix + addressType, val);
								document.getElementById(autocompletePrefix + addressType).disabled = false;
							}
						}
					} else {
						$field.val('');
						$field.change();
						<?php echo ($latitude  ? "updateValue('latitude',  '');\n" : ""); ?>
						<?php echo ($longitude ? "updateValue('longitude', '');\n" : ""); ?>
					}

					if (typeof doBranching === 'function') { doBranching(); }
				}

				/**
				 * Bias the autocomplete results toward the user's current location.
				 */
				function applyGeolocationBias(placeAutocomplete) {
					if (navigator.geolocation) {
						navigator.geolocation.getCurrentPosition(function(position) {
							var circle = new google.maps.Circle({
								center: {
									lat: position.coords.latitude,
									lng: position.coords.longitude
								},
								radius: position.coords.accuracy
							});
							placeAutocomplete.locationBias = circle.getBounds();
						});
					}
				}

				/**
				 * Helper: update a REDCap field value, handling radios, selects,
				 * and rc-autocomplete dropdowns.  (Preserved from v1.0.0.)
				 */
				function updateValue(id, value) {
					if (id == 'latitude') {
						var element = $('[name="<?php echo $latitude; ?>"]');
					}
					else if (id == 'longitude') {
						var element = $('[name="<?php echo $longitude; ?>"]');
					}
					else {
						var element = $('#' + id);
					}

					if (element.length === 0) {
						console.log('Could not find the element with the following id:', id);
						return;
					}

					var eleType = element.prop('type');
					element.val(value);

					// Handle special REDCap field types
					var eleName = element.attr('name');
					if (element.hasClass('hiddenradio')) {
						$('input[name="'+eleName+'___radio"][value="'+value+'"]').prop('checked', true);
					} else if (eleType.indexOf("select") >= 0) {
						if ($('#'+id+' option[value="'+value+'"]').length > 0) {
							$('#'+id+' option[value="'+value+'"]').prop('selected', true);
						} else {
							var valUnderscore = value.replace(/\s+/g,"_");
							if ($('#'+id+' option[value="'+valUnderscore+'"]').length > 0) {
								$('#'+id+' option[value="'+valUnderscore+'"]').prop('selected', true);
							} else if ($('#'+id+' option[value="Other"]').length > 0) {
								$('#'+id+' option[value="Other"]').prop('selected', true);
							} else {
								var optionsWithMatchingContent = $('#'+id+' option').filter(function(){
									return $(this).html() === value;
								});

								if (optionsWithMatchingContent.length === 1) {
									optionsWithMatchingContent.prop('selected', true);
								} else {
									alert("The value '" + value + "' is not a valid value for the '" + eleName + "' field.");
									$('#'+id+' option[value=""]').prop('selected', true);
								}
							}
						}
					}

					element.change();

					if (element.hasClass('rc-autocomplete')) {
						var autocompleteField = element.closest('td').find('.ui-autocomplete-input');
						autocompleteField.val(element.find('option:selected').text());
						autocompleteField.change();
					}
				}

				/**
				 * Populate (or clear) all address component fields from the selected Place.
				 * Uses the NEW Places API property names: addressComponents[].longText / shortText.
				 */
				function fillInAddress(place, $field) {
					// Clear all component fields first
					for (var component in componentForm) {
						updateValue(autocompletePrefix + component, '');
					}

					if (place && place.addressComponents && place.addressComponents.length > 0) {
						// Write the full formatted address into the hidden original REDCap field
						$field.val(place.formattedAddress || '');
						$field.change();

						// Latitude & Longitude
						if (place.location) {
							<?php echo ($latitude  ? "updateValue('latitude',  place.location.lat());\n" : ""); ?>
							<?php echo ($longitude ? "updateValue('longitude', place.location.lng());\n" : ""); ?>
						}

						// Map each address component into the configured REDCap fields
						for (var i = 0; i < place.addressComponents.length; i++) {
							var comp = place.addressComponents[i];
							var addressType = comp.types[0];
							if (componentForm[addressType] && document.getElementById(autocompletePrefix + addressType)) {
								var formatKey  = componentForm[addressType];   // 'short_name' or 'long_name'
								var val = comp[formatMap[formatKey]];          // maps to 'shortText' or 'longText'
								if (addressType === 'administrative_area_level_2') {
									val = $.trim(val.replace('County', ''));
								}
								updateValue(autocompletePrefix + addressType, val);
								document.getElementById(autocompletePrefix + addressType).disabled = false;
							}
						}
					} else {
						// No place selected — clear the original field and lat/lng
						$field.val('');
						$field.change();
						<?php echo ($latitude  ? "updateValue('latitude',  '');\n" : ""); ?>
						<?php echo ($longitude ? "updateValue('longitude', '');\n" : ""); ?>
					}

					if (typeof doBranching === 'function') { doBranching(); }
				}
			})();
			</script>
			<?php
		}
	}
}
