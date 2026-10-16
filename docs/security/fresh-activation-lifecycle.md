# Fresh activation lifecycle — October 16, 2026

Released 1.5.6 registers activation/deactivation hooks inside the singleton created at init@0. WordPress includes an inactive plugin after init during activation, so the activation callback is not present when WordPress fires it. A real empty synthetic database reported activation success but contained zero license tables immediately afterward, inspected directly through MySQL without a self-healing WordPress follow-up request. The next request logs migration queries against missing tables before creating them.

Candidate 1.5.7 registers lightweight lifecycle closures when the plugin file is included. The activation callback creates the singleton and calls its existing activation method; it does not run the normal migration/service bootstrap before tables exist. Ordinary initialization remains deferred to init@0. No schema, ownership, restriction or credential semantics change.

The isolated real-source include regression fails on 1.5.6 and passes on the candidate. Real WordPress 7.1 / PHP 8.2.33 fresh activation immediately creates license, activation and restriction tables and records schema 1.8.0 before any follow-up request. The first normal request completes without the old missing-table errors. Reactivation preserves synthetic license fields and a non-default option.

Deactivation testing also reproduced duplicate ML training events surviving cleanup: the old helper removed only the next event. It now clears all events for its own standard training hook; the regression verifies an unrelated hook is untouched. Real deactivation clears both training and key-sweep events while preserving the license row. PHP 8.4 full suite passes 371 tests / 8,272 assertions.

All databases and records are disposable synthetic fixtures. Production remains 1.5.6; WooCommerce remains inactive. Exact production ZIP/update qualification and required PR/main checks remain release gates. This change does not alter the existing migration ladder or rehash existing license records.
