# Changelog

## [Unreleased]
### Changed
* Certificate print and entry check results are now KS bulky links with icon and text (new language keys `print_certificate_button`, `entry_test_button`; `entry_test` removed)
* Print link always points to `ilias.php` with the current query instead of reusing the request path

### Notes
* The plugin version is unchanged, so after updating the code reload the plugin languages (Administration > Plugins > Refresh Languages)

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
