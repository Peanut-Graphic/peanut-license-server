# PR #98 client compatibility

Pending release; do not enable hardware locks merely because this client is updated.
The `peanut_license_hardware_id` WordPress filter receives an empty default and the product slug (`peanut-booker`, `peanut-suite`, or `formflow`). Return the exact fingerprint already recorded by the license administrator. Empty defaults retain existing unrestricted behavior. This is a supplied identity, not hardware attestation. Never derive a replacement fingerprint from the domain, IP, path, hostname, or a random UUID; it would not match existing bindings and can change on migration.

IP locks use the HTTP request's observed egress address; clients cannot select it. Domain locks use `home_url()` and need matching apex/subdomain/staging entries. Saved values apply even when the corresponding enforce flag is false. Missing, incorrect or unreadable restrictions continue to fail closed.

Server header support must deploy before these header-using clients. Deploy fingerprint configuration before enforcing a non-empty hardware lock; verify matching and mismatching requests on staging. Do not restore email-based ownership or grandfather existing activations around restrictions.

Download URLs still need separate review: WordPress package downloads cannot inherit headers from an earlier update-check request. Keep existing signed-token packages working; never remove a key from a legacy download URL without implementing scoped download authorization.
