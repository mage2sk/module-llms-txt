# Changelog

All notable changes to this extension are documented here. The format
is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.3.9] — 2026-05-13 — Magento 2.4.9 XSD compatibility

### Fixed
- `etc/adminhtml/system.xml` — wrapped six `<comment>` elements that
  contained inline HTML (`<code>...</code>`) in `<![CDATA[...]]>`
  blocks. Magento 2.4.9 tightened the `system.xml` XSD so the only
  child element allowed inside a `<field>` block is `<model>`; any
  inline HTML inside `<comment>` is now rejected during
  `setup:upgrade` with:

      Element 'code': This element is not expected.
      Expected is ( model ).

  CDATA defers XML validation of the comment body, so the existing
  `<code>` styling still renders in the admin form on every
  supported Magento version (2.4.4 through 2.4.9). Affected fields:

  - `panth_llms_txt/sitemap/auto`
  - `panth_llms_txt/llms_txt/shipping_page`
  - `panth_llms_txt/llms_txt/returns_page`
  - `panth_llms_txt/llms_txt/about_page`
  - `panth_llms_txt/llms_txt/faq_page`
  - `panth_llms_txt/json/enabled`

No functional changes — purely an XSD-validation compatibility fix.

---
