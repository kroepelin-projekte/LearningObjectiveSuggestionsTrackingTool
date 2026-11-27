# Changelog

## [Unreleased]
### Fixed
* Course counter "x of y" in the tracking tool is now translated
* English legend texts translated, typo in the entry test setting label fixed

### Notes
* After updating, refresh the plugin languages (Administration → Plugins → Refresh Languages), since the version is unchanged

## [3.2.0]
### Changed
* Certificate print and entry check results are now KS bulky links with icon and text (new language keys `print_certificate_button`, `entry_test_button`; `entry_test` removed)
* Print link always points to `ilias.php` with the current query instead of reusing the request path

### Added
* Edit form: optional content style format for the buttons (property `button_style`, name of a section format of the page's content style). If set, both buttons are rendered as `<a class="ilc_section_<name>">` and take their look from the content style; empty uses the standard ILIAS look (new language keys `button_style`, `button_style_info`, `button_style_invalid`)

### Notes
* After updating, run the plugin update (Administration > Plugins) so the plugin languages are reloaded

## [3.0.0]
### Updated
* Plugin for release_11 updated

### Notes
* After updating, run `composer du` so the ilCtrl artifact is rebuilt (the `create` command then requires a CSRF token)

## [2.2.0]
### Added
* Not suggested courses in tracking tool
* Initial test results link in tracking tool
* Button for printing certificate(s)
* New configuration setting for displaying initial test
