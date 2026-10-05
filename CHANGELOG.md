# Changelog

All notable changes to this extension are documented here. The format
is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.5.6] - 2026-10-06

### Fixed
- When the sitemap URL list is empty and Auto-detect Default Sitemap is Yes, the sitemap source and the Index Formats line in llms.txt and llms.json now use the sitemap file of each active Panth XML Sitemap profile of the store view (its Output Path and Sitemap Filename) instead of a hard-coded /sitemap.xml. /sitemap.xml is still used when Panth XML Sitemap is not installed, disabled for the store view or has not written a file yet.
