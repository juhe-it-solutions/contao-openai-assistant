# Premium Human And Machine URL Contract

Human-facing marketing and help live on the static Contao Assistant site. Checkout, subscription
management, and the stable licensing API live in the private Licenses platform. The extension maps
German backend locales to `de`; every other locale maps to `en`.

| Purpose | Canonical URL |
| --- | --- |
| Product information | `https://contao-chatbot.com/{de\|en}/` |
| Help and guide | `https://contao-chatbot.com/{de\|en}/help` |
| Buy a license | `https://licenses.juhe-it-solutions.at/{de\|en}/products/contao-openai-assistant/checkout` |
| Manage a subscription | `https://licenses.juhe-it-solutions.at/{de\|en}/products/contao-openai-assistant/manage` |
| Validate a license | `https://licenses.juhe-it-solutions.at/api/openai-assistant/validate` |
| Deactivate a seat | `https://licenses.juhe-it-solutions.at/api/openai-assistant/deactivate` |

`LicensePortalUrlService` owns the four human URLs. `LicenseValidationService` owns the machine
URLs; do not change their path, method, or header contract when changing website links. Existing
extension releases continue through Licenses compatibility redirects.

The validation fixture is mirrored between
`tests/Premium/Fixtures/validate-contract.json` here and
`juhe-licenses/tests/fixtures/openai-assistant-validate-contract.json`. Compare them after any
contract change:

```bash
# Run once here, once in a juhe-licenses checkout, and compare the two digests.
sha256sum tests/Premium/Fixtures/validate-contract.json
sha256sum tests/fixtures/openai-assistant-validate-contract.json
```

The two SHA-256 values must match. Never place license keys, customer data, or production
credentials in either fixture.
