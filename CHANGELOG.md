# Changelog

All notable changes to this extension are documented here. The format
is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.5.4] - 2026-10-03

### Fixed
- setup:upgrade no longer fails with a duplicate key error when the legacy panth_seo/llms_txt settings and the new panth_llms_txt/llms_txt settings both exist for the same scope. The new setting is kept and the legacy duplicate is removed.
- The "Store not available" placeholder for llms.txt, llms-full.txt and llms.json is no longer cached for an hour, so the files recover on the next request once the store is reachable.
