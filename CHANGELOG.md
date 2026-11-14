# Changelog

All notable changes to nxc_powercontent from 1.4.7 on. Earlier releases are described on
<https://github.com/se7enxweb/nxc_powercontent/releases>.

## 1.4.7 (2026-11-14)

- Updated: Pull request #3 (Marco-JAC) is merged: `eZContentFunctionCollection::fetchRelatedObjects()`
  (`modules/content/ezcontentfunctioncollection.php`) declares `?array $relatedClassIdentifiers = null`, as the
  kernel's own copy does. PHP 8.4 and later reported the implicitly nullable `array ... = null` as deprecated when
  the file was compiled.
- Updated: Pull request #4 (Marco-JAC) is merged: `content/versionview` (`modules/content/versionview.php`) escapes the
  language code, `FromLanguage` and `SiteAccess` it takes from the request with
  `htmlspecialchars( ..., ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 )`, the default of PHP 8.1 and later. Without
  flags PHP 8.0 used `ENT_COMPAT | ENT_HTML401` and left a single quote as it is. A request without a language or a
  source language no longer passes null to `htmlspecialchars()` (deprecated since PHP 8.1): the values are cast to a
  string first, as the kernel's own view does; the result for a missing parameter is still an empty string.
- No escaping call without flags and no implicitly nullable parameter is left in the extension; `php -l` passes on
  PHP 8.0 and 8.5 for every PHP file, and `tests/removeeditversionviewtest.php` passes on both.
- Updated: `ezinfo.php` and `extension.xml` state 1.4.7.
